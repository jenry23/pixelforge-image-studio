<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportBillingRequest;
use App\Reporting\BillingExport;
use App\Reporting\BillingReport;
use App\Reporting\ReportPeriod;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingExportController extends Controller
{
    public function daily(ExportBillingRequest $request, BillingReport $report): StreamedResponse
    {
        [$period, $data] = $this->period($request, $report);

        return $this->csv(
            "billing-{$period->slug()}-daily.csv",
            BillingExport::DAILY_HEADER,
            BillingExport::dailyRows($data),
        );
    }

    public function users(ExportBillingRequest $request, BillingReport $report): StreamedResponse
    {
        [$period, $data] = $this->period($request, $report);

        return $this->csv(
            "billing-{$period->slug()}-by-user.csv",
            BillingExport::USERS_HEADER,
            BillingExport::userRows($data),
        );
    }

    public function workbook(ExportBillingRequest $request, BillingReport $report): BinaryFileResponse
    {
        [$period, $data] = $this->period($request, $report);

        $path = tempnam(sys_get_temp_dir(), 'billing-').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);

        $this->sheet($writer, 'Summary', null, BillingExport::summaryRows($data));

        $writer->addNewSheetAndMakeItCurrent();
        $this->sheet($writer, 'Daily breakdown', BillingExport::DAILY_HEADER, BillingExport::dailyRows($data));

        $writer->addNewSheetAndMakeItCurrent();
        $this->sheet($writer, 'By user', BillingExport::USERS_HEADER, BillingExport::userRows($data));

        $writer->close();

        return response()
            ->download($path, "billing-{$period->slug()}.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * @return array{0: ReportPeriod, 1: array}
     */
    private function period(ExportBillingRequest $request, BillingReport $report): array
    {
        $validated = $request->validated();

        $period = ReportPeriod::fromDates(
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        return [$period, $report->full($period->start, $period->end)];
    }

    private function sheet(Writer $writer, string $name, ?array $header, array $rows): void
    {
        $writer->getCurrentSheet()->setName($name);

        if ($header !== null) {
            $writer->addRow(Row::fromValues($header));
        }

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(
                static fn (mixed $cell): string => BillingExport::neutralise($cell),
                $row,
            )));
        }
    }

    private function csv(string $filename, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, $header, escape: '');

            foreach ($rows as $row) {
                fputcsv($handle, array_map(
                    static fn (mixed $cell): string => BillingExport::neutralise($cell),
                    $row,
                ), escape: '');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
