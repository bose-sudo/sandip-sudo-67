<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelperController;
use App\Models\BorrowerKyc;
use App\Models\CoapplicantKyc;
use App\Models\DealerUserMap;
use App\Models\Guarantor;
use App\Models\LoanBasicDetails;
use App\Models\LenderBorrowerGroup;
use App\Models\LenderLoanSetting;
use App\Models\VendorServiceApi;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;
use Throwable;
use DateTime;

class LoanUsersController extends Controller
{
    private $helper_controller;

    public function __construct(HelperController $helper_controller) {
        // $this->middleware('auth:api');
        $this->helper_controller = $helper_controller;
    }

    public function get_borrower_details(Request $request) {
        $input          = $request->all();
        $user_id        = $request->user()->id;
        $errorArray     = [];
        $errorCount     = 0;

        $borrower_id        = $this->helper_controller->get_borrower_id($request->user()->id);
        
        if(!is_numeric($borrower_id)) {
            return $this->helper_controller->send_response('failed', "Invalid request, borrower not found", 422);
        }
        
        $loan_basic_details = LoanBasicDetails::find($borrower_id);
        if(!$loan_basic_details) {
            return $this->helper_controller->send_response("failed", "Invalid request", 422);
        }

        if (!empty($loan_basic_details->lender_id)) {
            $setting = LenderLoanSetting::where('lender_id', $loan_basic_details->lender_id)->first();
        }

        date_default_timezone_set('Asia/Kolkata');

        $borrower_details = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
        if(!$borrower_details) {
            return $this->helper_controller->send_response('failed', "Invalid request, borrower not found", 422);
        }

        $name               = $borrower_details->name ?? null;
        $mobile             = $borrower_details->mobile ?? null;
        $pan                = $borrower_details->pan ?? null;
        $is_verifies_pan    = $borrower_details->is_verifies_pan ?? null;
        $aadhar             = $borrower_details->aadhar ?? null;
        $is_verified_aadhar = $borrower_details->is_verified_aadhar ?? null;
        $dob                = $borrower_details->dob_date ?? null;
        $spouse_name        = $borrower_details->husband_name ?? null;
        $father_name        = $borrower_details->father_name ?? null;
        $gender             = $borrower_details->gender ?? null;
        $email              = $borrower_details->email ?? null;
        $cibil              = $borrower_details->cibil ?? null;
        $skip_co_applicant  = $borrower_details->skip_co_applicant ?? null;
        $skip_guarantor     = $borrower_details->skip_guarantor ?? null;
        $created_at         = $borrower_details->created_at ?? null;
        // $co_applicant_step  = $setting?->co_applicant_step ?? 'optional';
        // $guarantor_step     = $setting?->guarantor_step ?? 'required';
        $dobRaw = trim($borrower_details->dob_date);
        $dobFormats = ['Y-m-d', 'd-m-Y', 'd/m/Y'];
        $dobDateTime = null;

        // Try parsing with supported formats
        foreach ($dobFormats as $format) {
            $dt = DateTime::createFromFormat($format, $dobRaw);
            if ($dt && $dt->format($format) === $dobRaw) {
                $dobDateTime = $dt;
                break;
            }
        }

        $today = new DateTime();
        $age = $today->diff($dobDateTime)->y;

        // applicable in all cases
        $co_applicant_step  = $setting?->co_applicant_step ?? 'optional';
        $guarantor_step     = $setting?->guarantor_step ?? 'required';
        
        $rsm_dealer = DealerUserMap::where('dealer_unq', $loan_basic_details->dealer_id)->where('status', 'active')->first();
        if ($rsm_dealer) {
            if ($age >= 18 && $age < 21) {
                // applicable only for green raha case
                $co_applicant_step = 'required';
                $guarantor_step = 'optional';
            }
        }

        $result = [
            'borrower_id' => $loan_basic_details->id,
            'name' => $name,
            'mobile' => $mobile,
            'pan' => $pan,
            'is_verifies_pan' => $is_verifies_pan,
            'aadhar' => $aadhar,
            'is_verified_aadhar' => $is_verified_aadhar,
            'dob' => $dob,
            'spouse_name' => $spouse_name,
            'father_name' => $father_name,
            'gender' => $gender,
            'email' => $email,
            'cibil' => $cibil,
            'skip_co_applicant' => $skip_co_applicant,
            'skip_guarantor' => $skip_guarantor,
            'co_applicant_step' => $co_applicant_step,
            'guarantor_step' => $guarantor_step,
            'created_at' => $created_at,
        ];

        return $this->helper_controller->send_response('success', "Borrower details fetched", 200, $result);
    }

    public function user_address_details(Request $request) {
        $input          = $request->all();
        $user_id        = $request->user()->id;
        $borrower_id    = $this->helper_controller->get_borrower_id($request->user()->id);
        $errorArray     = [];
        $errorCount     = 0;
        date_default_timezone_set('Asia/Kolkata');
        if($input == null) {
            return $this->helper_controller->send_response("failed", "Request body is empty", 422);
        }
        
        if(!isset($request->user_type) || empty($request->user_type)) {
            $errorCount++;
            $errorArray['user_type'] = "User type is required";
            return $this->helper_controller->send_response("failed", $errorArray['user_type'], 422);
        } elseif(!in_array($input['user_type'], ['BORROWER', 'COAPPLICANT', 'GUARANTOR'])) {
            $errorCount++;
            $errorArray['user_type'] = "Invalid user type";
            return $this->helper_controller->send_response("failed", $errorArray['user_type'], 422);
        }

        $result = [];
        $vendor_service_api = VendorServiceApi::where('user_id', $user_id)
                                ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
                                ->where('status', "VALID")
                                ->where('borrower_id', $borrower_id);
        if($input['user_type'] == "BORROWER") {

            $borrower_deails = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
            if($borrower_deails) {
                $name = $borrower_deails->name;
                $mobile = $borrower_deails->mobile;
                $email = $borrower_deails->email;

                $dist = '';
                $vendor_service_api = $vendor_service_api->where('applicant_type', "BORROWER")
                                        ->latest()
                                        ->first();
                if($vendor_service_api) {
                    $response   = $vendor_service_api->response;
                    $response   = base64_decode($response);
                    $response   = unserialize($response);
                    $data       = json_decode($response, 200);

                    if($data['msg'] == 'success') {
                        $dist   = $data['model']['address']['dist'];
                    }
                }
                $single_line_address = $this->helper_controller->prepare_one_line_address_from_aadhar($data['model']['address']);
                $city = $dist; // $borrower_deails->name;

                $result = [
                    'name' => $name,
                    'mobile' => $mobile,
                    'email' => $email,
                    'city' => $city,
                    'single_line_address' => $single_line_address,
                    'full_address' => $data['model']['address'],
                ];
            }
        } elseif($input['user_type'] == "COAPPLICANT") {
            $borrower_deails = CoapplicantKyc::where('borrower_id', $borrower_id)->latest()->first();
            if($borrower_deails) {
                $name = $borrower_deails->name;
                $mobile = $borrower_deails->mobile;
                $email = $borrower_deails->email;

                $dist = '';
                $vendor_service_api = $vendor_service_api->where('applicant_type', "COAPPLICANT")
                                        ->latest()
                                        ->first();
                if($vendor_service_api) {
                    $response   = $vendor_service_api->response;
                    $response   = base64_decode($response);
                    $response   = unserialize($response);
                    $data       = json_decode($response, 200);

                    if($data['msg'] == 'success') {
                        $dist   = $data['model']['address']['dist'];
                    }
                }

                $single_line_address = $this->helper_controller->prepare_one_line_address_from_aadhar($data['model']['address']);
                $city = $dist;  // $borrower_deails->name;
                $result = [
                    'name' => $name,
                    'mobile' => $mobile,
                    'email' => $email,
                    'city' => $city,
                    'single_line_address' => $single_line_address,
                    'full_address' => $data['model']['address'],
                ];
            }
        } elseif($input['user_type'] == "GUARANTOR") {
            $borrower_deails = Guarantor::where('borrower_id', $borrower_id)->latest()->first();
            if($borrower_deails) {
                $name = $borrower_deails->name;
                $mobile = $borrower_deails->mobile;
                $email = $borrower_deails->email;

                $dist = '';
                $vendor_service_api = $vendor_service_api->where('applicant_type', "GUARANTOR")
                                        ->latest()
                                        ->first();
                if($vendor_service_api) {
                    $response   = $vendor_service_api->response;
                    $response   = base64_decode($response);
                    $response   = unserialize($response);
                    $data       = json_decode($response, 200);

                    if($data['msg'] == 'success') {
                        $dist   = $data['model']['address']['dist'];
                    }
                }
                $single_line_address = $this->helper_controller->prepare_one_line_address_from_aadhar($data['model']['address']);
                $city = $dist; // $borrower_deails->name;

                $result = [
                    'name' => $name,
                    'mobile' => $mobile,
                    'email' => $email,
                    'city' => $city,
                    'single_line_address' => $single_line_address,
                    'full_address' => $data['model']['address'],
                ];
            }
        }

        if(count($result) > 0) {
            return $this->helper_controller->send_response('success', "Data fetched successfully", 200, $result);
        } else {
            return $this->helper_controller->send_response('failed', "No result found", 422);

        }
    }

    // public function get_borrower_list(Request $request) {
    //     $result = [];
    //     $borrowers = LoanBasicDetails::whereNotIn('status', ['REJECTED', 'CLOSED'])
    //                     ->join('borrower_kycs as bk', '')
    // }

    public function generate_pre_signed_agreement_for_preview($borrower_id) {
        try {
            // ✅ Validate borrower_id
            $validator = Validator::make(
                ['borrower_id' => $borrower_id],
                [
                    'borrower_id' => 'required|integer|exists:loan_basic_details,id',
                ]
            );

            $loan_details           = LoanBasicDetails::find($borrower_id);
            if(!$loan_details) {
                return $this->send_response("failed", "Invalid borrower id", 422);
            }

            $borrower_fin_id    = $loan_details->borrower_id;

            $borrower_details = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
            if(!$borrower_details) {
                return $this->send_response('failed', "Invalid request, borrower not found", 422);
            }

            $data = $this->helper_controller->prepare_data_for_esign_doc($borrower_id);
            $pdf = Pdf::loadView('pdf.agreement-2', $data);
            
            $pdfPath = "e-sign/{$borrower_fin_id}/BORROWER/agreement.pdf";
            Storage::disk('public')->put($pdfPath, $pdf->output());
            $fileUrl = Storage::disk('public')->url($pdfPath);

            return $this->helper_controller->send_response('success', "Pre signed agreement fetched successfully", 200, [
                'agreement_url' => $fileUrl
            ]);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', "Unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function allow_application_to_proceed(Request $request)
    {
        try {

            // ✅ Validation
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|integer|exists:loan_basic_details,id',
                'status'      => 'required|in:approved,rejected',
                'remark'      => [
                    'required',
                    'regex:/^[a-zA-Z0-9\s,.\-\(\)]+$/'
                ],
            ], [
                'remark.regex' => 'Remark may contain only alphabets, numbers, spaces, comma, full stop, hyphen and brackets.'
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response(
                    'failed',
                    $validator->errors()->first(),
                    422
                );
            }

            // Fetch borrower
            $borrower = LoanBasicDetails::find($request->borrower_id);

            if (!$borrower) {
                return $this->helper_controller->send_response(
                    'failed',
                    'Borrower not found',
                    422
                );
            }

            // ✅ If approved
            if ($request->status === 'approved') {
                $borrower->is_agreement_reviewed = true;
                $borrower->agreement_preview_remarks = $request->remark;
            }

            // Optional: store remarks even on rejection (if needed later)
            if ($request->status === 'rejected') {
                $borrower->agreement_preview_remarks = $request->remark;
            }

            $borrower->save();

            return $this->helper_controller->send_response(
                'success',
                'Application status updated successfully',
                200
            );

        } catch (Throwable $e) {
            return $this->helper_controller->send_response(
                'failed',
                'Unknown error occurred: ' . $e->getMessage(),
                422
            );
        }
    }
}
