<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reporting\BillingReport;
use App\Reporting\ReportPeriod;
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
            'days_page' => ['nullable', 'integer', 'min:1'],
            'users_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $period = ReportPeriod::fromDates(
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        return Inertia::render('Admin/Report', [
            'report' => Inertia::defer(fn (): array => $report->build(
                $period->start,
                $period->end,
                (int) ($validated['days_page'] ?? 1),
                (int) ($validated['users_page'] ?? 1),
            )),
        ]);
    }
}
