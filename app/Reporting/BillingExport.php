<?php

declare(strict_types=1);

namespace App\Reporting;

final class BillingExport
{
    public const array DAILY_HEADER = [
        'utc_day',
        'images',
        'charged_usd',
        'charged_nanodollars',
        'our_cost_usd',
        'our_cost_nanodollars',
        'pixelforge_cost_usd',
        'pixelforge_cost_nanodollars',
        'delta_usd',
        'delta_nanodollars',
        'profit_usd',
        'profit_nanodollars',
        'reconciled',
    ];

    public const array USERS_HEADER = [
        'user_name',
        'user_email',
        'images',
        'charged_usd',
        'charged_nanodollars',
        'our_cost_usd',
        'our_cost_nanodollars',
        'profit_usd',
        'profit_nanodollars',
    ];

    /**
     * @return list<list<scalar>>
     */
    public static function dailyRows(array $report): array
    {
        return array_map(static fn (array $day): array => [
            $day['date'],
            $day['generations'],
            $day['credit_spend']['exact'],
            $day['credit_spend']['nanodollars'],
            $day['provider_cost']['exact'],
            $day['provider_cost']['nanodollars'],
            $day['provider_reported_cost']['exact'] ?? '',
            $day['provider_reported_cost']['nanodollars'] ?? '',
            $day['delta']['exact'] ?? '',
            $day['delta']['nanodollars'] ?? '',
            $day['profit']['exact'],
            $day['profit']['nanodollars'],
            match ($day['reconciled']) {
                true => 'yes',
                false => 'no',
                default => 'unverified',
            },
        ], $report['days']);
    }

    /**
     * @return list<list<scalar>>
     */
    public static function userRows(array $report): array
    {
        return array_map(static fn (array $user): array => [
            $user['name'],
            $user['email'],
            $user['generations'],
            $user['credit_spend']['exact'],
            $user['credit_spend']['nanodollars'],
            $user['provider_cost']['exact'],
            $user['provider_cost']['nanodollars'],
            $user['profit']['exact'],
            $user['profit']['nanodollars'],
        ], $report['users']);
    }

    /**
     * @return list<list<scalar>>
     */
    public static function summaryRows(array $report): array
    {
        $totals = $report['totals'];

        $reconciliation = match (true) {
            $report['reconciliation']['available'] === false => 'unverified: '
                .($report['reconciliation']['error'] ?? 'usage endpoint unavailable'),
            $report['reconciliation']['matches'] === true => 'provider costs match Pixelforge to the nanodollar',
            default => 'provider costs disagree with Pixelforge',
        };

        return [
            ['metric', 'usd', 'nanodollars'],
            ['Period start (UTC)', $report['start_date'], ''],
            ['Period end (UTC)', $report['end_date'], ''],
            ['Images', $totals['generations'], ''],
            ['Charged to users', $totals['credit_spend']['exact'], $totals['credit_spend']['nanodollars']],
            ['Paid to Pixelforge', $totals['provider_cost']['exact'], $totals['provider_cost']['nanodollars']],
            ['Profit', $totals['profit']['exact'], $totals['profit']['nanodollars']],
            [
                'Pixelforge reported cost',
                $totals['provider_reported_cost']['exact'] ?? '',
                $totals['provider_reported_cost']['nanodollars'] ?? '',
            ],
            [
                'Delta',
                $totals['delta']['exact'] ?? '',
                $totals['delta']['nanodollars'] ?? '',
            ],
            ['Reconciliation', $reconciliation, ''],
        ];
    }

    /**
     * A cell a spreadsheet would read as a formula is neutralised before it is
     * written, because user names reach this export unfiltered.
     */
    public static function neutralise(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
