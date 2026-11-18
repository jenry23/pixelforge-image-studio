<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reporting\BillingReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(Request $request, BillingReport $report): Response
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // Default to the last 14 UTC days inclusive. UTC throughout, because the
        // provider attributes usage by UTC day and a local-time window would
        // split days against its records.
        $end = isset($validated['end_date'])
            ? CarbonImmutable::parse($validated['end_date'], 'UTC')
            : CarbonImmutable::now('UTC');

        $start = isset($validated['start_date'])
            ? CarbonImmutable::parse($validated['start_date'], 'UTC')
            : $end->subDays(13);

        // The provider caps a usage query at 92 days.
        if ($start->diffInDays($end) > 91) {
            $start = $end->subDays(91);
        }

        return Inertia::render('Admin/Report', [
            'report' => $report->build($start, $end),
        ]);
    }
}
