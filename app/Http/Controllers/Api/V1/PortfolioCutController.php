<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class PortfolioCutController extends Controller
{
    use ApiResponse;

    public function export(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'year'  => 'nullable|integer',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        ini_set('memory_limit', '1024M');
        ini_set('max_execution_time', 600);

        $tz = 'Asia/Kolkata';

        // ✅ Months: April 2026 → September 2026
        $months = [
            ['year' => 2026, 'month' => 4, 'label' => "April'26"],
            ['year' => 2026, 'month' => 5, 'label' => "May'26"],
            ['year' => 2026, 'month' => 6, 'label' => "June'26"],
            ['year' => 2026, 'month' => 7, 'label' => "Jul'26"],
            ['year' => 2026, 'month' => 8, 'label' => "Aug'26"],
            ['year' => 2026, 'month' => 9, 'label' => "Sep'26"],
        ];

        $rows = [];

        foreach ($months as $m) {
            $start = Carbon::create($m['year'], $m['month'], 1, 0, 0, 0, $tz)->startOfMonth();
            $end   = $start->copy()->endOfMonth();

            // ✅ 1. Loan Amount = sum(loan_amount + iot) where disbursement_date in month
            $loanAmount = DB::table('disbursement_details as dd')
                ->join('loan_basic_details as lbd', 'dd.borrower_id', '=', 'lbd.id')
                ->leftJoin('borrower_loan_schemes as bls', function ($j) {
                    $j->on('bls.borrower_id', '=', 'lbd.id')
                      ->where('bls.is_current', true);
                })
                ->whereBetween('dd.disbursement_date', [$start, $end])
                ->sum(DB::raw('lbd.loan_amount + COALESCE(bls.iot, 0)'));

            // ✅ 2. Disbursed Amount = sum(disbursement_amount) where disbursement_date in month
            $disbursedAmount = DB::table('disbursement_details')
                ->whereBetween('disbursement_date', [$start, $end])
                ->sum('disbursement_amount');

            // ✅ 3. Principal Outstanding = sum(UNPAID principal_amount) up to month-end
            $principalOutstanding = DB::table('borrower_repayment_schedules')
                ->where('payment_status', 'UNPAID')
                ->where('due_date', '<=', $end)
                ->sum('principal_amount');

            // ✅ 4. Number of EMI collected = count(PAID) where payment_date in month
            $emiCollectedCount = DB::table('borrower_repayment_schedules')
                ->where('payment_status', 'PAID')
                ->whereBetween('payment_date', [$start, $end])
                ->count();

            // ✅ 5. EMI Collected (Amount) = sum(emi_amount) where PAID + payment_date in month
            $emiCollectedAmount = DB::table('borrower_repayment_schedules')
                ->where('payment_status', 'PAID')
                ->whereBetween('payment_date', [$start, $end])
                ->sum('emi_amount');

            // ✅ 6. Loan Closed Count = count(status = 'CLOSED') where updated_at in month
            $loanClosedCount = DB::table('loan_basic_details')
                ->where('status', 'CLOSED')
                ->whereBetween('updated_at', [$start, $end])
                ->count();

            $rows[] = [
                'month'                 => $m['label'],
                'loan_amount'           => round((float) $loanAmount, 2),
                'disbursed_amount'      => round((float) $disbursedAmount, 2),
                'principal_outstanding' => round((float) $principalOutstanding, 2),
                'no_of_emi_collected'   => (int) $emiCollectedCount,
                'emi_collected_amount'  => round((float) $emiCollectedAmount, 2),
                'loan_closed_count'     => (int) $loanClosedCount,
            ];
        }

        return $this->buildExcel($rows);
    }

    private function buildExcel(array $rows)
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Portfolio Cut');

        $headers = [
            'S. No',
            'Monthwise',
            'Loan Amount',
            'Disbursed Amount',
            'Principal Outstanding',
            'Number of EMI collected',
            'EMI Collected (Amount)',
            'Loan Closed (No. Only)',
        ];

        $colCount = count($headers);
        $lastCol  = Coordinate::stringFromColumnIndex($colCount);

        // ✅ Headers
        foreach ($headers as $i => $header) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $header);
            $sheet->getColumnDimension($col)->setWidth(24);
        }

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Arial', 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F4E79']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // ✅ Data rows
        $rowIdx = 2;
        $serial = 1;

        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowIdx}", $serial++);
            $sheet->setCellValue("B{$rowIdx}", $row['month']);
            $sheet->setCellValue("C{$rowIdx}", $row['loan_amount']);
            $sheet->setCellValue("D{$rowIdx}", $row['disbursed_amount']);
            $sheet->setCellValue("E{$rowIdx}", $row['principal_outstanding']);
            $sheet->setCellValue("F{$rowIdx}", $row['no_of_emi_collected']);
            $sheet->setCellValue("G{$rowIdx}", $row['emi_collected_amount']);
            $sheet->setCellValue("H{$rowIdx}", $row['loan_closed_count']);

            if ($rowIdx % 2 === 0) {
                $sheet->getStyle("A{$rowIdx}:{$lastCol}{$rowIdx}")
                      ->getFill()->setFillType(Fill::FILL_SOLID)
                      ->getStartColor()->setARGB('FFF0F4FA');
            }

            $rowIdx++;
        }

        // ✅ Borders
        if ($rowIdx > 2) {
            $sheet->getStyle("A1:{$lastCol}" . ($rowIdx - 1))
                  ->getBorders()->getAllBorders()
                  ->setBorderStyle(Border::BORDER_THIN)
                  ->getColor()->setRGB('BDD7EE');
        }

        $sheet->freezePane('A2');

        // ✅ Filename
        $filename = 'Portfolio_Cut_Report_' . date('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }
}