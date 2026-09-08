<?php

namespace App\Http\Controllers;

use App\Models\BorrowerIdentifier;
use App\Models\BorrowerIdentifierMapping;
use App\Models\BorrowerIdentifierUpload;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class BorrowerIdentifierUploadController extends Controller
{
    use ApiResponse;

    public function uploadExcel(Request $request)
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | Input Validation
            |--------------------------------------------------------------------------
            */

            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:xlsx,xls|max:10240',
                'upload_remark' => [
                    'nullable',
                    'string',
                    'max:500',
                    'regex:/^[A-Za-z0-9\s\-\(\),\.]+$/'
                ],
                [
                    'upload_remark.regex' => 'Remarks may contain only letters, numbers, spaces, hyphen, brackets, comma and full stop.'
                ]
            ]);

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Read Excel BEFORE storing
            |--------------------------------------------------------------------------
            */

            $rows = Excel::toArray([], $request->file('file'));

            if (empty($rows) || empty($rows[0])) {
                return $this->send_response('failed', 'Excel file is empty', 422);
            }

            $sheet = $rows[0];

            /*
            |--------------------------------------------------------------------------
            | Header Validation
            |--------------------------------------------------------------------------
            */

            $expectedHeaders = [
                'borrower_id',
                'customer_name',
                'identifier'
            ];

            $headers = array_map('strtolower', $sheet[0]);

            if ($headers !== $expectedHeaders) {

                return $this->send_response('failed', 'Invalid excel format. Required columns: borrower_id, customer_name, identifier', 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Row Validation
            |--------------------------------------------------------------------------
            */

            $errors = [];
            $rowNumber = 1;

            /*
            |--------------------------------------------------------------------------
            | Prepare borrower list for validation
            |--------------------------------------------------------------------------
            */

            $borrowerIds = [];

            foreach (array_slice($sheet, 1) as $row) {

                if (!empty($row[0])) {
                    $borrowerIds[] = trim($row[0]);
                }
            }

            $borrowerIds = array_unique($borrowerIds);

            $existingBorrowers = DB::table('loan_basic_details')
                ->whereIn('borrower_id', $borrowerIds)
                ->pluck('borrower_id')
                ->toArray();

            $existingBorrowers = array_flip($existingBorrowers); // faster lookup


            /*
            |--------------------------------------------------------------------------
            | Row Validation
            |--------------------------------------------------------------------------
            */

            $errors = [];
            $rowNumber = 1;

            foreach (array_slice($sheet, 1) as $row) {

                $rowNumber++;

                $borrowerId = trim($row[0] ?? '');
                $identifier = trim($row[2] ?? '');

                if (empty($borrowerId)) {
                    $errors[] = "Row {$rowNumber}: borrower_id missing";
                } elseif (!isset($existingBorrowers[$borrowerId])) {
                    $errors[] = "Row {$rowNumber}: borrower id '{$borrowerId}' is not found";
                }

                if (empty($identifier)) {
                    $errors[] = "Row {$rowNumber}: identifier missing";
                }
            }

            if (!empty($errors)) {

                return $this->send_response(
                    'failed',
                    'Excel validation failed',
                    422,
                    $errors
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Store file and create upload record
            |--------------------------------------------------------------------------
            */

            DB::beginTransaction();

            // $path = $request->file('file')
            //     ->store('borrower_identifier_excels');
            $file = $request->file('file');
            $originalFileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $timestamp = date('Y_m_d_H_i_s');
            $fileName = "{$originalFileName}_{$timestamp}.{$file->getClientOriginalExtension()}";
            $path = $file->storeAs('borrower_identifier_excels', $fileName, 'public');

            $upload = BorrowerIdentifierUpload::create([
                'file_path'     => $path,
                'uploaded_by'   => auth()->id(),
                'upload_remark' => $request->upload_remark,
                'status'        => 'pending',
                'row_status'    => 'A',
                'uploaded_at'   => now()
            ]);

            DB::commit();

            return $this->send_response('success', 'Excel uploaded successfully', 200, $upload);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred " . $e->getMessage(), 422);
        }
    }

    public function getUploads(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'page' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:100',

                'start_date' => 'nullable|date|before_or_equal:today',
                'end_date'   => 'nullable|date|before_or_equal:today|after_or_equal:start_date',
            ]);

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            $page  = $request->page ?? 1;
            $limit = $request->limit ?? 10;

            $query = BorrowerIdentifierUpload::query()
                ->where('row_status', '!=', 'D')
                ->with([
                    'uploader:id,name',
                    'approver:id,name'
                ]);

            /*
        |--------------------------------------------------------------------------
        | Date Filters
        |--------------------------------------------------------------------------
        */

            if ($request->start_date) {
                $query->whereDate('uploaded_at', '>=', $request->start_date);
            }

            if ($request->end_date) {
                $query->whereDate('uploaded_at', '<=', $request->end_date);
            }

            /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

            $uploads = $query
                ->orderByDesc('id')
                ->paginate($limit, ['*'], 'page', $page);
            $uploads->getCollection()->transform(function ($item) {

                $item->uploaded_at = $item->uploaded_at
                    ? Carbon::parse($item->uploaded_at)
                    ->timezone('Asia/Kolkata')
                    ->format('d-m-Y H:i:s')
                    : null;

                $item->approved_at = $item->approved_at
                    ? Carbon::parse($item->approved_at)
                    ->timezone('Asia/Kolkata')
                    ->format('d-m-Y H:i:s')
                    : null;

                $item->file_path = $item->file_path ? url('storage/'.$item->file_path) : null;

                return $item;
            });

            return $this->send_response(
                'success',
                'Upload list fetched successfully',
                200,
                $uploads
            );
        } catch (Throwable $e) {

            return $this->send_response(
                'failed',
                'An unknown error occurred ' . $e->getMessage(),
                422
            );
        }
    }

    public function changeStatus(Request $request, $id)
    {
        try {

            $validator = Validator::make(
                $request->all(),
                [
                    'status'  => 'required|in:approved,rejected',
                    'remarks' => [
                        'required',
                        'string',
                        'max:500',
                        'regex:/^[A-Za-z0-9\s\-\(\),\.]+$/'
                    ]
                ],
                [
                    'remarks.regex' => 'Remarks may contain only letters, numbers, spaces, hyphen, brackets, comma and full stop.'
                ]
            );

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            $upload = BorrowerIdentifierUpload::where('id', $id)
                ->where('row_status', '!=', 'D')
                ->first();

            if (!$upload) {
                return $this->send_response('failed', 'Upload record not found', 422);
            }

            if ($upload->status !== 'pending') {
                return $this->send_response('failed', 'Upload already processed', 422);
            }

            DB::beginTransaction();

            /*
        |--------------------------------------------------------------------------
        | If Approved → Process Excel
        |--------------------------------------------------------------------------
        */

            if ($request->status === 'approved') {
                $filePath = str_replace('storage/', '', $upload->file_path);

                if (!Storage::disk('public')->exists($filePath)) {
                    return $this->send_response('failed', 'File not found', 422);
                }

                $rows = Excel::toCollection(
                    null,
                    Storage::disk('public')->path($filePath)
                )[0];

                $sheet = $rows;

                $mappings = [];

                foreach ($sheet->skip(1) as $row) {

                    $borrowerIdExcel = trim($row[0]);
                    $identifierName  = trim($row[2]);

                    $loan = DB::table('loan_basic_details')
                        ->where('borrower_id', $borrowerIdExcel)
                        ->first();

                    if (!$loan) {
                        continue;
                    }

                    $identifier = BorrowerIdentifier::firstOrCreate([
                        'identifier_name' => $identifierName
                    ]);

                    $mappings[] = [
                        'upload_id'     => $upload->id,
                        'borrower_id'   => $loan->id,
                        'identifier_id' => $identifier->id,
                        'row_status'    => 'A',
                        'created_at'    => now(),
                        'updated_at'    => now()
                    ];
                }

                /*
            |--------------------------------------------------------------------------
            | Bulk Insert (fast)
            |--------------------------------------------------------------------------
            */

                if (!empty($mappings)) {
                    DB::table('borrower_identifier_mappings')
                        ->insert($mappings);
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Update Upload Status
        |--------------------------------------------------------------------------
        */

            $upload->update([
                'status'               => $request->status,
                'approval_remark'      => $request->remarks,
                'approved_rejected_by' => auth()->id(),
                'approved_rejected_at' => now()
            ]);

            DB::commit();

            return $this->send_response(
                'success',
                'Status updated successfully'
            );
        } catch (Throwable $e) {

            DB::rollBack();

            return $this->send_response(
                'failed',
                'An unknown error occurred ' . $e->getMessage(),
                422
            );
        }
    }

    public function toggleRowStatus(Request $request, $id)
    {
        try {

            $validator = Validator::make(
                $request->all(),
                [
                    'remarks' => [
                        'required',
                        'string',
                        'max:500',
                        'regex:/^[A-Za-z0-9\s\-\(\),\.]+$/'
                    ]
                ],
                [
                    'remarks.regex' => 'Remarks may contain only letters, numbers, spaces, hyphen, brackets, comma and full stop.'
                ]
            );

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            $upload = BorrowerIdentifierUpload::where('id', $id)->where('row_status', '<>', 'D')->first();

            if (!$upload) {
                return $this->send_response('failed', 'Record not found', 422);
            }

            DB::beginTransaction();

            $newStatus = $upload->row_status === 'A' ? 'I' : 'A';

            $upload->update([
                'row_status' => $newStatus,
                'row_status_remark' => $request->remarks
            ]);

            /*
        |--------------------------------------------------------------------------
        | Sync mappings status
        |--------------------------------------------------------------------------
        */

            DB::table('borrower_identifier_mappings')
                ->where('upload_id', $upload->id)
                ->update([
                    'row_status' => $newStatus,
                    'updated_at' => now()
                ]);

            DB::commit();

            return $this->send_response(
                'success',
                'Row status updated successfully'
            );
        } catch (Throwable $e) {

            DB::rollBack();

            return $this->send_response(
                'failed',
                'An unknown error occurred ' . $e->getMessage(),
                422
            );
        }
    }

    public function deleteUpload(Request $request, $id)
    {
        try {

            $validator = Validator::make(
                $request->all(),
                [
                    'remarks' => [
                        'required',
                        'string',
                        'max:500',
                        'regex:/^[A-Za-z0-9\s\-\(\),\.]+$/'
                    ]
                ],
                [
                    'remarks.regex' => 'Remarks may contain only letters, numbers, spaces, hyphen, brackets, comma and full stop.'
                ]
            );

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            $upload = BorrowerIdentifierUpload::where('id', $id)->where('row_status', '<>', 'D')->first();

            if (!$upload) {
                return $this->send_response('failed', 'Record not found', 422);
            }

            DB::beginTransaction();

            $upload->update([
                'row_status' => 'D',
                'row_status_remark' => $request->remark
            ]);

            /*
        |--------------------------------------------------------------------------
        | Mark mappings deleted
        |--------------------------------------------------------------------------
        */

            DB::table('borrower_identifier_mappings')
                ->where('upload_id', $upload->id)
                ->update([
                    'row_status' => 'D',
                    'updated_at' => now()
                ]);

            DB::commit();

            return $this->send_response(
                'success',
                'Upload deleted successfully'
            );
        } catch (Throwable $e) {

            DB::rollBack();

            return $this->send_response(
                'failed',
                'An unknown error occurred ' . $e->getMessage(),
                422
            );
        }
    }

    public function download_sample_excel()
    {
        try {
            $fileName = 'identifier-sample-excel.xlsx';
            // Define the path to the template directory
            $filePath = public_path("Assets/templates/{$fileName}");

            // Check if the file exists and is not a directory
            if (!file_exists($filePath) || !is_file($filePath)) {
                
                return $this->send_response('failed', "File not found", 404);
            }

            // Generate a secure download link
            $downloadLink = asset("Assets/templates/{$fileName}");

            return $this->send_response('success', "Template url retrieved", 200, ['download_link' => $downloadLink]);

        } catch (Exception $e) {
            // Log the exception for debugging purposes

            return $this->send_response('failed', "An unexpected error occurred. Please try again later.", 422);
        }
    }
}
