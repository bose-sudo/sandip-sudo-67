<?php

namespace App\Http\Controllers;

use App\Models\BorrowerKyc;
use App\Models\BorrowerLoanScheme;
use App\Models\DisbursementRequest;
use App\Models\LoanBasicDetails;
use App\Models\ProductModel;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DisbursementController extends Controller
{
    protected $helper_controller;
    public function __construct(HelperController $helper_controller) {
        $this->helper_controller = $helper_controller;
    }

    public function raise_disbursement_request(Request $request) {
        // Validate the inputs
        $validator = Validator::make($request->all(), [
            'borrower_id' => 'required|integer|unique:disbursement_requests,borrower_id|exists:loan_basic_details,id',
            'disbursement_amount' => 'required|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'request_remarks' => 'required|string|max:255|regex:/^[a-zA-Z0-9\-\/\(\),:]+$/'
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }
        
        try {
            $loan_details = LoanBasicDetails::find($request->borrower_id);
            if(!$loan_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }
            
            if($loan_details->status !== 'NEW') {
                $status = strtolower($loan_details->status);
                return $this->helper_controller->send_response('failed', "This application is already ". $status, 422);
            }

            $disbursementRequest                        = new DisbursementRequest();
            $disbursementRequest->borrower_id           = $request->borrower_id;
            $disbursementRequest->disbursement_amount   = $request->disbursement_amount;
            $disbursementRequest->request_date          = date('Y-m-d H:i:s');
            $disbursementRequest->request_remarks       = $request->request_remarks;
            $disbursementRequest->request_ip            = $this->helper_controller->get_user_ip();
            $disbursementRequest->request_by            = auth()->id();
            $disbursementRequest->status                = "PENDING";
            $disbursementRequest->save();

            /**
             * Need to integrate mail
             * send mail to super admin and other necessary person about the request
             */

             return $this->helper_controller->send_response('success', "Disbursement request raised", 200);

        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function disbursement_request_list(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'nullable|string',
                'disbursement_amount' => 'nullable|numeric',
                'status' => 'nullable|in:PENDING,APPROVED,REJECTED',
                'request_start_date' => 'nullable|date|before_or_equal:request_end_date',
                'request_end_date' => 'nullable|date|after_or_equal:request_start_date',
                'modification_start_date' => 'nullable|date|before_or_equal:modification_end_date',
                'modification_end_date' => 'nullable|date|after_or_equal:modification_start_date',
                'page' => 'nullable|integer|min:1',
                'number_of_records' => 'nullable|integer|min:1|max:100',
            ]);
    
            if ($validator->fails()) {
                return $this->helper_controller->send_response('error', $validator->errors()->first(), 422);
            }
    
            // Get pagination inputs with default values
            $page = $request->input('page', 1);
            $number_of_records = $request->input('number_of_records', 10);
    
            $query = DisbursementRequest::select([
                    'disbursement_requests.*',
                    'loan_basic_details.borrower_id as borrower_unq',
                    'borrower_kycs.name as borrower_name',
                    'borrower_kycs.mobile as borrower_mobile',
                ])
                ->with(['requestBy:id,name', 'updatedBy:id,name'])
                ->join('loan_basic_details', 'disbursement_requests.borrower_id', '=', 'loan_basic_details.id')
                ->leftJoin('borrower_kycs', 'disbursement_requests.borrower_id', '=', 'borrower_kycs.borrower_id');
    
            if ($request->filled('borrower_id')) {
                $borrowerId = $request->borrower_id;
                if (is_numeric($borrowerId)) {
                    $query->where('disbursement_requests.borrower_id', $borrowerId)
                        ->orWhere('loan_basic_details.id', $borrowerId);
                } else {
                    $borrowerId = strtolower($borrowerId);
                    $query->whereRaw('LOWER(loan_basic_details.borrower_id) LIKE ?', ["%{$borrowerId}%"]);
                }
            }
    
            if ($request->filled('disbursement_amount')) {
                $query->where('disbursement_amount', $request->disbursement_amount);
            }
    
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
    
            if ($request->filled('request_start_date')) {
                $query->whereDate('request_date', '>=', $request->request_start_date);
            }
    
            if ($request->filled('request_end_date')) {
                $query->whereDate('request_date', '<=', $request->request_end_date);
            }
    
            if ($request->filled('modification_start_date')) {
                $query->whereDate('status_updated_on', '>=', $request->modification_start_date);
            }
    
            if ($request->filled('modification_end_date')) {
                $query->whereDate('status_updated_on', '<=', $request->modification_end_date);
            }
    
            $disbursement_requests = $query->orderBy('disbursement_requests.id', 'desc')
                ->paginate($number_of_records, ['*'], 'page', $page);
    
            // Transform the date format before returning the response
            $disbursement_requests->getCollection()->transform(function ($item) {
                $item->request_date = $item->request_date ? \Carbon\Carbon::parse($item->request_date)->format('d-m-Y H:i:s') : null;
                $item->status_updated_on = $item->status_updated_on ? \Carbon\Carbon::parse($item->status_updated_on)->format('d-m-Y H:i:s') : null;
                return $item;
            });
    
            return $this->helper_controller->send_response('success', 'Disbursement requests retrieved', 200, $disbursement_requests);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    public function get_borrower_loan_scheme($borrower_id) {
        try {
            if(!is_numeric($borrower_id)) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }
            
            $loan_details = LoanBasicDetails::find($borrower_id);
            if(!$loan_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }
            
            $borrower_details = BorrowerKyc::where('borrower_id', $borrower_id)->first();
            if(!$borrower_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }

            $loan_scheme = BorrowerLoanScheme::where('borrower_id', $borrower_id)->first();
            if(!$loan_scheme) {
                return $this->helper_controller->send_response('failed', "Loan scheme not found", 422);
            }

            $product_details = ProductModel::find($loan_scheme->product_id);

            $result = [
                'borrower_name' => $borrower_details->name,
                'borrower_mobile' => $borrower_details->mobile,
                'loan_amount' => $loan_scheme->loan_amount,
                'total_loan_interest' => $loan_scheme->total_interest,
                'tenure' => $loan_details->loan_term,
                'loan_emi' => $loan_scheme->loan_emi,
                'product_id' => $product_details->model_unq,
                'product_name' => $product_details->name,
                'battery_type' => $product_details->battery_type,
                'product_type' => $product_details->product_type,
                'scheme_details' => [
                    'interest' =>  $loan_scheme->interest,
                    'processing_fee' =>  $loan_scheme->processing_fee,
                    'subvention_amount' =>  $loan_scheme->subvention_amount,
                    'fldg' =>  $loan_scheme->fldg,
                    'iot' =>  $loan_scheme->iot,
                    'insurance_1st_year' =>  $loan_scheme->insurance_1st_year,
                    'insurance_2nd_year' =>  $loan_scheme->insurance_2nd_year,
                    'down_payment' =>  $loan_scheme->down_payment_amount,
                ]
            ];

            return $this->helper_controller->send_response('success', "Borrower loan scheme fetched successfully", 200, $result);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }
    
}
