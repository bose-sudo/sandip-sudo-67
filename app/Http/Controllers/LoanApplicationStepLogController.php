<?php

// app/Http/Controllers/LoanApplicationStepLogController.php

namespace App\Http\Controllers;

use App\Models\LoanApplicationStepLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LoanApplicationStepLogController extends Controller
{
    protected $helper_controller;

    public function __construct()
    {
        $this->helper_controller = new HelperController();
    }

    // ── Shared filter builder ──────────────────────────────────────

    private function validationRules(): array
    {
        return [
            'borrower_id' => 'nullable|integer|exists:loan_basic_details,id',
            'step_flag'   => 'nullable|string|max:100',
            'step_number' => 'nullable|integer|min:1|max:29',
            'platform'    => 'nullable|in:b2c,b2b,admin',
            'status'      => 'nullable|in:completed,reverted',
            'done_by'     => 'nullable|integer|exists:admin_users,id',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
        ];
    }

    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('borrower_id')) {
            $query->where('borrower_id', $request->borrower_id);
        }

        if ($request->filled('step_flag')) {
            $query->where('step_flag', strtolower($request->step_flag));
        }

        if ($request->filled('step_number')) {
            $query->where('step_number', $request->step_number);
        }

        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('done_by')) {
            $query->where('done_by', $request->done_by);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('done_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('done_at', '<=', $request->end_date);
        }
    }

    // ── List API ───────────────────────────────────────────────────

    public function list(Request $request)
    {
        try {
            $rules = array_merge($this->validationRules(), [
                'page'             => 'nullable|integer|min:1',
                'per_page'         => 'nullable|integer|min:1|max:100',
                'latest_only'      => 'nullable|boolean',  // if true, one row per borrower+step
            ]);

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return $this->helper_controller->send_response(
                    'failed',
                    $validator->errors()->first(),
                    422
                );
            }

            $page    = (int) $request->input('page', 1);
            $perPage = (int) $request->input('per_page', 20);

            $query = LoanApplicationStepLog::with([
                'doneBy:id,name,email',
                'borrower:id,borrower_id',  // adjust selected columns to your LoanBasicDetails
                'borrower.latestBorrowerKyc:borrower_id,name',
            ])
                ->excludeAuditMarkers();

            // Scope to latest attempts only when explicitly requested
            // (useful for "where is each borrower right now" view)
            if (filter_var($request->input('latest_only', false), FILTER_VALIDATE_BOOLEAN)) {
                $query->latestAttempts();
            }

            $this->applyFilters($query, $request);

            $total = $query->count();

            $logs = $query
                ->orderBy('done_at', 'desc')
                ->orderBy('id', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get()
                ->map(fn($log) => $this->formatRow($log));

            return $this->helper_controller->send_response('success', 'Logs retrieved successfully', 200, [
                'data'       => $logs,
                'pagination' => [
                    'total'        => $total,
                    'per_page'     => $perPage,
                    'current_page' => $page,
                    'last_page'    => (int) ceil($total / $perPage),
                    'from'         => (($page - 1) * $perPage) + 1,
                    'to'           => min($page * $perPage, $total),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // ── Export Excel API ───────────────────────────────────────────

    // composer require phpoffice/phpspreadsheet

    public function export(Request $request)
    {
        try {
            $rules = array_merge($this->validationRules(), [
                'latest_only' => 'nullable|boolean',
            ]);

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return $this->helper_controller->send_response(
                    'failed',
                    $validator->errors()->first(),
                    422
                );
            }

            $query = LoanApplicationStepLog::with([
                'doneBy:id,name,email',
                'borrower:id,borrower_id',
                'borrower.latestBorrowerKyc:borrower_id,name',  // borrower_kycs.name via LoanBasicDetails
            ])
                ->excludeAuditMarkers();

            if (filter_var($request->input('latest_only', false), FILTER_VALIDATE_BOOLEAN)) {
                $query->latestAttempts();
            }

            $this->applyFilters($query, $request);

            // ── Build spreadsheet ──────────────────────────────────────
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet       = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Step Logs');

            // ── Header style ───────────────────────────────────────────
            $headerStyle = [
                'font' => [
                    'bold'  => true,
                    'color' => ['argb' => 'FFFFFFFF'],
                    'name'  => 'Arial',
                    'size'  => 11,
                ],
                'fill' => [
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF2E75B6'],
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color'       => ['argb' => 'FFD9D9D9'],
                    ],
                ],
            ];

            // ── Row styles (alternating) ───────────────────────────────
            $rowStyleEven = [
                'fill'    => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF2F7FC']],
                'font'    => ['name' => 'Arial', 'size' => 10],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFD9D9D9']]],
            ];

            $rowStyleOdd = [
                'fill'    => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFFFFF']],
                'font'    => ['name' => 'Arial', 'size' => 10],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFD9D9D9']]],
            ];

            // ── Columns definition ─────────────────────────────────────
            $columns = [
                'A' => ['header' => 'Borrower Ref',     'width' => 18],
                'B' => ['header' => 'Borrower Name',    'width' => 22],
                'C' => ['header' => 'Step Number',      'width' => 14],
                'D' => ['header' => 'Step Flag',        'width' => 30],
                'E' => ['header' => 'Attempt No.',      'width' => 14],
                'F' => ['header' => 'Status',           'width' => 14],
                'G' => ['header' => 'Platform',         'width' => 12],
                'H' => ['header' => 'Done By (ID)',     'width' => 14],
                'I' => ['header' => 'Done By (Name)',   'width' => 22],
                'J' => ['header' => 'Done By (Email)',  'width' => 28],
                'K' => ['header' => 'Description',      'width' => 35],
                'L' => ['header' => 'Done At',          'width' => 22],
            ];

            foreach ($columns as $col => $config) {
                $sheet->setCellValue("{$col}1", $config['header']);
                $sheet->getColumnDimension($col)->setWidth($config['width']);
            }

            $lastCol = array_key_last($columns);  // 'L'
            $sheet->getStyle("A1:{$lastCol}1")->applyFromArray($headerStyle);
            $sheet->getRowDimension(1)->setRowHeight(20);

            // ── Write data rows in chunks ──────────────────────────────
            $rowIndex = 2;

            $query->orderBy('done_at', 'desc')
                ->orderBy('id', 'desc')
                ->chunk(500, function ($logs) use ($sheet, &$rowIndex, $rowStyleEven, $rowStyleOdd, $lastCol) {
                    foreach ($logs as $log) {
                        $sheet->setCellValue("A{$rowIndex}", $log->borrower?->borrower_id ?? '');
                        $sheet->setCellValue("B{$rowIndex}", $log->borrower?->latestBorrowerKyc?->name ?? '');
                        $sheet->setCellValue("C{$rowIndex}", $log->step_number);
                        $sheet->setCellValue("D{$rowIndex}", $log->step_flag);
                        $sheet->setCellValue("E{$rowIndex}", $log->attempt_number);
                        $sheet->setCellValue("F{$rowIndex}", $log->status);
                        $sheet->setCellValue("G{$rowIndex}", $log->platform);
                        $sheet->setCellValue("H{$rowIndex}", $log->done_by);
                        $sheet->setCellValue("I{$rowIndex}", $log->doneBy?->name ?? '');
                        $sheet->setCellValue("J{$rowIndex}", $log->doneBy?->email ?? '');
                        $sheet->setCellValue("K{$rowIndex}", $log->description ?? '');
                        $sheet->setCellValue(
                            "L{$rowIndex}",   // ← was wrongly "M"
                            $log->done_at
                                ? Carbon::parse($log->done_at)
                                ->setTimezone('Asia/Kolkata')
                                ->format('d-m-Y H:i:s')
                                : ''
                        );

                        $style = ($rowIndex % 2 === 0) ? $rowStyleEven : $rowStyleOdd;
                        $sheet->getStyle("A{$rowIndex}:{$lastCol}{$rowIndex}")->applyFromArray($style);
                        $sheet->getRowDimension($rowIndex)->setRowHeight(16);

                        $rowIndex++;
                    }
                });

            $sheet->freezePane('A2');
            $sheet->setAutoFilter("A1:{$lastCol}1");

            $filename = 'loan_step_logs_' . Carbon::now('Asia/Kolkata')->format('d_m_Y_His') . '.xlsx';
            $writer   = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

            return response()->streamDownload(function () use ($writer) {
                $writer->save('php://output');
            }, $filename, [
                'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma'        => 'no-cache',
                'Expires'       => '0',
            ]);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    private function formatRow(LoanApplicationStepLog $log): array
    {
        return [
            'id'             => $log->id,
            'borrower_ref'   => $log->borrower?->borrower_id ?? null,
            'borrower_name'  => $log->borrower?->latestBorrowerKyc?->name ?? null,  // ← fixed chain
            'step_number'    => $log->step_number,
            'step_flag'      => $log->step_flag,
            'attempt_number' => $log->attempt_number,
            'status'         => $log->status,
            'platform'       => $log->platform,
            'done_by'        => [
                'id'    => $log->done_by,
                'name'  => $log->doneBy?->name,
                'email' => $log->doneBy?->email,
            ],
            'description'    => $log->description,
            'done_at'        => $log->done_at
                ? Carbon::parse($log->done_at)
                ->setTimezone('Asia/Kolkata')
                ->format('d-m-Y H:i:s')
                : null,
        ];
    }
}
