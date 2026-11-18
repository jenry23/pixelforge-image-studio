<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Money;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class BillingReport
{
    public function __construct(private PixelforgeClient $client) {}

    public function build(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $start = $startDate->toDateString();
        $end = $endDate->toDateString();

        [$providerUsage, $usageError] = $this->fetchProviderUsage($start, $end);

        $ourDays = $this->ourDailyTotals($start, $end);

        $days = [];
        $totals = ['generations' => 0, 'spend' => 0, 'cost' => 0, 'provider_reported' => 0];
        $anyDiscrepancy = false;

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
                'available' => $providerUsage !== null,
                'error' => $usageError,
                'matches' => $providerUsage === null ? null : ! $anyDiscrepancy,
            ],
        ];
    }

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

    private function datesBetween(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $dates = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    private function money(Money $money): array
    {
        return [
            'exact' => $money->toUsdString(),
            'display' => $money->toDisplayString(6),
            'nanodollars' => $money->nanodollars,
        ];
    }
}
