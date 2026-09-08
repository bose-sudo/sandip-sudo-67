<?php

namespace App\Http\Controllers;

use App\Models\SubventionDetails;
use App\Models\SubventionUploadLog;
use App\Services\SubventionUploadService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class SubventionUploadController extends Controller
{
    public function __construct(
        private readonly HelperController        $helper_controller,
        private readonly SubventionUploadService $uploadService,
    ) {}

    // ══════════════════════════════════════════════════════════════
    //  1. DOWNLOAD EXCEL TEMPLATE
    //     GET /subvention/excel/template
    // ══════════════════════════════════════════════════════════════

    public function downloadTemplate()
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Subvention Upload');

        $headers = [
            'A1' => 'borrower_id',
            'B1' => 'subvention_amount',
            'C1' => 'payment_date',
            'D1' => 'utr_number',
            'E1' => 'transaction_id',
            'F1' => 'bank_name',
            'G1' => 'rc_amount',
            'H1' => 'rc_utr_number',
            'I1' => 'rc_payment_date',
            'J1' => 'rc_bank_name',
            'K1' => 'cheque_amount',
            'L1' => 'cheque_utr_number',
            'M1' => 'cheque_bank_name',
            'N1' => 'cheque_payment_date',
        ];

        foreach ($headers as $cell => $label) {
            $sheet->setCellValue($cell, $label);
        }

        // Style header row
        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle('A1:N1')->applyFromArray($headerStyle);

        // Auto-width
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Sample data row
        $sheet->setCellValue('A2', 'ANC-001');
        $sheet->setCellValue('B2', 5000.00);
        $sheet->setCellValue('C2', date('d/m/Y'));
        $sheet->setCellValue('D2', "'UTR123456");
        $sheet->setCellValue('E2', 'TXN001');
        $sheet->setCellValue('F2', 'State Bank of India');
        // RC and cheque columns left blank — optional

        // Column comments
        $sheet->getComment('C1')->getText()->createTextRun('Date format: dd/mm/yyyy');
        $sheet->getComment('I1')->getText()->createTextRun('Date format: dd/mm/yyyy');
        $sheet->getComment('N1')->getText()->createTextRun('Date format: dd/mm/yyyy');
        $sheet->getComment('D1')->getText()->createTextRun("Add a leading apostrophe to preserve leading zeros, e.g. '0012345");
        $sheet->getComment('H1')->getText()->createTextRun("Add a leading apostrophe to preserve leading zeros, e.g. '0012345");
        $sheet->getComment('L1')->getText()->createTextRun("Add a leading apostrophe to preserve leading zeros, e.g. '0012345");

        // Force date columns to text so Excel doesn't auto-convert
        foreach (['C', 'I', 'N'] as $col) {
            $sheet->getStyle("{$col}2:{$col}1000")->getNumberFormat()->setFormatCode('@');
        }

        $writer   = new Xlsx($spreadsheet);
        $fileName = 'subvention_upload_template.xlsx';
        $tempPath = sys_get_temp_dir() . '/' . $fileName;
        $writer->save($tempPath);

        return response()->download($tempPath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    // ══════════════════════════════════════════════════════════════
    //  2. UPLOAD EXCEL FILE
    //     POST /subvention/excel/upload
    //
    //  Flow:
    //   a) Store file to disk
    //   b) Parse + validate all rows immediately
    //   c) Errors found  → log as 'failed', return errors with row numbers
    //   d) Clean file    → log as 'pending', awaiting admin approval
    // ══════════════════════════════════════════════════════════════

    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file'    => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'remarks' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $file         = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $storedName   = 'subvention_uploads/' . now()->format('Ymd_His') . '_' . auth()->id() . '.' . $file->getClientOriginalExtension();

            Storage::disk('public')->putFileAs(
                'subvention_uploads',
                $file,
                basename($storedName)
            );

            // ── Parse file ────────────────────────────────────────
            [$rows, $parseError] = $this->_parseFile($storedName);

            if ($parseError) {
                $log = SubventionUploadLog::create([
                    'file_path'        => $storedName,
                    'original_name'    => $originalName,
                    'uploaded_by'      => auth()->id(),
                    'uploaded_remarks' => $request->remarks,
                    'ip'               => $this->helper_controller->get_user_ip(),
                    'status'           => 'failed',
                    'total_rows'       => 0,
                    'success_rows'     => 0,
                    'failed_rows'      => 0,
                    'error_summary'    => json_encode([['row' => '-', 'field' => 'file', 'message' => $parseError]]),
                ]);

                return $this->helper_controller->send_response('failed', $parseError, 422, [
                    'upload_log_id' => $log->id,
                    'errors'        => [['row' => '-', 'field' => 'file', 'message' => $parseError]],
                ]);
            }

            // ── Validate all rows ─────────────────────────────────
            $result = $this->uploadService->validateRows($rows);

            if (!empty($result['errors'])) {
                // Row-level errors — log as failed, return errors with row numbers to uploader
                $log = SubventionUploadLog::create([
                    'file_path'        => $storedName,
                    'original_name'    => $originalName,
                    'uploaded_by'      => auth()->id(),
                    'uploaded_remarks' => $request->remarks,
                    'ip'               => $this->helper_controller->get_user_ip(),
                    'status'           => 'failed',
                    'total_rows'       => $rows->count(),
                    'success_rows'     => count($result['valid']),
                    'failed_rows'      => count($result['errors']),
                    'error_summary'    => json_encode($result['errors']),
                ]);

                return $this->helper_controller->send_response('failed', 'Validation errors found. Please fix the highlighted rows and re-upload.', 422, [
                    'upload_log_id' => $log->id,
                    'total_rows'    => $rows->count(),
                    'error_count'   => count($result['errors']),
                    'errors'        => $result['errors'],
                ]);
            }

            // ── All rows clean → pending admin approval ───────────
            $log = SubventionUploadLog::create([
                'file_path'        => $storedName,
                'original_name'    => $originalName,
                'uploaded_by'      => auth()->id(),
                'uploaded_remarks' => $request->remarks,
                'ip'               => $this->helper_controller->get_user_ip(),
                'status'           => 'pending',
                'total_rows'       => $rows->count(),
                'success_rows'     => 0,
                'failed_rows'      => 0,
                'error_summary'    => null,
            ]);

            return $this->helper_controller->send_response('success', 'File validated successfully, pending admin approval', 200, [
                'upload_log_id' => $log->id,
                'original_name' => $originalName,
                'total_rows'    => $rows->count(),
                'status'        => 'pending',
            ]);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Upload failed: ' . $e->getMessage(), 422);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  3. LIST UPLOAD LOGS
    //     GET /subvention/excel/logs
    // ══════════════════════════════════════════════════════════════

    public function uploadLogs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'status'     => 'nullable|in:pending,approved,rejected,failed',
            'start_date' => 'nullable|date|before_or_equal:today',
            'end_date'   => 'nullable|date|before_or_equal:today|after_or_equal:start_date',
            'page'       => 'nullable|integer|min:1',
            'limit'      => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $query = SubventionUploadLog::with(['uploadedBy:id,name,email', 'actionedBy:id,name,email'])
            ->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $page  = $request->input('page', 1);
        $limit = $request->input('limit', 10);
        $logs  = $query->paginate($limit, ['*'], 'page', $page);

        $formatDate = fn($date) => $date ? \Carbon\Carbon::parse($date)->format('d-m-Y H:i:s') : null;

        $data = collect($logs->items())->map(function ($log) use ($formatDate) {
            return [
                'id'               => $log->id,
                'file_path'        => $log->file_path ? url(Storage::url($log->file_path)) : null,
                'original_name'    => $log->original_name,
                'uploaded_by'      => $log->uploadedBy,
                'uploaded_remarks' => $log->uploaded_remarks,
                'ip'               => $log->ip,
                'status'           => $log->status,
                'actioned_by'      => $log->actionedBy,
                'action_remarks'   => $log->action_remarks,
                'actioned_at'      => $formatDate($log->actioned_at),
                'action_ip'        => $log->action_ip,
                'total_rows'       => $log->total_rows,
                'success_rows'     => $log->success_rows,
                'failed_rows'      => $log->failed_rows,
                'error_summary'    => $log->error_summary,
                'created_at'       => $formatDate($log->created_at),
                'updated_at'       => $formatDate($log->updated_at),
            ];
        });

        return $this->helper_controller->send_response('success', 'Upload logs retrieved', 200, [
            'data'         => $data,
            'total'        => $logs->total(),
            'current_page' => $logs->currentPage(),
            'last_page'    => $logs->lastPage(),
            'per_page'     => $logs->perPage(),
            'from'         => $logs->firstItem(),
            'to'           => $logs->lastItem(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  4. APPROVE OR REJECT
    //     POST /subvention/excel/logs/{id}/action
    //
    //  Flow on approval:
    //   a) Re-parse + re-validate (safety net — data may have changed
    //      in DB since upload e.g. another batch inserted the same borrower)
    //   b) Errors found → mark failed, return row-level errors
    //   c) Clean        → batch insert, mark approved
    // ══════════════════════════════════════════════════════════════

    public function action(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'action'  => 'required|in:approved,rejected',
            'remarks' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $log = SubventionUploadLog::find($id);
        if (!$log) {
            return $this->helper_controller->send_response('failed', 'Upload log not found', 422);
        }

        if ($log->status !== 'pending') {
            return $this->helper_controller->send_response('failed', "This upload has already been {$log->status}", 422);
        }

        // ── REJECTION — simple status update, no processing ───────
        if ($request->action === 'rejected') {
            $log->update([
                'status'         => 'rejected',
                'actioned_by'    => auth()->id(),
                'action_remarks' => $request->remarks,
                'actioned_at'    => now(),
                'action_ip'      => $this->helper_controller->get_user_ip(),
            ]);

            return $this->helper_controller->send_response('success', 'Upload rejected successfully', 200, $log->fresh());
        }

        // ── APPROVAL — re-validate + batch insert ─────────────────
        try {
            [$rows, $parseError] = $this->_parseFile($log->file_path);

            if ($parseError) {
                $log->update([
                    'status'         => 'failed',
                    'actioned_by'    => auth()->id(),
                    'action_remarks' => $parseError,
                    'actioned_at'    => now(),
                    'action_ip'      => $this->helper_controller->get_user_ip(),
                    'error_summary'  => json_encode([['row' => '-', 'field' => 'file', 'message' => $parseError]]),
                ]);

                return $this->helper_controller->send_response('failed', $parseError, 422);
            }

            if ($rows->isEmpty()) {
                $log->update([
                    'status'         => 'failed',
                    'actioned_by'    => auth()->id(),
                    'action_remarks' => 'File contains no data rows',
                    'actioned_at'    => now(),
                    'action_ip'      => $this->helper_controller->get_user_ip(),
                    'total_rows'     => 0,
                    'failed_rows'    => 0,
                    'success_rows'   => 0,
                ]);

                return $this->helper_controller->send_response('failed', 'File contains no data rows', 422);
            }

            // Re-validate — catches races like another batch inserting same borrower between upload and approve
            $result = $this->uploadService->validateRows($rows);

            if (!empty($result['errors'])) {
                $log->update([
                    'status'         => 'failed',
                    'actioned_by'    => auth()->id(),
                    'action_remarks' => $request->remarks,
                    'actioned_at'    => now(),
                    'action_ip'      => $this->helper_controller->get_user_ip(),
                    'total_rows'     => $rows->count(),
                    'failed_rows'    => count($result['errors']),
                    'success_rows'   => count($result['valid']),
                    'error_summary'  => json_encode($result['errors']),
                ]);

                return $this->helper_controller->send_response('failed', 'Validation failed on approval. Please review errors and re-upload a corrected file.', 422, [
                    'total_rows'  => $rows->count(),
                    'error_count' => count($result['errors']),
                    'errors'      => $result['errors'],
                ]);
            }

            // Batch insert
            $this->uploadService->batchInsert(
                $result['valid'],
                auth()->id(),
                $this->helper_controller->get_user_ip()
            );

            $log->update([
                'status'         => 'approved',
                'actioned_by'    => auth()->id(),
                'action_remarks' => $request->remarks,
                'actioned_at'    => now(),
                'action_ip'      => $this->helper_controller->get_user_ip(),
                'total_rows'     => $rows->count(),
                'success_rows'   => count($result['valid']),
                'failed_rows'    => 0,
                'error_summary'  => null,
            ]);

            return $this->helper_controller->send_response('success', 'File approved and data inserted successfully', 200, [
                'total_rows'    => $rows->count(),
                'inserted_rows' => count($result['valid']),
            ]);
        } catch (Exception $e) {
            $log->update([
                'status'         => 'failed',
                'actioned_by'    => auth()->id(),
                'actioned_at'    => now(),
                'action_ip'      => $this->helper_controller->get_user_ip(),
                'action_remarks' => 'Processing exception: ' . $e->getMessage(),
                'error_summary'  => json_encode([['row' => '-', 'field' => '-', 'message' => $e->getMessage()]]),
            ]);

            return $this->helper_controller->send_response('failed', 'Processing failed: ' . $e->getMessage(), 422);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  5. GET UPLOAD LOG DETAIL (with errors)
    //     GET /subvention/excel/logs/{id}
    // ══════════════════════════════════════════════════════════════

    public function uploadLogDetail(int $id)
    {
        $log = SubventionUploadLog::with(['uploadedBy:id,name,email', 'actionedBy:id,name,email'])->find($id);

        if (!$log) {
            return $this->helper_controller->send_response('failed', 'Upload log not found', 422);
        }

        $formatDate = fn($date) => $date ? \Carbon\Carbon::parse($date)->format('d-m-Y H:i:s') : null;

        $data = [
            'id'               => $log->id,
            'file_path'        => $log->file_path ? url(Storage::url($log->file_path)) : null,
            'original_name'    => $log->original_name,
            'uploaded_by'      => $log->uploadedBy,
            'uploaded_remarks' => $log->uploaded_remarks,
            'ip'               => $log->ip,
            'status'           => $log->status,
            'actioned_by'      => $log->actionedBy,
            'action_remarks'   => $log->action_remarks,
            'actioned_at'      => $formatDate($log->actioned_at),
            'action_ip'        => $log->action_ip,
            'total_rows'       => $log->total_rows,
            'success_rows'     => $log->success_rows,
            'failed_rows'      => $log->failed_rows,
            'errors'           => $log->error_summary ? json_decode($log->error_summary, true) : [],
            'created_at'       => $formatDate($log->created_at),
            'updated_at'       => $formatDate($log->updated_at),
        ];

        return $this->helper_controller->send_response('success', 'Upload log details retrieved', 200, $data);
    }

    // ══════════════════════════════════════════════════════════════
    //  PRIVATE: parse file from storage, handle empty-row edge cases
    //  Returns [$rows Collection, $errorString|null]
    // ══════════════════════════════════════════════════════════════

    private function _parseFile(string $storedPath): array
    {
        try {
            $rows = $this->uploadService->parseFile($storedPath);

            if ($rows->isEmpty()) {
                return [collect(), 'The uploaded file contains no data rows.'];
            }

            return [$rows, null];
        } catch (Exception $e) {
            return [collect(), 'Could not read the file: ' . $e->getMessage()];
        }
    }
}