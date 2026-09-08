<?php

namespace App\Http\Controllers;

use App\Models\DisbursementDetails;
use App\Models\DisbursementDetailsExcel;
use App\Models\Lenders;
use App\Models\LoanBasicDetails;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class DisbursementDetailsController extends Controller
{
    protected $helper_controller;
    public function __construct(HelperController $helper_controller) {
        $this->helper_controller = $helper_controller;
    }

    public function borrower_screener() {
        
        // $loan_basic_details = LoanBasicDetails::select(['id', 'borrower_id'])
        //                         ->whereIn('status', ["NEW", "DISBURSED"])
        //                         ->whereRaw(
        //                             "loan_application_steps::jsonb @> '[{\"step\": 27, \"status\": \"COMPLETED\"}]'::jsonb"
        //                         )
        //                         ->orderBy('id', 'asc')
        //                         ->get();
        $loan_basic_details = LoanBasicDetails::select([
                            'loan_basic_details.id',
                            DB::raw("CONCAT(loan_basic_details.borrower_id, ' (', borrower_kycs.name, ')') as borrower_id")
                        ])
                        ->join('borrower_kycs', 'borrower_kycs.borrower_id', '=', 'loan_basic_details.id')
                        ->whereIn('loan_basic_details.status', ['NEW', 'DISBURSED'])
                        ->whereRaw(
                            "loan_application_steps::jsonb @> '[{\"step\": 27, \"status\": \"COMPLETED\"}]'::jsonb"
                        )
                        ->orderBy('loan_basic_details.id', 'asc')
                        ->get();
        return $this->helper_controller->send_response('success', "Borrower list fetched", 200, $loan_basic_details);
    }

    public function index(Request $request)
    {
        // Validate the inputs
        $validator = Validator::make($request->all(), [
            'utr_number' => 'nullable|string|regex:/^[a-zA-Z0-9]+$/|max:50',
            'borrower_id' => 'nullable|string',
            'bank_name' => 'nullable|string|regex:/^[a-zA-Z\s]+$/',
            'disbursement_amount' => 'nullable|numeric',
            'status' => 'nullable|in:Active,Inactive',
            'start_date' => 'nullable|date|before_or_equal:end_date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'transaction_id' => 'nullable|string|regex:/^[a-zA-Z0-9]+$/|max:50',
            'loan_id' => 'nullable|string|regex:/^[a-zA-Z0-9]+$/',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('error', $validator->errors()->first(), 422);
        }

        // Get pagination inputs with default values
        $page = $request->input('page', 1); // Default to page 1 if not provided
        $limit = $request->input('limit', 10); // Default to 10 records per page if not provided

        $query = DisbursementDetails::select(
                    [
                        'disbursement_details.*',
                        'loan_basic_details.borrower_id as borrower_unq',
                        'lenders.name as lender_name'
                    ])
                ->join('loan_basic_details', 'disbursement_details.borrower_id', '=', 'loan_basic_details.id')
                ->leftJoin('lenders', DB::raw('CAST(disbursement_details.lender_id AS INTEGER)'), '=', 'lenders.id')
                ->where('disbursement_details.status', 'active');

        // Apply filters only if inputs are filled
        if ($request->filled('utr_number')) {
            $query->whereRaw('LOWER(utr_number) LIKE ?', ['%' . strtolower($request->utr_number) . '%']);
        }

        if ($request->filled('transaction_id')) {
            $query->whereRaw('LOWER(transaction_id) LIKE ?', ['%' . strtolower($request->transaction_id) . '%']);
        }

        if ($request->filled('loan_id')) {
            $query->whereRaw('LOWER(loan_id) LIKE ?', ['%' . strtolower($request->loan_id) . '%']);
        }

        // if ($request->filled('borrower_id')) {
        //     $query->where('disbursement_details.borrower_id', $request->borrower_id);
        // }
        if ($request->filled('borrower_id')) {
            $borrowerId = $request->borrower_id;
            if (is_numeric($borrowerId)) {
                $query->where('disbursement_details.borrower_id', $borrowerId)
                    ->orWhereRaw('LOWER(loan_basic_details.borrower_id) LIKE ?', ['%' . $borrowerId . '%']);
            } else {
                $borrowerId = strtolower($request->borrower_id);
                $query->WhereRaw('LOWER(loan_basic_details.borrower_id) LIKE ?', ['%' . $borrowerId . '%']);

            }
        }

        if ($request->filled('disbursement_amount')) {
            $query->where('disbursement_amount', $request->disbursement_amount);
        }
        if ($request->filled('bank_name')) {
            $query->where('bank_name', $request->bank_name);
        }

        if ($request->filled('status')) {
            $query->whereRaw('LOWER(disbursement_details.status) LIKE ?',  ['%' . strtolower($request->status) . '%']);
        }

        // Filter by start and end date
        // if ($request->filled('start_date') && $request->filled('end_date')) {
        //     $query->whereBetween('disbursement_date', [$request->start_date, $request->end_date]);
        // }

        if ($request->filled('start_date')) {
            $query->whereDate('disbursement_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('disbursement_date', '<=', $request->end_date);
        }

        // Apply pagination
        $disbursements = $query->orderBy('disbursement_details.id', 'desc')->paginate($limit, ['*'], 'page', $page);

        return $this->helper_controller->send_response('success', 'Disbursements retrieved', 200, $disbursements);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'utr_number' => 'required|string|regex:/^[a-zA-Z0-9]+$/|max:50',
            'disbursement_amount' => 'required|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'disbursement_date' => [
                'required',
                'date',
                'after_or_equal:' . now('Asia/Kolkata')->subMonths(2)->format('Y-m-d'),  // Ensures the date is within the last 2 months
                'before_or_equal:today',  // Ensures the date is not in the future
            ],
            'borrower_id' => 'required|integer|unique:disbursement_details,borrower_id',
            'loan_id' => 'required|string|regex:/^[a-zA-Z0-9]+$/|unique:disbursement_details,loan_id',
            'lender_id' => 'required|integer',
            'transaction_id' => 'required|string|regex:/^[a-zA-Z0-9]+$/|max:50|unique:disbursement_details,transaction_id',
            'bank_name' => 'required|string|regex:/^[a-zA-Z\s]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('error', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();
        try {
            $disbursement = new DisbursementDetails();
            $disbursement->fill([
                'utr_number' => $request->utr_number,
                'disbursement_amount' => $request->disbursement_amount,
                'disbursement_date' => $request->disbursement_date,
                'borrower_id' => $request->borrower_id,
                'modified_by' => auth()->id(),
                'status' => 'active',
                'ip' => $request->ip(),
                'loan_id' => $request->loan_id,
                'lender_id' => $request->lender_id,
                'transaction_id' => $request->transaction_id,
                'bank_name' => $request->bank_name,
            ]);
            $disbursement->save();

            $loan_basic_details = LoanBasicDetails::find(trim($request->borrower_id));
            if ($loan_basic_details) {
                $loan_basic_details->status = 'DISBURSED';
                $loan_basic_details->loan_id = $request->loan_id;
                $loan_basic_details->save();
            }

            DB::commit();
            return $this->helper_controller->send_response('success', 'Disbursement added successfully', 200, $disbursement);
        } catch (Exception $e) {
            DB::rollBack();
            // Log::error('Disbursement store failed: ' . $e->getMessage());
            return $this->helper_controller->send_response('error', 'Failed to add disbursement: ' . $e->getMessage(), 422);
        }
    }


    public function show($id)
    {
        $disbursement = DisbursementDetails::find($id);

        if (!$disbursement) {
            return $this->helper_controller->send_response('error', 'Disbursement not found', 422);
        }

        return $this->helper_controller->send_response('success', 'Disbursement retrieved', 200, $disbursement);
    }


    public function update(Request $request, $id)
    {
        $disbursement = DisbursementDetails::find($id);

        if (!$disbursement) {
            return $this->helper_controller->send_response('error', 'Disbursement not found', 422);
        }

        $validator = Validator::make($request->all(), [
            'utr_number' => 'string|required|regex:/^[a-zA-Z0-9]+$/|max:50',
            'disbursement_amount' => 'required|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'disbursement_date' => [
                'required',
                'date',
                'after_or_equal:' . now('Asia/Kolkata')->subMonths(2)->format('Y-m-d'),  // Ensures the date is within the last 2 months
                'before_or_equal:today',  // Ensures the date is not in the future
            ],
            'borrower_id' => 'required|integer|unique:disbursement_details,borrower_id,'. $id,
            'loan_id' => 'required|string|regex:/^[a-zA-Z0-9]+$/|unique:disbursement_details,loan_id,'. $id,
            'lender_id' => 'required|integer',
            'transaction_id' => 'required|string|regex:/^[a-zA-Z0-9]+$/|max:50|unique:disbursement_details,transaction_id,'. $id,
            'bank_name' => 'required|string|regex:/^[a-zA-Z\s]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('error', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();
        try {
            $disbursement->utr_number           = $request->utr_number ?? $disbursement->utr_number;
            $disbursement->disbursement_amount  = $request->disbursement_amount ?? $disbursement->disbursement_amount;
            $disbursement->disbursement_date    = $request->disbursement_date ?? $disbursement->disbursement_date;
            $disbursement->borrower_id          = $request->borrower_id ?? $disbursement->borrower_id;
            $disbursement->modified_by          = auth()->id();
            $disbursement->loan_id              = $request->loan_id ?? $disbursement->loan_id;
            $disbursement->lender_id            = $request->lender_id ?? $disbursement->lender_id;
            $disbursement->transaction_id       = $request->transaction_id ?? $disbursement->transaction_id;
            $disbursement->bank_name            = $request->bank_name ?? $disbursement->bank_name;
            $disbursement->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Disbursement updated successfully', 200, $disbursement);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('error', 'Failed to update disbursement', 422);
        }
    }


    public function destroy($id)
    {
        $disbursement = DisbursementDetails::find($id);

        if (!$disbursement) {
            return $this->helper_controller->send_response('error', 'Disbursement not found', 422);
        }

        DB::beginTransaction();
        try {
            $disbursement->status = 'deleted';
            $disbursement->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Disbursement deleted successfully', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('error', 'Failed to delete disbursement', 422);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $disbursement = DisbursementDetails::find($id);

        if (!$disbursement) {
            return $this->helper_controller->send_response('error', 'Disbursement not found', 422);
        }

        DB::beginTransaction();
        try {
            $disbursement->status = $disbursement->status === 'active' ? 'inactive' : 'active';
            $disbursement->save();
            $disbursement->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Disbursement status updated', 200, $disbursement);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('error', 'Failed to update status', 422);
        }
    }

    // Method to upload and validate Excel file
    public function upload_excel_and_validate(Request $request)
    {
        date_default_timezone_set('Asia/Kolkata');
        try {
            // Validate the incoming request
            $request->validate([
                'file' => 'required|mimes:xlsx'
            ]);
    
            // Store the file
            $file = $request->file('file');
            $originalFileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $timestamp = date('Y_m_d_H_i_s');
            $fileName = "{$originalFileName}_{$timestamp}.{$file->getClientOriginalExtension()}";
            $path = $file->storeAs('Disbursement/excel', $fileName, 'public');
            
            
            // Load and validate the Excel file
            $rows = Excel::toCollection(null, $file)[0];
            $validationResult = $this->validateExcelRows($rows);
    
            // Check if validation errors exist
            if (!empty($validationResult['errors'])) {
                // If validation errors exist, store the upload record with status 'REJECTED'
                $uploadRecord = DisbursementDetailsExcel::create([
                    'file_name' => $fileName,
                    'status' => 'REJECTED',
                    'uploaded_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                    'errors' => "Validation errors",
                    'reason' => 'Validation errors occurred',
                    'file_path' => 'storage/' . $path
                ]);
    
                return $this->helper_controller->send_response('failed', 'Validation errors', 422, ['errors' => $validationResult['errors'], 'record' => $uploadRecord]);
            }
    
            // Create a record with status 'PENDING' if no validation errors
            $uploadRecord = DisbursementDetailsExcel::create([
                'file_name' => $fileName,
                'status' => 'PENDING',
                'uploaded_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'errors' => null,
                'reason' => null,
                'file_path' => 'storage/' . $path
            ]);
    
            return $this->helper_controller->send_response('success', 'File uploaded successfully', 200, ['record' => $uploadRecord]);
    
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Unable to process the file', 422, ['message' => $e->getMessage()]);
        }
    }

    private function validateExcelRows($rows)
    {
        $errors = [];
        $validRows = [];

        $headers = [
            'borrower_id', 'utr_number', 'disbursement_amount', 'disbursement_date', 'loan_id', 'lender_id', 'transaction_id', 'bank_name'
        ];
        $headerCount = count($headers);
    
        // Skip the header row
        $rows = $rows->skip(1);
    
        $maxEmptyRows = 3; // Define a threshold for maximum consecutive empty rows
        $emptyRowCount = 0;
    
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1; // Adjust row number to account for the header row
    
            // Convert $row from Collection to Array
            $rowArray = $row->toArray();
    
            // Trim or pad the row to match header count
            $rowArray = array_slice($rowArray, 0, $headerCount);
            $rowArray = array_pad($rowArray, $headerCount, null);
    
            // Remove empty rows
            if (empty(array_filter($rowArray))) {
                $emptyRowCount++;
                if ($emptyRowCount > $maxEmptyRows) {
                    // Exit early if consecutive empty rows exceed the threshold
                    $errors[] = "Processing stopped after encountering {$emptyRowCount} consecutive empty rows.";
                    break; // Exit loop early
                }
                continue; // Skip to the next row
            } else {
                // Reset the empty row counter if a valid row is found
                $emptyRowCount = 0;
            }
    
            // Map row data to headers
            $mappedRow = array_combine($headers, $rowArray);
    
            if ($mappedRow === false) {
                $errors[] = "Row {$rowNumber}: Could not map row to headers.";
                continue;
            }
    
            // Validate based on the mapped data
            $borrower_id            = $mappedRow['borrower_id'];
            $utr_number             = $mappedRow['utr_number'];
            $disbursement_amount    = $mappedRow['disbursement_amount'];
            $disbursement_date      = date('Y-m-d', strtotime($mappedRow['disbursement_date']));
            $loan_id                = $mappedRow['loan_id'];
            $lender_id              = $mappedRow['lender_id'];
            $transaction_id         = $mappedRow['transaction_id'];
            $bank_name              = $mappedRow['bank_name'];
            
            // Validate dealer_id
            if (empty($borrower_id)) {
                $errors[] = "Row {$rowNumber}: Borrower ID is required";
            } else {
                // Validate dealer existence
                $borrower = LoanBasicDetails::where('borrower_id', $borrower_id)->where('status', 'NEW')->first();

                if (!$borrower) {
                    $errors[] = "Row {$rowNumber}: Borrower with id '{$borrower_id}' not found";
                } else {
                    $steps_completed = LoanBasicDetails::where('borrower_id', $borrower_id)
                        ->where('status', 'NEW')
                        ->whereRaw("loan_application_steps::jsonb @> '[{\"step\": 27, \"status\": \"COMPLETED\"}]'::jsonb")
                        ->first();

                    if (!$steps_completed) {
                        $errors[] = "Row {$rowNumber}: Borrower with id '{$borrower_id}' is not eligible for disbursement";
                    }
                }

            }
    
            // Validate required string fields
            if ($utr_number === null || $utr_number === '') {
                $errors[] = "Row {$rowNumber}: " . "UTR number is required";
            }
            
            if ($transaction_id === null || $transaction_id === '') {
                $errors[] = "Row {$rowNumber}: " . "Transaction id is required";
            } else {
                $transaction_id_exist = DisbursementDetails::whereRaw('LOWER(transaction_id) LIKE ?', ['%' . strtolower($transaction_id) . '%'])->exists();
                if($transaction_id_exist) {
                    $errors[] = "Row {$rowNumber}: " . "Transaction id is already exist";
                }
            }
            
            if ($disbursement_amount === null || $disbursement_amount === '') {
                $errors[] = "Row {$rowNumber}: " . "UTR number is required";
            } elseif (!is_numeric($disbursement_amount)) {
                $errors[] = "Row {$rowNumber}: " . "UTR number is must be numeric";
            }
            
            // if ($disbursement_date === '') {
            //     $errors[] = "Row {$rowNumber}: " . "Invalid disbursement date";
            // }

            // Set the timezone to Asia/Kolkata
            date_default_timezone_set('Asia/Kolkata');

            // Get current date and date 2 months ago
            $twoMonthsAgo = strtotime('-2 months');
            $currentDate = time(); // Current date in Unix timestamp

            // Validate the disbursement date format and value
            if ($disbursement_date === '') {
                $errors[] = "Row {$rowNumber}: Invalid disbursement date";
            } else {
                // Replace 'd/m/Y' with the format that matches your Excel data (e.g., 'Y-m-d')
                if($disbursement_date == '1970-01-01') {
                    $excelDate = $mappedRow['disbursement_date']; // Example Excel date
                    $disbursement_date = date('Y-m-d', ($excelDate - 25569) * 86400);
                }

                $dateTimeObj = DateTime::createFromFormat('Y-m-d', $disbursement_date);

                if (!$dateTimeObj) {
                    $errors[] = "Row {$rowNumber}: Invalid disbursement date format";
                } else {
                    $disbursementTimestamp = $dateTimeObj->getTimestamp();

                    // Check if the disbursement date is within the last 2 months
                    if ($disbursementTimestamp < $twoMonthsAgo || $disbursementTimestamp > $currentDate) {
                        $errors[] = "Row {$rowNumber} {$disbursement_date}: Disbursement date must be within the last 2 months from today";
                    }
                }
            }
            
            if ($loan_id === null || $loan_id === '') {
                $errors[] = "Row {$rowNumber}: " . "Loan id is required";
            } else {
                $loan_id_exist = DisbursementDetails::whereRaw('LOWER(loan_id) LIKE ?', ['%' . strtolower($loan_id) . '%'])->exists();
                if($loan_id_exist) {
                    $errors[] = "Row {$rowNumber}: " . "Loan id is already exist";
                }
            }
            if ($bank_name === null || $bank_name === '') {
                $errors[] = "Row {$rowNumber}: " . "Bank name is required";
            }
            
            if ($lender_id === null || $lender_id === '') {
                $errors[] = "Row {$rowNumber}: " . "Lender id is required";
            } else {
                $lender_exist = Lenders::where('lender_unq', $lender_id)->exists();
                if(!$lender_exist) {
                    $errors[] = "Row {$rowNumber}: " . "Invalid lender id";
                }
            }
            
            // Add valid row to the validRows array if no errors
            if (empty($errors)) {
                $validRows[] = $mappedRow;
            }
        }
    
        // If errors exist and valid rows are empty, notify of the issues
        if (!empty($errors) || empty($validRows)) {
            $errors[] = "No valid rows found.";
        }
    
        return [
            'errors' => $errors,
            'validRows' => $validRows
        ];
    }

    // Method to approve and process the file
    public function approve_excel_and_process(Request $request, $id) {
        try {
            DB::beginTransaction();
            // Validate request input
            $request->validate([
                'remarks' => 'nullable|string'
            ]);

            // Find the upload record
            $uploadRecord = DisbursementDetailsExcel::find($id);

            if (!$uploadRecord) {
                return $this->helper_controller->send_response('failed', 'File record not found', 422);
            }

            if ($uploadRecord->status === 'REJECTED') {
                return $this->helper_controller->send_response('failed', 'File already rejected', 422);
            }

            if ($uploadRecord->status === 'APPROVED') {
                return $this->helper_controller->send_response('failed', 'File already approved', 422);
            }

            $file_path = 'public/Disbursement/excel/' . $uploadRecord->file_name;
            if (!Storage::exists($file_path)) {
                return $this->helper_controller->send_response('failed', 'File not found', 422);
            }

            // Load the Excel file from storage
            $rows = Excel::toCollection(null, Storage::path($file_path))[0];
            $validationResult = $this->validateExcelRows($rows);

            // Check for validation errors
            if (!empty($validationResult['errors'])) {
                $uploadRecord->update([
                    'status' => 'REJECTED',
                    'reason' => $request->input('remarks', 'Validation errors occurred'),
                    'errors' => "Validation errors",
                    'updated_by' => auth()->id()
                ]);

                return $this->helper_controller->send_response('failed', 'Validation errors', 422, $validationResult['errors']);
            }

            // Process valid rows
            $validRows = $validationResult['validRows'];
            foreach ($validRows as $row) {
                $loan_basic_details = LoanBasicDetails::where('borrower_id', $row['borrower_id'])->first();
                $borrower_id = null;
                if($loan_basic_details) {
                    $borrower_id = $loan_basic_details->id;
                }
                $lender_details = Lenders::where('lender_unq', $row['lender_id'])->first();
                if($lender_details) {
                    $lender_id = $lender_details->id;
                }

                $disbursement_date = date('Y-m-d', strtotime($row['disbursement_date']));

                if($disbursement_date == '1970-01-01') {
                    $excelDate = $row['disbursement_date']; // Example Excel date
                    $disbursement_date = date('Y-m-d', ($excelDate - 25569) * 86400);
                } else {
                    $disbursement_date = date('Y-m-d', strtotime($row['disbursement_date']));
                }

                $disbursement                       = new DisbursementDetails();
                $disbursement->utr_number           = $row['utr_number'];
                $disbursement->disbursement_amount  = $row['disbursement_amount'];
                $disbursement->disbursement_date    = $disbursement_date;
                $disbursement->borrower_id          = $borrower_id;
                $disbursement->modified_by          = auth()->id();
                $disbursement->status               = 'active';
                $disbursement->ip                   = $request->ip();
                $disbursement->loan_id              = $row['loan_id'];
                $disbursement->lender_id            = $lender_id ?? null;
                $disbursement->transaction_id       = $row['transaction_id'];
                $disbursement->bank_name            = $row['bank_name'];
                $disbursement->save();  
                
                $loan_basic_details->status = 'DISBURSED';
                $loan_basic_details->loan_id = $row['loan_id'];
                $loan_basic_details->save();
            }

            // Update upload record status to APPROVED
            $uploadRecord->status = 'APPROVED';
            $uploadRecord->reason = $request->input('remarks', 'Approved');
            $uploadRecord->updated_by = auth()->id();
            $uploadRecord->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Products imported successfully', 200);
        } catch (Exception $e) {
            // Handle exceptions
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Unable to process the file', 422, ['message' => $e->getMessage()]);
        }
    }

    public function get_uploaded_files(Request $request)
    {
        try {
            $query = DisbursementDetailsExcel::query();

            // Optional date filter
            if ($request->filled('start_date') && $request->filled('end_date')) {
                $start_date = Carbon::parse($request->input('start_date', 'Asia/Kolkata'))->startOfDay();
                $end_date = Carbon::parse($request->input('end_date', 'Asia/Kolkata'))->endOfDay();
                $query->whereBetween('created_at', [$start_date, $end_date]);
            }

            // Pagination parameters
            $page = $request->input('page', 1);
            $records_per_page = $request->input('records_per_page', 10);

            // Join with admin_user table to fetch names
            $files = $query->leftJoin('admin_users as uploader', 'disbursement_details_excels.uploaded_by', '=', 'uploader.id')
                        ->leftJoin('admin_users as updater', 'disbursement_details_excels.updated_by', '=', 'updater.id')
                        ->select('disbursement_details_excels.*', 
                                    'uploader.name as uploaded_by_name', 
                                    'updater.name as updated_by_name')
                        ->orderBy('disbursement_details_excels.created_at', 'desc')->paginate($records_per_page, ['*'], 'page', $page);
            $files->getCollection()->transform(function ($file) {
                // Assuming file_path is the path stored in the database like 'storage/folder/subfolder'
                $file->file_path = url($file->file_path); // Or use asset($file->file_path)
                return $file;
            });
            
            return $this->helper_controller->send_response('success', 'Files retrieved successfully', 200, $files);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Unable to retrieve files', 422, ['message' => $e->getMessage()]);
        }
    }

    public function reject_product_excel(Request $request, $id)
    {
        try {
            // Validate the incoming request
            $request->validate([
                'remarks' => 'required|string|max:255'
            ]);

            // Find the file record by ID
            $uploadRecord = DisbursementDetailsExcel::find($id);

            // Check if the file record exists
            if (!$uploadRecord) {
                return $this->helper_controller->send_response('failed', 'File record not found', 422);
            }

            // Check if the file is already rejected
            if ($uploadRecord->status === 'REJECTED') {
                return $this->helper_controller->send_response('failed', 'File already rejected', 422);
            }

            if ($uploadRecord->status === 'APPROVED') {
                return $this->helper_controller->send_response('failed', 'File already approved', 422);
            }

            // Update the file record to 'REJECTED' status
            $uploadRecord->update([
                'status' => 'REJECTED',
                'reason' => $request->input('remarks', 'Rejected'),
                'updated_by' => auth()->id()
            ]);

            // Optionally, delete the file from storage
            // $file_path = 'public/uploads/excel/' . $uploadRecord->file_name;
            // if (Storage::exists($file_path)) {
            //     Storage::delete($file_path);
            // }

            return $this->helper_controller->send_response('success', 'File rejected successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Unable to reject the file', 422, ['message' => $e->getMessage()]);
        }
    }

    public function get_disbursement_excel_template()
    {
        try {
            $fileName = 'disbursement-excel.xlsx';
            // Define the path to the template directory
            $filePath = public_path("Assets/templates/{$fileName}");

            // Check if the file exists and is not a directory
            if (!file_exists($filePath) || !is_file($filePath)) {
                // Log::warning("Template file not found: {$fileName}");
                
                return $this->helper_controller->send_response('failed', "File not found", 404);
            }

            // Generate a secure download link
            $downloadLink = asset("Assets/templates/{$fileName}");

            return $this->helper_controller->send_response('success', "Template url retrieved", 200, ['download_link' => $downloadLink]);

        } catch (Exception $e) {
            // Log the exception for debugging purposes
            // Log::error("An error occurred while generating the download link: " . $e->getMessage());

            return $this->helper_controller->send_response('failed', "An unexpected error occurred. Please try again later.", 422);
        }
    }
}
