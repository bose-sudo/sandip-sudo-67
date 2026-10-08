<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BorrowerKyc;
use App\Models\LoanBasicDetails;
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

class PosDataReportController extends Controller
{
    use ApiResponse;

    public function export(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'year'  => 'nullable|integer',
            'month' => 'nullable|integer|min:1|max:12',
            'fin_ids' => 'required|string',   // Comma-separated FIN IDs
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        ini_set('memory_limit', '1024M');
        ini_set('max_execution_time', 600);

        $tz = 'Asia/Kolkata';
        $year  = (int) ($request->input('year') ?: 2026);
        $month = (int) ($request->input('month') ?: 9);   // Default September

        // ✅ FIN IDs parse korun (comma-separated)
        $finIds = array_map('trim', explode(',', $request->input('fin_ids')));
        $finIds = array_filter($finIds);

        if (empty($finIds)) {
            return $this->send_response('failed', 'No FIN IDs provided', 422);
        }

        // ✅ Period: Sept 2026
        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, $tz)->startOfMonth();
        $periodEnd   = $periodStart->copy()->endOfMonth();

        // ✅ Fetch loans for these FIN IDs
        $loans = LoanBasicDetails::with([
            'latestBorrowerKyc:id,borrower_id,name',
            'repaymentSchedules:id,borrower_id,emi_amount,due_date,payment_status,principal_amount,interest_amount,payment_date',
        ])
        ->whereIn('borrower_id', $finIds)
        ->select([
            'id',
            'borrower_id',
            'loan_amount',
            'loan_term',
            'status',
            'created_at',
        ])
        ->orderBy('borrower_id')
        ->get();

        if ($loans->isEmpty()) {
            return $this->send_response('success', 'No records found', 200, []);
        }

        // ✅ Prepare rows
        $rows = [];

        foreach ($loans as $loan) {
            $kyc       = $loan->latestBorrowerKyc;
            $schedules = $loan->repaymentSchedules;

            // First schedule → EMI amount
            $firstSched = $schedules->sortBy('due_date')->first();
            $emiAmount  = $firstSched?->emi_amount ?? '';

            // No of EMIs paid → Count PAID
            $noOfEmiPaid = $schedules
                ->filter(fn($s) => strtoupper($s->payment_status) === 'PAID')
                ->count();

            // POS Amount → Sum of UNPAID principal
            $posAmount = $schedules
                ->filter(fn($s) => strtoupper($s->payment_status) === 'UNPAID')
                ->sum(fn($s) => (float) ($s->principal_amount ?? 0));

            $rows[] = [
                'fin_id'         => $loan->borrower_id,
                'borrower_name'  => $kyc?->name ?? '',
                'loan_amount'    => $loan->loan_amount,
                'emi_amount'     => $emiAmount,
                'tenure'         => $loan->loan_term,
                'no_of_emi_paid' => $noOfEmiPaid,
                'pos_amount'     => round($posAmount, 2),
            ];
        }

        // ✅ Sort by FIN ID
        usort($rows, fn($a, $b) => strcmp($a['fin_id'], $b['fin_id']));

        // ✅ Build Excel
        return $this->buildExcel($rows, $year, $month);
    }

    private function buildExcel(array $rows, int $year, int $month): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('POS Data');

        // Headers
        $headers = [
            'S. No',
            'FIN ID',
            'Borrower name',
            'Loan amount',
            'EMI amount',
            'Tenure',
            'No of emi paid',
            'POS amount',
        ];

        $colCount = count($headers);
        $lastCol  = Coordinate::stringFromColumnIndex($colCount);

        // Write headers
        foreach ($headers as $i => $header) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $header);
            $sheet->getColumnDimension($col)->setWidth(18);
        }

        // Header style
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['argb' => 'FFFFFFFF'],
                'name'  => 'Arial',
                'size'  => 11,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF1F4E79'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        // Data rows
        $rowIdx = 2;
        $serial = 1;

        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowIdx}", $serial++);
            $sheet->setCellValue("B{$rowIdx}", $row['fin_id']);
            $sheet->setCellValue("C{$rowIdx}", $row['borrower_name']);
            $sheet->setCellValue("D{$rowIdx}", $row['loan_amount']);
            $sheet->setCellValue("E{$rowIdx}", $row['emi_amount']);
            $sheet->setCellValue("F{$rowIdx}", $row['tenure']);
            $sheet->setCellValue("G{$rowIdx}", $row['no_of_emi_paid']);
            $sheet->setCellValue("H{$rowIdx}", $row['pos_amount']);

            // Alternate row shading
            if ($rowIdx % 2 === 0) {
                $sheet->getStyle("A{$rowIdx}:{$lastCol}{$rowIdx}")
                    ->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFF0F4FA');
            }

            $rowIdx++;
        }

        // Borders
        if ($rowIdx > 2) {
            $sheet->getStyle("A1:{$lastCol}" . ($rowIdx - 1))
                ->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setRGB('BDD7EE');
        }

        $sheet->freezePane('A2');

        // Filename
        $monthLabel = date('M', mktime(0, 0, 0, $month, 1));
        $filename   = "POS_Data_Report_{$monthLabel}_{$year}_" . date('Ymd_His') . '.xlsx';

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