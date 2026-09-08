<?php

namespace App\Http\Controllers;

use App\Models\BorrowerKyc;
use App\Models\BorrowerRepaymentSchedule;
use App\Models\EmiTransactions;
use App\Models\Lenders;
use App\Models\LoanBasicDetails;
use App\Models\OfflineTransactionExcel;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class OfflineTransactionController extends Controller
{
    protected $helper_controller;
    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }
    // Method to upload and validate Excel file
    public function upload_excel_and_validate(Request $request)
    {
        date_default_timezone_set('Asia/Kolkata');
        try {
            // Validate the incoming request
            $validator = Validator::make($request->all(), [
                'file' => 'required|mimes:xlsx'
            ]);
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
    
            // Store the file
            $file = $request->file('file');
            $originalFileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $timestamp = date('Y_m_d_H_i_s');
            $fileName = "{$originalFileName}_{$timestamp}.{$file->getClientOriginalExtension()}";
            $path = $file->storeAs('Offline_Transactions/excel', $fileName, 'public');
            
            
            // Load and validate the Excel file
            $rows = Excel::toCollection(null, $file)[0];
            $validationResult = $this->validateExcelRows($rows);
    
            // Check if validation errors exist
            if (!empty($validationResult['errors'])) {
                // If validation errors exist, store the upload record with status 'REJECTED'
                $uploadRecord = OfflineTransactionExcel::create([
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
            $uploadRecord = OfflineTransactionExcel::create([
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

    // private function validateExcelRows($rows)
    // {
    //     $errors = [];
    //     $validRows = [];

    //     $headers = [
    //         'borrower_id', 'loan_id', 'installment_no', 'payment_amount', 'payment_mode',
    //         'payment_date', 'utr_number', 'cheque_no', 'transaction_id', 'payment_status', 'remarks'
    //     ];
    //     $headerCount = count($headers);
    
    //     // Skip the header row
    //     $rows = $rows->skip(1);
    
    //     $maxEmptyRows = 3; // Define a threshold for maximum consecutive empty rows
    //     $emptyRowCount = 0;
    
    //     foreach ($rows as $index => $row) {
    //         $rowNumber = $index + 1; // Adjust row number to account for the header row
    
    //         // Convert $row from Collection to Array
    //         $rowArray = $row->toArray();
    
    //         // Trim or pad the row to match header count
    //         $rowArray = array_slice($rowArray, 0, $headerCount);
    //         $rowArray = array_pad($rowArray, $headerCount, null);
    
    //         // Remove empty rows
    //         if (empty(array_filter($rowArray))) {
    //             $emptyRowCount++;
    //             if ($emptyRowCount > $maxEmptyRows) {
    //                 // Exit early if consecutive empty rows exceed the threshold
    //                 $errors[] = "Processing stopped after encountering {$emptyRowCount} consecutive empty rows.";
    //                 break; // Exit loop early
    //             }
    //             continue; // Skip to the next row
    //         } else {
    //             // Reset the empty row counter if a valid row is found
    //             $emptyRowCount = 0;
    //         }
    
    //         // Map row data to headers
    //         $mappedRow = array_combine($headers, $rowArray);
    
    //         if ($mappedRow === false) {
    //             $errors[] = "Row {$rowNumber}: Could not map row to headers.";
    //             continue;
    //         }
    
    //         $mappedRow['payment_date'] = ltrim($mappedRow['payment_date'], "'\"");
    //         $mappedRow['utr_number'] = ltrim($mappedRow['utr_number'], "'\"");
    //         $mappedRow['cheque_no'] = ltrim($mappedRow['cheque_no'], "'\"");
    //         // Validate based on the mapped data
    //         $borrower_id    = $mappedRow['borrower_id'];
    //         $loan_id        = $mappedRow['loan_id'];
    //         $installment_no = $mappedRow['installment_no'];
    //         $payment_amount = $mappedRow['payment_amount'];
    //         $payment_mode   = $mappedRow['payment_mode'];
    //         $payment_date   = $mappedRow['payment_date'];
    //         $utr_number     = $mappedRow['utr_number'];
    //         $cheque_no      = $mappedRow['cheque_no'];
    //         $transaction_id = $mappedRow['transaction_id'];
            
    //         // Validate dealer_id
    //         if (empty($borrower_id)) {
    //             $errors[] = "Row {$rowNumber}: Borrower ID is required";
    //         } else {
    //             // Validate dealer existence
    //             $borrower = LoanBasicDetails::where('borrower_id', $borrower_id)->first();

    //             if (!$borrower) {
    //                 $errors[] = "Row {$rowNumber}: Borrower with id '{$borrower_id}' not found";
    //             }

    //             if($borrower->status != 'DISBURSED') {
    //                 $errors[] = "Row {$rowNumber}: Loan with borrower id '{$borrower_id}' is not disbursed yet";
    //             }
    //         }

    //         if($loan_id === null || $loan_id === '') {
    //             $errors[] = "Row {$rowNumber}: " . "Loan id is required";
    //         }

    //         if($installment_no === null || $installment_no === '' || !is_numeric($installment_no)) {
    //             $errors[] = "Row {$rowNumber}: " . "Instalment number is required and must be numeric";
    //         }

    //         if($payment_amount === null || $payment_amount === '' || !is_numeric($payment_amount)) {
    //             $errors[] = "Row {$rowNumber}: " . "Payment amount is required and must be numeric";
    //         }
    //         if(isset($borrower)) {
    //             $check_installment = BorrowerRepaymentSchedule::where('borrower_id', $borrower->id)
    //                 ->where('number_of_installment', $installment_no)
    //                 ->first();
    //             if (!$check_installment) {
    //                 $errors[] = "Row {$rowNumber}: " . "Instalment number is invalid";
    //             }

    //             if ($check_installment->payment_status === "PAID") {
    //                 $errors[] = "Row {$rowNumber}: " . "This instalment is already paid";
    //             }

    //             if($check_installment->emi_amount != $payment_amount) {
    //                 $errors[] = "Row {$rowNumber}: " . "Payment amount must be same as emi amount";
    //             }
    //         }


    //         if($payment_mode === null || $payment_mode === '') {
    //             $errors[] = "Row {$rowNumber}: " . "Payment mode is required";
    //         }

    //         if($payment_date === null || $payment_date === '') {
    //             $errors[] = "Row {$rowNumber}: " . "Payment date is required";
    //         } else {
    //             // Replace 'd/m/Y' with the format that matches your Excel data (e.g., 'Y-m-d')
    //             if($payment_date == '1970-01-01') {
    //                 $excelDate = $mappedRow['payment_date']; // Example Excel date
    //                 $payment_date = date('Y-m-d', ($excelDate - 25569) * 86400);
    //             }

    //             $dateTimeObj = DateTime::createFromFormat('Y-m-d', $payment_date);

    //             if (!$dateTimeObj) {
    //                 $errors[] = "Row {$rowNumber}: Invalid payment date format";
    //             }
    //         }
    
    //         // Validate required string fields
    //         if ($utr_number === '' && $cheque_no === '') {
    //             $errors[] = "Row {$rowNumber}: " . "Please provide either the UTR number or the cheque number.";
    //         }

    //         if(!empty($utr_number)) {
    //             $checkExistUtr = BorrowerRepaymentSchedule::where('reference', $utr_number)
    //                                 ->where('payment_status', 'PAID')
    //                                 ->first();
    //             if($checkExistUtr) {
    //                 $errors[] = "Row {$rowNumber}: " . "Duplicate UTR number, this UTR number is already exist.";
    //             }
    //         }

    //         if(!empty($cheque_no)) {
    //             $checkExistUtr = BorrowerRepaymentSchedule::where('reference', $cheque_no)
    //                                 ->where('payment_status', 'PAID')
    //                                 ->first();
    //             if($checkExistUtr) {
    //                 $errors[] = "Row {$rowNumber}: " . "Duplicate Cheque no, this Cheque no is already exist.";
    //             }
    //         }
            
    //         if ($transaction_id === null || $transaction_id === '') {
    //             $errors[] = "Row {$rowNumber}: " . "Transaction id is required";
    //         }
     
    //         date_default_timezone_set('Asia/Kolkata');      
            
    //         if (empty($errors)) {
    //             $validRows[] = $mappedRow;
    //         }
    //     }
    
    //     // If errors exist and valid rows are empty, notify of the issues
    //     if (!empty($errors) || empty($validRows)) {
    //         $errors[] = "No valid rows found.";
    //     }
    
    //     return [
    //         'errors' => $errors,
    //         'validRows' => $validRows
    //     ];
    // }

    private function validateExcelRows($rows)
    {
        $headers = [
            'borrower_id', 'loan_id', 'installment_no', 'payment_amount', 'payment_mode',
            'payment_date', 'utr_number', 'cheque_no', 'transaction_id', 'payment_status', 'remarks'
        ];
        $headerCount = count($headers);

        $rows = $rows->skip(1);
        $maxEmptyRows = 3;
        $emptyRowCount = 0;
        $errors = [];
        $parsed = []; // rowNumber => mappedRow

        // ---- Phase 1: parse + normalize ----
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;

            $rowArray = array_slice($row->toArray(), 0, $headerCount);
            $rowArray = array_pad($rowArray, $headerCount, null);

            $nonEmpty = array_filter($rowArray, fn($v) => $v !== null && $v !== '');
            if (empty($nonEmpty)) {
                if (++$emptyRowCount > $maxEmptyRows) {
                    $errors[] = "Processing stopped after encountering {$emptyRowCount} consecutive empty rows.";
                    break;
                }
                continue;
            }
            $emptyRowCount = 0;

            $mappedRow = array_combine($headers, $rowArray);

            foreach (['payment_date', 'utr_number', 'cheque_no'] as $k) {
                $mappedRow[$k] = ltrim((string) $mappedRow[$k], "'\"");
            }
            $mappedRow['payment_status'] = strtoupper(trim((string) $mappedRow['payment_status']));
            $mappedRow['remarks']        = trim((string) $mappedRow['remarks']);

            $parsed[$rowNumber] = $mappedRow;
        }

        // ---- Phase 2: batch preload (kills N+1) ----
        $borrowerIds = array_filter(array_column($parsed, 'borrower_id'));

        $loans = $borrowerIds
            ? LoanBasicDetails::whereIn('borrower_id', $borrowerIds)
                ->get(['id', 'borrower_id', 'status'])->keyBy('borrower_id')
            : collect();

        $loanPids = $loans->pluck('id')->all();
        $schedules = $loanPids
            ? BorrowerRepaymentSchedule::whereIn('borrower_id', $loanPids)
                ->get(['id', 'borrower_id', 'number_of_installment', 'emi_amount', 'payment_status'])
                ->keyBy(fn($s) => $s->borrower_id . '-' . $s->number_of_installment)
            : collect();

        $refs = array_filter(array_merge(
            array_column($parsed, 'utr_number'),
            array_column($parsed, 'cheque_no')
        ));
        $existingRefs = $refs
            ? BorrowerRepaymentSchedule::whereIn('reference', $refs)
                ->where('payment_status', 'PAID')->pluck('reference')->flip()
            : collect();

        // ---- Phase 3: validate ----
        $validRows = [];
        foreach ($parsed as $rowNumber => $r) {
            $rowErrors = [];

            $borrower = null;
            if ($r['borrower_id'] === '' || $r['borrower_id'] === null) {
                $rowErrors[] = "Borrower ID is required";
            } else {
                $borrower = $loans[$r['borrower_id']] ?? null;
                if (!$borrower) {
                    $rowErrors[] = "Borrower with id '{$r['borrower_id']}' not found";
                } elseif ($borrower->status !== 'DISBURSED') {
                    $rowErrors[] = "Loan with borrower id '{$r['borrower_id']}' is not disbursed yet";
                }
            }

            if ($r['loan_id'] === null || $r['loan_id'] === '') {
                $rowErrors[] = "Loan id is required";
            }

            $instNumeric = is_numeric($r['installment_no']);
            if ($r['installment_no'] === null || $r['installment_no'] === '' || !$instNumeric) {
                $rowErrors[] = "Instalment number is required and must be numeric";
            }

            $amtNumeric = is_numeric($r['payment_amount']);
            if ($r['payment_amount'] === null || $r['payment_amount'] === '' || !$amtNumeric) {
                $rowErrors[] = "Payment amount is required and must be numeric";
            }

            if ($borrower && $instNumeric) {
                $installment = $schedules->get($borrower->id . '-' . $r['installment_no']);
                if (!$installment) {
                    $rowErrors[] = "Instalment number is invalid";
                } else {
                    if ($installment->payment_status === 'PAID') {
                        $rowErrors[] = "This instalment is already paid";
                    }
                    if ($amtNumeric && (float) $installment->emi_amount != (float) $r['payment_amount']) {
                        $rowErrors[] = "Payment amount must be same as emi amount";
                    }
                }
            }

            if ($r['payment_mode'] === null || $r['payment_mode'] === '') {
                $rowErrors[] = "Payment mode is required";
            }

            if ($r['payment_date'] === null || $r['payment_date'] === '') {
                $rowErrors[] = "Payment date is required";
            } else {
                $pd = $this->normalizePaymentDate($r['payment_date']);
                if (!$pd) {
                    $rowErrors[] = "Invalid payment date format";
                } else {
                    $r['payment_date'] = $pd;
                }
            }

            // payment_status (new)
            if ($r['payment_status'] === '') {
                $rowErrors[] = "Payment status is required";
            } elseif (!in_array($r['payment_status'], ['PAID', 'UNPAID'], true)) {
                $rowErrors[] = "Payment status must be PAID or UNPAID";
            }

            // remarks (new)
            if ($r['remarks'] !== '' && !preg_match('/^[A-Za-z0-9 ,@#&\-]+$/', $r['remarks'])) {
                $rowErrors[] = "Remarks may only contain letters, numbers, spaces and , @ # & -";
            }

            if ($r['utr_number'] === '' && $r['cheque_no'] === '') {
                $rowErrors[] = "Please provide either the UTR number or the cheque number.";
            }
            if ($r['utr_number'] !== '' && $existingRefs->has($r['utr_number'])) {
                $rowErrors[] = "Duplicate UTR number, this UTR number is already exist.";
            }
            if ($r['cheque_no'] !== '' && $existingRefs->has($r['cheque_no'])) {
                $rowErrors[] = "Duplicate Cheque no, this Cheque no is already exist.";
            }

            if(isset($r['payment_status']) && $r['payment_status'] === 'PAID') {
                
                if ($r['transaction_id'] === null || $r['transaction_id'] === '') {
                    $rowErrors[] = "Transaction id is required";
                }
            }

            if ($rowErrors) {
                foreach ($rowErrors as $e) {
                    $errors[] = "Row {$rowNumber}: {$e}";
                }
            } else {
                $validRows[] = $r;
            }
        }

        if (empty($validRows)) {
            $errors[] = "No valid rows found.";
        }

        return ['errors' => $errors, 'validRows' => $validRows];
    }

    private function normalizePaymentDate($value)
    {
        if (is_numeric($value)) {
            return date('Y-m-d', (int) (((float) $value - 25569) * 86400)); // Excel serial
        }
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        return $dt ? $dt->format('Y-m-d') : null;
    }

    // Method to approve and process the file
    // public function approve_excel_and_process(Request $request, $id) {
    //     try {
    //         DB::beginTransaction();
    //         // Validate request input
    //         $validator = Validator::make($request->all(), [
    //             'remarks' => 'nullable|string'
    //         ]);
    //         if ($validator->fails()) {
    //             return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
    //         }

    //         // Find the upload record
    //         $uploadRecord = OfflineTransactionExcel::find($id);

    //         if (!$uploadRecord) {
    //             return $this->helper_controller->send_response('failed', 'File record not found', 422);
    //         }

    //         if ($uploadRecord->status === 'REJECTED') {
    //             return $this->helper_controller->send_response('failed', 'File already rejected', 422);
    //         }

    //         if ($uploadRecord->status === 'APPROVED') {
    //             return $this->helper_controller->send_response('failed', 'File already approved', 422);
    //         }

    //         $file_path = 'public/Offline_Transactions/excel/' . $uploadRecord->file_name;
    //         if (!Storage::exists($file_path)) {
    //             return $this->helper_controller->send_response('failed', 'File not found', 422);
    //         }

    //         // Load the Excel file from storage
    //         $rows = Excel::toCollection(null, Storage::path($file_path))[0];
    //         $validationResult = $this->validateExcelRows($rows);

    //         // Check for validation errors
    //         if (!empty($validationResult['errors'])) {
    //             $uploadRecord->update([
    //                 'status' => 'REJECTED',
    //                 'reason' => $request->input('remarks', 'Validation errors occurred'),
    //                 'errors' => "Validation errors",
    //                 'updated_by' => auth()->id()
    //             ]);

    //             return $this->helper_controller->send_response('failed', 'Validation errors', 422, $validationResult['errors']);
    //         }

    //         // Process valid rows
    //         $validRows = $validationResult['validRows'];
    //         foreach ($validRows as $row) {
    //             $loan_details = LoanBasicDetails::where('borrower_id', $row['borrower_id'])->first();
    //             if (!$loan_details) {
    //                 DB::rollBack();
    //                 return $this->helper_controller->send_response('failed', "Invalid borrower", 422);
    //             }
    //             $borrower_primary_id = $loan_details->id;

    //             if($loan_details->status != 'DISBURSED') {
    //                 $this->helper_controller->send_response('failed', $loan_details->borrower_id . "is not disbursed yet", 422);
    //             }

    //             $borrower_details = BorrowerKyc::where('borrower_id', $borrower_primary_id)->first();
    //             if (!$borrower_details) {
    //                 DB::rollBack();
    //                 return $this->helper_controller->send_response('failed', "Invalid borrower", 422);
    //             }


    //             $check_installment = BorrowerRepaymentSchedule::where('borrower_id', $borrower_primary_id)
    //                 ->where('number_of_installment', $row['installment_no'])
    //                 ->first();
    //             if (!$check_installment) {
    //                 DB::rollBack();
    //                 return $this->helper_controller->send_response('failed', "Invalid request", 422);
    //             }

    //             if ($check_installment->payment_status === "PAID") {
    //                 DB::rollBack();
    //                 return $this->helper_controller->send_response('failed', "This installment is already paid", 422);
    //             }

    //             // $fine_amount = $check_installment->fine_amount;
    //             // $total_payment_amount = $check_installment->emi_amount + $fine_amount;
                
    //             // store transactions
    //             // Create a new EMI transaction.
    //             $row['utr_number'] = ltrim($row['utr_number'], "'\"");
    //             $row['cheque_no'] = ltrim($row['cheque_no'], "'\"");

    //             $row['payment_date'] = ltrim($row['payment_date'], "'\"");

    //             $payment_date = date('Y-m-d', strtotime($row['payment_date']));

    //             if($payment_date == '1970-01-01') {
    //                 $excelDate = $row['payment_date']; // Example Excel date
    //                 $payment_date = date('Y-m-d', ($excelDate - 25569) * 86400);
    //             } else {
    //                 $payment_date = date('Y-m-d', strtotime($row['payment_date']));
    //             }

    //             if(empty($row['utr_number']) && empty($row['cheque_no'])) {
    //                 return $this->helper_controller->send_response('failed', "Please provide either the UTR number or the cheque number.", 422);
    //             }
    //             $ref_no = !empty($row['utr_number']) ? $row['utr_number'] : (!empty($row['cheque_no']) ? $row['cheque_no'] : '');

    //             $emi_txn                    = new EmiTransactions();
    //             $emi_txn->payment_date      = date('Y-m-d');
    //             $emi_txn->borrower_id       = $loan_details->id;
    //             $emi_txn->payment_status    = 'PAID';
    //             $emi_txn->emi_amount        = $check_installment->emi_amount;
    //             $emi_txn->fine_amount       = $fine_amount ?? 0;
    //             $emi_txn->total_payment     = $row['payment_amount'];
    //             $emi_txn->bank_ref_num      = $ref_no;
    //             $emi_txn->payment_date      = $payment_date;
    //             $emi_txn->payment_updated_at= date('Y-m-d H:i:s');
    //             $emi_txn->user_id           = auth()->id();
    //             $emi_txn->ip                = $this->helper_controller->get_user_ip();
    //             $emi_txn->payment_mode      = $row['payment_mode'];
    //             $emi_txn->loan_id           = $row['loan_id'];
    //             $emi_txn->transaction_id    = $row['transaction_id'];
    //             $emi_txn->save();

    //             // $txnnumber                  = str_pad($emi_txn->id, 3, '0', STR_PAD_LEFT);
    //             // $transactionId              = "FIN-" . date('YmdHis') . '-' . $txnnumber;
    //             // $emi_txn->transaction_id    = $transactionId;
    //             // $emi_txn->save();

    //             // Update Repayment schedule
    //             $check_installment->transaction_id  = $row['transaction_id'];
    //             $check_installment->receipt_no      = $row['transaction_id'];
    //             $check_installment->payment_status  = 'PAID';
    //             $check_installment->payment_date    = $row['payment_date'];
    //             $check_installment->payment_mode    = $row['payment_mode'];
    //             $check_installment->reference       = $ref_no;
    //             $check_installment->fine_amount     = 0;
    //             $check_installment->total_payment   = $row['payment_amount'];
    //             $check_installment->payment_date    = $row['payment_date'];
    //             $check_installment->save();

    //         }

    //         // Update upload record status to APPROVED
    //         $uploadRecord->status = 'APPROVED';
    //         $uploadRecord->reason = $request->input('remarks', 'Approved');
    //         $uploadRecord->updated_by = auth()->id();
    //         $uploadRecord->save();

    //         DB::commit();
    //         return $this->helper_controller->send_response('success', 'Products imported successfully', 200);
    //     } catch (Exception $e) {
    //         // Handle exceptions
    //         DB::rollBack();
    //         return $this->helper_controller->send_response('failed', 'Unable to process the file', 422, ['message' => $e->getMessage()]);
    //     }
    // }

    public function approve_excel_and_process(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), ['remarks' => 'nullable|string']);
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $uploadRecord = OfflineTransactionExcel::find($id);
            if (!$uploadRecord) {
                return $this->helper_controller->send_response('failed', 'File record not found', 422);
            }
            if ($uploadRecord->status === 'REJECTED') {
                return $this->helper_controller->send_response('failed', 'File already rejected', 422);
            }
            if ($uploadRecord->status === 'APPROVED') {
                return $this->helper_controller->send_response('failed', 'File already approved', 422);
            }

            $file_path = 'public/Offline_Transactions/excel/' . $uploadRecord->file_name;
            if (!Storage::exists($file_path)) {
                return $this->helper_controller->send_response('failed', 'File not found', 422);
            }

            $rows = Excel::toCollection(null, Storage::path($file_path))[0];
            $validationResult = $this->validateExcelRows($rows);

            if (!empty($validationResult['errors'])) {
                $uploadRecord->update([
                    'status'     => 'REJECTED',
                    'reason'     => $request->input('remarks', 'Validation errors occurred'),
                    'errors'     => 'Validation errors',
                    'updated_by' => auth()->id(),
                ]);
                return $this->helper_controller->send_response('failed', 'Validation errors', 422, $validationResult['errors']);
            }

            $validRows = $validationResult['validRows'];

            // Batch preload for mapping (no per-row queries)
            $borrowerIds = array_unique(array_column($validRows, 'borrower_id'));
            $loans = LoanBasicDetails::whereIn('borrower_id', $borrowerIds)
                ->get(['id', 'borrower_id', 'status'])->keyBy('borrower_id');
            $loanPids = $loans->pluck('id')->all();
            $schedules = BorrowerRepaymentSchedule::whereIn('borrower_id', $loanPids)
                ->get(['id', 'borrower_id', 'number_of_installment', 'emi_amount'])
                ->keyBy(fn($s) => $s->borrower_id . '-' . $s->number_of_installment);

            $now    = now();
            $userId = auth()->id();
            $ip     = $this->helper_controller->get_user_ip();

            $emiInserts      = [];
            $scheduleUpserts = [];

            foreach ($validRows as $row) {
                $loan = $loans[$row['borrower_id']] ?? null;
                if (!$loan) continue;

                $schedule = $schedules->get($loan->id . '-' . $row['installment_no']);
                if (!$schedule) continue;

                $ref     = $row['utr_number'] !== '' ? $row['utr_number'] : $row['cheque_no'];
                $remarks = $row['remarks'] !== '' ? $row['remarks'] : null;
                $isPaid  = $row['payment_status'] === 'PAID';

                // Full NOT NULL set included so the INSERT branch of ON CONFLICT is always valid.
                $scheduleUpserts[] = [
                    'id'                    => $schedule->id,
                    'borrower_id'           => $loan->id,
                    'number_of_installment' => $row['installment_no'],
                    'emi_amount'            => $schedule->emi_amount,
                    'transaction_id'        => $row['transaction_id'],
                    'receipt_no'            => $row['transaction_id'],
                    'payment_status'        => $row['payment_status'],
                    'payment_date'          => $row['payment_date'],
                    'payment_mode'          => $row['payment_mode'],
                    'reference'             => $ref,
                    'fine_amount'           => 0,
                    'total_payment'         => $row['payment_amount'],
                    'remarks'               => $remarks,
                    'created_at'            => $now, // only consumed on a true insert
                    'updated_at'            => $now,
                ];

                if ($isPaid) {
                    $emiInserts[] = [
                        'borrower_id'        => $loan->id,
                        'payment_status'     => 'PAID',
                        'emi_amount'         => $schedule->emi_amount,
                        'fine_amount'        => 0,
                        'total_payment'      => $row['payment_amount'],
                        'bank_ref_num'       => $ref,
                        'payment_date'       => $row['payment_date'],
                        'payment_updated_at' => $now,
                        'user_id'            => $userId,
                        'ip'                 => $ip,
                        'payment_mode'       => $row['payment_mode'],
                        'loan_id'            => $row['loan_id'],
                        'transaction_id'     => $row['transaction_id'],
                        'remarks'            => $remarks,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ];
                }
            }

            DB::beginTransaction();

            if ($emiInserts) {
                foreach (array_chunk($emiInserts, 1000) as $chunk) {
                    DB::table('emi_transactions')->insert($chunk);
                }
            }

            if ($scheduleUpserts) {
                foreach (array_chunk($scheduleUpserts, 1000) as $chunk) {
                    DB::table('borrower_repayment_schedules')->upsert(
                        $chunk,
                        ['id'], // conflict target (PK)
                        [       // columns updated on conflict — excludes borrower_id/installment/emi_amount/created_at
                            'transaction_id', 'receipt_no', 'payment_status', 'payment_date',
                            'payment_mode', 'reference', 'fine_amount', 'total_payment',
                            'remarks', 'updated_at',
                        ]
                    );
                }
            }

            $uploadRecord->update([
                'status'     => 'APPROVED',
                'reason'     => $request->input('remarks', 'Approved'),
                'updated_by' => $userId,
            ]);

            DB::commit();
            return $this->helper_controller->send_response('success', 'Offline transactions imported successfully', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Unable to process the file', 422, ['message' => $e->getMessage()]);
        }
    }

    public function get_uploaded_files(Request $request)
    {
        try {
            $query = OfflineTransactionExcel::query();

            // Optional date filter
            if ($request->filled('start_date') && $request->filled('end_date')) {
                $start_date = Carbon::parse($request->input('start_date', 'Asia/Kolkata'))->startOfDay();
                $end_date = Carbon::parse($request->input('end_date', 'Asia/Kolkata'))->endOfDay();
                $query->whereBetween('created_at', [$start_date, $end_date]);
            }

            // Pagination parameters
            $page = $request->input('page', 1);
            $records_per_page = $request->input('records_per_page', 10);

            $files = $query->leftJoin('admin_users as uploader', 'offline_transaction_excels.uploaded_by', '=', 'uploader.id')
               ->leftJoin('admin_users as updater', 'offline_transaction_excels.updated_by', '=', 'updater.id')
               ->select(
                   'offline_transaction_excels.*', 
                   'uploader.name as uploaded_by_name', 
                   'updater.name as updated_by_name'
               )
               ->orderBy('offline_transaction_excels.created_at', 'desc')
               ->paginate($records_per_page, ['*'], 'page', $page);

            $files->transform(function ($file) {
                return [
                    'id' => $file->id,
                    'file_name' => $file->file_name,
                    'status' => $file->status,
                    'reason' => $file->reason,
                    'errors' => $file->errors,
                    'file_path' => $file->file_path,
                    'created_at' => date('d-m-Y H:i:s', strtotime($file->created_at)),
                    'updated_at' => date('d-m-Y H:i:s', strtotime($file->updated_at)),
                    'uploaded_by_name' => $file->uploaded_by_name,
                    'updated_by_name' => $file->updated_by_name,
                ];
            });

            
            return $this->helper_controller->send_response('success', 'Files retrieved successfully', 200, $files);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Unable to retrieve files', 422, ['message' => $e->getMessage()]);
        }
    }

    public function reject_product_excel(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'remarks' => 'required|string|max:255'
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $uploadRecord = OfflineTransactionExcel::find($id);

            if (!$uploadRecord) {
                return $this->helper_controller->send_response('failed', 'File record not found', 422);
            }

            if ($uploadRecord->status === 'REJECTED') {
                return $this->helper_controller->send_response('failed', 'File already rejected', 422);
            }

            if ($uploadRecord->status === 'APPROVED') {
                return $this->helper_controller->send_response('failed', 'File already approved', 422);
            }

            $uploadRecord->update([
                'status' => 'REJECTED',
                'reason' => $request->input('remarks', 'Rejected'),
                'updated_by' => auth()->id()
            ]);

            return $this->helper_controller->send_response('success', 'File rejected successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Unable to reject the file', 422, ['message' => $e->getMessage()]);
        }
    }

    public function getTemplateDownloadLink()
    {
        try {
            $fileName = 'offline-transaction.xlsx';
            $filePath = public_path("Assets/templates/{$fileName}");

            if (!file_exists($filePath) || !is_file($filePath)) {                
                return $this->helper_controller->send_response('failed', "File not found", 404);
            }

            $downloadLink = asset("Assets/templates/{$fileName}");

            return $this->helper_controller->send_response('success', "Template url retrieved", 200, ['download_link' => $downloadLink]);

        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unexpected error occurred. Please try again later.", 422);
        }
    }

    public function getInstructions(Request $request)
    {
        try {
            $instructions = [
                'Loan id' => 'Loan ID refers to the loan ID specified at the time of disbursement.',
                'Installment number' => 'Installment number must be a numeric value.',
                'Payment amount' => 'Payment amount must be a numeric value and same as emi amount.',
                'Payment date' => "Payment date must start with an inverted comma and be in 'YYYY-MM-DD' format, e.g., '2025-05-12.",
                'UTR, Cheque, Transaction no' => "If UTR number, cheque number, or transaction number consists only of digits, prefix with an inverted comma, e.g., '123456789874589.",
                'Payment Status' => "Payment status can be either 'PAID' or 'UNPAID'"
            ];

            return $this->helper_controller->send_response('success', 'Loan payment instructions fetched successfully.', 200, $instructions);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An error occurred: " . $e->getMessage(), 500);
        }
    }
}
