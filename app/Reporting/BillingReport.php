<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Money;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\UsageReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The operator report: credit spend, provider cost and profit, per UTC day and
 * per user, reconciled against the provider's own billing record.
 *
 * Two things make it trustworthy rather than merely plausible:
 *
 *   - It groups by `billed_on_utc`, derived from the provider's `completed_at`.
 *     Pixelforge bills "on the UTC day it completed, not the day it was
 *     created", so grouping any other way produces a report that almost ties
 *     out, which is worse than one that obviously does not.
 *   - It compares our computed provider cost to GET /v1/usage as exact
 *     integers, and when that endpoint is unreachable it says so explicitly
 *     instead of presenting our own figures as verified.
 */
final readonly class BillingReport
{
    public function __construct(private PixelforgeClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $start = $startDate->toDateString();
        $end = $endDate->toDateString();

        [$providerUsage, $usageError] = $this->fetchProviderUsage($start, $end);

        $ourDays = $this->ourDailyTotals($start, $end);

        $days = [];
        $totals = ['generations' => 0, 'spend' => 0, 'cost' => 0, 'provider_reported' => 0];
        $anyDiscrepancy = false;

        // Walk every day in the window, including days with no activity, so the
        // report lines up with the provider's own zero-filled response.
        foreach ($this->datesBetween($startDate, $endDate) as $date) {
            $ours = $ourDays[$date] ?? ['generations' => 0, 'spend' => 0, 'cost' => 0];

            $spend = Money::fromNanodollars((int) $ours['spend']);
            $cost = Money::fromNanodollars((int) $ours['cost']);
            $reported = $providerUsage?->costOn($date);

            $delta = $reported === null ? null : $reported->minus($cost);
            $matches = $delta?->isZero();

            if ($matches === false) {
                $anyDiscrepancy = true;
            }

            $days[] = [
                'date' => $date,
                'generations' => (int) $ours['generations'],
                'credit_spend' => $this->money($spend),
                'provider_cost' => $this->money($cost),
                'profit' => $this->money($spend->minus($cost)),
                // Null when the provider's record could not be fetched -- the UI
                // renders this as "unavailable", never as agreement.
                'provider_reported_cost' => $reported === null ? null : $this->money($reported),
                'delta' => $delta === null ? null : $this->money($delta),
                'reconciled' => $matches,
            ];

            $totals['generations'] += (int) $ours['generations'];
            $totals['spend'] += $spend->nanodollars;
            $totals['cost'] += $cost->nanodollars;
            $totals['provider_reported'] += $reported?->nanodollars ?? 0;
        }

        return [
            'start_date' => $start,
            'end_date' => $end,
            'days' => $days,
            'users' => $this->perUserTotals($start, $end),
            'totals' => [
                'generations' => $totals['generations'],
                'credit_spend' => $this->money(Money::fromNanodollars($totals['spend'])),
                'provider_cost' => $this->money(Money::fromNanodollars($totals['cost'])),
                'profit' => $this->money(Money::fromNanodollars($totals['spend'] - $totals['cost'])),
                'provider_reported_cost' => $providerUsage === null
                    ? null
                    : $this->money(Money::fromNanodollars($totals['provider_reported'])),
                'delta' => $providerUsage === null
                    ? null
                    : $this->money(Money::fromNanodollars($totals['provider_reported'] - $totals['cost'])),
            ],
            'reconciliation' => [
                // The distinction the UI depends on: did we verify, and did it agree?
                'available' => $providerUsage !== null,
                'error' => $usageError,
                'matches' => $providerUsage === null ? null : ! $anyDiscrepancy,
            ],
        ];
    }

    /**
     * @return array{0: ?UsageReport, 1: ?string}
     */
    private function fetchProviderUsage(string $start, string $end): array
    {
        try {
            return [$this->client->usage($start, $end), null];
        } catch (Throwable $e) {
            Log::warning('Could not fetch Pixelforge usage for the operator report.', [
                'reason' => $e->getMessage(),
            ]);

            return [null, $e->getMessage()];
        }
    }

    /**
     * Our own billed totals per UTC day.
     *
     * @return array<string, array{generations: int, spend: int, cost: int}>
     */
    private function ourDailyTotals(string $start, string $end): array
    {
        return Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('billed_on_utc', [$start, $end])
            ->groupBy('billed_on_utc')
            ->select('billed_on_utc')
            ->selectRaw('COUNT(*) as generations')
            ->selectRaw('COALESCE(SUM(charged_nanodollars), 0) as spend')
            ->selectRaw('COALESCE(SUM(provider_cost_nanodollars), 0) as cost')
            ->get()
            ->keyBy(fn ($row): string => CarbonImmutable::parse($row->billed_on_utc)->toDateString())
            ->map(fn ($row): array => [
                'generations' => (int) $row->generations,
                'spend' => (int) $row->spend,
                'cost' => (int) $row->cost,
            ])
            ->all();
    }

    /**
     * Per-user totals for the window, so an operator can see who spent what.
     *
     * @return array<int, array<string, mixed>>
     */
    private function perUserTotals(string $start, string $end): array
    {
        return Generation::query()
            ->where('generations.status', GenerationStatus::Succeeded)
            ->whereBetween('billed_on_utc', [$start, $end])
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->select('users.id', 'users.name', 'users.email')
            ->selectRaw('COUNT(*) as generations')
            ->selectRaw('COALESCE(SUM(charged_nanodollars), 0) as spend')
            ->selectRaw('COALESCE(SUM(provider_cost_nanodollars), 0) as cost')
            ->orderByDesc(DB::raw('SUM(charged_nanodollars)'))
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'generations' => (int) $row->generations,
                'credit_spend' => $this->money(Money::fromNanodollars((int) $row->spend)),
                'provider_cost' => $this->money(Money::fromNanodollars((int) $row->cost)),
                'profit' => $this->money(Money::fromNanodollars((int) $row->spend - (int) $row->cost)),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function datesBetween(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $dates = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    /**
     * Money for the UI: an exact string for auditing, a rounded one for reading,
     * and the integer for any client-side sorting.
     *
     * @return array<string, mixed>
     */
    private function money(Money $money): array
    {
        return [
            'exact' => $money->toUsdString(),
            'display' => $money->toDisplayString(6),
            'nanodollars' => $money->nanodollars,
        ];
    }
}
