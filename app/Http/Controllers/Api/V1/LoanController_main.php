<?php
// B2C Production
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelperController;
use App\Models\BorrowerAccountDetails;
use App\Models\BorrowerAddress;
use App\Models\BorrowerCheques;
use App\Models\BorrowerKyc;
use App\Models\BorrowerLoanScheme;
use App\Models\BorrowerOccupation;
use App\Models\BorrowerReferenceDetails;
use App\Models\CoapplicantKyc;
use App\Models\CoapplicantOccupation;
use App\Models\Dealer;
use App\Models\DealerShops;
use App\Models\DealerUserMap;
use App\Models\OemDynamicProduct;
use App\Models\FieldInspectionDetails;
use App\Models\FieldInspectionParty;
use App\Models\Documents;
use App\Models\EnachRequest;
use App\Models\Guarantor;
use App\Models\GuarantorOccupation;
use App\Models\BorrowerLoanProducts;
use App\Models\LenderBorrowerGroup;
use App\Models\LenderDealerGroup;
use App\Models\LenderLoanSetting;
use App\Models\LoanApplicationSummary;
use App\Models\LoanBasicDetails;
use App\Models\NpciBankList;
use App\Models\ProductModel;
use App\Models\StateMaster;
use App\Models\User;
use App\Models\VendorServiceApi;
use Exception;
use DateTime;
use Throwable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Services\TwilioService;
use App\Services\FieldInspectionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Mpdf\Mpdf;
use App\Services\SmsService;
use App\Traits\LogsApplicationStep;
use App\Traits\SendsSanctionMail;
use App\Traits\SyncsDigilockerKyc;
use App\Traits\CalculatesLoanSchedule;
use App\Traits\BorrowerEmiTrait;
use App\Traits\VkycTrait;
use Illuminate\Support\Facades\Log;

class LoanController extends Controller
{
    use CalculatesLoanSchedule;
    use BorrowerEmiTrait;
    use LogsApplicationStep;
    use SendsSanctionMail;
    use SyncsDigilockerKyc;
    use VkycTrait;

    private $max_annual_income;
    private $min_annual_income;
    private $max_monthly_income;
    private $min_monthly_income;
    private $client_id;
    private $client_secret;
    private $uat_authorization_key;
    private $prod_authorization_key;
    private $signzy_auth_key;
    protected $twilio;
    protected $helper_controller;
    protected SmsService $smsService;

    public function __construct(
        TwilioService $twilio,
        HelperController $helper_controller,
        SmsService $smsService,
        private readonly FieldInspectionService $fi
    ) {
        $this->middleware('auth:api', [
            'except' => [
                'verify_dealer_id',
                'loan_terms',
                'field_verification',
                'send_otp_field_verification',
                'verify_otp_field_verification',
                'verify_fi_access_token',
                'calculate_distance_between_borrower_dealer',
                'store_meter_image',
                'get_field_inspection_status',
            ]
        ]);

        $this->max_annual_income    = 10000000;
        $this->min_annual_income    = 180000;
        $this->max_monthly_income   = 800000;
        $this->min_monthly_income   = 15000;

        $uatClientId = config('services.digitap.uat.client_id');
        $uatSecret   = config('services.digitap.uat.client_secret');
        $this->uat_authorization_key = base64_encode($uatClientId . ':' . $uatSecret);
        
        $prodClientId = config('services.digitap.prod.client_id');
        $prodSecret   = config('services.digitap.prod.client_secret');
        $this->prod_authorization_key = base64_encode($prodClientId . ':' . $prodSecret);
        
        $this->signzy_auth_key = config('services.signzy.prod.auth_key');

        $this->twilio = $twilio;
        $this->helper_controller = $helper_controller;
        $this->smsService = $smsService;
    }


    public function send_response($status, $message, $code, $data=null) {
        $result = [
            'status' => $status,
            'message' => $message
        ];

        if(isset($data) && !empty($data)) {
            $result['data'] = $data;
        }

        return response()->json($result, $code);
    }

    /**
     * Method to generate random string
     */
    public function generate_random_string($length = 16) {
        $characters = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $characters_length = strlen($characters);
        $random_string = '';
        for ($i=0; $i < $length; $i++) { 
            $random_string .= $characters[rand(0, $characters_length - 1)];
        }

        return $random_string;
    }

    public function generate_borrower_id($id) {
        if(isset($id) && !empty($id)) {
            $loan_basic_details = LoanBasicDetails::find($id);
            if($loan_basic_details) {
                $loan_details_primary_key = $loan_basic_details->id;
                $number = str_pad($loan_details_primary_key, 4, '0', STR_PAD_LEFT);
                $borrower_id = "FIN-".$number;
                $loan_basic_details->borrower_id = $borrower_id;
                $loan_basic_details->save();   
                
                return $borrower_id;
            }
        }
    }

    /**
     * Method to get user IP address
     */
    public function get_user_ip() {
        $ipAddress = '';

        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ipAddress = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
            $ipAddress = $_SERVER['HTTP_X_FORWARDED'];
        } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ipAddress = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
            $ipAddress = $_SERVER['HTTP_FORWARDED'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ipAddress = $_SERVER['REMOTE_ADDR'];
        }

        return $ipAddress;
    }

    /**
     * Method to generate random numbers
     */
    public function generate_random_number($length = 6) {
        $characters = "0123456789";
        $characters_length = strlen($characters);
        $random_number = '';
        for ($i=0; $i < $length; $i++) { 
            $random_number .= $characters[rand(0, $characters_length - 1)];
        }

        return $random_number;
    }

    /**
     * Method to generate alpha numeric id
     */
    public function generate_random_id($length = 4) {
        $random_str = $this->generate_random_string($length);
        date_default_timezone_set('Asia/Kolkata');
        $date = date('YmdHis');
        $generate_random_id = $random_str.$date;

        return $generate_random_id;
    }

    public function get_borrower_id($user_id) {
        $loan_details = LoanBasicDetails::where('user_id', $user_id)
                                        ->whereIn('status', ['NEW', 'DISBURSED'])
                                        ->where('is_active', false)
                                        ->where('borrower_id', '!=', '')
                                        ->latest()
                                        ->first();
        if($loan_details) {
            return $loan_details->id; // borrower primary key
        } else {
            return 'Invalid';
        }
    }

    public function check_if_step_is_already_cleard($userId, $borrower_id, $step) {
        $userId             = $userId;
        $currentStep        = $step;
        $isStepCompleted    = false;
        $nextStep           = 0;
        $step_status        = 'INCOMPLETE';

        $isApplicationExist = LoanBasicDetails::where('user_id', $userId)
                                ->where('borrower_id', $borrower_id)
                                ->where('status', '<>', 'CLOSED')
                                ->first();
        if($isApplicationExist) {
            $existing_application_steps = json_decode($isApplicationExist->loan_application_steps);
            foreach($existing_application_steps as $each_step) {
                if($each_step->step === $currentStep && $each_step->status === "COMPLETED") {
                    $isStepCompleted = true;
                    $step_status = $each_step->status;
                }
                $nextStep = $each_step->step;
            }
            $nextStep = $nextStep + 1;
        }
        
        return [
            'current_step' => $currentStep,
            'step_status' => $step_status,
            'next_step' => $nextStep
        ];
    }

    public function verify_dealer_id(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        date_default_timezone_set('Asia/Kolkata');

        // Validation starts
            if(!isset($request->dealer_id) || empty($request->dealer_id)) {
                $errorCount++;
                $errorArray['dealer_id'] = "Dealer id is required";
                return $this->send_response("failed", "Dealer id is required", 422);
            } elseif (!ctype_alnum($request->dealer_id)) {
                $errorCount++;
                $errorArray['dealer_id'] = "Dealer id must be alphanumeric";
                return $this->send_response("failed", "Dealer id must be alphanumeric", 422);
            }

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }
        // Validation ends

        $dealer_ids = [
            [
                'id'=>'1',
                'dealer_id' => 'A1234'
            ],
            [
                'id'=>'2',
                'dealer_id' => 'A1235'
            ],
            [
                'id'=>'3',
                'dealer_id' => 'A1236'
            ],
            [
                'id'=>'4',
                'dealer_id' => 'A1237'
            ],
            [
                'id'=>'5',
                'dealer_id' => 'A1238'
            ],
        ];

        $models = [];
        $id = 0;
        foreach($dealer_ids as $dealer_id) {
            for($i=0; $i<5; $i++) {
                $id++;
                $price = 20000 * ($i+1);
                $max_loan_amt = $price * 0.75;
                $max_loan_tenure = 3*($i+1);
                $models[] = [
                    'id' => $id,
                    'dealer_id' => $dealer_id['dealer_id'],
                    'model_name' => "Model ".$dealer_id['dealer_id']."S".$i+1,
                    'manufacturer' => "Demo manufacturer",
                    'price' => $price,
                    'max_loan_amount' => $max_loan_amt,
                    'max_loan_tenure' => $max_loan_tenure
                    
                ];
            }
        }
            
        $valid_dealer_id = false;
        $resultModels = [];
        foreach($dealer_ids as $dealer_id) {
            if($request->dealer_id == $dealer_id['dealer_id']) {
                $valid_dealer_id = true;
                foreach($models as $model) {
                    if($model['dealer_id'] == $request->dealer_id) {
                        $resultModels[] = $model;
                    }
                }
                break;
            }
        }

        if($valid_dealer_id == true) {
            return response()->json([
                'status' => 'success',
                'message' => "Models data fetched successfully",
                'data' => $resultModels
            ], 200);
        } else {
            return response()->json([
                'status' => 'failed',
                'message' => "Dealer not found"
            ], 422);
        }
    }

    public function maskAadharNumber($aadharNumber) {
        // Ensure the input is a string
        $aadharNumber = strval($aadharNumber);
    
        // Get the length of the Aadhaar number
        $length = strlen($aadharNumber);
    
        // Check if the length is valid (Aadhaar numbers are 12 digits long)
        if ($length != 12) {
            return "Invalid Aadhaar Number";
        }
    
        // Create the masked Aadhaar number (e.g., XXXXXXXX1234)
        $maskedAadhar = str_repeat('X', $length - 4) . substr($aadharNumber, -4);
    
        return $maskedAadhar;
    }

    public function get_loan_status(Request $request) {
        $userId             = $request->user()->id;
        $borrower_id        = $this->get_borrower_id($request->user()->id);
        $currentStep        = 1;
        $isStepCompleted    = false;
        $nextStep           = 0;
        $step_status        = 'INCOMPLETE';
        
        $is_case_rejected = LoanBasicDetails::where('user_id', $userId)
                ->where('status', 'REJECTED')
                ->where('updated_at', '>=', now()->subMonths(6))
                ->exists(); // Returns true if a rejected case exists

        if ($is_case_rejected) {
            return $this->send_response('failed', "Your previous case was rejected within the last 6 months. You cannot proceed further.", 422);
        }

        if($borrower_id != "Invalid") {

            $isApplicationExist = LoanBasicDetails::where('user_id', $userId)
                                    ->where('id', $borrower_id)
                                    ->where('status', '<>', 'CLOSED')->first();
            if($isApplicationExist) {
                $existing_application_steps = json_decode($isApplicationExist->loan_application_steps);
                // foreach($existing_application_steps as $each_step) {
                //     if($each_step->step === $currentStep && $each_step->status === "COMPLETED") {
                //         $isStepCompleted = true;
                //         $step_status = $each_step->status;
                //     }
                //     $nextStep = $each_step->step;
                // }
                // $nextStep = $nextStep + 1;
                // return end($existing_application_steps);
                return response()->json([
                    'status' => 'success',
                    'is_loan_exist' => true,
                    'borrower_primary' => $isApplicationExist->id,
                    'skip_coapplicant_guarantor' => $isApplicationExist->skip_coapplicant_guarantor,
                    'step_details' => end($existing_application_steps),
                ], 200);
            } else {
                // $nextStep = $nextStep + 1;
                return response()->json([
                    'status' => 'success',
                    'is_loan_exist' => false,
                    'step_details' => [
                        'details' => "No applications"
                    ],
                ], 200);
            }
        } else {
            // $nextStep = $nextStep + 1;
            return response()->json([
                'status' => 'success',
                'is_loan_exist' => false,
                'step_details' => [
                    'details' => "No applications"
                ],
            ], 200);
        }
        
        // return response()->json([
        //     'status' => 'success',
        //     'current_step' => $currentStep,
        //     'step_status' => $step_status,
        //     'next_step' => $nextStep
        // ], 200);
    }

    public function get_loan_status_by_product(Request $request) {
        try {
            $userId             = $request->user()->id;
            $borrower_id        = $this->get_borrower_id($request->user()->id);

            $is_case_rejected = LoanBasicDetails::where('user_id', $userId)
                ->where('status', 'REJECTED')
                ->where('updated_at', '>=', now()->subMonths(6))
                ->exists(); // Returns true if a rejected case exists

            if ($is_case_rejected) {
                return $this->send_response('failed', "Your previous case was rejected within the last 6 months. You cannot proceed further.", 422);
            }
            
            if($borrower_id == "Invalid") {
                return response()->json([
                    'status' => 'success',
                    'is_loan_exist' => false,
                    'step_details' => [
                        'details' => "No applications"
                    ],
                ], 200);
            }

            if(!isset($request->product_id) || empty($request->product_id)) {

                return $this->send_response('failed', "Product id is required", 422);
            }

            $isApplicationExist = LoanBasicDetails::where('user_id', $userId)
                                    ->where('id', $borrower_id)
                                    ->where('status', '<>', 'CLOSED')->first();
            if(!$isApplicationExist) {
                return response()->json([
                    'status' => 'success',
                    'is_loan_exist' => false,
                    'step_details' => [
                        'details' => "No applications"
                    ],
                ], 200);
            }

            $existing_application_steps = json_decode($isApplicationExist->loan_application_steps);

            if(isset($request->product_id) && !empty($request->product_id)) {
                if(!in_array($request->product_id, ["1", "2", "3", "4"])) {
                    return $this->send_response('failed', "Please input valid product id", 422);
                }

                if($isApplicationExist->product_id == $request->product_id) {

                    return response()->json([
                        'status' => 'success',
                        'is_loan_exist' => true,
                        'borrower_primary' => $isApplicationExist->id,
                        'skip_coapplicant_guarantor' => $isApplicationExist->skip_coapplicant_guarantor,
                        'step_details' => end($existing_application_steps),
                    ], 200);
                } else {
                    if ($isApplicationExist->product_id == "1") {
                        $existing_application_product = 'E-Rickshaw (L3)';
                    } elseif ($isApplicationExist->product_id == "2") {
                        $existing_application_product = 'E-Auto (L5)';    
                    } elseif ($isApplicationExist->product_id == "3") {
                        $existing_application_product = 'E-Two Wheeler';    
                    } elseif ($isApplicationExist->product_id == "4") {
                        $existing_application_product = 'Battery';    
                    }

                    return response()->json([
                        'status' => 'failed',
                        'message' => 'There is an ongoing application for '. $existing_application_product,
                    ], 200);
                }
            }

            return response()->json([
                'status' => 'success',
                'is_loan_exist' => true,
                'skip_coapplicant_guarantor' => $isApplicationExist->skip_coapplicant_guarantor,
                'step_details' => end($existing_application_steps),
            ], 200);
        } catch(Exception $e) {
            return $this->send_response('failed', "Something went wrong, while fetching loan status", 422);
        }
    }

    public function get_existing_application(Request $request) {
        $userId             = $request->user()->id;
        
        $borrower_id        = $this->get_borrower_id($request->user()->id);
        if($borrower_id === 'Invalid') {
            return $this->send_response('failed', "No application exist", 422);
        }

        $isApplicationExist = LoanBasicDetails::where('user_id', $userId)
                                ->where('id', $borrower_id)
                                ->where('status', '<>', 'CLOSED')->first();
        if($isApplicationExist) {
            $existing_application_steps = json_decode($isApplicationExist->loan_application_steps);
            $result = [
                'borrower_primary' => $isApplicationExist->id,
                'borrower_id' => $isApplicationExist->borrower_id,
                'dealer_id' => $isApplicationExist->dealer_id,
                'loan_amount' => $isApplicationExist->loan_amount,
                'loan_term' => $isApplicationExist->loan_term,
                'application_date' => $isApplicationExist->created_at,
                'skip_coapplicant_guarantor' => $isApplicationExist->skip_coapplicant_guarantor,
                'step_details' => end($existing_application_steps),
            ];
            return $this->send_response('success', "Loan application exist", 200, $result);
        } else {
            return $this->send_response('failed', "No application exist", 422);
        }
    }

    public function reset_loan_application(Request $request) {
        $userId = $request->user()->id;

        $isApplicationExist = LoanBasicDetails::where('user_id', $userId)->where('status', '<>', 'CLOSED')->first();
        if($isApplicationExist) {
            if($isApplicationExist->delete()) {
                return response()->json([
                    'status' => 'success',
                    'message' => "The loan application has been reset.",
                ], 200);
            } else {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Unable to reset the application, try again",
                ], 200);
            }
        } else {
            return response()->json([
                'status' => 'failed',
                'message' => "There is no application by the user",
            ], 200);
        }
    }

    public function check_if_kyc_data_is_similar($data, $borrower_id, $user_type) {
        // return true;
        function checkKycData($model, $borrower_id, $data) {
            if ($model::where('borrower_id', $borrower_id)->where('pan', $data['pan'])->exists()) {
                return "This pan id is already used";
            }
    
            if(isset($data['aadhar'])) {
                if ($model::where('borrower_id', $borrower_id)->where('aadhar', $data['aadhar'])->exists()) {
                    return "This aadhar id is already used";
                }
            }
    
            if ($model::where('borrower_id', $borrower_id)->where('mobile', $data['contact_number'])->exists()) {
                return "This contact number is already used";
            }
    
            return false;
        }
    
        // Check for the borrower
        $borrowerResponse = checkKycData(BorrowerKyc::class, $borrower_id, $data);
        if ($borrowerResponse) {
            return $this->send_response('failed', $borrowerResponse . " for borrower", 422);
        }
    
        // Check for co-applicant or guarantor
        if ($user_type == "COAPPLICANT") {
            $coapplicantResponse = checkKycData(Guarantor::class, $borrower_id, $data);
            if ($coapplicantResponse) {
                return $this->send_response('failed', $coapplicantResponse . " for co applicant", 422);
            }
        } elseif ($user_type == "GUARANTOR") {
            $guarantorResponse = checkKycData(CoapplicantKyc::class, $borrower_id, $data);
            if ($guarantorResponse) {
                return $this->send_response('failed', $guarantorResponse . " for guarantor", 422);
            }
        }
    
        return true;
    }

    public function updateApplicationStep($user_id, $borrower_id, $application_step) {
        $isApplicationExist = LoanBasicDetails::where('user_id', $user_id)
                                ->where('id', $borrower_id)
                                ->first();
        if($isApplicationExist) {
            $existing_application_steps = json_decode($isApplicationExist->loan_application_steps);
            $existing_application_steps[] = $application_step;

            $updatedApplicationStep = json_encode($existing_application_steps);
            $isApplicationExist->loan_application_steps = $updatedApplicationStep;
            $isApplicationExist->save();
        }
    }

    // public function storeLoanBasicDetails(Request $request) {
    //     try {
    //         $input = $request->all();
    //         $errorArray = [];
    //         $errorCount = 0;
    //         $step = '';
    //         date_default_timezone_set('Asia/Kolkata');
    //         DB::beginTransaction();
    //         // Validation start 
    //             if(!isset($request->preferred_language) || empty($request->preferred_language)) {
    //                 $errorCount++;
    //                 $errorArray['preferred_language'] = "Preferred language is required";
    //                 return $this->send_response("failed", "Preferred language is required", 422);
    //             }
                
    //             if(!isset($request->product_id) || empty($request->product_id)) {
    //                 $errorCount++;
    //                 $errorArray['product_id'] = "Product id is required";
    //                 return $this->send_response("failed", "Product id is required", 422);
    //             }
                
    //             if(!isset($request->dealer_id) || empty($request->dealer_id)) {
    //                 $errorCount++;
    //                 $errorArray['dealer_id'] = "Dealer id is required";
    //                 return $this->send_response("failed", "Dealer id is required", 422);
    //             }
    //             // elseif (!ctype_alnum($request->dealer_id)) {
    //             //     $errorCount++;
    //             //     $errorArray['dealer_id'] = "Dealer id must be alphanumeric";
    //             //     return $this->send_response("failed", "Dealer id must be alphanumeric", 422);
    //             // }
                
    //             if(!isset($request->model) || empty($request->model)) {
    //                 $errorCount++;
    //                 $errorArray['model'] = "Model is required";
    //                 return $this->send_response("failed", "Model is required", 422);
    //             }
                
    //             if(!isset($request->loan_amount) || empty($request->loan_amount)) {
    //                 $errorCount++;
    //                 $errorArray['loan_amount'] = "Loan amount is required";
    //                 return $this->send_response("failed", "Loan amount is required", 422);
    //             } elseif (!is_numeric($request->loan_amount)) {
    //                 $errorCount++;
    //                 $errorArray['loan_amount'] = "Loan amount must be numeric";
    //                 return $this->send_response("failed", "Loan amount must be numeric", 422);
    //             }    
                
    //             if(!isset($request->loan_term) || empty($request->loan_term)) {
    //                 $errorCount++;
    //                 $errorArray['loan_term'] = "Loan term is required";
    //                 return $this->send_response("failed", "Loan term is required", 422);
    //             } elseif (!is_numeric($request->loan_term)) {
    //                 $errorCount++;
    //                 $errorArray['loan_term'] = "Loan term must be numeric";
    //                 return $this->send_response("failed", "Loan term must be numeric", 422);
    //             }
    //             if(!isset($request->model_id) || empty($request->model_id)) {
    //                 return $this->send_response('failed', "Model is is required", 422);
    //             }
    //             $productModel = ProductModel::where('model_unq', $request->model_id)->first();
    //             if(!$productModel) {
    //                 return $this->send_response("failed", "Invalid product model", 422);
    //             }

    //             if($request->loan_amount > $productModel->max_loan_amount) {
    //                 return $this->send_response("failed", "Max loan amount allowed for this product is ". $productModel->max_loan_amount, 422);
    //             }

    //         // Validation ends
    //         $userId             = $request->user()->id;
    //         $isApplicationExist = LoanBasicDetails::where('user_id', $userId)->first();
    //         if ($isApplicationExist) {
    //             return $this->send_response("failed", "An application is already exist", 422);
    //         }
            
    //         if($errorCount > 0) {
    //             return response()->json([
    //                 'status'=>'error',
    //                 'message'=>"Invalid data",
    //                 'error_list'=> $errorArray
    //             ], 422);

    //         } else {
    //             $nextStep           = 0;
    //             $currentStep        = 1;
    //             // $isStepCompleted    = false;

    //             $preferred_language = $request->preferred_language;
    //             $dealer_id          = $request->dealer_id;
    //             $model              = $request->model;
    //             $loan_amount        = $request->loan_amount;
    //             $loan_term          = $request->loan_term;
    //             $product_id         = $request->product_id;

    //             $current_step[] = [
    //                 'step' => $currentStep,
    //                 'details' => "Loan Basic Details",
    //                 'status' => "COMPLETED",
    //             ];
                
    //             /**
    //              * StashFin Lender case where we need to skip co applicant and guarantor step
    //              * Created on 19-12-2024
    //              */
    //             $dealerDetails = Dealer::where('dealer_unq', $dealer_id)->first();
    //             if(!$dealerDetails) {
    //                 return $this->send_response('failed', "Invalid dealer id", 422);
    //             }

    //             $lender_dealer_group = LenderDealerGroup::where('dealer_id', $dealerDetails->id)->where('status', '=', 'active')->first();
    //             if($lender_dealer_group) {
    //                 $lender_id = $lender_dealer_group->lender_id;
    //                 $skip_coapplicant_guarantor = true;
    //             }
    //             /**
    //              * StashFin Lender case ends
    //              */

    //             $application_step = json_encode($current_step);

    //             $loanBasicDetails                           = new LoanBasicDetails;
    //             $loanBasicDetails->user_id                  = $userId;
    //             $loanBasicDetails->borrower_id              = '';
    //             $loanBasicDetails->loan_application_steps   = $application_step; 
    //             $loanBasicDetails->preferred_language       = $preferred_language;
    //             $loanBasicDetails->dealer_id                = $dealer_id;
    //             $loanBasicDetails->model                    = $model;
    //             $loanBasicDetails->loan_amount              = $loan_amount;
    //             $loanBasicDetails->loan_term                = $loan_term;
    //             $loanBasicDetails->status                   = "NEW";
    //             $loanBasicDetails->product_id               = $product_id;
    //             $loanBasicDetails->model_id                 = $request->model_id ?? '';
    //             $loanBasicDetails->lender_id                = $lender_id ?? null;
    //             // $loanBasicDetails->skip_coapplicant_guarantor = $skip_coapplicant_guarantor ?? false;

    //             if($loanBasicDetails->save()) {
    //                 $borrower_id = $this->generate_borrower_id($loanBasicDetails->id);

    //                 // update loan application summary
    //                 $loan_application_summary               = new LoanApplicationSummary;
    //                 $loan_application_summary->borrower_id  = $loanBasicDetails->id;
    //                 $loan_application_summary->user_id      = $userId;
    //                 $loan_application_summary->product_id   = $product_id;
    //                 $loan_application_summary->loan_amount  = $loan_amount;
    //                 $loan_application_summary->tenure       = $loan_term;
    //                 $loan_application_summary->save();

    //                 $loan_scheme                        = new BorrowerLoanScheme();
    //                 $loan_scheme->borrower_id           = $loanBasicDetails->id;
    //                 $loan_scheme->product_id            = $productModel->id;
    //                 $loan_scheme->ex_showroom_price     = $productModel->ex_showroom_price ?? 0;
    //                 $loan_scheme->loan_to_value_ratio   = $productModel->loan_to_value_ratio ?? '';
    //                 $loan_scheme->min_loan_amount       = $productModel->min_loan_amount ?? 0;
    //                 $loan_scheme->max_loan_amount       = $productModel->max_loan_amount ?? 0;
    //                 $loan_scheme->min_loan_tenure       = $productModel->min_loan_tenure ?? 0;
    //                 $loan_scheme->max_loan_tenure       = $productModel->max_loan_tenure ?? 0;
    //                 $loan_scheme->interest              = $productModel->interest ?? 0;
    //                 $loan_scheme->processing_fee        = $productModel->processing_fee ?? 0;
    //                 $loan_scheme->subvention_amount     = $productModel->subvention_amount ?? 0;
    //                 $loan_scheme->fldg                  = $productModel->fldg ?? 0;
    //                 $loan_scheme->iot                   = $productModel->iot ?? 0;
    //                 $loan_scheme->insurance_1st_year    = $productModel->insurance_1st_year ?? 0;
    //                 $loan_scheme->insurance_2nd_year    = $productModel->insurance_2nd_year ?? 0;
    //                 $loan_scheme->interest_type         = $productModel->interest_type ?? null;
    //                 $loan_scheme->save();
                        
    //                 $this->logStep($loanBasicDetails->id, $currentStep, 'Loan Basic Details', 'b2c');

    //                 DB::commit();
    //                 return response()->json([
    //                     'status'=>'success',
    //                     'message'=>"Basic details stored successfully",
    //                     'data'=> ''
    //                 ], 201);
    //             } else {
    //                 DB::rollBack();
    //                 return response()->json([
    //                     'status'=>'failed',
    //                     'message'=>"Failed to store basic details",
    //                 ], 422);
    //             }
    //         }
    //     } catch (Exception $e) {
    //         DB::rollBack();
    //         return $this->send_response('failed', "Unknown error occurred". $e->getMessage(), 422);
    //     }
    // }

    public function storeLoanBasicDetails(Request $request) {
        $r = app(\App\Services\Loan\LoanBasicDetailsService::class)->store($request, 'b2c');
        return $this->send_response($r['ok'] ? 'success' : 'failed', $r['message'], $r['code'], $r['data'] ?? null);
    }

    public function storeBorrowerDetails(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            $age = 0;
            date_default_timezone_set('Asia/Kolkata');
            DB::beginTransaction();

            $borrower_id = $this->get_borrower_id($request->user()->id);
            // Validation starts
                $loan_details = LoanBasicDetails::find($borrower_id);
                if(!$loan_details) {
                    return $this->send_response('failed', "Invalid borrower", 422);
                }

                if($loan_details->status == 'REJECTED') {
                    return $this->send_response('failed', "Invalid request, the borrower is already rjected", 422);
                }

                if($loan_details->status == 'CANCELLED') {
                    return $this->send_response('failed', "Your application is on hold, please contact Finayo", 422);
                }
            
                if (!isset($request->pan_number) || empty($request->pan_number)) {
                    $errorCount++;
                    $errorArray['pan_number'] = "Pan number is required";
                    return $this->send_response("failed", "Pan number is required", 422);
                } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $request->pan_number)) {
                    $errorCount++;
                    $errorArray['pan_number'] = "Invalid Pan number format";
                    return $this->send_response("failed", "Invalid Pan number format", 422);
                }

                if(!isset($request->is_pan_verified) || empty($request->is_pan_verified)) {
                    $errorCount++;
                    $errorArray['pan_number'] = "Pan verification is required";
                    return $this->send_response("failed", "Pan verification is required", 422);
                } else {
                    if($request->is_pan_verified == 0) {
                        $errorCount++;
                        $errorArray['pan_number'] = "Pan must be validated";
                        return $this->send_response("failed", "Pan must be validated", 422);
                    }
                }
            
                if (!isset($request->full_name) || empty($request->full_name)) {
                    $errorCount++;
                    $errorArray['full_name'] = "Full name is required";
                    return $this->send_response("failed", "Full name is required", 422);
                }
            
                if (!isset($request->dob) || empty($request->dob)) {
                    $errorCount++;
                    $errorArray['dob'] = "Date of birth is required";
                    return $this->send_response("failed", "Date of birth is required", 422);
                } elseif (!strtotime($request->dob)) {
                    $errorCount++;
                    $errorArray['dob'] = "Invalid date of birth";
                    return $this->send_response("failed", "Invalid date of birth", 422);
                } else {
                    // Calculate age
                    date_default_timezone_set('Asia/Kolkata');
                    $dobTimestamp = strtotime($request->dob);
                    $age = date('Y') - date('Y', $dobTimestamp);
                    if (date('md') < date('md', $dobTimestamp)) {
                        $age--;
                    }
                    if ($age < 18) {
                        $errorCount++;
                        $errorArray['dob'] = "Age must be greater than 18";
                        return $this->send_response("failed", "Age must be greater than 18", 422);
                    }
                    if ($age > 55) {
                        $errorCount++;
                        $errorArray['dob'] = "Age must be less than 56";
                        return $this->send_response("failed", "Age must be less than 56", 422);
                    }
                }
            
                if (!isset($request->gender) || empty($request->gender)) {
                    $errorCount++;
                    $errorArray['gender'] = "Gender is required";
                    return $this->send_response("failed", "Gender is required", 422);
                }
            
                if (!isset($request->mobile_number) || empty($request->mobile_number)) {
                    $errorCount++;
                    $errorArray['mobile_number'] = "Mobile number is required";
                    return $this->send_response("failed", "Mobile number is required", 422);
                } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $request->mobile_number)) {
                    $errorCount++;
                    $errorArray['mobile_number'] = "Invalid mobile number format";
                    return $this->send_response("failed", "Invalid mobile number format", 422);
                }
            
                if (!empty($request->alternative_mobile_number) && !preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $request->alternative_mobile_number)) {
                    $errorCount++;
                    $errorArray['alternative_mobile_number'] = "Invalid alternative mobile number format";
                    return $this->send_response("failed", "Invalid alternative mobile number format", 422);
                }
            
                if (!isset($request->email) || empty($request->email)) {
                    $errorCount++;
                    $errorArray['email'] = "Email ID is required";
                    return $this->send_response("failed", "Email ID is required", 422);
                } elseif (!filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
                    $errorCount++;
                    $errorArray['email'] = "Invalid email format";
                    return $this->send_response("failed", "Invalid email format", 422);
                }
            
                if (!isset($request->marital_status) || empty($request->marital_status)) {
                    $errorCount++;
                    $errorArray['marital_status'] = "Marital status is required";
                    return $this->send_response("failed", "Marital status is required", 422);
                }
            
                if ($request->marital_status === 'Married') {
                    if (!isset($request->husband_name) || empty($request->husband_name)) {
                        $errorCount++;
                        if($request->gender == "Female") {
                            $errorArray['husband_name'] = "Husband's name is required";
                            return $this->send_response("failed", "Husband's name is required", 422);
                        } elseif($request->gender == "Male"){
                            $errorArray['husband_name'] = "Wife's name is required";
                            return $this->send_response("failed", "Wife's name is required", 422);
                        } else {
                            $errorArray['husband_name'] = "Spouse name is required";
                            return $this->send_response("failed", "Spouse name is required", 422);
                        }
                    }
                }
            
                if (!isset($request->father_name) || empty($request->father_name)) {
                    $errorCount++;
                    $errorArray['father_name'] = "Father's name is required";
                    return $this->send_response("failed", "Father's name is required", 422);
                }
                
                // if(!isset($input['dl_no']) || empty($input['dl_no'])){
                //     $errorCount++;
                //     $errorArray['dl_no'] = "Driving Licence is required";
                //     return $this->send_response("failed", $errorArray['dl_no'], 422);
                // }

                if(!isset($input['consent_acceptance']) || empty($input['consent_acceptance'])) {
                    $errorCount++;
                    $errorArray['consent_acceptance'] = "Consent acceptance is required";
                    return $this->send_response("failed", $errorArray['consent_acceptance'], 422);
                }
                // echo $input['dl_no'];
                // die();

                if ($gate = $this->require_digilocker_verified($borrower_id, 'BORROWER')) {
                    return $gate;
                }
                
                if(isset($input['dl_no']) && !empty($input['dl_no'])){
                    if(!isset($input['is_licence_verified']) || empty($input['is_licence_verified'])){
                        $errorCount++;
                        $errorArray['is_licence_verified'] = "Driving Licence must be verified";
                        return $this->send_response("failed", $errorArray['is_licence_verified'], 422);
                    }
                }

                $validator = Validator::make($input, [
                    'aadhar_front'             => 'required|string',
                    'aadhar_back'              => 'required|string',
                    'pan_doc'                  => 'required|string',
                ]);

                if ($validator->fails()) {
                    return $this->send_response('failed', $validator->errors()->first(), 422);
                }

                $pan_details = VendorServiceApi::where('borrower_id', $borrower_id)
                                        ->where('api_flag', 'PAN_DETAILS_VALIDATION')
                                        ->where('applicant_type', 'BORROWER')
                                        ->where('status', 'VALID')
                                        ->latest()->first();

                $panData = $pan_details->response;
                $panData = base64_decode($panData);
                $panData = unserialize($panData);
                $panData = json_decode($panData);
                if(isset($panData->result->dob) && !empty($panData->result->dob)) {
                    $panDob = $panData->result->dob;
                }

                if(isset($aadharDob) && !empty($aadharDob) && isset($panDob) && !empty($panDob)) {
                    $dob1 = new DateTime($aadharDob);
                    $dob2 = DateTime::createFromFormat('d/m/Y', $panDob);
                    $diffDays = abs($dob1->diff($dob2)->days);
                        if($diffDays > 89 && $diffDays < 1096) {
                            if(!$request->filled('dob_declaration')) {
                                return $this->send_response('failed', "DOB declaration is required", 422);
                            }
                        } elseif ($diffDays >= 1096) {
                            $loan_details->status = "REJECTED";
                            $loan_details->admin_remarks = "Date of birth difference in aadhar and pan is more than 3 years.";
                            $loan_details->save();
                            $message = "You are not eligible for a loan";
                        }
                }
            
                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }
            // Validation ends

            $loan_summary = LoanApplicationSummary::where('borrower_id', $borrower_id)->first();

            if (!$loan_summary) {
                return $this->send_response("failed", "Invalid request", 422);
            }

            // Common rejection message
            // $rejectionMsg = "You are not eligible for a loan. Please consider reapplying after few months.";
            $rejectionMsg = "Please wait while we review your application.";

            // Eligibility checks
            $isLowCibil   = !empty($loan_summary->cibil_score) && $loan_summary->cibil_score > 10 && $loan_summary->cibil_score < 650;
            $hasDpd30     = !empty($loan_summary->dpd_30_in_3_month) && $loan_summary->dpd_30_in_3_month > 0;
            $hasDpd90     = !empty($loan_summary->dpd_90_in_1_year) && $loan_summary->dpd_90_in_1_year > 0;

            if ($isLowCibil || $hasDpd30 || $hasDpd90) {
                if ($loan_details && $loan_details->manual_override === true) {
                    // Admin has force-approved — skip rejection
                } else {
                    return $this->send_response("failed", $rejectionMsg, 422);
                }
            }

            $aadhar_number  = $request->aadhar_number ?? '';
            $pan_number     = $request->pan_number;
            $full_name      = $request->full_name;
            $dob            = date('Y-m-d', strtotime($request->dob));
            $gender         = ucfirst(strtolower($request->gender));
            $mobile_number  = $request->mobile_number;
            $email          = $request->email;
            $marital_status = $request->marital_status;
            $husband_name   = $request->husband_name;
            $father_name    = $request->father_name;

            $data = [
                'mobile_no' => $mobile_number,
                'name' => $full_name,
                'dob' => $dob,
                'email' => $email,
                'pan' => $pan_number,
            ];

            if ($request->filled('is_pan_verified') && $request->is_pan_verified == '1') {
                $is_pan_verified = true;
                $pan_message = "Active";
            } else {
                $is_pan_verified = false;
                $pan_message = "Invalid";
            }
            if ($request->filled('is_aadhar_verified') && $request->is_aadhar_verified == '1') {
                $is_aadhar_verified = true;
            } else {
                $is_aadhar_verified = false;
            }

            $is_mobile_number_verified = false;

            if(isset($input['is_licence_verified']) && $input['is_licence_verified'] == '1') {
                $dl_verified = true;
            } else {
                $dl_verified = false;
            }
            
            $userId             = $request->user()->id;
            $nextStep           = 0;
            $currentStep        = 2;
            $data['user_id'] = $userId;

            $borrower_id = $this->get_borrower_id($request->user()->id);
            
            $borrower_state = '';
            $borrower_pin   = 0;
            $borrowerAddress = $this->helper_controller->user_address_details($borrower_id, 'BORROWER');
            if($borrowerAddress['status'] === 'success') {
                $borrower_state = $borrowerAddress['data']['full_address']['state'] ?? '';
                $borrower_pin   = $borrowerAddress['data']['full_address']['pc'] ?? 0;
            }
            // $borrowerDetails                                = new BorrowerKyc;
            $borrowerDetails                                = BorrowerKyc::where('borrower_id', $borrower_id)->first() ?: new BorrowerKyc;
            $borrowerDetails->name                          = $full_name;
            $borrowerDetails->mobile                        = $mobile_number;
            $borrowerDetails->is_verified_mobile            = false;
            $borrowerDetails->pan                           = $pan_number;
            $borrowerDetails->is_verified_pan               = $is_pan_verified;
            $borrowerDetails->pan_message                   = $pan_message;
            $borrowerDetails->aadhar                        = $aadhar_number;
            $borrowerDetails->is_verified_aadhar            = $is_aadhar_verified;
            $borrowerDetails->asset_value                   = 0;
            $borrowerDetails->loan_amount                   = 0;
            $borrowerDetails->down_payment                  = 0;
            $borrowerDetails->dob_date                      = $dob;
            $borrowerDetails->marital_status                = $marital_status;
            $borrowerDetails->husband_name                  = $husband_name;
            $borrowerDetails->father_name                   = $father_name;
            $borrowerDetails->gender                        = $gender;
            $borrowerDetails->email                         = $email;
            $borrowerDetails->cibil                         = isset($credit_score) ? $credit_score : 0;
            $borrowerDetails->postal                        = $borrower_pin;
            $borrowerDetails->state                         = $borrower_state;
            $borrowerDetails->country                       = '';
            $borrowerDetails->avtar                         = '';
            $borrowerDetails->is_driving_licence_verified   = $dl_verified;
            $borrowerDetails->is_electricity_verified       = false;
            $borrowerDetails->created_by                    = 0;
            $borrowerDetails->updated_by                    = 0;
            $borrowerDetails->alternate_number1             = '';
            $borrowerDetails->alternate_number2             = '';
            $borrowerDetails->borrower_id                   = $borrower_id;
            $borrowerDetails->driving_license_no            = isset($input['dl_no']) ? $input['dl_no'] : '';

            if($borrowerDetails->save()) {

                // Overwrite aadhaar fields from the verified DigiLocker payload.
                // No-op when DigiLocker wasn't used — request values stand.
                $this->sync_digilocker_to_kyc($borrower_id, 'BORROWER', $borrowerDetails->id, $full_name, $mobile_number);

                $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                if (!$kyc_docs) {
                    $kyc_docs                    = new Documents;
                    $kyc_docs->borrower_id       = $borrower_id;
                }
                $kyc_docs->aadhar_front = $request->aadhar_front ?? null;
                $kyc_docs->aadhar_back = $request->aadhar_back ?? null;
                $kyc_docs->pan_doc      = $request->pan_doc ?? null;

                if ($request->filled('name_declaration') || $request->filled('dob_declaration')) {
                    $kyc_docs->borrower_name_declaration = $request->name_declaration ?? null;
                    $kyc_docs->borrower_dob_declaration = $request->dob_declaration ?? null;
                }
                $kyc_docs->save();

                $application_step = [
                    'step' => $currentStep,
                    'borrower_details_id' => $borrowerDetails->id,
                    'details' => "Borrower Details",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($userId, $borrower_id, $application_step);

                // Update Loan Application summary
                $loan_application_summary = LoanApplicationSummary::where('user_id', $userId)
                                                    ->where('borrower_id', $borrower_id)
                                                    ->first();
                if($loan_application_summary) {
                    $loan_application_summary->age = $age;
                    $loan_application_summary->save();
                }

                /**
                 * StashFin Lender case where we need to skip co applicant and guarantor step
                 * Created on 19-12-2024
                 */
                // $loan_basic_details = LoanBasicDetails::find($borrower_id);
                // if($loan_basic_details && ($loan_basic_details->skip_coapplicant_guarantor === true)) {
                //     $borrower_kycs_details = BorrowerKyc::where('borrower_id', $borrower_id)->first();
                //     if($borrower_kycs_details) {
                //         $borrower_kycs_details->skip_co_applicant = true;
                //         $borrower_kycs_details->skip_guarantor = true;
                //         $borrower_kycs_details->save();
                //     }
                // } // code disabled on 06-02-2025
                /**
                 * StashFin Lender case ends
                 */

                $this->logStep($borrower_id, $currentStep, 'Borrower Details', 'b2c');

                DB::commit();
                return response()->json([
                    'status'=>'success',
                    'message'=>"Borrower details stored successfully",
                    'data'=> ''
                ], 201);
            } else {
                DB::rollBack();
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Failed to store borrower details",
                ], 422);
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred ". $e->getMessage(), 422);
        }
    }

    public function storeBorrowerFamilyDetails(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            date_default_timezone_set('Asia/Kolkata');

            // Validation starts
            $userId             = $request->user()->id;
            $borrower_id        = $this->get_borrower_id($request->user()->id);
            $loan_details       = LoanBasicDetails::find($borrower_id);
            if(!$loan_details) {
                return $this->send_response("failed", "Loan details not found", 422);
            }

            $product_id = $loan_details->product_id;

            if (!isset($request->resident_type) || empty($request->resident_type)) {
                $errorCount++;
                $errorArray['resident_type'] = "Resident type is required";
                return $this->send_response("failed", $errorArray['resident_type'], 422);
            } elseif(!in_array($request->resident_type, ['Owned', 'Rented'])) {
                $errorCount++;
                $errorArray['resident_type'] = "Invalid resident type";
                return $this->send_response("failed", $errorArray['resident_type'], 422);
            } else {
                if($product_id == 4) {
                    if($request->resident_type != "Owned") {
                        return $this->send_response("failed", "Resident type must be owned", 422);
                    }
                }
            }

            if($request->resident_type == "Rented") {
                if (!isset($request->rent_agreement) || empty($request->rent_agreement)) {
                    $errorCount++;
                    $errorArray['rent_agreement'] = "Rent Agreement is required";
                    return $this->send_response("failed", $errorArray['rent_agreement'], 422);
                }
            }

            if (!isset($request->current_address) || empty($request->current_address)) {
                $errorCount++;
                $errorArray['current_address'] = "Current address is required";
                return $this->send_response("failed", "Current address is required", 422);
            }
            
            if (!isset($request->permanent_address) || empty($request->permanent_address)) {
                $errorCount++;
                $errorArray['permanent_address'] = "Permanent address is required";
                return $this->send_response("failed", "Permanent address is required", 422);
            }

            if (!isset($request->years_living) || empty($request->years_living)) {
                $errorCount++;
                $errorArray['years_living'] = "Years living is required";
                return $this->send_response("failed", $errorArray['years_living'], 422);
            } elseif(!is_numeric($request->years_living)) {
                $errorCount++;
                $errorArray['years_living'] = "Years living must be numeric";
                return $this->send_response("failed", $errorArray['years_living'], 422);
            } elseif($request->years_living > 100) {
                $errorCount++;
                $errorArray['years_living'] = "Invalid years of living";
                return $this->send_response("failed", $errorArray['years_living'], 422);
            }

            if($product_id != 4) {

                if (!isset($request->number_of_family_members) || empty($request->number_of_family_members)) {
                    $errorCount++;
                    $errorArray['number_of_family_members'] = "Number of family members is required";
                    return $this->send_response("failed", "Number of family members is require", 422);
                } elseif(!is_numeric($request->number_of_family_members)) {
                    $errorCount++;
                    $errorArray['number_of_family_members'] = "Number of family members must be numeric";
                    return $this->send_response("failed", "Number of family members must be numeric", 422);
                } elseif ($request->number_of_family_members > 50) {
                    return $this->send_response("failed", "Number of family members should be under 50", 422);
                }
            }

            if (!isset($request->number_of_earning_members) || empty($request->number_of_earning_members)) {
                $errorCount++;
                $errorArray['number_of_earning_members'] = "Number of earning members is required";
                return $this->send_response("failed", "Number of earning members is required", 422);
            }

            if (!is_numeric($request->number_of_earning_members)) {
                $errorCount++;
                $errorArray['number_of_earning_members'] = "Number of earning members must be numeric";
                return $this->send_response("failed", "Number of earning members must be numeric", 422);
            }

            // Ensure earning members are between 1 and 6
            if ($request->number_of_earning_members < 1 || $request->number_of_earning_members > 6) {
                $errorCount++;
                $errorArray['number_of_earning_members'] = "Number of earning members must be between 1 to 6";
                return $this->send_response("failed", "Number of earning members must be between 1 to 6", 422);
            }

            // Ensure earning members do not exceed family members
            if (
                isset($request->number_of_family_members) &&
                is_numeric($request->number_of_family_members) &&
                $request->number_of_earning_members > $request->number_of_family_members
            ) {
                $errorCount++;
                $errorArray['number_of_earning_members'] = "Number of earning members cannot be more than number of family members";
                return $this->send_response("failed", "Number of earning members cannot be more than number of family members", 422);
            }

            $emi_amount        = $this->getBorrowerEmiAmount($borrower_id);
            $min_annual_income = max(60000, $emi_amount * 24); // monthly income >= 2 × EMI
            if (!isset($request->family_income) || empty($request->family_income)) {
                $errorCount++;
                $errorArray['family_income'] = "Family income is required";
                return $this->send_response("failed", "Family income is required", 422);
            } elseif(!is_numeric($request->family_income)) {
                $errorCount++;
                $errorArray['family_income'] = "Family income must be numeric";
                return $this->send_response("failed", "Family income must be numeric", 422);
            } else {
                if($request->family_income >= $this->max_annual_income) {
                    $errorCount++;
                    $errorArray['family_income'] = "Family income must be less than ". $this->max_annual_income;
                    return $this->send_response("failed", $errorArray['family_income'], 422);
                }  elseif($request->family_income < 60000) {
                    $errorCount++;
                    $errorArray['family_income'] = "Family income must be atleast ". 60000;
                    return $this->send_response("failed", $errorArray['family_income'], 422);
                }
            }
            // elseif ($request->family_income < $min_annual_income) {
            //     $errorCount++;
            //     $errorArray['family_income'] = $emi_amount > 0 && $min_annual_income > 60000
            //         ? "Family income must be at least " . $min_annual_income
            //         : "Family income must be at least " . $min_annual_income;
            //     return $this->send_response("failed", $errorArray['family_income'], 422);
            // }

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }

            if($request->resident_type == 'Rented') {
                $validator = Validator::make($request->all(), [
                    'permanent_state'   => 'required|exists:state_masters,id',
                    'permanent_city'    => 'required|string|max:100',
                    'permanent_district'=> 'required|string|max:100',
                    'permanent_pincode' => 'required|digits:6',

                    'present_state'     => 'required|exists:state_masters,id',
                    'present_city'      => 'required|string|max:100',
                    'present_district'  => 'required|string|max:100',
                    'present_pincode'   => 'required|digits:6',
                ]);
                if ($validator->fails()) {
                    return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
                }

                // Validate state IDs and fetch names
                $presentState = StateMaster::find($request->present_state);
                $permanentState = StateMaster::find($request->permanent_state);

                if (!$presentState || !$permanentState) {
                    return $this->helper_controller->send_response("failed", "Please select valid state(s)", 422);
                }

                // Prepare address data arrays
                $presentAddress = [
                    'vtc'   => $request->present_city,
                    'dist'  => $request->present_district,
                    'pc'    => $request->present_pincode,
                    'state' => $presentState->state,
                ];

                $permanentAddress = [
                    'vtc'   => $request->permanent_city,
                    'dist'  => $request->permanent_district,
                    'pc'    => $request->permanent_pincode,
                    'state' => $permanentState->state,
                ];

                // Calculate distances
                $presentDistanceResponse = $this->distance_calculation_dealer_borrower_address($borrower_id, $presentAddress);
                if ($presentDistanceResponse['status'] === 'failed') {
                    return $this->helper_controller->send_response('failed', $presentDistanceResponse['message'], 422);
                }

                $permanentDistanceResponse = $this->distance_calculation_dealer_borrower_address($borrower_id, $permanentAddress);
                if ($permanentDistanceResponse['status'] === 'failed') {
                    return $this->helper_controller->send_response('failed', $permanentDistanceResponse['message'], 422);
                }

                // Return distances
                $present_address_distanceKm   = $presentDistanceResponse['distance'];
                $permanent_address_distanceKm = $permanentDistanceResponse['distance'];

                // Distance validations
                if ($permanent_address_distanceKm > 100) {
                    return $this->helper_controller->send_response('failed', 'Permanent address is more than 100 km away from the dealer.', 422);
                }

                if ($present_address_distanceKm > 25) {
                    return $this->helper_controller->send_response('failed', 'Present address is more than 25 km away from the dealer.', 422);
                }
            }
            // Validation ends
            
            $resident_type              = $request->resident_type;
            $current_address            = $request->current_address;
            $permanent_address          = $request->permanent_address;
            $years_living               = $request->years_living;
            $number_of_family_members   = $request->number_of_family_members ?? 0;
            $number_of_earning_members  = $request->number_of_earning_members;
            $family_income              = $request->family_income;
            
            $userId             = $request->user()->id;
            $nextStep           = 0;
            $currentStep        = 3;
            
            // $isStepCleared = $this->check_if_step_is_already_cleard($userId, $borrower_id, $currentStep);
            // if($isStepCleared['step_status'] == "COMPLETED") {
            //     return response()->json([
            //         'status'=>'failed',
            //         'message'=>"This step is already completed",
            //         'next step'=> $isStepCleared['next_step'],
            //     ], 409);
            // }
            $borrowerFamilyDetails                      = New BorrowerAddress;
            $borrowerFamilyDetails->resident_type       = $resident_type;
            $borrowerFamilyDetails->current_address     = $current_address;
            $borrowerFamilyDetails->years_living        = $years_living;
            $borrowerFamilyDetails->permanent_address   = $permanent_address;
            $borrowerFamilyDetails->family_members      = $number_of_family_members ?? 0;
            $borrowerFamilyDetails->earning_members     = $number_of_earning_members;
            $borrowerFamilyDetails->family_income       = $family_income;
            $borrowerFamilyDetails->reference_one       = '';
            $borrowerFamilyDetails->reference_two       = '';
            $borrowerFamilyDetails->borrower_id         = $borrower_id;
            $borrowerFamilyDetails->save();
            $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
            if(!$kyc_docs) {
                $kyc_docs                    = new Documents;
                $kyc_docs->borrower_id       = $borrower_id;
            }
            $kyc_docs->borrower_rent_doc = $request->rent_agreement;
            $kyc_docs->save();

            $application_step = [
                'step' => $currentStep,
                'borrower_family_details_id' => $borrowerFamilyDetails->id,
                'details' => "Borrower Family Details",
                'status' => "COMPLETED",
            ];

            $this->updateApplicationStep($userId, $borrower_id, $application_step);

            // Update Loan Application Summary
            $loan_application_summary = LoanApplicationSummary::where('user_id', $userId)
                                                ->where('borrower_id', $borrower_id)
                                                ->first();
            if($loan_application_summary) {

                $loan_application_summary->resience_type        = $resident_type;
                $loan_application_summary->residence_stability  = $years_living;
                $loan_application_summary->field_verification   = false;
                $loan_application_summary->save();
            }

            $this->logStep($borrower_id, $currentStep, 'Borrower Family Details', 'b2c');
            DB::commit();
            return $this->send_response('success', "Borrower family details stored successfully", 201);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function storeBorrowerOccupation(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            date_default_timezone_set('Asia/Kolkata');

            DB::beginTransaction();
            $borrower_id        = $this->get_borrower_id($request->user()->id);
            $loan_details       = LoanBasicDetails::find($borrower_id);
            if(!$loan_details) {
                return $this->send_response("failed", "Loan details not found", 422);
            }

            $product_id = $loan_details->product_id;

            // Validation starts
                if (!isset($request->occupation_type) || empty($request->occupation_type)) {
                    $errorCount++;
                    $errorArray['occupation_type'] = "Occupation type is required";
                    return $this->send_response("failed", "", 422);
                }
                if (!isset($request->current_occupation) || empty($request->current_occupation)) {
                    $errorCount++;
                    $errorArray['current_occupation'] = "Current occupation is required";
                    return $this->send_response("failed", $errorArray['current_occupation'], 422);
                }

                if($product_id != 4) {
                    if (!isset($request->driving_experience) || empty($request->driving_experience)) {
                        $errorCount++;
                        $errorArray['driving_experience'] = "Driving experience is required";
                        return $this->send_response("failed", $errorArray['driving_experience'], 422);
                    } elseif(!is_numeric($request->driving_experience)) {
                        $errorCount++;
                        $errorArray['driving_experience'] = "Driving experience must be numeric";
                        return $this->send_response("failed", $errorArray['driving_experience'], 422);
                    }

                    if (!isset($request->vehicle_type) || empty($request->vehicle_type)) {
                        $errorCount++;
                        $errorArray['vehicle_type'] = "Vehicle type is required";
                        return $this->send_response("failed", $errorArray['vehicle_type'], 422);
                    }
                }

                $emi_amount         = $this->getBorrowerEmiAmount($borrower_id);
                $min_monthly_income = max(5000, $emi_amount * 2);   // monthly income >= 2 × EMI
                $min_annual_income  = max(60000, $emi_amount * 24);

                if (!isset($request->monthly_income) || empty($request->monthly_income)) {
                    $errorCount++;
                    $errorArray['monthly_income'] = "Monthly income is required";
                    return $this->send_response("failed", $errorArray['monthly_income'], 422);
                } elseif (!is_numeric($request->monthly_income)) {
                    $errorCount++;
                    $errorArray['monthly_income'] = "Monthly income must be numeric";
                    return $this->send_response("failed", $errorArray['monthly_income'], 422);
                } else {
                    // if($request->monthly_income < $min_monthly_income) {
                    //     $errorCount++;
                    //     $errorArray['monthly_income'] = $emi_amount > 0 && $min_monthly_income > 5000
                    //         ? "Monthly income must be atleast ". $min_monthly_income
                    //         : "Monthly income must be atleast ". $min_monthly_income;
                    //     return $this->send_response("failed", $errorArray['monthly_income'], 422);
                    // } 
                    if($request->monthly_income < 5000) {
                        $errorCount++;
                        $errorArray['monthly_income'] = "Monthly income must be atleast ". 5000;
                        return $this->send_response("failed", $errorArray['monthly_income'], 422);
                    } elseif($request->monthly_income > $this->max_monthly_income) {
                        $errorCount++;
                        $errorArray['monthly_income'] = "Monthly income must be less than ". $this->max_monthly_income;
                        return $this->send_response("failed", $errorArray['monthly_income'], 422);
                    }
                }

                if ($product_id != 4) {
                    if (!isset($request->expected_monthly_income) || empty($request->expected_monthly_income)) {
                        $errorCount++;
                        $errorArray['expected_monthly_income'] = "Expected monthly income is required";
                        return $this->send_response("failed", $errorArray['expected_monthly_income'], 422);
                    } elseif (!is_numeric($request->expected_monthly_income)) {
                        $errorCount++;
                        $errorArray['expected_monthly_income'] = "Expected monthly income must be numeric";
                        return $this->send_response("failed", $errorArray['expected_monthly_income'], 422);
                    } else {
                        // if($request->expected_monthly_income < 5000) {
                        //     $errorCount++;
                        //     $errorArray['expected_monthly_income'] = "Expected monthly income must be atleast ". 5000;
                        //     return $this->send_response("failed", $errorArray['expected_monthly_income'], 422);
                        // }
                        if($request->expected_monthly_income < $min_monthly_income) {
                            $errorCount++;
                            $errorArray['expected_monthly_income'] = $emi_amount > 0 && $min_monthly_income > 5000
                                ? "Expected monthly income must be atleast ". $min_monthly_income
                                : "Expected monthly income must be atleast ". $min_monthly_income;
                            return $this->send_response("failed", $errorArray['expected_monthly_income'], 422);
                        } elseif($request->expected_monthly_income >= $this->max_monthly_income) {
                            $errorCount++;
                            $errorArray['expected_monthly_income'] = "Expected monthly income must be less than ". $this->max_monthly_income;
                            return $this->send_response("failed", $errorArray['expected_monthly_income'], 422);
                        }
                    }

                    if (!empty($request->other_income) && !is_numeric($request->other_income)) {
                        $errorCount++;
                        $errorArray['other_income'] = "Other income must be numeric";
                        return $this->send_response("failed", $errorArray['other_income'], 422);
                    } elseif(!empty($request->other_income) && ($request->other_income >= $this->max_monthly_income)) {
                        $errorCount++;
                        $errorArray['other_income'] = "Other income must be less than ". $this->max_monthly_income;
                        return $this->send_response("failed", $errorArray['other_income'], 422);
                    }

                    if (isset($request->other_income) && (!isset($request->extra_source_of_income) || empty($request->extra_source_of_income))) {
                        $errorCount++;
                        $errorArray['extra_source_of_income'] = "Source of income is required";
                        return $this->send_response("failed", $errorArray['extra_source_of_income'], 422);
                    }
                }

                if (!isset($request->total_yearly_income) || empty($request->total_yearly_income)) {
                    $errorCount++;
                    $errorArray['total_yearly_income'] = "Current occupation is required";
                    return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                } elseif (!is_numeric($request->total_yearly_income)) {
                    $errorCount++;
                    $errorArray['total_yearly_income'] = "Total yearly income must be numeric";
                    return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                } else {
                    if($request->total_yearly_income >= $this->max_annual_income) {
                        $errorCount++;
                        $errorArray['total_yearly_income'] = "Total yearly income must be under ". $this->max_annual_income;
                        return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                    } elseif($request->total_yearly_income < 60000) {
                        $errorCount++;
                        $errorArray['total_yearly_income'] = "Total yearly income must be atlease ". 60000;
                        return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                    }

                    // elseif($request->total_yearly_income < $min_annual_income) {
                    //     $errorCount++;
                    //     $errorArray['total_yearly_income'] = $emi_amount > 0 && $min_annual_income > 60000
                    //         ? "Total yearly income must be atleast ". $min_annual_income
                    //         : "Total yearly income must be atleast ". $min_annual_income;
                    //     return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                    // }
                     
                }

                if ($request->monthly_income > 12000 && !$request->filled('borrower_income_proof_doc')) {
                    return $this->send_response('failed', "Income proof document is required", 422);
                }

                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }
            // Validation ends

            $occupation_type = $request->occupation_type;
            $current_occupation = $request->current_occupation;
            $driving_experience = $request->driving_experience ?? 0;
            $vehicle_type = $request->vehicle_type ?? '';
            $monthly_income = $request->monthly_income;
            $expected_monthly_income = $request->expected_monthly_income ?? 0;
            $other_income = $request->other_income ?? 0;
            $extra_source_of_income = $request->extra_source_of_income ?? '';
            $total_yearly_income = $request->total_yearly_income;
            
            $userId             = $request->user()->id;
            $nextStep           = 0;
            $currentStep        = 4;

            // $isStepCleared = $this->check_if_step_is_already_cleard($userId, $borrower_id, $currentStep);
            // if($isStepCleared['step_status'] == "COMPLETED") {
            //     return response()->json([
            //         'status'=>'failed',
            //         'message'=>"This step is already completed",
            //         'next step'=> $isStepCleared['next_step'],
            //     ], 409);
            // }
            $borrowerOccupation                             = new BorrowerOccupation;
            $borrowerOccupation->occupation_type            = $occupation_type;
            $borrowerOccupation->current_occupation         = $current_occupation;
            $borrowerOccupation->driving_experience         = $driving_experience ?? 0;
            $borrowerOccupation->vehicle_type               = $vehicle_type ?? '';
            $borrowerOccupation->monthly_earning            = $monthly_income;
            $borrowerOccupation->expected_monthly_earning   = $expected_monthly_income ?? 0;
            $borrowerOccupation->extra_source_income        = $other_income ?? 0;
            $borrowerOccupation->source_income_area         = $extra_source_of_income ?? '';
            $borrowerOccupation->total_income               = $total_yearly_income;
            $borrowerOccupation->current_city               = '';
            $borrowerOccupation->borrower_id                = $borrower_id;
            if($borrowerOccupation->save()){

                if($request->filled('borrower_income_proof_doc')) {
                    $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                    if (!$kyc_docs) {
                        $kyc_docs                    = new Documents;
                        $kyc_docs->borrower_id       = $borrower_id;
                    }
                    $kyc_docs->borrower_income_proof_doc = $request->borrower_income_proof_doc;
                    $kyc_docs->save();
                }

                $application_step = [
                    'step' => $currentStep,
                    'borrower_occupation_id' => $borrowerOccupation->id,
                    'details' => "Borrower Occupation",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($userId, $borrower_id, $application_step);

                $this->logStep($borrower_id, $currentStep, 'Borrower Occupation', 'b2c');

                DB::commit();
                return response()->json([
                    'status'=>'success',
                    'message'=>"Borrower occupation details stored successfully",
                    'data'=> ''
                ], 201);
            } else {
                DB::rollBack();
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Failed to store borrower occupation details",
                ], 422);
            }
        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred ". $e->getMessage(), 422);
        }
    }

    public function skip_co_applicant(Request $request) {
        try {
            // skip_co_applicant
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            DB::beginTransaction();
            date_default_timezone_set('Asia/Kolkata');
            
            $userId             = $request->user()->id;
            $nextStep           = 0;
            $borrower_id        = $this->get_borrower_id($request->user()->id);

            /**
             * Lender loan settings part start
             * added on 06-02-2025
             */
            $loan_basic_details = LoanBasicDetails::find($borrower_id);
            if(!$loan_basic_details) {
                return $this->send_response("failed", "Invalid request", 422);
            }

            $borrower_details   = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
            if (!$borrower_details) {
                return $this->send_response('failed', "Invalid request, borrower not found", 422);
            }

            if(!isset($input['skip_coapplicant']) || empty($input['skip_coapplicant'])) {
                $errorCount++;
                $errorArray['skip_coapplicant'] = "Skip consent is required";
                return $this->send_response("failed", $errorArray['skip_coapplicant'], 422);
            }

            if($loan_basic_details->product_id == 4) {
                $borrower_details->skip_co_applicant = true;
                $borrower_details->save();
                    
                $application_step = [
                    'step' => 5,
                    'co_applicant_details_id' => $borrower_details->id,
                    'details' => "Co-Applicant Details Skipped",
                    'status' => "COMPLETED",
                ];
                
                $this->updateApplicationStep($userId, $borrower_id, $application_step);

                $this->logStep($borrower_id, 5, 'Co-Applicant Details Skipped', 'b2c');

                $application_step = [
                    'step' => 6,
                    'co_applicant_occupation_id' => $borrower_details->id,
                    'details' => "Co-Applicant Occupation Details Skipped",
                    'status' => "COMPLETED",
                ];
                
                $this->updateApplicationStep($userId, $borrower_id, $application_step);

                $this->logStep($borrower_id, 6, 'Co-Applicant Occupation Details Skipped', 'b2c');
                
                DB::commit();
                return response()->json([
                    'status'=>'success',
                    'message'=>"Co applicant details skipped successfully",
                    'data'=> ''
                ], 201);
            }

            $setting = null;
            if (!empty($loan_basic_details->lender_id)) {
                $setting = LenderLoanSetting::where('lender_id', $loan_basic_details->lender_id)->first();
            }

            if(!$setting) {
                $lenderBorrowerGroup = LenderBorrowerGroup::where('borrower_id', $borrower_id)->first();
                if($lenderBorrowerGroup) {
                    $setting = LenderLoanSetting::where('lender_id', $lenderBorrowerGroup->lender_id)->first();
                }
            }

            if ($setting?->co_applicant_step === 'required') {
                return $this->send_response("failed", "Co-applicant step is required, cannot skip.", 422);
            }
            
            // Lender loan settings part ends

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

            $rsm_dealer = DealerUserMap::where('dealer_unq', $loan_basic_details->dealer_id)->where('status', 'active')->first();
            if ($rsm_dealer) {
                if ($age >= 18 && $age < 21) {
                    return $this->send_response("failed", "Co-applicant step is required, cannot skip.", 422);
                }
            }
            
            $gender = $borrower_details->gender;
            if ($setting?->co_applicant_step === 'optional' && strtolower($gender) == 'female') {
                return $this->send_response('failed', "Co-applicant is mandatory for a female borrower", 422);
            }

            $borrower_details->skip_co_applicant = true;
            if($borrower_details->save()) {

                $application_step = [
                    'step' => 5,
                    'co_applicant_details_id' => $borrower_details->id,
                    'details' => "Co-Applicant Details Skipped",
                    'status' => "COMPLETED",
                ];
                
                $this->updateApplicationStep($userId, $borrower_id, $application_step);

                $this->logStep($borrower_id, 5, 'Co-Applicant Details Skipped', 'b2c');

                $application_step = [
                    'step' => 6,
                    'co_applicant_occupation_id' => $borrower_details->id,
                    'details' => "Co-Applicant Occupation Details Skipped",
                    'status' => "COMPLETED",
                ];
                
                $this->updateApplicationStep($userId, $borrower_id, $application_step);
                
                $this->logStep($borrower_id, 6, 'Co-Applicant Occupation Details Skipped', 'b2c');

                DB::commit();
                return response()->json([
                    'status'=>'success',
                    'message'=>"Co applicant details skipped successfully",
                    'data'=> ''
                ], 201);
            } else {
                DB::rollBack();
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Failed to update details, try again",
                ], 422);
            }
        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred ". $e->getMessage(), 422);
        }
    }

    public function storeCoApplicantDetails(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            date_default_timezone_set('Asia/Kolkata');
            $step = '';
            DB::beginTransaction();
            $borrower_id = $this->get_borrower_id($request->user()->id);
            // validation starts
                // if (!isset($request->aadhar_number) || empty($request->aadhar_number)) {
                //     $errorCount++;
                //     $errorArray['aadhar_number'] = "Aadhar number is required";
                //     return $this->send_response("failed", $errorArray['aadhar_number'], 422);
                // } elseif (!preg_match('/^[0-9]{12}$/', $request->aadhar_number)) {
                //     $errorCount++;
                //     $errorArray['aadhar_number'] = "Invalid Aadhar number format";
                //     return $this->send_response("failed", $errorArray['aadhar_number'], 422);
                // }

                // if(!isset($request->is_aadhar_verified) || empty($request->is_aadhar_verified)) {
                //     $errorCount++;
                //     $errorArray['aadhar_number'] = "Aadhar verification is required";
                //     return $this->send_response("failed", "Aadhar verification is required", 422);
                // } else {
                //     if($request->is_aadhar_verified == 0) {
                //         $errorCount++;
                //         $errorArray['aadhar_number'] = "Aadhar must be validated";
                //         return $this->send_response("failed", "Aadhar must be validated", 422);
                //     }
                // }
            
                if (!isset($request->pan_number) || empty($request->pan_number)) {
                    $errorCount++;
                    $errorArray['pan_number'] = "Pan number is required";
                    return $this->send_response("failed", $errorArray['pan_number'], 422);
                } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $request->pan_number)) {
                    $errorCount++;
                    $errorArray['pan_number'] = "Invalid Pan number format";
                    return $this->send_response("failed", $errorArray['pan_number'], 422);
                }

                if(!isset($request->is_pan_verified) || empty($request->is_pan_verified)) {
                    $errorCount++;
                    $errorArray['pan_number'] = "Pan verification is required";
                    return $this->send_response("failed", "Pan verification is required", 422);
                } else {
                    if($request->is_pan_verified == 0) {
                        $errorCount++;
                        $errorArray['pan_number'] = "Pan must be validated";
                        return $this->send_response("failed", "Pan must be validated", 422);
                    }
                }
            
                if (!isset($request->full_name) || empty($request->full_name)) {
                    $errorCount++;
                    $errorArray['full_name'] = "Full name is required";
                    return $this->send_response("failed", $errorArray['full_name'], 422);
                }
            
                // if (!isset($request->dob) || empty($request->dob)) {
                //     $errorCount++;
                //     $errorArray['dob'] = "Date of birth is required";
                //     return $this->send_response("failed", $errorArray['dob'], 422);
                // } elseif (!strtotime($request->dob)) {
                //     $errorCount++;
                //     $errorArray['dob'] = "Invalid date of birth";
                //     return $this->send_response("failed", $errorArray['dob'], 422);
                // } else {
                //     // Calculate age
                //     date_default_timezone_set('Asia/Kolkata');
                //     $dobTimestamp = strtotime($request->dob);
                //     $age = date('Y') - date('Y', $dobTimestamp);
                //     if (date('md') < date('md', $dobTimestamp)) {
                //         $age--;
                //     }
                //     if ($age < 21) {
                //         $errorCount++;
                //         $errorArray['dob'] = "Age must be greater than 21";
                //         return $this->send_response("failed", $errorArray['dob'], 422);
                //     }
                //     if ($age > 55) {
                //         $errorCount++;
                //         $errorArray['dob'] = "Age must be less than 56";
                //         return $this->send_response("failed", "Age must be less than 56", 422);
                //     }
                // }
                if (!isset($input['dob']) || empty($input['dob'])) {
                    $errorCount++;
                    $errorArray['dob'] = "Date of birth is required";
                    return $this->send_response("failed", $errorArray['dob'], 422);
                } else {
                    $dobRaw = trim($input['dob']);
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

                    if (!$dobDateTime) {
                        $errorCount++;
                        $errorArray['dob'] = "Invalid date of birth";
                        return $this->send_response("failed", $errorArray['dob'], 422);
                    }

                    // Calculate age
                    $today = new DateTime();
                    $age = $today->diff($dobDateTime)->y;

                    if ($age < 18 || $age > 55) {
                        $errorCount++;
                        $errorArray['dob'] = "Age must be within 18 to 55";
                        return $this->send_response("failed", $errorArray['dob'], 422);
                    }
                }
            
                if (!isset($request->gender) || empty($request->gender)) {
                    $errorCount++;
                    $errorArray['gender'] = "Gender is required";
                    return $this->send_response("failed", $errorArray['gender'], 422);
                }
            
                if (!isset($request->mobile_number) || empty($request->mobile_number)) {
                    $errorCount++;
                    $errorArray['mobile_number'] = "Mobile number is required";
                    return $this->send_response("failed", $errorArray['mobile_number'], 422);
                } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $request->mobile_number)) {
                    $errorCount++;
                    $errorArray['mobile_number'] = "Invalid mobile number format";
                    return $this->send_response("failed", $errorArray['mobile_number'], 422);
                }

                if (!isset($request->relation_with_borrower) || empty($request->relation_with_borrower)) {
                    $errorCount++;
                    $errorArray['relation_with_borrower'] = "Relation with borrower is required";
                    return $this->send_response("failed", $errorArray['relation_with_borrower'], 422);
                }
            
                if (!isset($request->email) || empty($request->email)) {
                    $errorCount++;
                    $errorArray['email'] = "Email ID is required";
                    return $this->send_response("failed", $errorArray['email'], 422);
                } elseif (!filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
                    $errorCount++;
                    $errorArray['email'] = "Invalid email format";
                    return $this->send_response("failed", $errorArray['email'], 422);
                }

                if (!isset($request->father_name) || empty($request->father_name)) {
                    $errorCount++;
                    $errorArray['father_name'] = "Father's name is required";
                    return $this->send_response("failed", $errorArray['father_name'], 422);
                }

                $validator = Validator::make($input, [
                    'aadhar_front'            => 'required|string',
                    'aadhar_back'             => 'required|string',
                    'pan_doc'                 => 'required|string',
                ]);

                if ($validator->fails()) {
                    return $this->send_response('failed', $validator->errors()->first(), 422);
                }

                $loan_details = LoanBasicDetails::find($borrower_id);
                if(!$loan_details) {
                    return $this->send_response('failed', "Invalid borrower", 422);
                }

                if($loan_details->status == 'REJECTED') {
                    return $this->send_response('failed', "Invalid request, the borrower is already rjected", 422);
                }

                if($loan_details->status == 'CANCELLED') {
                    return $this->send_response('failed', "Your application is on hold, please contact Finayo", 422);
                }

                // $aadhar_details = VendorServiceApi::where('borrower_id', $borrower_id)
                //                         ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
                //                         ->where('applicant_type', 'COAPPLICANT')
                //                         ->where('status', 'VALID')
                //                         ->latest()->first();
                // if(!$aadhar_details) {
                //     return $this->send_response('failed', "Verify aadhar first", 422);
                // }
                // $aadharData = $aadhar_details->response;
                // $aadharData = base64_decode($aadharData);
                // $aadharData = unserialize($aadharData);
                // $aadharData = json_decode($aadharData);
                // if(isset($aadharData->model->dob) && !empty($aadharData->model->dob)) {
                //     $aadharDob = date('Y-m-d', strtotime($aadharData->model->dob));
                // }

                $pan_details = VendorServiceApi::where('borrower_id', $borrower_id)
                                        ->where('api_flag', 'PAN_DETAILS_VALIDATION')
                                        ->where('applicant_type', 'COAPPLICANT')
                                        ->where('status', 'VALID')
                                        ->latest()->first();

                $panData = $pan_details->response;
                $panData = base64_decode($panData);
                $panData = unserialize($panData);
                $panData = json_decode($panData);
                if(isset($panData->result->dob) && !empty($panData->result->dob)) {
                    $panDob = $panData->result->dob;
                }

                if(isset($aadharDob) && !empty($aadharDob) && isset($panDob) && !empty($panDob)) {
                    $dob1 = new DateTime($aadharDob);
                    $dob2 = DateTime::createFromFormat('d/m/Y', $panDob);
                    $diffDays = abs($dob1->diff($dob2)->days);
                        if($diffDays > 89 && $diffDays < 1096) {
                            if(!$request->filled('dob_declaration')) {
                                return $this->send_response('failed', "DOB declaration is required", 422);
                            }
                        } elseif ($diffDays >= 1096) {
                            return $this->send_response('failed', "Date of birth difference in aadhar and pan is more than 3 years, please change the Co-Applicant", 422);
                        }
                }
            
                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }
            // Validation Ends

            if ($gate = $this->require_digilocker_verified($borrower_id, 'COAPPLICANT')) {
                return $gate;
            }

            $loan_summary = LoanApplicationSummary::where('borrower_id', $borrower_id)->first();

            if (!$loan_summary) {
                return $this->send_response("failed", "Invalid request", 422);
            }

            // Common rejection message
            $rejectionMsg = "You are not eligible for a loan. Please consider reapplying after few months.";

            // Eligibility checks
            $isLowCibil   = !empty($loan_summary->coappli_cibil_score) && $loan_summary->coappli_cibil_score > 10 && $loan_summary->coappli_cibil_score < 650;
            $hasDpd30     = !empty($loan_summary->coappli_dpd_30) && $loan_summary->coappli_dpd_30 > 0;
            $hasDpd90     = !empty($loan_summary->coappli_dpd_90) && $loan_summary->coappli_dpd_90 > 0;

            if ($isLowCibil || $hasDpd30 || $hasDpd90) {
                return $this->send_response("failed", $rejectionMsg, 422);
            }

            $aadhar_number              = $request->aadhar_number ?? '';
            $pan_number                 = $request->pan_number;
            $full_name                  = $request->full_name;
            $dob                        = date('Y-m-d', strtotime($request->dob));
            $gender                     = ucfirst(strtolower($request->gender));
            $mobile_number              = $request->mobile_number;
            $relation_with_borrower     = $request->relation_with_borrower;
            $email                      = $request->email;
            $father_name                = $request->father_name;

            if($request->filled('is_pan_verified') && $request->is_pan_verified == '1') {
                $is_pan_verified = true;
                $pan_message = "Active";
            } else {
                $is_pan_verified = false;
                $pan_message = "Invalid";
            }
            if($request->filled('is_aadhar_verified') && $request->is_aadhar_verified == '1') {
                $is_aadhar_verified = true;
                
            } else {
                $is_aadhar_verified = false;
            }
            $is_mobile_number_verified = false;

            $userId             = $request->user()->id;
            $nextStep           = 0;
            $currentStep        = 5;

            // $isStepCleared = $this->check_if_step_is_already_cleard($userId, $borrower_id, $currentStep);
            // if($isStepCleared['step_status'] == "COMPLETED") {
            //     return response()->json([
            //         'status'=>'failed',
            //         'message'=>"This step is already completed",
            //         'next step'=> $isStepCleared['next_step'],
            //     ], 409);
            // }
            $borrower_id = $this->get_borrower_id($request->user()->id);
            $checkData = [
                'pan' => $request->pan_number,
                // 'aadhar' => $request->aadhar_number,
                'contact_number' => $request->mobile_number
            ];
            $checkDuplicate = $this->check_if_kyc_data_is_similar($checkData, $borrower_id, "COAPPLICANT");
            if($checkDuplicate !== true) {
                return $checkDuplicate;
            }

            $coapplicant_details                            = new CoapplicantKyc;
            $coapplicant_details->name                      = $full_name;
            $coapplicant_details->mobile                    = $mobile_number;
            $coapplicant_details->is_verified_mobile        = $is_mobile_number_verified;
            $coapplicant_details->pan                       = $pan_number;
            $coapplicant_details->is_verified_pan           = $is_pan_verified;
            $coapplicant_details->pan_message               = $pan_message;
            $coapplicant_details->aadhar                    = $aadhar_number;
            $coapplicant_details->is_verified_aadhar        = $is_aadhar_verified;
            $coapplicant_details->asset_value               = 0;
            $coapplicant_details->loan_amount               = 0;
            $coapplicant_details->down_payment              = 0;
            $coapplicant_details->dob_date                  = $dob;
            $coapplicant_details->father_name               = $father_name;
            $coapplicant_details->relation_with_borrower    = $relation_with_borrower;
            $coapplicant_details->gender                    = $gender;
            $coapplicant_details->email                     = $email;
            $coapplicant_details->cibil                     = 0;
            $coapplicant_details->postal                    = 0;
            $coapplicant_details->state                     = '';
            $coapplicant_details->country                   = '';
            $coapplicant_details->avtar                     = '';
            $coapplicant_details->alternate_number1         = '';
            $coapplicant_details->alternate_number2         = '';
            $coapplicant_details->borrower_id               = $borrower_id;
            if($coapplicant_details->save()) {

                // Overwrite aadhaar fields from the verified DigiLocker payload.
                // No-op when DigiLocker wasn't used — request values stand.
                $this->sync_digilocker_to_kyc($borrower_id, 'COAPPLICANT', $coapplicant_details->id, $full_name, $mobile_number);

                $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                if (!$kyc_docs) {
                    $kyc_docs                    = new Documents;
                    $kyc_docs->borrower_id       = $borrower_id;
                }

                $kyc_docs->coAadhar_front = $request->aadhar_front ?? null;
                $kyc_docs->coAadhar_back = $request->aadhar_back ?? null;  
                $kyc_docs->coPan_doc        = $request->pan_doc ?? null;
                
                if ($request->filled('name_declaration') || $request->filled('dob_declaration')) {
                    $kyc_docs->coapplicant_name_declaration = $request->name_declaration ?? null;
                    $kyc_docs->coapplicant_dob_declaration = $request->dob_declaration ?? null;

                }
                $kyc_docs->save();

                $application_step = [
                    'step' => $currentStep,
                    'co_applicant_details_id' => $coapplicant_details->id,
                    'details' => "Co-Applicant Details",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($userId, $borrower_id, $application_step);

                // Update Loan Application Summary
                $loan_application_summary = LoanApplicationSummary::where('user_id', $userId)
                                                    ->where('borrower_id', $borrower_id)
                                                    ->first();
                if($loan_application_summary) {

                    $loan_application_summary->co_applicant_id        = $coapplicant_details->id;
                    $loan_application_summary->save();
                }

                $this->logStep($borrower_id, $currentStep, 'Co-Applicant Details', 'b2c');

                DB::commit();
                return response()->json([
                    'status'=>'success',
                    'message'=>"Coapplicant details stored successfully",
                    'data'=> ''
                ], 201);
            } else {
                DB::rollBack();
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Failed to store coapplicant details",
                ], 422);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred ". $e->getMessage(), 422);
        }
    }

    public function storeCoApplicantOccupation(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            date_default_timezone_set('Asia/Kolkata');
            $step = '';
            DB::beginTransaction();
            // Validation starts
                if (!isset($request->resident_type) || empty($request->resident_type)) {
                    $errorCount++;
                    $errorArray['resident_type'] = "Resident type is required";
                    return $this->send_response("failed", "Resident type is required", 422);
                } elseif(!in_array($request->resident_type, ['Owned', 'Rented'])) {
                    $errorCount++;
                    $errorArray['resident_type'] = "Resident type is invalid";
                    return $this->send_response("failed", $errorArray['resident_type'], 422);
                }

                if (!isset($request->current_address) || empty($request->current_address)) {
                    $errorCount++;
                    $errorArray['current_address'] = "Current address is required";
                    return $this->send_response("failed", "Current address is required", 422);
                }
                
                if (!isset($request->permanent_address) || empty($request->permanent_address)) {
                    $errorCount++;
                    $errorArray['permanent_address'] = "Permanent address is required";
                    return $this->send_response("failed", "Permanent address is required", 422);
                }

                if (!isset($request->years_living) || empty($request->years_living)) {
                    $errorCount++;
                    $errorArray['years_living'] = "Years living is required";
                    return $this->send_response("failed", "Years living is required", 422);
                } elseif(!is_numeric($request->years_living)) {
                    $errorCount++;
                    $errorArray['years_living'] = "Years living must be numeric";
                    return $this->send_response("failed", $errorArray['years_living'], 422);
                }

                if (!isset($request->occupation_type) || empty($request->occupation_type)) {
                    $errorCount++;
                    $errorArray['occupation_type'] = "Occupation type is required";
                    return $this->send_response("failed", $errorArray['occupation_type'], 422);
                }
                if (!isset($request->current_occupation) || empty($request->current_occupation)) {
                    $errorCount++;
                    $errorArray['current_occupation'] = "Current occupation is required";
                    return $this->send_response("failed", $errorArray['current_occupation'], 422);
                }
                
                if (!isset($request->monthly_income) || empty($request->monthly_income)) {
                    $errorCount++;
                    $errorArray['monthly_income'] = "Monthly income is required";
                    return $this->send_response("failed", $errorArray['monthly_income'], 422);
                } elseif (!is_numeric($request->monthly_income)) {
                    $errorCount++;
                    $errorArray['monthly_income'] = "Monthly income must be numeric";
                    return $this->send_response("failed", $errorArray['monthly_income'], 422);
                } else {
                    if($request->monthly_income < 3000) {
                        $errorCount++;
                        $errorArray['monthly_income'] = "Monthly income must be atleast ". 3000;
                        return $this->send_response("failed", $errorArray['monthly_income'], 422);
                    } elseif($request->monthly_income > $this->max_monthly_income) {
                        $errorCount++;
                        $errorArray['monthly_income'] = "Monthly income must be less than ". $this->max_monthly_income;
                        return $this->send_response("failed", $errorArray['monthly_income'], 422);
                    }
                }

                if (!isset($request->expected_monthly_income) || empty($request->expected_monthly_income)) {
                    $errorCount++;
                    $errorArray['expected_monthly_income'] = "Expected monthly income is required";
                    return $this->send_response("failed", $errorArray['expected_monthly_income'], 422);
                } elseif (!is_numeric($request->expected_monthly_income)) {
                    $errorCount++;
                    $errorArray['expected_monthly_income'] = "Expected monthly income must be numeric";
                    return $this->send_response("failed", $errorArray['expected_monthly_income'], 422);
                } else {
                    if($request->expected_monthly_income < 3000) {
                        $errorCount++;
                        $errorArray['expected_monthly_income'] = "Expected monthly income must be atleast ". 3000;
                        return $this->send_response("failed", $errorArray['expected_monthly_income'], 422);
                    } elseif($request->expected_monthly_income >= $this->max_monthly_income) {
                        $errorCount++;
                        $errorArray['expected_monthly_income'] = "Expected monthly income must be less than ". $this->max_monthly_income;
                        return $this->send_response("failed", $errorArray['expected_monthly_income'], 422);
                    }
                }

                if (!empty($request->other_income) && !is_numeric($request->other_income)) {
                    $errorCount++;
                    $errorArray['other_income'] = "Other income must be numeric";
                    return $this->send_response("failed", $errorArray['other_income'], 422);
                } elseif(!empty($request->other_income) && ($request->other_income >= $this->max_monthly_income)) {
                    $errorCount++;
                    $errorArray['other_income'] = "Other income must be less than ". $this->max_monthly_income;
                    return $this->send_response("failed", $errorArray['other_income'], 422);
                }

                if (isset($request->other_income) && (!isset($request->extra_source_of_income) || empty($request->extra_source_of_income))) {
                    $errorCount++;
                    $errorArray['extra_source_of_income'] = "Source of income is required";
                    return $this->send_response("failed", $errorArray['extra_source_of_income'], 422);
                }

                if (!isset($request->total_yearly_income) || empty($request->total_yearly_income)) {
                    $errorCount++;
                    $errorArray['total_yearly_income'] = "Current occupation is required";
                    return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                } elseif (!is_numeric($request->total_yearly_income)) {
                    $errorCount++;
                    $errorArray['total_yearly_income'] = "Total yearly income must be numeric";
                    return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                } else {
                    if($request->total_yearly_income >= $this->max_annual_income) {
                        $errorCount++;
                        $errorArray['total_yearly_income'] = "Total yearly income must be under ". $this->max_annual_income;
                        return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                    } elseif($request->total_yearly_income < 36000) {
                        $errorCount++;
                        $errorArray['total_yearly_income'] = "Total yearly income must be atlease ". 36000;
                        return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                    }
                }

                if ($request->monthly_income > 12000 && !$request->filled('coapplicant_income_proof_doc')) {
                    return $this->helper_controller->send_response('failed', "Income proof document is required", 422);
                }

                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }
            // Validation ends
            $occupation_type = $request->occupation_type;
            $current_occupation = $request->current_occupation;
            $monthly_income = $request->monthly_income;
            $expected_monthly_income = $request->expected_monthly_income;
            $other_income = $request->other_income;
            $extra_source_of_income = $request->extra_source_of_income;
            $total_yearly_income = $request->total_yearly_income;

            $userId             = $request->user()->id;
            $nextStep           = 0;
            $currentStep        = 6;

            // $isStepCleared = $this->check_if_step_is_already_cleard($userId, $borrower_id, $currentStep);
            // if($isStepCleared['step_status'] == "COMPLETED") {
            //     return response()->json([
            //         'status'=>'failed',
            //         'message'=>"This step is already completed",
            //         'next step'=> $isStepCleared['next_step'],
            //     ], 409);
            // }
            $borrower_id = $this->get_borrower_id($request->user()->id);
            $coapplicantOccupation                      = new CoapplicantOccupation;
            $coapplicantOccupation->occupation_type     = $occupation_type;
            $coapplicantOccupation->current_occupation  = $current_occupation;
            $coapplicantOccupation->driving_experience  = isset($driving_experience) ? $driving_experience : 0;
            $coapplicantOccupation->vehicle_type        = isset($vehicle_type) ? $vehicle_type : 'No Vehicle';
            $coapplicantOccupation->monthly_earning     = $monthly_income;
            $coapplicantOccupation->expected_monthly_earning = $expected_monthly_income;
            $coapplicantOccupation->extra_source_income = $other_income;
            $coapplicantOccupation->source_income_area  = $extra_source_of_income;
            $coapplicantOccupation->total_income        = $total_yearly_income;
            $coapplicantOccupation->current_city        = '';
            $coapplicantOccupation->resident_type       = $request->resident_type;
            $coapplicantOccupation->current_address     = $request->current_address;
            $coapplicantOccupation->years_living        = $request->years_living;
            $coapplicantOccupation->permanent_address   = $request->permanent_address;
            $coapplicantOccupation->borrower_id         = $borrower_id;
            if($coapplicantOccupation->save()) {

                if($request->filled('coapplicant_income_proof_doc')) {
                    $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                    if (!$kyc_docs) {
                        $kyc_docs                    = new Documents;
                        $kyc_docs->borrower_id       = $borrower_id;
                    }
                    $kyc_docs->coapplicant_income_proof_doc = $request->coapplicant_income_proof_doc;
                    $kyc_docs->save();
                }

                $application_step = [
                    'step' => $currentStep,
                    'co_applicant_occupation_id' => $coapplicantOccupation->id,
                    'details' => "Co-Applicant Occupation",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($userId, $borrower_id, $application_step);

                $this->logStep($borrower_id, $currentStep, 'Co-Applicant Occupation', 'b2c');

                DB::commit();
                return response()->json([
                    'status'=>'success',
                    'message'=>"Coapplicant occupation details stored successfully",
                    'data'=> ''
                ], 201);
            } else {
                DB::rollBack();
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Failed to store coapplicant occupation details",
                ], 422);
            }

        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred ". $e->getMessage(), 422);
        }
    }

    public function skip_guarantor(Request $request) {
        try {
            // skip guarantor
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            date_default_timezone_set('Asia/Kolkata');
            DB::beginTransaction();
            
            $userId             = $request->user()->id;
            $nextStep           = 0;
            $currentStep        = 7;
            $borrower_id        = $this->get_borrower_id($request->user()->id);
            $borrower_details   = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
            if(!$borrower_details) {
                return $this->send_response('failed', "Invalid request, borrower not found", 422);
            }

            /**
             * Lender loan settings part start
             * added on 03-02-2025
             */
            $loan_basic_details = LoanBasicDetails::find($borrower_id);
            if(!$loan_basic_details) {
                return $this->send_response("failed", "Invalid request", 422);
            }

            $setting = null;
            if (!empty($loan_basic_details->lender_id)) {
                $setting = LenderLoanSetting::where('lender_id', $loan_basic_details->lender_id)->first();
                // if ($setting?->guarantor_step === 'required') {
                //     return $this->send_response("failed", "Guarantor step is required, cannot skip.", 422);
                // }
            }

            if(!$setting) {
                $lenderBorrowerGroup = LenderBorrowerGroup::where('borrower_id', $borrower_id)->first();
                if($lenderBorrowerGroup) {
                    $setting = LenderLoanSetting::where('lender_id', $lenderBorrowerGroup->lender_id)->first();
                }
            }
            // Lender loan settings part end

            if ($loan_basic_details->product_id != 4 && $setting?->guarantor_step === 'required') {
                return $this->send_response('failed', "Guarantor is required", 422);
            }

            if(!isset($input['skip_guarantor']) || empty($input['skip_guarantor'])) {
                $errorCount++;
                $errorArray['skip_guarantor'] = "Skip consent is required";
                return $this->send_response("failed", $errorArray['skip_guarantor'], 422);
            }
            
            $borrower_details->skip_guarantor = true;
            $borrower_details->save();
                
            $application_step = [
                'step' => $currentStep,
                'loan_basic_details_id' => $borrower_details->id,
                'details' => "Guarantor Details Skipped",
                'status' => "COMPLETED",
            ];
            
            $this->updateApplicationStep($userId, $borrower_id, $application_step);
            
            $this->logStep($borrower_id, $currentStep, 'Guarantor Details Skipped', 'b2c');

            $currentStep = 8;
            $application_step = [
                'step' => $currentStep,
                'loan_basic_details_id' => $borrower_details->id,
                'details' => "Guarantor Occupation Details Skipped",
                'status' => "COMPLETED",
            ];
            
            $this->updateApplicationStep($userId, $borrower_id, $application_step);
            
            $this->logStep($borrower_id, $currentStep, 'Guarantor Occupation Details Skipped', 'b2c');

            DB::commit();
            return $this->send_response('success', "Guarantor details skipped successfully", 201);
            
        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function skip_iot_step(Request $request) {
        try {
            // skip guarantor
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            date_default_timezone_set('Asia/Kolkata');
            
            if(!isset($input['skip_iot']) || empty($input['skip_iot'])) {
                $errorCount++;
                $errorArray['skip_iot'] = "Skip consent is required";
                return $this->send_response("failed", $errorArray['skip_iot'], 422);
            }
            
            DB::beginTransaction();
            $userId             = $request->user()->id;
            $nextStep           = 0;
            $currentStep        = 29;
            $borrower_id        = $this->get_borrower_id($request->user()->id);
            $loan_details       = LoanBasicDetails::find($borrower_id);
            if(!$loan_details) {
                return $this->send_response("failed", "Loan details not found", 422);
            }
            
            $product_id = $loan_details->product_id;
            
            if($product_id != '4') {
                return $this->send_response("failed", "Unable to proceed with this request", 422);
            }
                
            $application_step = [
                'step' => 29,
                'loan_basic_details_id' => $loan_details->id,
                'details' => "IOT Details Skipped",
                'status' => "COMPLETED",
            ];
            
            $this->updateApplicationStep($userId, $borrower_id, $application_step);

            $this->logStep($borrower_id, 29, 'IOT Details Skipped', 'b2c');

            DB::commit();
            return $this->send_response('success', "IOT details skipped successfully", 201);
            
        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function storeGuarantorDetails(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            date_default_timezone_set('Asia/Kolkata');
            $errorCount = 0;
            $step = '';

            DB::beginTransaction();
            $borrower_id = $this->get_borrower_id($request->user()->id);
            $loan_details       = LoanBasicDetails::find($borrower_id);
            if(!$loan_details) {
                return $this->send_response("failed", "Loan details not found", 422);
            }

            $product_id = $loan_details->product_id;

            // Validation starts
                // if (!isset($request->aadhar_number) || empty($request->aadhar_number)) {
                //     $errorCount++;
                //     $errorArray['aadhar_number'] = "Aadhar number is required";
                //     return $this->send_response("failed", $errorArray['aadhar_number'], 422);
                // } elseif (!preg_match('/^[0-9]{12}$/', $request->aadhar_number)) {
                //     $errorCount++;
                //     $errorArray['aadhar_number'] = "Invalid Aadhar number format";
                //     return $this->send_response("failed", $errorArray['aadhar_number'], 422);
                // }

                // if(!isset($request->is_aadhar_verified) || empty($request->is_aadhar_verified)) {
                //     $errorCount++;
                //     $errorArray['aadhar_number'] = "Aadhar verification is required";
                //     return $this->send_response("failed", "Aadhar verification is required", 422);
                // } else {
                //     if($request->is_aadhar_verified == 0) {
                //         $errorCount++;
                //         $errorArray['aadhar_number'] = "Aadhar must be validated";
                //         return $this->send_response("failed", "Aadhar must be validated", 422);
                //     }
                // }
            
                if (!isset($request->pan_number) || empty($request->pan_number)) {
                    $errorCount++;
                    $errorArray['pan_number'] = "Pan number is required";
                    return $this->send_response("failed", $errorArray['pan_number'], 422);
                } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $request->pan_number)) {
                    $errorCount++;
                    $errorArray['pan_number'] = "Invalid Pan number format";
                    return $this->send_response("failed", $errorArray['pan_number'], 422);
                }

                if(!isset($request->is_pan_verified) || empty($request->is_pan_verified)) {
                    $errorCount++;
                    $errorArray['pan_number'] = "Pan verification is required";
                    return $this->send_response("failed", "Pan verification is required", 422);
                } else {
                    if($request->is_pan_verified == 0) {
                        $errorCount++;
                        $errorArray['pan_number'] = "Pan must be validated";
                        return $this->send_response("failed", "Pan must be validated", 422);
                    }
                }
            
                if (!isset($request->full_name) || empty($request->full_name)) {
                    $errorCount++;
                    $errorArray['full_name'] = "Full name is required";
                    return $this->send_response("failed", $errorArray['full_name'], 422);
                }
            
                // if (!isset($request->dob) || empty($request->dob)) {
                //     $errorCount++;
                //     $errorArray['dob'] = "Date of birth is required";
                //     return $this->send_response("failed", $errorArray['dob'], 422);
                // } elseif (!strtotime($request->dob)) {
                //     $errorCount++;
                //     $errorArray['dob'] = "Invalid date of birth";
                //     return $this->send_response("failed", $errorArray['dob'], 422);
                // } else {
                //     // Calculate age
                //     date_default_timezone_set('Asia/Kolkata');
                //     $dobTimestamp = strtotime($request->dob);
                //     $age = date('Y') - date('Y', $dobTimestamp);
                //     if (date('md') < date('md', $dobTimestamp)) {
                //         $age--;
                //     }
                //     if ($age < 21) {
                //         $errorCount++;
                //         $errorArray['dob'] = "Age must be greater than 21";
                //         return $this->send_response("failed", $errorArray['dob'], 422);
                //     }
                //     if ($age > 55) {
                //         $errorCount++;
                //         $errorArray['dob'] = "Age must be less than 56";
                //         return $this->send_response("failed", "Age must be less than 56", 422);
                //     }
                // }
                if (!isset($request->dob) || empty($request->dob)) {
                    $errorCount++;
                    $errorArray['dob'] = "Date of birth is required";
                    return $this->send_response("failed", $errorArray['dob'], 422);
                } else {
                    $dobRaw = trim($request->dob);
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

                    if (!$dobDateTime) {
                        $errorCount++;
                        $errorArray['dob'] = "Invalid date of birth";
                        return $this->send_response("failed", $errorArray['dob'], 422);
                    }

                    // Calculate age
                    $today = new DateTime();
                    $age = $today->diff($dobDateTime)->y;

                    if ($age < 18 || $age > 55) {
                        $errorCount++;
                        $errorArray['dob'] = "Age must be within 18 to 55";
                        return $this->send_response("failed", $errorArray['dob'], 422);
                    }
                }
            
                if (!isset($request->gender) || empty($request->gender)) {
                    $errorCount++;
                    $errorArray['gender'] = "Gender is required";
                    return $this->send_response("failed", $errorArray['gender'], 422);
                }
            
                if (!isset($request->mobile_number) || empty($request->mobile_number)) {
                    $errorCount++;
                    $errorArray['mobile_number'] = "Mobile number is required";
                    return $this->send_response("failed", $errorArray['mobile_number'], 422);
                } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $request->mobile_number)) {
                    $errorCount++;
                    $errorArray['mobile_number'] = "Invalid mobile number format";
                    return $this->send_response("failed", $errorArray['mobile_number'], 422);
                }

                if (isset($request->alternative_mobile_number) && !empty($request->alternative_mobile_number) && !preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $request->alternative_mobile_number)) {
                    $errorCount++;
                    $errorArray['alternative_mobile_number'] = "Invalid alternative mobile number format";
                    return $this->send_response("failed", $errorArray['alternative_mobile_number'], 422);
                }
            
                if (!isset($request->email) || empty($request->email)) {
                    $errorCount++;
                    $errorArray['email'] = "Email ID is required";
                    return $this->send_response("failed", $errorArray['email'], 422);
                } elseif (!filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
                    $errorCount++;
                    $errorArray['email'] = "Invalid email format";
                    return $this->send_response("failed", $errorArray['email'], 422);
                }

                if (!isset($request->father_name) || empty($request->father_name)) {
                    $errorCount++;
                    $errorArray['father_name'] = "Father's name is required";
                    return $this->send_response("failed", $errorArray['father_name'], 422);
                }

                $validator = Validator::make($request->all(), [
                    'aadhar_front'             => 'required|string',
                    'aadhar_back'              => 'required|string',
                    'pan_doc'              => 'required|string',
                ]);

                if ($validator->fails()) {
                    return $this->send_response('failed', $validator->errors()->first(), 422);
                }

                if($loan_details->status == 'REJECTED') {
                    return $this->send_response('failed', "Invalid request, the borrower is already rjected", 422);
                }

                if($loan_details->status == 'CANCELLED') {
                    return $this->send_response('failed', "Your application is on hold, please contact Finayo", 422);
                }

                // $aadhar_details = VendorServiceApi::where('borrower_id', $borrower_id)
                //                         ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
                //                         ->where('applicant_type', 'GUARANTOR')
                //                         ->where('status', 'VALID')
                //                         ->latest()->first();
                // if(!$aadhar_details) {
                //     return $this->send_response('failed', "Verify aadhar first", 422);
                // }
                // $aadharData = $aadhar_details->response;
                // $aadharData = base64_decode($aadharData);
                // $aadharData = unserialize($aadharData);
                // $aadharData = json_decode($aadharData);
                // if(isset($aadharData->model->dob) && !empty($aadharData->model->dob)) {
                //     $aadharDob = date('Y-m-d', strtotime($aadharData->model->dob));
                // }

                $pan_details = VendorServiceApi::where('borrower_id', $borrower_id)
                                        ->where('api_flag', 'PAN_DETAILS_VALIDATION')
                                        ->where('applicant_type', 'GUARANTOR')
                                        ->where('status', 'VALID')
                                        ->latest()->first();

                $panData = $pan_details->response;
                $panData = base64_decode($panData);
                $panData = unserialize($panData);
                $panData = json_decode($panData);
                if(isset($panData->result->dob) && !empty($panData->result->dob)) {
                    $panDob = $panData->result->dob;
                }

                if(isset($aadharDob) && !empty($aadharDob) && isset($panDob) && !empty($panDob)) {
                    $dob1 = new DateTime($aadharDob);
                    $dob2 = DateTime::createFromFormat('d/m/Y', $panDob);
                    $diffDays = abs($dob1->diff($dob2)->days);
                        if($diffDays > 89 && $diffDays < 1096) {
                            if(!$request->filled('dob_declaration')) {
                                return $this->send_response('failed', "DOB declaration is required", 422);
                            }
                        } elseif ($diffDays >= 1096) {
                            return $this->send_response('failed', "Date of birth difference in aadhar and pan is more than 3 years, please change the Guarantor", 422);
                        }
                }
            
                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }
            // Validation ends

            if ($gate = $this->require_digilocker_verified($borrower_id, 'GUARANTOR')) {
                return $gate;
            }

            $loan_summary = LoanApplicationSummary::where('borrower_id', $borrower_id)->first();

            if (!$loan_summary) {
                return $this->send_response("failed", "Invalid request", 422);
            }

            // Common rejection message
            $rejectionMsg = "You are not eligible for a loan. Please consider reapplying after few months.";

            // Eligibility checks
            $isLowCibil   = !empty($loan_summary->guarantor_cibil_score) && $loan_summary->guarantor_cibil_score > 10 && $loan_summary->guarantor_cibil_score < 650;
            $hasDpd30     = !empty($loan_summary->guarantor_dpd_30) && $loan_summary->guarantor_dpd_30 > 0;
            $hasDpd90     = !empty($loan_summary->guarantor_dpd_90) && $loan_summary->guarantor_dpd_90 > 0;

            if ($isLowCibil || $hasDpd30 || $hasDpd90) {
                return $this->send_response("failed", $rejectionMsg, 422);
            }

            $aadhar_number              = $request->aadhar_number ?? '';
            $pan_number                 = $request->pan_number;
            $full_name                  = $request->full_name;
            $dob                        = date('Y-m-d', strtotime($request->dob));
            $gender                     = ucfirst(strtolower($request->gender));
            $mobile_number              = $request->mobile_number;
            $relation_with_borrower     = $request->relation_with_borrower;
            $email                      = $request->email;
            $father_name                = $request->father_name;

            if($request->filled('is_pan_verified') && $request->is_pan_verified == '1') {
                $is_pan_verified = true;
                $pan_message = "Active";
            } else {
                $is_pan_verified = false;
                $pan_message = "Invalid";
            }
            if($request->filled('is_aadhar_verified') && $request->is_aadhar_verified == '1') {
                $is_aadhar_verified = true;
                
            } else {
                $is_aadhar_verified = false;
            }

            $userId             = $request->user()->id;
            $nextStep           = 0;
            $currentStep        = 7;

            // $isStepCleared = $this->check_if_step_is_already_cleard($userId, $borrower_id, $currentStep);
            // if($isStepCleared['step_status'] == "COMPLETED") {
            //     return response()->json([
            //         'status'=>'failed',
            //         'message'=>"This step is already completed",
            //         'next step'=> $isStepCleared['next_step'],
            //     ], 409);
            // }
            $checkData = [
                'pan' => $request->pan_number,
                // 'aadhar' => $request->aadhar_number,
                'contact_number' => $request->mobile_number
            ];
            $checkDuplicate = $this->check_if_kyc_data_is_similar($checkData, $borrower_id, "GUARANTOR");
            if($checkDuplicate !== true) {
                return $checkDuplicate;
            }

            $guarantor_details = new Guarantor;
            $guarantor_details->name                = $full_name;
            $guarantor_details->mobile              = $mobile_number;
            $guarantor_details->is_verified_mobile  = isset($is_mobile_number_verified) ? $is_mobile_number_verified : false;
            $guarantor_details->pan                 = $pan_number;
            $guarantor_details->is_verified_pan     = $is_pan_verified;
            $guarantor_details->pan_message         = $pan_message;
            $guarantor_details->aadhar              = $aadhar_number;
            $guarantor_details->is_verified_aadhar  = $is_aadhar_verified;
            $guarantor_details->asset_value         = 0;
            $guarantor_details->loan_amount         = 0;
            $guarantor_details->down_payment        = 0;
            $guarantor_details->dob_date            = $dob;
            $guarantor_details->father_name         = isset($father_name) ? $father_name : '';
            $guarantor_details->relation_with_borrower = isset($relation_with_borrower) ? $relation_with_borrower : '';
            $guarantor_details->gender              = $gender;
            $guarantor_details->email               = $email;
            $guarantor_details->cibil               = 0;
            $guarantor_details->postal              = 0;
            $guarantor_details->state               = '';
            $guarantor_details->country             = '';
            $guarantor_details->avtar               = '';
            $guarantor_details->alternate_number1   = $request->alternative_mobile_number ?? '';
            $guarantor_details->alternate_number2   = '';
            $guarantor_details->borrower_id         = $borrower_id;
            if($guarantor_details->save()) {


                // Overwrite aadhaar fields from the verified DigiLocker payload.
                // No-op when DigiLocker wasn't used — request values stand.
                $this->sync_digilocker_to_kyc($borrower_id, 'GUARANTOR',   $guarantor_details->id, $full_name, $mobile_number);

                // if(isset($request->name_declaration) && !empty($request->name_declaration)) {
                //     $kyc_docs       = Documents::where('borrower_id', $borrower_id)->latest()->first();
                //     if(!$kyc_docs) {
                //         $kyc_docs                    = new Documents;
                //         $kyc_docs->borrower_id       = $borrower_id;
                //     }
                //     $kyc_docs->guarantor_name_declaration = $request->name_declaration;
                // }
                $kyc_docs       = Documents::where('borrower_id', $borrower_id)->latest()->first();
                if (!$kyc_docs) {
                    $kyc_docs                    = new Documents;
                    $kyc_docs->borrower_id       = $borrower_id;
                }

                $kyc_docs->guarantor_aadhar_front = $request->aadhar_front ?? null;
                $kyc_docs->guarantor_aadhar_back = $request->aadhar_back ?? null;
                $kyc_docs->guarantor_pan_doc = $request->pan_doc ?? null;

                if ($request->filled('name_declaration') || $request->filled('dob_declaration')) {
                    $kyc_docs->guarantor_name_declaration = $request->name_declaration ?? null;
                    $kyc_docs->guarantor_dob_declaration = $request->dob_declaration ?? null;
                }
                $kyc_docs->save();

                $application_step = [
                    'step' => $currentStep,
                    'guarantor_details_id' => $guarantor_details->id,
                    'details' => "Guarantor Details",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($userId, $borrower_id, $application_step);
                $this->logStep($borrower_id, $currentStep, 'Guarantor Details', 'b2c');

                // Update Loan Application Summary
                $loan_application_summary = LoanApplicationSummary::where('user_id', $userId)
                                                    ->where('borrower_id', $borrower_id)
                                                    ->first();
                if($loan_application_summary) {

                    $loan_application_summary->guarantor_id        = $guarantor_details->id;
                    $loan_application_summary->save();
                }

                DB::commit();
                return response()->json([
                    'status'=>'success',
                    'message'=>"Guatantor details stored successfully",
                    'data'=> ''
                ], 201);
            } else {
                DB::rollBack();
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Failed to store guatantor details",
                ], 422);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred ". $e->getMessage(), 422);
        }
    }
    
    public function storeGuarantorOccupation(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            date_default_timezone_set('Asia/Kolkata');
            $errorCount = 0;
            $step = '';
            DB::beginTransaction();

            $borrower_id    = $this->get_borrower_id($request->user()->id);
            $loan_details   = LoanBasicDetails::find($borrower_id);
            if(!$loan_details) {
                return $this->send_response("failed", "Loan details not found", 422);
            }

            $product_id = $loan_details->product_id;

            // Validation starts
                if (!isset($request->resident_type) || empty($request->resident_type)) {
                    $errorCount++;
                    $errorArray['resident_type'] = "Resident type is required";
                    return $this->send_response("failed", "Resident type is required", 422);
                } elseif(!in_array($request->resident_type, ['Owned', 'Rented'])) {
                    $errorCount++;
                    $errorArray['resident_type'] = "Resident type is invalid";
                    return $this->send_response("failed", $errorArray['resident_type'], 422);
                }

                if (!isset($request->current_address) || empty($request->current_address)) {
                    $errorCount++;
                    $errorArray['current_address'] = "Current address is required";
                    return $this->send_response("failed", "Current address is required", 422);
                }
                
                if (!isset($request->permanent_address) || empty($request->permanent_address)) {
                    $errorCount++;
                    $errorArray['permanent_address'] = "Permanent address is required";
                    return $this->send_response("failed", "Permanent address is required", 422);
                }

                if (!isset($request->years_living) || empty($request->years_living)) {
                    $errorCount++;
                    $errorArray['years_living'] = "Years living is required";
                    return $this->send_response("failed", "Years living is required", 422);
                } elseif(!is_numeric($request->years_living)) {
                    $errorCount++;
                    $errorArray['years_living'] = "Years living must be numeric";
                    return $this->send_response("failed", $errorArray['years_living'], 422);
                }

                if (!isset($request->occupation_type) || empty($request->occupation_type)) {
                    $errorCount++;
                    $errorArray['occupation_type'] = "Occupation type is required";
                    return $this->send_response("failed", $errorArray['occupation_type'], 422);
                }
                if (!isset($request->current_occupation) || empty($request->current_occupation)) {
                    $errorCount++;
                    $errorArray['current_occupation'] = "Current occupation is required";
                    return $this->send_response("failed", $errorArray['current_occupation'], 422);
                }
                
                if (!isset($request->monthly_income) || empty($request->monthly_income)) {
                    $errorCount++;
                    $errorArray['monthly_income'] = "Monthly income is required";
                    return $this->send_response("failed", $errorArray['monthly_income'], 422);
                } elseif (!is_numeric($request->monthly_income)) {
                    $errorCount++;
                    $errorArray['monthly_income'] = "Monthly income must be numeric";
                    return $this->send_response("failed", $errorArray['monthly_income'], 422);
                } else {
                    if($request->monthly_income < 12000) {
                        $errorCount++;
                        $errorArray['monthly_income'] = "Monthly income must be atleast ". 12000;
                        return $this->send_response("failed", $errorArray['monthly_income'], 422);
                    } elseif($request->monthly_income > $this->max_monthly_income) {
                        $errorCount++;
                        $errorArray['monthly_income'] = "Monthly income must be less than ". $this->max_monthly_income;
                        return $this->send_response("failed", $errorArray['monthly_income'], 422);
                    }
                }

                if($product_id != 4) {

                    if (!isset($request->expected_monthly_income) || empty($request->expected_monthly_income)) {
                        $errorCount++;
                        $errorArray['expected_monthly_income'] = "Expected monthly income is required";
                        return $this->send_response("failed", $errorArray['expected_monthly_income'], 422);
                    } elseif (!is_numeric($request->expected_monthly_income)) {
                        $errorCount++;
                        $errorArray['expected_monthly_income'] = "Expected monthly income must be numeric";
                        return $this->send_response("failed", $errorArray['expected_monthly_income'], 422);
                    } else {
                        if($request->expected_monthly_income < 12000) {
                            $errorCount++;
                            $errorArray['expected_monthly_income'] = "Expected monthly income must be atleast ". 12000;
                            return $this->send_response("failed", $errorArray['expected_monthly_income'], 422);
                        } elseif($request->expected_monthly_income >= $this->max_monthly_income) {
                            $errorCount++;
                            $errorArray['expected_monthly_income'] = "Expected monthly income must be less than ". $this->max_monthly_income;
                            return $this->send_response("failed", $errorArray['expected_monthly_income'], 422);
                        }
                    }

                    if (!empty($request->other_income) && !is_numeric($request->other_income)) {
                        $errorCount++;
                        $errorArray['other_income'] = "Other income must be numeric";
                        return $this->send_response("failed", $errorArray['other_income'], 422);
                    } elseif(!empty($request->other_income) && ($request->other_income >= $this->max_monthly_income)) {
                        $errorCount++;
                        $errorArray['other_income'] = "Other income must be less than ". $this->max_monthly_income;
                        return $this->send_response("failed", $errorArray['other_income'], 422);
                    }

                    if (isset($request->other_income) && (!isset($request->extra_source_of_income) || empty($request->extra_source_of_income))) {
                        $errorCount++;
                        $errorArray['extra_source_of_income'] = "Source of income is required";
                        return $this->send_response("failed", $errorArray['extra_source_of_income'], 422);
                    }
                }

                if (!isset($request->total_yearly_income) || empty($request->total_yearly_income)) {
                    $errorCount++;
                    $errorArray['total_yearly_income'] = "Current occupation is required";
                    return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                } elseif (!is_numeric($request->total_yearly_income)) {
                    $errorCount++;
                    $errorArray['total_yearly_income'] = "Total yearly income must be numeric";
                    return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                } else {
                    if($request->total_yearly_income >= $this->max_annual_income) {
                        $errorCount++;
                        $errorArray['total_yearly_income'] = "Total yearly income must be under ". $this->max_annual_income;
                        return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                    } elseif($request->total_yearly_income < 144000) {
                        $errorCount++;
                        $errorArray['total_yearly_income'] = "Total yearly income must be atlease ". 144000;
                        return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                    }
                }

                if ($request->monthly_income > 12000 && !$request->filled('guarantor_income_proof_doc')) {
                    return $this->helper_controller->send_response('failed', "Income proof document is required", 422);
                }

                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }
            // Validation ends

            $occupation_type            = $request->occupation_type;
            $current_occupation         = $request->current_occupation;
            $monthly_income             = $request->monthly_income ?? 0;
            $expected_monthly_income    = $request->expected_monthly_income ?? $monthly_income;
            $other_income               = $request->other_income ?? 0;
            $extra_source_of_income     = $request->extra_source_of_income ?? '';
            $total_yearly_income        = $request->total_yearly_income;

            $userId             = $request->user()->id;
            $nextStep           = 0;
            $currentStep        = 8;

            // $isStepCleared = $this->check_if_step_is_already_cleard($userId, $borrower_id, $currentStep);
            // if($isStepCleared['step_status'] == "COMPLETED") {
            //     return response()->json([
            //         'status'=>'failed',
            //         'message'=>"This step is already completed",
            //         'next step'=> $isStepCleared['next_step'],
            //     ], 409);
            // }

            // $borrower_id = $this->get_borrower_id($request->user()->id);
            $gurantorOccupation                             = new GuarantorOccupation;
            $gurantorOccupation->occupation_type            = $occupation_type;
            $gurantorOccupation->current_occupation         = $current_occupation;
            $gurantorOccupation->driving_experience         = isset($driving_experience) ? $driving_experience : 0;
            $gurantorOccupation->vehicle_type               = isset($vehicle_type) ? $vehicle_type : 'No Vehicle';
            $gurantorOccupation->monthly_earning            = $monthly_income;
            $gurantorOccupation->expected_monthly_earning   = $expected_monthly_income;
            $gurantorOccupation->extra_source_income        = $other_income ?? 0;
            $gurantorOccupation->source_income_area         = $extra_source_of_income ?? '';
            $gurantorOccupation->total_income               = $total_yearly_income;
            $gurantorOccupation->current_city               = '';
            $gurantorOccupation->borrower_id                = $borrower_id;
            
            $gurantorOccupation->resident_type              = $request->resident_type;
            $gurantorOccupation->current_address            = $request->current_address;
            $gurantorOccupation->years_living               = $request->years_living;
            $gurantorOccupation->permanent_address          = $request->permanent_address;

            $resident_stability_check = $this->check_resident_stability($userId, $request->resident_type);
            if($resident_stability_check) {
                if(isset($resident_stability_check['status']) && $resident_stability_check['status'] !== true) {
                    return $this->send_response("failed", $resident_stability_check['message'], 422);
                }
            } else {
                return $this->send_response("failed", 'Borrower or guarantor or co applicant must have owned house', 422);
            }

            if($gurantorOccupation->save()) {
                if($request->filled('guarantor_income_proof_doc')) {
                
                    $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                    if (!$kyc_docs) {
                        $kyc_docs                    = new Documents;
                        $kyc_docs->borrower_id       = $borrower_id;
                    }
                    $kyc_docs->guarantor_income_proof_doc = $request->guarantor_income_proof_doc;
                    $kyc_docs->save();
                }

                $application_step = [
                    'step' => $currentStep,
                    'guarantor_occupation_id' => $gurantorOccupation->id,
                    'details' => "Guarantor Occupation",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($userId, $borrower_id, $application_step);
                $this->logStep($borrower_id, $currentStep, 'Guarantor Occupation', 'b2c');

                DB::commit();
                return response()->json([
                    'status'=>'success',
                    'message'=>"Guarantor occupation details stored successfully",
                    'data'=> ''
                ], 201);
            } else {
                DB::rollBack();
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Failed to store guarantor occupation details",
                ], 422);
            }
        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function check_resident_stability($user_id, $guarantor_resident_type) {
        $borrower_id = $this->get_borrower_id($user_id); 

        $borrower_address = BorrowerAddress::where('borrower_id', $borrower_id)->latest()->first();
        $borrower_kyc_details = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
        
        if(!$borrower_address || !$borrower_kyc_details) {
            $data = [
                'status' => false,
                'message' => 'Invalid request'
            ];

            return $data;
        }
    
        $borrower_resident = $borrower_address->resident_type;
        $skip_co_applicant  = $borrower_kyc_details->skip_co_applicant;

        if($skip_co_applicant === true) {
            if($borrower_resident != 'Owned' && $guarantor_resident_type != 'Owned') {
                $data = [
                    'status' => false,
                    'message' => 'Borrower or guarantor must have owned house'
                ];

                return $data;
            }
        } else {
            $coapplicant_address = CoapplicantOccupation::where('borrower_id', $borrower_id)->latest()->first();
            if(!$coapplicant_address) {
                $data = [
                    'status' => false,
                    'message' => 'Coapplicant not found'
                ];

                return $data;
            }

            $coapplicant_resident_type = $coapplicant_address->resident_type;
            if($borrower_resident != 'Owned' && $guarantor_resident_type != 'Owned' && $coapplicant_resident_type != 'Owned') {
                $data = [
                    'status' => false,
                    'message' => 'Borrower or guarantor or co applicant must have owned house'
                ];

                return $data;
            }
        }

        $data = [
            'status' => true,
            'message' => 'Residence stability is ok'
        ];

        return $data;   
    }

    public function loan_terms(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;

        if(!isset($input['lender_id']) || empty($input['lender_id'])) {
            $errorCount++;
            $errorArray['lender_id'] = "Lender id is required";
            return $this->send_response("failed", $errorArray['lender_id'], 422);
        }

        $lender_data = [
            [
                'lender_id' => '1',
                'description' => "Your lender is one of Finayo's partner we work together to give you seamless services",
                'lender' => "__________________",
                'process_by_finayo' => [
                    'Lorem ipsum dolor sit amet consectetur',
                    'Lorem ipsum dolor sit amet consectetur',
                    'Lorem ipsum dolor sit amet consectetur'
                ],
            ],
            [
                'lender_id' => '2',
                'description' => "Your lender is one of Finayo's partner we work together to give you seamless services",
                'lender' => "________________",
                'process_by_finayo' => [
                    'Lorem ipsum dolor sit amet consectetur',
                    'Lorem ipsum dolor sit amet consectetur',
                    'Lorem ipsum dolor sit amet consectetur'
                ],
            ],
            [
                'lender_id' => '3',
                'description' => "Your lender is one of Finayo's partner we work together to give you seamless services",
                'lender' => "____________________",
                'process_by_finayo' => [
                    'Lorem ipsum dolor sit amet consectetur',
                    'Lorem ipsum dolor sit amet consectetur',
                    'Lorem ipsum dolor sit amet consectetur'
                ],
            ]
        ];

        $lender_result_data = '';
        $lender_found = false;
        foreach($lender_data as $lender) {
            
            if($lender['lender_id'] == $input['lender_id']) {
                $lender_found = true;
                $lender_result_data = $lender;
                break;
            }
        }
        if($lender_found == true) {
            
            return response()->json([
                'status'=>'success',
                'data'=> $lender_result_data
            ], 200);
        } else {
            return response()->json([
                'status'=>'failed',
                'message'=>'Lender not found',
                'data'=> []
            ], 422);
        }

    }

    public function lender_consent_acceptance(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        date_default_timezone_set('Asia/Kolkata');

        if(!isset($input['lender_consent']) || empty($input['lender_consent'])) {
            $errorCount++;
            $errorArray['lender_consent'] = "Lender consent required";
            return $this->send_response("failed", $errorArray['lender_consent'], 422);
        } elseif(!in_array($input['lender_consent'], ["YES", "NO"])) {
            $errorCount++;
            $errorArray['lender_consent'] = "Please enter 'YES' or 'NO'";
            return $this->send_response("failed", $errorArray['lender_consent'], 422);
        }

        if ($errorCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Invalid data",
                'error_list' => $errorArray
            ], 422);
        }

        if($input['lender_consent'] === 'YES') {
            $consent = true;
        } else {
            $consent = false;
        }
        $userId             = $request->user()->id;
        $borrower_id        = $this->get_borrower_id($request->user()->id);

        $loan_basic_details                 = LoanBasicDetails::find($borrower_id);
        $loan_basic_details->lender_consent = $consent;

        if($loan_basic_details->save()) {
            $application_step = [
                'step' => 10, //$currentStep,
                'loan_basic_details' => $loan_basic_details->id,
                'details' => "Lender Consent",
                'status' => "COMPLETED",
            ];

            $this->updateApplicationStep($userId, $borrower_id, $application_step);
            $this->logStep($borrower_id, 10, 'Lender Consent', 'b2c');

            return $this->send_response('success', "Lender Consent", 200);
        } else {
            return $this->send_response('failed', "Failed to updated consent, try again", 422);
        }
    }

    // public function store_reference_details(Request $request) {
    //     $input = $request->all();
    //     $errorArray = [];
    //     $errorCount = 0;
    //     $step = '';
    //     $currentStep = 14;
    //     date_default_timezone_set('Asia/Kolkata');
        
    //     if($input == null) {
    //         return $this->send_response("failed", "Request body is empty", 422);
    //     } else {
    //         if(!isset($input['name_1']) || empty($input['name_1'])) {
    //             $errorCount++;
    //             $errorArray['name_1'] = "Reference name is required";
    //             return $this->send_response("failed", $errorArray['name_1'], 422);
    //         }
            
    //         if (!isset($input['mobile_1']) || empty($input['mobile_1'])) {
    //             $errorCount++;
    //             $errorArray['mobile_1'] = "Mobile number is required";
    //             return $this->send_response("failed", $errorArray['mobile_1'], 422);
    //         } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $input['mobile_1'])) {
    //             $errorCount++;
    //             $errorArray['mobile_1'] = "Invalid mobile number format";
    //             return $this->send_response("failed", $errorArray['mobile_1'], 422);
    //         }
            
    //         if(!isset($input['relation_1']) || empty($input['relation_1'])) {
    //             $errorCount++;
    //             $errorArray['relation_1'] = "Relation is required";
    //             return $this->send_response("failed", $errorArray['relation_1'], 422);
    //         }
            
    //         if(!isset($input['address_1']) || empty($input['address_1'])) {
    //             $errorCount++;
    //             $errorArray['address_1'] = "Address is required";
    //             return $this->send_response("failed", $errorArray['address_1'], 422);
    //         }

    //         if(!isset($input['name_2']) || empty($input['name_2'])) {
    //             $errorCount++;
    //             $errorArray['name_2'] = "Reference name is required";
    //             return $this->send_response("failed", $errorArray['name_2'], 422);
    //         }
            
    //         if (!isset($input['mobile_2']) || empty($input['mobile_2'])) {
    //             $errorCount++;
    //             $errorArray['mobile_2'] = "Mobile number is required";
    //             return $this->send_response("failed", $errorArray['mobile_2'], 422);
    //         } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $input['mobile_2'])) {
    //             $errorCount++;
    //             $errorArray['mobile_2'] = "Invalid mobile number format";
    //             return $this->send_response("failed", $errorArray['mobile_2'], 422);
    //         }
            
    //         if(!isset($input['relation_2']) || empty($input['relation_2'])) {
    //             $errorCount++;
    //             $errorArray['relation_2'] = "Relation is required";
    //             return $this->send_response("failed", $errorArray['relation_2'], 422);
    //         }
            
    //         if(!isset($input['address_2']) || empty($input['address_2'])) {
    //             $errorCount++;
    //             $errorArray['address_2'] = "Address is required";
    //             return $this->send_response("failed", $errorArray['address_2'], 422);
    //         }
    //     }
        
    //     if ($errorCount > 0) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => "Invalid data",
    //             'error_list' => $errorArray
    //         ], 422);
    //     }
    //     $short_link_1 = $this->generate_short_url_link();
            
    //     $short_link_2 = $this->generate_short_url_link();

    //     $userId                                     = $request->user()->id;
    //     $borrower_id                                = $this->get_borrower_id($request->user()->id);
    //     $reference_details                          = new BorrowerReferenceDetails;
    //     $reference_details->borrower_id             = $borrower_id;
    //     $reference_details->reference_1             = $input['name_1'];
    //     $reference_details->mobile_1                = $input['mobile_1'];
    //     $reference_details->relation_1              = $input['relation_1'];
    //     $reference_details->address_1               = $input['address_1'];
    //     $reference_details->is_verified_mobile_1    = false;
    //     $reference_details->is_verified_1           = false;
    //     $reference_details->shortend_url_1          = $short_link_1;
    //     $reference_details->reference_2             = $input['name_2'];
    //     $reference_details->mobile_2                = $input['mobile_2'];
    //     $reference_details->relation_2              = $input['relation_2'];
    //     $reference_details->address_2               = $input['address_2'];
    //     $reference_details->is_verified_mobile_2    = false;
    //     $reference_details->is_verified_2           = false;
    //     $reference_details->shortend_url_2          = $short_link_2;
    //     $link_1 = 'https://insta.finayo.tech/reference/'.$short_link_1;
    //     $link_2 = 'https://insta.finayo.tech/reference/'.$short_link_2;
        
    //     if($reference_details->save()) {
    //         try {
    //             $mobile_1 = '+91'. $input['mobile_1'];
    //             $mobile_2 = '+91'. $input['mobile_2'];
    //             $message_1 = "Click the link to verify as a reference in Finayo. ". $link_1;
    //             $message_2 = "Click the link to verify as a reference in Finayo. ". $link_2;
    //             $this->twilio->sendSms($mobile_1, $message_1);
    //             $this->twilio->sendSms($mobile_2, $message_2);

    //             $application_step = [
    //                 'step' => $currentStep,
    //                 'reference_details_id' => $reference_details->id,
    //                 'details' => "Reference Details",
    //                 'status' => "COMPLETED",
    //             ];

    //             $this->updateApplicationStep($userId, $borrower_id, $application_step);

    //             return response()->json([
    //                 'status' => 'success',
    //                 'message' => 'Click the link to verify as a reference in Finayo.',
    //                 'link_1' => $link_1,
    //                 'link_2' => $link_2,
    //                 'step' => $step
    //             ], 200);
    //         } catch (\Exception $e) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => $e->getMessage()
    //             ], 403);
    //         }

    //         return response()->json([
    //             'status'=>'success',
    //             'message'=>"Reference details details stored successfully",
    //             'data'=> ''
    //         ], 201);
    //     } else {
    //         return response()->json([
    //             'status'=>'failed',
    //             'message'=>"Failed to store Reference details details",
    //         ], 422);
    //     }
    // }
    public function store_reference_details(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            $currentStep = 14;
            date_default_timezone_set('Asia/Kolkata');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            }

            $userId             = $request->user()->id;
            $borrower_id        = $this->get_borrower_id($request->user()->id);
            $loan_details       = LoanBasicDetails::find($borrower_id);
            if(!$loan_details) {
                return $this->send_response("failed", "Loan details not found", 422);
            }
            $product_id = $loan_details->product_id;

            if(!isset($input['name_1']) || empty($input['name_1'])) {
                $errorCount++;
                $errorArray['name_1'] = "Reference name is required";
                return $this->send_response("failed", $errorArray['name_1'], 422);
            }
            
            if (!isset($input['mobile_1']) || empty($input['mobile_1'])) {
                $errorCount++;
                $errorArray['mobile_1'] = "Mobile number is required";
                return $this->send_response("failed", $errorArray['mobile_1'], 422);
            } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $input['mobile_1'])) {
                $errorCount++;
                $errorArray['mobile_1'] = "Invalid mobile number format";
                return $this->send_response("failed", $errorArray['mobile_1'], 422);
            }
            
            if(!isset($input['relation_1']) || empty($input['relation_1'])) {
                $errorCount++;
                $errorArray['relation_1'] = "Relation is required";
                return $this->send_response("failed", $errorArray['relation_1'], 422);
            }
            
            if(!isset($input['address_1']) || empty($input['address_1'])) {
                $errorCount++;
                $errorArray['address_1'] = "Address is required";
                return $this->send_response("failed", $errorArray['address_1'], 422);
            }

            if($product_id != 4) {
                // battery case
                if(!isset($input['name_2']) || empty($input['name_2'])) {
                    $errorCount++;
                    $errorArray['name_2'] = "Reference name is required";
                    return $this->send_response("failed", $errorArray['name_2'], 422);
                }
                
                if (!isset($input['mobile_2']) || empty($input['mobile_2'])) {
                    $errorCount++;
                    $errorArray['mobile_2'] = "Mobile number is required";
                    return $this->send_response("failed", $errorArray['mobile_2'], 422);
                } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $input['mobile_2'])) {
                    $errorCount++;
                    $errorArray['mobile_2'] = "Invalid mobile number format";
                    return $this->send_response("failed", $errorArray['mobile_2'], 422);
                }
                
                if(!isset($input['relation_2']) || empty($input['relation_2'])) {
                    $errorCount++;
                    $errorArray['relation_2'] = "Relation is required";
                    return $this->send_response("failed", $errorArray['relation_2'], 422);
                }
                
                if(!isset($input['address_2']) || empty($input['address_2'])) {
                    $errorCount++;
                    $errorArray['address_2'] = "Address is required";
                    return $this->send_response("failed", $errorArray['address_2'], 422);
                }

                $short_link_2 = $this->generate_short_url_link();
                $link_2 = 'https://insta.finayo.tech/reference/'.$short_link_2;
                $mobile_2 = '+91'. $input['mobile_2'];
                $message_2 = "Click the link to verify as a reference in Finayo. ". $link_2;

            }

            /**
             * Reference unique validation start
             * Created on 08-11-2024
             */

            // 1. The given number should not match any borrower's, co-applicant's, or guarantor's number.
            $numbers_used_in_this_application = [];

            // Get borrower number
            $borrower_kyc = BorrowerKyc::where('borrower_id', $borrower_id)->first();
            if (!$borrower_kyc) {
                return $this->helper_controller->send_response('failed', 'Invalid request', 422);
            }

            // Collect all relevant phone numbers
            $numbers_used_in_this_application[] = $borrower_kyc->mobile;

            // Add co-applicant's number if it exists
            $coapplicant_kyc = CoapplicantKyc::where('borrower_id', $borrower_id)->first();
            if ($coapplicant_kyc) {
                $numbers_used_in_this_application[] = $coapplicant_kyc->mobile;
            }

            // Add guarantor's number if it exists
            $guarantor_kyc = Guarantor::where('borrower_id', $borrower_id)->first();
            if ($guarantor_kyc) {
                $numbers_used_in_this_application[] = $guarantor_kyc->mobile;
            }

            // Check if the input number is already used in the current application
            if (in_array($input['mobile_1'], $numbers_used_in_this_application, true)) {
                return $this->helper_controller->send_response('failed', "Borrower's, co-applicant's, or guarantor's number cannot be used as a reference", 422);
            }

            // 2. Ensure the number is a unique reference number (hasn't been used in previous applications)
            $reference_exists = BorrowerReferenceDetails::where('mobile_1', $input['mobile_1'])
                ->orWhere('mobile_2', $input['mobile_1'])
                ->exists();

            if ($reference_exists) {
                $response_text = $input['mobile_1'] . " has already been used in another application";
                return $this->helper_controller->send_response('failed', $response_text, 422);
            }

            if(isset($input['mobile_2']) && !empty($input['mobile_2'])) {
                if (in_array($input['mobile_2'], $numbers_used_in_this_application, true)) {
                    return $this->helper_controller->send_response('failed', "Borrower's, co-applicant's, or guarantor's number cannot be used as a reference", 422);
                }
    
                // 2. Ensure the number is a unique reference number (hasn't been used in previous applications)
                $reference_exists = BorrowerReferenceDetails::where('mobile_1', $input['mobile_2'])
                    ->orWhere('mobile_2', $input['mobile_2'])
                    ->exists();
    
                if ($reference_exists) {
                    $response_text = $input['mobile_2'] . " has already been used in another application";
                    return $this->helper_controller->send_response('failed', $response_text, 422);
                }
            }

            /**
             * Reference unique validation end.
             */
            
            $short_link_1 = $this->generate_short_url_link();
            $link_1 = 'https://insta.finayo.tech/reference/'.$short_link_1;
            $mobile_1 = '+91'. $input['mobile_1'];
            $message_1 = "Click the link to verify as a reference in Finayo. ". $link_1;
        
            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }
            
            DB::beginTransaction();
            $reference_details = BorrowerReferenceDetails::create([
                'borrower_id' => $borrower_id,
                'reference_1' => $input['name_1'],
                'mobile_1' => $input['mobile_1'],
                'relation_1' => $input['relation_1'],
                'address_1' => $input['address_1'],
                'is_verified_1' => true,
                'shortend_url_1' => $short_link_1,
                'reference_2' => $input['name_2'] ?? '',
                'mobile_2' => $input['mobile_2'] ?? '',
                'relation_2' => $input['relation_2'] ?? '',
                'address_2' => $input['address_2'] ?? '',
                'shortend_url_2' => $short_link_2 ?? '',
                'is_verified_2' => true,
            ]);
            try {
                // $update_reference_details = BorrowerReferenceDetails::find($reference_details);
                // if($update_reference_details) {

                //     $update_reference_details->is_verified_mobile_1    = false;
                //     $update_reference_details->is_verified_1           = false;

                //     $update_reference_details->is_verified_mobile_2    = false;
                //     $update_reference_details->is_verified_2           = false;
                //     $update_reference_details->save();
                // }                
                
                // $this->twilio->sendSms($mobile_1, $message_1);
            
                if ($product_id != 4) {
                    //  Battery case
                    // $this->twilio->sendSms($mobile_2, $message_2);
                }
                
                $application_step = [
                    'step' => $currentStep,
                    'reference_details_id' => $reference_details->id,
                    'details' => "Reference Details",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($userId, $borrower_id, $application_step);
                
                $this->logStep($borrower_id, $currentStep, 'Reference Details', 'b2c');
                DB::commit();
                if($product_id == 4) {
                    return response()->json([
                        'status' => 'success',
                        'message' => 'Click the link to verify as a reference in Finayo.',
                        'link_1' => $link_1
                    ], 200);

                } else {
                    return response()->json([
                        'status' => 'success',
                        'message' => 'Click the link to verify as a reference in Finayo.',
                        'link_1' => $link_1,
                        'link_2' => $link_2 ?? '',
                    ], 200);
                }

            } catch (Exception $e) {
                
                DB::rollBack();
                return $this->send_response('failed', $e->getMessage(), 422);
            }
        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function generate_short_url_link() {
        // $service_api = new ServiceApiController;
        
        do {
            $short_code = $this->generate_random_string(6);
        } while(
                BorrowerReferenceDetails::where('shortend_url_1', $short_code)
                    ->orWhere('shortend_url_1', $short_code)
                    ->exists()
        );

        return $short_code;
    }

    // public function get_reference_list(Request $request) {
    //     $userId         = $request->user()->id;
    //     $borrower_id    = $this->get_borrower_id($request->user()->id);
    //     $reference      = BorrowerReferenceDetails::where('borrower_id', $borrower_id)->latest()->first();
    //     $currentStep    = 15;
    //     if($reference) {
    //         if($reference->is_verified_1 == true && $reference->is_verified_2 == true) {
    //             $application_step = [
    //                 'step' => $currentStep,
    //                 'reference_details_id' => $reference->id,
    //                 'details' => "Reference Verification",
    //                 'status' => "COMPLETED",
    //             ];

    //             $this->updateApplicationStep($userId, $borrower_id, $application_step);
    //         }

    //         return response()->json([
    //             'status'=>'success',
    //             'message'=>"Reference details fetched successfully",
    //             'data'=> [
    //                 'reference_1' => $reference->reference_1,
    //                 'mobile_1' => $reference->mobile_1,
    //                 'relation_1' => $reference->relation_1,
    //                 'address_1' => $reference->address_1,
    //                 'is_verified_mobile_1' => $reference->is_verified_mobile_1,
    //                 'is_reference_verified_1' => $reference->is_verified_1,
    //                 'reference_2' => $reference->reference_2,
    //                 'mobile_2' => $reference->mobile_2,
    //                 'relation_2' => $reference->relation_2,
    //                 'address_2' => $reference->address_2,
    //                 'is_verified_mobile_2' => $reference->is_verified_mobile_2,
    //                 'is_reference_verified_2' => $reference->is_verified_2,
    //             ]
    //         ], 200);
    //     } else {
    //         return response()->json([
    //             'status'=>'failed',
    //             'message'=>"Reference not found",
    //         ], 422);
        
    //     }
    // }
    public function get_reference_list(Request $request) {
        try {
            $userId         = $request->user()->id;
            $borrower_id    = $this->get_borrower_id($request->user()->id);
            $currentStep    = 15;
            
            $loan_details       = LoanBasicDetails::find($borrower_id);
            if(!$loan_details) {
                return $this->send_response("failed", "Loan details not found", 422);
            }
            $product_id = $loan_details->product_id;
            
            $reference      = BorrowerReferenceDetails::where('borrower_id', $borrower_id)->latest()->first();
            if($reference) {
                if($product_id == 4) {
                    // battery case
                    if($reference->is_verified_1 == true) {
                        $application_step = [
                            'step' => $currentStep,
                            'reference_details_id' => $reference->id,
                            'details' => "Reference Verification",
                            'status' => "COMPLETED",
                        ];
                        
                        $this->updateApplicationStep($userId, $borrower_id, $application_step);

                        $this->logStep($borrower_id, $currentStep, 'Reference Verification', 'b2c');
                    }

                    $resultData = [
                        'reference_1' => $reference->reference_1,
                        'mobile_1' => $reference->mobile_1,
                        'relation_1' => $reference->relation_1,
                        'address_1' => $reference->address_1,
                        'is_verified_mobile_1' => $reference->is_verified_mobile_1,
                        'is_reference_verified_1' => $reference->is_verified_1
                    ];
                } else {

                    if($reference->is_verified_1 == true && $reference->is_verified_2 == true) {
                        $application_step = [
                            'step' => $currentStep,
                            'reference_details_id' => $reference->id,
                            'details' => "Reference Verification",
                            'status' => "COMPLETED",
                        ];
                        $this->updateApplicationStep($userId, $borrower_id, $application_step);
                        
                        $this->logStep($borrower_id, $currentStep, 'Reference Verification', 'b2c');
                    }
                    $resultData = [
                        'reference_1' => $reference->reference_1,
                        'mobile_1' => $reference->mobile_1,
                        'relation_1' => $reference->relation_1,
                        'address_1' => $reference->address_1,
                        'is_verified_mobile_1' => $reference->is_verified_mobile_1,
                        'is_reference_verified_1' => $reference->is_verified_1,
                        'reference_2' => $reference->reference_2,
                        'mobile_2' => $reference->mobile_2,
                        'relation_2' => $reference->relation_2,
                        'address_2' => $reference->address_2,
                        'is_verified_mobile_2' => $reference->is_verified_mobile_2,
                        'is_reference_verified_2' => $reference->is_verified_2,
                    ];
                }

                return $this->send_response('success', "Reference details fetched successfully", 200, $resultData);
            } else {
                return $this->send_response('failed', "Reference not found", 422);
            }
        } catch (Exception $e) {
            return $this->send_response('failed', $e->getMessage(), 422);
        }
    }

    // public function store_field_verification_details(Request $request) {
    //     $input = $request->all();
    //     $errorArray = [];
    //     $errorCount = 0;
    //     $step = '';
    //     $currentStep = 16;
    //     date_default_timezone_set('Asia/Kolkata');
        
    //     if($input == null) {
    //         return $this->send_response("failed", "Request body is empty", 422);
    //     } else {
    //         if(!isset($input['name']) || empty($input['name'])) {
    //             $errorCount++;
    //             $errorArray['name'] = "Name is required";
    //             return $this->send_response("failed", $errorArray['name'], 422);
    //         }
            
    //         if (!isset($input['mobile']) || empty($input['mobile'])) {
    //             $errorCount++;
    //             $errorArray['mobile'] = "Mobile number is required";
    //             return $this->send_response("failed", $errorArray['mobile'], 422);
    //         } elseif (!preg_match('/^[6-9]\d{9}$/', $input['mobile'])) {
    //             $errorCount++;
    //             $errorArray['mobile'] = "Invalid mobile number format";
    //             return $this->send_response("failed", $errorArray['mobile'], 422);
    //         }

    //         if ($errorCount > 0) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => "Invalid data",
    //                 'error_list' => $errorArray
    //             ], 422);
    //         }

    //         $validator = Validator::make($request->all(), [
    //             'dealer_shop_id' => ['required', 'integer', 'exists:dealer_shops,id'],
    //         ]);

    //         if ($validator->fails()) {
    //             return $this->send_response('failed', $validator->errors()->first(), 422);
    //         }

    //         $userId                                             = $request->user()->id;
    //         $borrower_id                                        = $this->get_borrower_id($request->user()->id);
            
    //         // $service_api = new ServiceApiController;
    //         do {
    //             $short_link = $this->generate_random_string(6);
    //         } while(
    //             FieldInspectionDetails::where('verification_url', $short_link)
    //                     ->orWhere('verification_url', $short_link)
    //                     ->exists()
    //         );
    //         $field_inspection_details = FieldInspectionDetails::where('borrower_id', $borrower_id)->first();
    //         if(!$field_inspection_details) {
    //             $field_inspection_details = new FieldInspectionDetails;
    //         }
    //         $field_inspection_details->borrower_id              = $borrower_id;
    //         $field_inspection_details->name                     = $input['name'];
    //         $field_inspection_details->mobile                   = $input['mobile'];
    //         $field_inspection_details->is_inspection_verified   = false;
    //         $field_inspection_details->verification_url         = $short_link;
    //         $field_inspection_details->dealer_shop_id           = $request->dealer_shop_id;
    //         if($field_inspection_details->save()) {

    //             // $link = 'https://insta.finayo.tech/field-inspecction/'.$short_link;
    //             $link = 'https://play.google.com/store/apps/details?id=com.finayo_inspector';
    //             try {
    //                 $mobile = '+91'. $input['mobile'];
    //                 // $message = "Click the below link for address verification in Finayo. ". $link;
    //                 $message = "Please download the Finayo Inspector app from the link below to complete your field verification: ". $link;
    //                 // $this->twilio->sendSms($mobile, $message);

    //                 $smsResponse = $this->smsService->sendLink($input['mobile'], $message);

    //                 $application_step = [
    //                     'step' => $currentStep,
    //                     'field_inspection_details_id' => $field_inspection_details->id,
    //                     'details' => "Field Inspection Details",
    //                     'status' => "COMPLETED",
    //                 ];
    
    //                 $this->updateApplicationStep($userId, $borrower_id, $application_step);

    //                 $this->logStep($borrower_id, $currentStep, 'Field Inspection Details', 'b2c');

    //                 return response()->json([
    //                     'status' => 'success',
    //                     'message' => 'Click the link for address verification in Finayo.',
    //                     'link' => $link,
    //                     'step' => $step
    //                 ], 200);
    //             } catch (\Exception $e) {
    //                 return response()->json([
    //                     'status' => 'error',
    //                     'message' => $e->getMessage()
    //                 ], 403);
    //             }

    //             return response()->json([
    //                 'status'=>'success',
    //                 'message'=>"Field inspection details stored successfully",
    //                 'data'=> ''
    //             ], 201);
    //         } else {
    //             return response()->json([
    //                 'status'=>'failed',
    //                 'message'=>"Failed to store field inspection details",
    //             ], 422);
    //         }
    //     }
    // }

    public function store_field_verification_details(Request $request) {
        $input = $request->all();
        $step = '';
        $currentStep = 16;
        date_default_timezone_set('Asia/Kolkata');

        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        }

        if(!isset($input['name']) || empty($input['name'])) {
            return $this->send_response("failed", "Name is required", 422);
        }

        if (!isset($input['mobile']) || empty($input['mobile'])) {
            return $this->send_response("failed", "Mobile number is required", 422);
        } elseif (!preg_match('/^[6-9]\d{9}$/', $input['mobile'])) {
            return $this->send_response("failed", "Invalid mobile number format", 422);
        }

        $validator = Validator::make($request->all(), [
            'dealer_shop_id' => ['required', 'integer', 'exists:dealer_shops,id'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $userId      = $request->user()->id;
        $borrower_id = (int) $this->get_borrower_id($request->user()->id);

        // Which parties this application needs (borrower always; co-app/guarantor
        // only when borrower address is Rented AND the party exists).
        $req = $this->fi->resolveRequirements($borrower_id);

        // Borrower from request; co-applicant / guarantor from their KYC tables.
        // Hard-fail if a required party's KYC name/mobile is missing.
        $identities = [
            'borrower' => ['name' => $input['name'], 'mobile' => $input['mobile']],
        ];

        foreach (['co_applicant', 'guarantor'] as $type) {
            if (!$req[$type]) {
                continue;
            }

            $identity = $this->fi->resolvePartyIdentity($type, $borrower_id, []);
            $label    = $type === 'co_applicant' ? 'Co-applicant' : 'Guarantor';

            if (!$identity || empty(trim((string) $identity['name'])) || empty(trim((string) $identity['mobile']))) {
                return $this->send_response('failed', "{$label} KYC data (name/mobile) is incomplete.", 422);
            }

            $identities[$type] = $identity;
        }

        try {
            DB::beginTransaction();

            do {
                $short_link = $this->generate_random_string(6);
            } while (FieldInspectionDetails::where('verification_url', $short_link)->exists());

            $field_inspection_details = FieldInspectionDetails::where('borrower_id', $borrower_id)->first()
                ?? new FieldInspectionDetails;
            $field_inspection_details->borrower_id      = $borrower_id;
            $field_inspection_details->name             = $input['name'];
            $field_inspection_details->mobile           = $input['mobile'];
            $field_inspection_details->verification_url = $short_link;
            $field_inspection_details->dealer_shop_id   = $request->dealer_shop_id;
            if (!$field_inspection_details->exists) {
                $field_inspection_details->is_inspection_verified = false;
            }
            $field_inspection_details->save();

            // Create / refresh party rows (borrower from request, others from KYC),
            // then recompute the parent aggregate flag.
            $parties = $this->fi->syncParties($field_inspection_details, $identities, $req);
            $this->fi->recomputeCompletion($field_inspection_details);

            DB::commit();

            // Notify each unverified party; dedupe when a number is shared across parties.
            $link     = 'https://play.google.com/store/apps/details?id=com.finayo_inspector';
            $message  = "Please download the Finayo Inspector app from the link below to complete your field verification: " . $link;
            $notified = [];

            foreach ($parties as $party) {
                if (!$party->is_verified && !in_array($party->mobile, $notified, true)) {
                    $this->smsService->sendLink($party->mobile, $message);
                    $notified[] = $party->mobile;
                }
            }

            $this->updateApplicationStep($userId, $borrower_id, [
                'step' => $currentStep,
                'field_inspection_details_id' => $field_inspection_details->id,
                'details' => "Field Inspection Details",
                'status' => "COMPLETED",
            ]);
            $this->logStep($borrower_id, $currentStep, 'Field Inspection Details', 'b2c');

            return response()->json([
                'status'  => 'success',
                'message' => 'Click the link for address verification in Finayo.',
                'link'    => $link,
                'step'    => $step,
                'parties' => collect($parties)->map(fn ($p) => [
                    'party_type'  => $p->party_type,
                    'party_uid'   => $p->party_uid,
                    'name'        => $p->name,
                    'mobile'      => $p->mobile,
                    'is_verified' => (bool) $p->is_verified,
                ])->values(),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function send_otp_field_verification(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'mobile' => 'required|digits:10',
            ]);
        
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
            
            DB::beginTransaction();
            $fi_details = FieldInspectionDetails::where('mobile', $request->mobile)->first();
            if(!$fi_details) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }
            
            if ($fi_details->is_inspection_verified === true) {
                $result = [
                    'verified_by' => $fi_details->name,
                    'mobile' => $fi_details->mobile,
                    'verification_status' => true,
                    'verified_at' => $fi_details->verification_time
                ];
                return $this->helper_controller->send_response('success', "Field already verified", 200, $result);
            }

            if($request->mobile == '9999000000') {
                $otp = '100000';
            } else {

                do {
                    $otp = mt_rand(100000, 999999);
                } while (FieldInspectionDetails::where('otp', $otp)->exists());
            }

            // $temp_otp = substr((string) $request->mobile, -6);
            // $otp = $temp_otp;

            $fi_details->otp = $otp;
            $fi_details->save();


            $mobile = '+91' . $request->mobile;
            $message = $otp." is your Finayo field inspection login code. Please enter this code to verify your mobile number.";

            if($request->mobile != '9999000000') {
                // $this->twilio->sendSms($mobile, $message); // enable
                $smsResponse = $this->smsService->sendOtp($request->mobile, $message);

            }
            
            $result = [
                'verification_status' => false,
                'meter_verification_status' => !empty($fi_details->meter_verification_status) ? $fi_details->meter_verification_status : false
            ];

            DB::commit();
            return $this->helper_controller->send_response('success', "OTP sent successfully", 200, $result);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function verify_otp_field_verification(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'mobile' => 'required|digits:10',
                'otp' => 'required|digits:6',
            ]);
        
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
            
            DB::beginTransaction();
            $fi_details = FieldInspectionDetails::where('otp', $request->otp)->where('mobile', $request->mobile)->first();
            if(!$fi_details) {
                return $this->helper_controller->send_response('failed', "Invalid otp", 422);
            }

            do {
                $access_token = $this->generate_random_string(16);
            }  while (FieldInspectionDetails::where('access_token', $access_token)->exists());

            $fi_details->otp = null;
            $fi_details->access_token = $access_token;
            $fi_details->save();

            $result = [
                'name' => $fi_details->name,
                'mobile' => $fi_details->mobile,
                'access_token' => $access_token,
                'verification_status' => $fi_details->is_inspection_verified,
                'meter_verification_status' => !empty($fi_details->meter_verification_status) ? $fi_details->meter_verification_status : false,
            ];

            DB::commit();
            return $this->helper_controller->send_response('success', "User logged in successfully", 200, $result);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function verify_fi_access_token(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'access_token' => 'required|regex:/^[a-zA-Z0-9]+$/',
            ]);
        
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
            
            DB::beginTransaction();
            $fi_details = FieldInspectionDetails::where('access_token', $request->access_token)->first();
            if(!$fi_details) {
                return $this->helper_controller->send_response('failed', "Invalid access token", 422);
            }

            $result = [
                'access_token' => $fi_details->access_token,
                'name' => $fi_details->name,
                'mobile' => $fi_details->mobile,
                'verification_status' => $fi_details->is_inspection_verified,
                'meter_verification_status' => !empty($fi_details->meter_verification_status) ? $fi_details->meter_verification_status : false,
            ];

            DB::commit();
            return $this->helper_controller->send_response('success', "Access token is valid", 200, $result);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function store_meter_image(Request $request)
    {
        $written  = [];
        $existing = [];
        try {
            $validator = Validator::make($request->all(), [
                'access_token'     => 'required|regex:/^[a-zA-Z0-9]+$/',
                'meter_img'        => 'required|image|mimes:jpeg,png,jpg|max:2048',
                'fi_premise_img_2' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
                'fi_premise_img_3' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $fi_details = FieldInspectionDetails::where('access_token', $request->access_token)->first();
            if (!$fi_details) {
                return $this->helper_controller->send_response('failed', 'Invalid access token', 422);
            }

            // Block resubmission while pending or already approved; reject/null allows recapture.
            if ($fi_details->meter_verification_status === 'pending') {
                return $this->helper_controller->send_response('failed', 'Meter image already submitted and awaiting verification.', 409);
            }
            if ($fi_details->meter_verification_status === 'approved') {
                return $this->helper_controller->send_response('failed', 'Meter image already approved.', 409);
            }

            $loan_details = LoanBasicDetails::find($fi_details->borrower_id);
            if (!$loan_details) {
                return $this->helper_controller->send_response('failed', 'Invalid request', 422);
            }

            DB::beginTransaction();

            $disk        = Storage::disk('public');
            $storagePath = 'Borrower/' . $loan_details->borrower_id . '/Field_Inspection';

            $imageMap = [
                'fi_premise_img_2' => ['column' => 'fi_image_2', 'name' => 'fi_premise_image_2'],
                'fi_premise_img_3' => ['column' => 'fi_image_3', 'name' => 'fi_premise_image_3'],
            ];

            // every write is a create, never an overwrite — avoids chmod-on-foreign-inode EPERM
            // and leaves no orphan when the extension changes between submissions
            $stamp    = time();
            $prefixes = ['meter_image', 'fi_premise_image_2', 'fi_premise_image_3'];

            // snapshot BEFORE any write, else the new files land in $existing
            // and get deleted as "stale" after commit
            foreach ($disk->files($storagePath) as $path) {
                $basename = pathinfo($path, PATHINFO_BASENAME);
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($basename, $prefix)) {
                        $existing[] = $path;
                        break;
                    }
                }
            }

            // plus whatever the columns currently point at (covers legacy filenames)
            foreach (['meter_image', 'fi_image_2', 'fi_image_3'] as $column) {
                if (filled($fi_details->{$column})) {
                    $existing[] = preg_replace('#^storage/#', '', $fi_details->{$column});
                }
            }
            $existing = array_unique($existing);

            $meter     = $request->file('meter_img');
            $meterExt  = strtolower($meter->getClientOriginalExtension() ?: $meter->extension());
            $meterPath = $meter->storeAs($storagePath, 'meter_image_' . $stamp . '.' . $meterExt, 'public');

            if ($meterPath === false) {
                throw new \RuntimeException('Failed to store meter_img');
            }
            $written[] = $meterPath;

            foreach ($imageMap as $inputKey => $meta) {
                $image     = $request->file($inputKey);
                $extension = strtolower($image->getClientOriginalExtension() ?: $image->extension());
                $stored    = $image->storeAs($storagePath, $meta['name'] . '_' . $stamp . '.' . $extension, 'public');

                if ($stored === false) {
                    throw new \RuntimeException("Failed to store {$inputKey}");
                }

                $written[] = $stored;
                $fi_details->{$meta['column']} = 'storage/' . $stored;
            }

            $fi_details->meter_image               = 'storage/' . $meterPath;
            $fi_details->meter_verification_status = 'pending';
            $fi_details->meter_remarks             = null;
            $fi_details->meter_uploaded_at         = now();
            $fi_details->meter_verified_by         = null;
            $fi_details->meter_verified_at         = null;
            $fi_details->save();

            DB::commit();

            // commit succeeded — drop superseded files (old timestamps, different extensions)
            $stale = array_diff($existing, $written);
            if ($stale) {
                $disk->delete(array_values($stale));
            }

            return $this->helper_controller->send_response('success', 'Meter image submitted for verification.', 200, [
                'meter_verification_status' => 'pending',
                'meter_image'               => $fi_details->meter_image,
                'fi_image_2'                => $fi_details->fi_image_2,
                'fi_image_3'                => $fi_details->fi_image_3,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            // every file this request wrote is new, so all of them are orphans
            if ($written) {
                Storage::disk('public')->delete($written);
            }

            Log::error('store_meter_image failed', [
                'access_token' => $request->access_token,
                'exception'    => $e,
            ]);

            return $this->helper_controller->send_response('failed', 'Unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function get_field_inspection_status(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'access_token' => 'required|regex:/^[a-zA-Z0-9]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $fi_details = FieldInspectionDetails::where('access_token', $request->access_token)->first();
        if (!$fi_details) {
            return $this->helper_controller->send_response('failed', 'Invalid access token', 422);
        }

        $meterStatus = $fi_details->meter_verification_status ?? 'not_uploaded';

        // Stage machine
        $stage = match (true) {
            (bool) $fi_details->is_inspection_verified => 'completed',
            $meterStatus === 'approved'                => 'capture_house',
            $meterStatus === 'rejected'                => 'recapture_meter',
            $meterStatus === 'pending'                 => 'meter_pending',
            default                                    => 'capture_meter',
        };

        $nextAction = match ($stage) {
            'capture_meter', 'recapture_meter' => 'upload_meter_image',
            'meter_pending'                    => 'wait_admin_approval',
            'capture_house'                    => 'upload_house_image_with_location',
            'completed'                        => 'none',
        };

        return $this->helper_controller->send_response('success', 'Status fetched.', 200, [
            'stage'                     => $stage,
            'next_action'               => $nextAction,
            'meter_verification_status' => $meterStatus,
            'meter_remarks'             => $fi_details->meter_remarks,
            'is_inspection_verified'    => (bool) $fi_details->is_inspection_verified,
            'distance'                  => $fi_details->distance,
        ]);
    }

    public function calculate_distance_between_borrower_dealer(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'access_token' => 'required|regex:/^[a-zA-Z0-9]+$/',
                'fi_premise_img' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
                'latitude' => [
                    'required',
                    'regex:/^-?\d{1,2}\.\d{1,8}$/', // Allows up to 8 decimal places
                    'between:-90,90'
                ],
                'longitude' => [
                    'required',
                    'regex:/^-?\d{1,3}\.\d{1,8}$/', // Allows up to 8 decimal places
                    'between:-180,180'
                ],
            ]);
    
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();

            // $fi_details = FieldInspectionDetails::where('access_token', $request->access_token)->first();
            $fi_details = FieldInspectionDetails::where('access_token', $request->access_token)->lockForUpdate()->first();
            if (!$fi_details) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', "Invalid access token", 422);
            }

            if ($fi_details->meter_verification_status !== 'approved') {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Meter image not approved yet.', 422);
            }

            $loan_details = LoanBasicDetails::find($fi_details->borrower_id);
            if(!$loan_details) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            $borrower_kyc = BorrowerKyc::where('borrower_id', $fi_details->borrower_id)->first();
            if(!$borrower_kyc) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', "Invalid borrower data", 422);
            }

            // $productModel = ProductModel::where('model_unq', $loan_details->model_id)->first();
            // if (!$productModel) {
            //     DB::rollBack();
            //     return $this->helper_controller->send_response('failed', "Invalid Product Model", 422);
            // }
            // dynamic (FIN-DYP) vs legacy
            $dynamicProduct = OemDynamicProduct::where('oem_product_unq', $loan_details->model_id)->first();
            $productModel   = $dynamicProduct ? null : ProductModel::where('model_unq', $loan_details->model_id)->first();
            if (!$dynamicProduct && !$productModel) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', "Invalid Product Model", 422);
            }

            // Initialize as null
            $origin_latitude = null;
            $origin_longitude = null;

            if (filled($fi_details->dealer_shop_id)) {
                $dealer_shop = DealerShops::find($fi_details->dealer_shop_id);
                if ($dealer_shop) {
                    $origin_latitude = $dealer_shop->latitude;
                    $origin_longitude = $dealer_shop->longitude;
                }
            }

            $dealer_details = Dealer::where('dealer_unq', $loan_details->dealer_id)->first();
            if (!$dealer_details) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            // Fallback to dealer's coordinates if shop was not found or doesn't have data
            if (is_null($origin_latitude) || is_null($origin_longitude)) {
                $origin_latitude = $dealer_details->latitude;
                $origin_longitude = $dealer_details->longitude;
            }
            
            if (is_null($origin_latitude) && is_null($origin_longitude)) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', "Dealer latitude and longitude is missing", 422);
            }
            // need to get dealer lat long from dealers table

            // Origin coordinates [dealer co ordinates]          

            // destination coordinates (borrower coordinates)
            $destinationLat = $request->latitude;
            $destinationLng = $request->longitude;

            $earthRadius = 6371; // Earth's radius in kilometers

            // Convert coordinates to radians
            $origin_latitude_rad = deg2rad($origin_latitude);
            $origin_longitude_rad = deg2rad($origin_longitude);
            $destinationLat_rad = deg2rad($destinationLat);
            $destinationLng_rad = deg2rad($destinationLng);

            // Calculate the difference in coordinates
            $latDiff = $destinationLat_rad - $origin_latitude_rad;
            $lngDiff = $destinationLng_rad - $origin_longitude_rad;

            // Haversine formula
            $a = sin($latDiff / 2) * sin($latDiff / 2) +
                cos($origin_latitude_rad) * cos($destinationLat_rad) *
                sin($lngDiff / 2) * sin($lngDiff / 2);

            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

            // Distance in kilometers
            $distance = $earthRadius * $c;

            // Prepare result data
            $result = [
                'access_token' => $fi_details->access_token,
                'name' => $fi_details->name,
                'mobile' => $fi_details->mobile,
                'distance' => round($distance, 4),
                'verification_status' => false,
            ];

            // $state = preg_replace('/\s+/', ' ', strtolower(trim($borrower_kyc->state)));

            // $max_distance = 25;

            // if (
            //     in_array($state, ['madhya pradesh', 'bihar', 'uttar pradesh']) &&
            //     $loan_details->product_id === 2 &&
            //     in_array($productModel->sub_product_type, ['EKA L5-6S', 'EKA L5-3S'])
            // ) {
            //     $max_distance = 30;
            // }

            $state = preg_replace('/\s+/', ' ', strtolower(trim($borrower_kyc->state)));
            $max_distance = 25;
            if (
                in_array($loan_details->product_id, [1, 2])
            ) {
                $max_distance = 30; // 09-09-2026
            }

            if ($dynamicProduct) {
                // dynamic: distance from the matching distance rule (by pincode or state), else default 25
                $compiler = app(\App\Domain\Lending\Bre\Engine\RuleSetCompiler::class);
                $matcher  = app(\App\Domain\Lending\Bre\Engine\ConditionMatcher::class);
                $facts    = app(\App\Domain\Lending\Bre\Engine\FactExtractor::class)->normalize([
                    'state'   => $borrower_kyc->state ?? '',
                    'pincode' => (string) ($borrower_kyc->postal ?? ''),
                ]);

                $set = $compiler->compile($dynamicProduct, $dealer_details->id);

                foreach ($set->rules('constraint') as $rule) {
                    if (($rule->output['gate'] ?? null) === 'distance'
                        && $matcher->allMatch($rule->conditions, $facts)) {
                        $max_distance = (float) ($rule->output['max_km'] ?? $max_distance);
                        break;   // most-specific match wins (compiler sorts priority DESC, condition-count DESC)
                    }
                }
            } else {
                // legacy — unchanged
                // if (
                //     in_array($state, ['madhya pradesh', 'bihar', 'uttar pradesh']) &&
                //     $loan_details->product_id === 2 &&
                //     in_array($productModel->sub_product_type, ['EKA L5-6S', 'EKA L5-3S'])
                // ) {
                //     $max_distance = 30;
                // }
            }

            if(in_array($fi_details->borrower_id, ['2097'])) {
                // exception
                $max_distance = 34;
            }

            if ($distance < 0.05 || $distance > $max_distance)  {
                $fi_details->latitude = $request->latitude; 
                $fi_details->longitude = $request->longitude;
                $fi_details->verification_time = date('Y-m-d H:i:s');
                $fi_details->distance = round($distance, 4);
                $fi_details->save();
                DB::commit();
                return $this->helper_controller->send_response('failed', "Distance must be between 50 meters and {$max_distance} KM.", 422, $result);
            }

            $image = $request->file('fi_premise_img');

            // Get the original extension of the uploaded file
            $extension = $image->getClientOriginalExtension();

            // Define the new file name
            $fileName = 'fi_premise_image.' . $extension;

            // Define the storage path
            $storagePath = 'Borrower/' . $loan_details->borrower_id . '/Field_Inspection';

            // Store the image with the defined name
            $imagePath = $image->storeAs($storagePath, $fileName, 'public');

            // Generate the full URL to the stored file
            $imageUrl = 'storage/' . $imagePath;
            
            $fi_details->latitude = $request->latitude;
            $fi_details->longitude = $request->longitude;
            $fi_details->verification_time = date('Y-m-d H:i:s');
            $fi_details->is_inspection_verified = true;
            $fi_details->access_token = null;
            $fi_details->fi_image = $imageUrl;
            $fi_details->distance = round($distance, 4);
            $fi_details->save();

            $result['verification_status'] = true;

            DB::commit();
            return $this->helper_controller->send_response('success', 'Field inspection completed successfully.', 200, $result);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function field_verification(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        $currentStep = 17;
        date_default_timezone_set('Asia/Kolkata');

        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 200);
        } else {
            if(!isset($input['latitude']) || empty($input['latitude'])) {
                $errorCount++;
                $errorArray['latitude'] = "Latitude is required";
                return $this->send_response("failed", $errorArray['latitude'], 200);
            }
            
            if(!isset($input['longitude']) || empty($input['longitude'])) {
                $errorCount++;
                $errorArray['longitude'] = "Longitude is required";
                return $this->send_response("failed", $errorArray['longitude'], 200);
            }
            
            if(!isset($input['verification_code']) || empty($input['verification_code'])) {
                $errorCount++;
                $errorArray['verification_code'] = "Verification code is required";
                return $this->send_response("failed", $errorArray['verification_code'], 200);
            }
            

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 200);
            }

            $latitude = $input['latitude'];
            $longitude = $input['longitude'];
            $verification_code = $input['verification_code'];

            $field_inspection_details = FieldInspectionDetails::where('verification_url', $verification_code)->first();
            if(!$field_inspection_details){
                return response()->json([
                    'status' => 'failed',
                    'message' => "Invalid verification code",
                    'error_list' => $errorArray
                ], 200);
            }

            $field_inspection_details->longitude    = number_format($longitude, 4);
            $field_inspection_details->latitude     = number_format($latitude, 4);
            $field_inspection_details->save();

            $borrower_id = $field_inspection_details->borrower_id;
            $loan_basic_details = LoanBasicDetails::find($borrower_id);
            if($loan_basic_details) {
                $user_id = $loan_basic_details->user_id;
                $vendor_service_api = VendorServiceApi::where('user_id', $user_id)
                                        ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
                                        ->where('status', "VALID")
                                        ->latest()
                                        ->first();
                if($vendor_service_api) {
                    $response = $vendor_service_api->response;
                    $response = base64_decode($response);
                    $response = unserialize($response);
                    $data = json_decode($response, 200);

                    if($data['msg'] == 'success') {
                        $dist = $data['model']['address']['dist'];
                        $pc = intval(trim($data['model']['address']['pc']));
                        $state = $data['model']['address']['state'];

                        $result = $this->address_verification_api($input);
                        if(isset($result['status']) && $result['status'] == 'success') {
                            $api_address = $result['data']['address'];
                            $api_pincode = intval(trim($result['data']['pincode']));
                            $api_district = $result['data']['district'];
                            $api_state = $result['data']['state'];

                            // $api_pincode = 700065;
                            if($pc === $api_pincode) {
                                $field_inspection_details = FieldInspectionDetails::where('verification_url', $verification_code)->first();
                                $field_inspection_details->is_inspection_verified = true;
                                $field_inspection_details->verification_time = date('Y-m-d H:i:s');
                                $field_inspection_details->save();
                            
                                $loan_summary = LoanApplicationSummary::where('borrower_id', $borrower_id)->first();
                                if($loan_summary) {
                                    $loan_summary->field_verification = true;
                                    $loan_summary->save();
                                }

                                $application_step = [
                                    'step' => $currentStep,
                                    'field_inspection_details_id' => $field_inspection_details->id,
                                    'details' => "Field Inspection Verification",
                                    'status' => "COMPLETED",
                                ];
                                $this->updateApplicationStep($user_id, $borrower_id, $application_step);
                    
                                $this->logStep($borrower_id, $currentStep, 'Field Inspection Verification', 'b2c');

                                return response()->json([
                                    'status' => 'success',
                                    'message' => "Address verification successfull",
                                ], 200);
                            } else {
                                return response()->json([
                                    'status' => 'failed',
                                    'message' => "Address does not matched, try again later",
                                ], 200);
                            }
                        } else {
                            return response()->json([
                                'status' => 'failed',
                                'message' => "Address verification failed",
                                'error_list' => $errorArray
                            ], 200);
                        }
                    } else {
                        return response()->json([
                            'status' => 'failed',
                            'message' => "Address verification failed",
                            'error_list' => $errorArray
                        ], 200);
                    }
                } else {
                    return response()->json([
                        'status' => 'failed',
                        'message' => "Address verification failed",
                        'error_list' => $errorArray
                    ], 200);
                }
            } else {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Invalid request",
                    'error_list' => $errorArray
                ], 200);
            }
        }
    }

    // public function get_field_verification_status(Request $request) {
    //     $input = $request->all();
    //     $userId         = $request->user()->id;
    //     $borrower_id    = $this->get_borrower_id($request->user()->id);

    //     $field_verification_details = FieldInspectionDetails::where('borrower_id', $borrower_id)->latest()->first();
    //     if ($field_verification_details) {
    //         $isVerified = $field_verification_details->is_inspection_verified === true;

    //         return response()->json([
    //             'status' => $isVerified ? 'success' : 'failed',
    //             'message' => $isVerified
    //                 ? 'Field inspection details fetched successfully'
    //                 : 'Field inspection not yet verified',
    //             'data' => [
    //                 'name' => $field_verification_details->name,
    //                 'mobile' => $field_verification_details->mobile,
    //                 'is_verified' => $field_verification_details->is_inspection_verified,
    //             ],
    //         ], $isVerified ? 200 : 422);
    //     } else {
    //         return response()->json([
    //             'status'=>'failed',
    //             'message'=>"Not found",
    //         ], 422);
    //     }
    // }

    public function get_field_verification_status(Request $request) {
        $borrower_id = (int) $this->get_borrower_id($request->user()->id);

        $field_verification_details = FieldInspectionDetails::where('borrower_id', $borrower_id)->latest('id')->first();

        if (!$field_verification_details) {
            return response()->json([
                'status'  => 'failed',
                'message' => "Not found",
            ], 422);
        }

        $isVerified = (bool) $field_verification_details->is_inspection_verified;

        return response()->json([
            'status'  => $isVerified ? 'success' : 'failed',
            'message' => $isVerified
                ? 'Field inspection details fetched successfully'
                : 'Field inspection not yet verified',
            'data' => [
                'name'             => $field_verification_details->name,
                'mobile'           => $field_verification_details->mobile,
                'is_verified'      => $isVerified,
                'required_parties' => $field_verification_details->requiredPartyTypes(),
                'pending_parties'  => $this->fi->pendingPartyTypes($field_verification_details),
                'completed_at'     => $field_verification_details->completed_at,
                'parties'          => $this->fi->partySummary($field_verification_details),
            ],
        ], $isVerified ? 200 : 422);
    }

    public function address_verification_api($data) {        
        date_default_timezone_set('Asia/Kolkata');

        // $service_api    = new ServiceApiController;
        $ip             = $this->get_user_ip();
        $unique_id = $this->generate_random_id(4);

        if(isset($data['longitude']) && isset($data['latitude'])) {
            $longitude  = $data['longitude'];
            $latitude   = $data['latitude'];
            $verification_code = $data['verification_code'];

            $field_inspection_details = FieldInspectionDetails::where('verification_url', $verification_code)->first();
            if($field_inspection_details) {
                $borrower_id = $field_inspection_details->borrower_id;
            }
            // $user_id    = $data['user_id'];

            $jsonRequestBody = json_encode([
                'uniqueId' =>$unique_id,
                'latitude' =>$latitude,
                'longitude' =>$longitude
            ]);
            try{
                $curl = curl_init();
                if ($curl === false) {
                    throw new Exception('Failed to initialize cURL');
                }
                curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://api.digitap.ai/ent/v1/address-verification',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => $jsonRequestBody,
                    CURLOPT_HTTPHEADER => array(
                        'Accept: application/json',
                        'authorization: '.$this->prod_authorization_key,
                        'Content-Type: application/json'
                    ),
                ));

                $response = curl_exec($curl);
                if ($response === false) {
                    throw new Exception('cURL Error: ' . curl_error($curl));
                }
                $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);

                $result = json_decode($response);
    
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('JSON decode error: ' . json_last_error_msg());
                }

                if(isset($result->code) && $result->code == 200) {
                    $api_status = "VALID";
                } else {
                    $api_status = "INVALID";
                }
    
                $vendorServiceApi               = new VendorServiceApi;
                $vendorServiceApi->user_id      = isset($user_id) ? $user_id : 0;
                $vendorServiceApi->ip_address   = $ip;
                $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
                $vendorServiceApi->status       = $api_status;
                $vendorServiceApi->api_flag     = "ADDRESS_VERIFICATION_API";
                $vendorServiceApi->request      = json_encode($data);
                $vendorServiceApi->response     = $response;
                $vendorServiceApi->borrower_id  = $borrower_id ?? null;
                $vendorServiceApi->save();

                if(isset($result->code) && $result->code == 200) {

                    $result_response = [
                        'status' => 'success',
                        'data' => [
                            'address' => $result->model->address,
                            'pincode' => $result->model->pincode,
                            'district' => $result->model->district,
                            'state' => $result->model->state,
                        ]
                    ];
                } else {
                    $result_response = [
                        'status' => 'invalid',
                        'data' => 'Address verification failed'
                    ];
                }

                return $result_response;
            } catch (Exception $e) {
                $result_response = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'data' => [],
                ];

                return $result_response;
            }
        } else {
            return [
                'status' => 'failed'
            ];
        }
    }

    // public function verify_reference(Request $request){

    // }

    public function emi_debit_consent(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            date_default_timezone_set('Asia/Kolkata');

            /**
             * Code Updated On: 20-01-2025
             * This validation part Validator::make(), specially the account details is added on 20-01-2025
             * as required in stashfin module developement as well as our later development process
             * Code added in live on 27-01-2025
             */
            $validator = Validator::make($request->all(), [
                'account_holder_name' => ['required', 'regex:/^[a-zA-Z\s]+$/', 'max:255'],
                'account_number' => 'required|regex:/^[0-9]+$/',
                'confirm_account_number' => 'required|regex:/^[0-9]+$/|same:account_number',
                // 'ifsc_code' => ['required', 'regex:/^[a-zA-Z0-9]+$/', 'max:25'],
                'ifsc_code' => [
                    'required',
                    'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/',
                    'size:11'
                ],
                // 'bank_name' => ['required', 'regex:/^[a-zA-Z\s]+$/', 'max:255'],
                'bank_name' => ['required', 'regex:/^[a-zA-Z\s&-]+$/', 'max:255'],
                'branch_code' => ['nullable', 'regex:/^[a-zA-Z0-9\s]+$/', 'max:255'],
                'emi_debit_consent' => 'required|in:YES,NO',
                'account_type' => 'required|string',
                'bank_code' => 'required|regex:/^[a-zA-Z0-9]+$/',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            if(!isset($input['emi_debit_consent']) || empty($input['emi_debit_consent'])) {
                $errorCount++;
                $errorArray['emi_debit_consent'] = "Lender consent required";
                return $this->send_response("failed", $errorArray['emi_debit_consent'], 422);
            } elseif(!in_array($input['emi_debit_consent'], ["YES", "NO"])) {
                $errorCount++;
                $errorArray['emi_debit_consent'] = "Please enter 'YES' or 'NO'";
                return $this->send_response("failed", $errorArray['emi_debit_consent'], 422);
            }

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }

            DB::beginTransaction();
            $userId         = $request->user()->id;
            $borrower_id    = $this->get_borrower_id($request->user()->id);

            $loan_basic_details = LoanBasicDetails::find($borrower_id);
            if(!$loan_basic_details) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            // 2025-05-25 //start
            $bank_code = $request->bank_code;

            // $bank_npci_info = NpciBankList::where('bank_id', $bank_code)->first();

            // if (!$bank_npci_info || $bank_npci_info->card_flag !== true) {
            //     return $this->helper_controller->send_response('failed', 'eNACH is not available for this bank.', 422);
            // } 
            // disabled on 09-09-2025 as yogendra sir informed that we need to enable these cases as an exception as the borrower have only one account.

            // 2025-05-25 //end

            if($input['emi_debit_consent'] == "YES") {
                $debit_consent = true;
            } else {
                $debit_consent = false;
            }
            if($loan_basic_details) {
                $loan_basic_details->emi_debit_consent = $debit_consent;
                $loan_basic_details->save();

                // this account details portion is also part of the developement process mentioned above for date:20-01-2025
                $borrower_account_detail = BorrowerAccountDetails::where('borrower_id', $borrower_id)->first();
                if(!$borrower_account_detail) {
                    $borrower_account_detail = new BorrowerAccountDetails();
                }

                $borrower_account_detail->borrower_id           = $borrower_id;
                $borrower_account_detail->emi_debit_consent     = $debit_consent;
                $borrower_account_detail->account_holder_name   = $request->account_holder_name;
                $borrower_account_detail->account_number        = $request->account_number;
                $borrower_account_detail->ifsc                  = strtoupper($request->ifsc_code);
                $borrower_account_detail->bank_name             = $request->bank_name ?? '';
                $borrower_account_detail->branch_code           = $request->branch_code ?? '';
                $borrower_account_detail->account_type          = $request->filled('account_type') ? strtolower($request->account_type) : null;

                $borrower_account_detail->bank_code             = $request->bank_code ?? null;
                $borrower_account_detail->save();

                $currentStep = 20;
                $application_step = [
                    'step' => $currentStep,
                    'loan_basic_details_id' => $loan_basic_details->id,
                    'details' => "EMI Debit Consent",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($userId, $borrower_id, $application_step);

                $this->logStep($borrower_id, $currentStep, 'EMI Debit Consent', 'b2c');
                DB::commit();
                return response()->json([
                    'status' => 'success',
                    'message' => "Emi debit consent stored",
                    'data' => ''
                ], 200);
            } else {
                DB::rollBack();
                return response()->json([
                    'status' => 'failed',
                    'message' => "Invalid request",
                ], 422);
            }
        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred:". $e->getMessage(), 422);
        }
    }

    // public function upload_image(Request $request) {
    //     try {
    //         $flags      = ['BORROWER_KYC', 'COAPPLICANT_KYC', 'GUARANTOR_KYC', 'VEHICLE_DOCUMENT', 'BATTERY_DOCUMENT'];
    //         $doc_type   = [
    //             'PAN_CARD', 
    //             'AADHAR_CARD', 
    //             'AADHAR_FRONT', 
    //             'AADHAR_BACK',
    //             'ELECTRIC_BILL',
    //             'PERSON', 
    //             'VEHICLE_RTO', 
    //             'VEHICLE_RTO_TAX', 
    //             'VEHICLE_MMR', 
    //             'VEHICLE_INVOICE', 
    //             'VEHICLE_INSURANCE',
    //             'VEHICLE_IMAGE',
    //             'CHASIS_IMAGE',
    //             'MOTOR_CONTROLLER_IMAGE',
    //             'BATTERY_IMAGE',
    //             'URC_CERTIFICATE',
    //             'REGISTRATION_CERTIFICATE',
    //             'SIGNED_CHEQUE',
    //             'DRIVING_LICENCE',
    //             'DOH',
    //             'RENT_AGREEMENT',
    //             'NAME_DECLARATION',
    //             'BATTERY_INVOICE',
    //             'BATTERY_WARRANTY_DOCUMENT',
    //             'BATTERY_BANK_STATEMENT',
    //             'OTHER',
    //             'BORROWER_INCOME_PROOF_DOC',
    //             'COAPPLICANT_INCOME_PROOF_DOC',
    //             'GUARANTOR_INCOME_PROOF_DOC',
    //             'DOB_DECLARATION',
    //             'NOC_DOCUMENT',
    //             'ADDRESS_PROOF',
    //             'LOI_PERMIT',
    //             'VEHICLE_KEY_IMAGE',
    //         ];
            
                
    //         $input = $request->all();
    //         $errorArray = [];
    //         $errorCount = 0;
    //         $step = '';
    //         // $currentStep = 19;
    //         date_default_timezone_set('Asia/Kolkata');
            
    //         if($input == null) {
    //             return $this->send_response("failed", "Request body is empty", 422);
    //         } else {
    //             if (!$request->hasFile('file')) {
    //                 return $this->send_response("failed", "File is required", 422);
    //             }
                
    //             if (!isset($request->file) || empty($request->file)) {
    //                 return $this->send_response("failed", "File is required", 422);
    //             }
                
    //             if (!$request->file('file')->isValid()) {
    //                 return $this->send_response("failed", "Invalid file", 422);
    //             }
                
    //             $allowed_types = array("image/jpeg", "image/png", "image/jpg", "application/pdf");
                
    //             if (!in_array($request->file('file')->getMimeType(), $allowed_types)) {
    //                 return $this->send_response("failed", "Only JPG, PNG, JPEG, and PDF files are allowed", 422);
    //             }
                
    //             $max_size = 5 * 1024 * 1024; // 5 MB
                
    //             if(!isset($input['flag']) || empty($input['flag'])) {
    //                 $errorCount++;
    //                 $errorArray['flag'] = "Flag is required";
    //                 return $this->send_response("failed", $errorArray['flag'], 422);
    //             } elseif(!in_array($input['flag'], $flags)) {
    //                 $errorCount++;
    //                 $errorArray['flag'] = "Please put valid flag";
    //                 return $this->send_response("failed", $errorArray['flag'], 422);
    //             }

    //             if(!isset($input['doc_type']) || empty($input['doc_type'])) {
    //                 $errorCount++;
    //                 $errorArray['doc_type'] = "Document type is required";
    //                 return $this->send_response("failed", $errorArray['doc_type'], 422);
    //             } elseif(!in_array($input['doc_type'], $doc_type)) {
    //                 $errorCount++;
    //                 $errorArray['doc_type'] = "Please put valid document type";
    //                 return $this->send_response("failed", $errorArray['doc_type'], 422);
    //             }

    //             if ($request->file('file')->getSize() > $max_size) {
    //                 if(isset($input['doc_type']) && !empty($input['doc_type'])) {
    //                     $doc_type_name = strtolower($input['doc_type']);
    //                     $doc_type_name = str_replace('_', ' ', $doc_type_name);
    //                     $doc_type_name = ucfirst($doc_type_name);
    //                 } else {
    //                     $doc_type_name = '';
    //                 }

    //                 $response_msg = $doc_type_name . "file size must not exceed 5 MB";
    //                 return $this->send_response("failed", $response_msg, 422);
    //             }


    //             if ($errorCount > 0) {
    //                 return response()->json([
    //                     'status' => 'error',
    //                     'message' => "Invalid data",
    //                     'error_list' => $errorArray
    //                 ], 422);
    //             }

    //             $user_id            = $request->user()->id;
    //             $borrower_id        = $this->get_borrower_id($request->user()->id);
    //             $borrower_primary   = $borrower_id;
    //             $loan_details       = LoanBasicDetails::find($borrower_id);
    //             if($loan_details) {
    //                 $borrower_id = $loan_details->borrower_id;
    //             } else {
    //                 $borrower_id = date('d-m-Y');
    //             }
    //             $file               = $request->file('file');

    //             // Generate a unique file name
    //             // $serviceApiController = new ServiceApiController;
    //             if($input['doc_type'] == 'OTHER' || $input['doc_type'] == "SIGNED_CHEQUE") {
    //                 $fileName = $input['doc_type'].'-'.$this->generate_random_string(16) . '.' . $file->getClientOriginalExtension();
    //             } else {
    //                 $fileName = $input['doc_type']. '.' . $file->getClientOriginalExtension();
    //             }

    //             // Store the uploaded file with the new file name in storage/app/public directory
    //             // borrower/[borrower kyc, co applicant kyc, guarantor kyc, vehicle document]
    //             $path = $file->storeAs('public/Borrower/'.$borrower_id.'/'.$input['flag'], $fileName);

    //             // Determine the full path
    //             $fullPath = storage_path('app/'.$path);

    //             // Set the correct permissions for the file
    //             // chmod($fullPath, 0644);

    //             // Ensure the directory has the correct permissions (if necessary)
    //             $directory = dirname($fullPath);
    //             if (!is_dir($directory)) {
    //                 File::makeDirectory($directory, 0775, true, true);
    //             }

    //             if (file_exists($fullPath)) {
    //                 // Set the correct permissions for the file
    //                 // Note: chmod might not be allowed, so you may need to skip this or handle it differently
    //                 @chmod($fullPath, 0644); // Using @ to suppress any errors
    //             }

    //             // Get the URL of the uploaded file
    //             $url = Storage::url($path);

    //             // Prepare parameters for cURL request
    //             // $localPath = $file->getPathname();

    //             // --- DB persistence for KYC doc types ---
    //             $kycDocTypes = ['AADHAR_FRONT', 'AADHAR_BACK', 'PAN_CARD'];
    //             $flag        = $input['flag'];
    //             $docType     = $input['doc_type'];

    //             if (in_array($docType, $kycDocTypes)) {
    //                 $kyc_docs = Documents::where('borrower_id', $borrower_primary)->latest()->first();
    //                 if (!$kyc_docs) {
    //                     $kyc_docs              = new Documents;
    //                     $kyc_docs->borrower_id = $borrower_primary;
    //                 }

    //                 if ($flag === 'BORROWER_KYC') {
    //                     if ($docType === 'AADHAR_FRONT') $kyc_docs->aadhar_front = $url;
    //                     if ($docType === 'AADHAR_BACK')  $kyc_docs->aadhar_back  = $url;
    //                     if ($docType === 'PAN_CARD')      $kyc_docs->pan_doc      = $url;

    //                 } elseif ($flag === 'COAPPLICANT_KYC') {
    //                     if ($docType === 'AADHAR_FRONT') $kyc_docs->coAadhar_front = $url;
    //                     if ($docType === 'AADHAR_BACK')  $kyc_docs->coAadhar_back  = $url;
    //                     if ($docType === 'PAN_CARD')      $kyc_docs->coPan_doc      = $url;

    //                 } elseif ($flag === 'GUARANTOR_KYC') {
    //                     if ($docType === 'AADHAR_FRONT') $kyc_docs->guarantor_aadhar_front = $url;
    //                     if ($docType === 'AADHAR_BACK')  $kyc_docs->guarantor_aadhar_back  = $url;
    //                     if ($docType === 'PAN_CARD')      $kyc_docs->guarantor_pan_doc      = $url;
    //                 }

    //                 $kyc_docs->save();
    //             }
    //             // --- end DB persistence ---

    //             return response()->json([
    //                 'status' => 'success',
    //                 'message' => "File uploaded successfully",
    //                 'file_url' => $url
    //             ], 200);
    //         }
    //     } catch (Exception $e) {
    //         // Handle errors
    //         return response()->json([
    //             'error' => 'failed',
    //             'message' => $e->getMessage()
    //         ], 422);
    //     }
    // }

    public function upload_image(Request $request) {
        try {
            $flags      = ['BORROWER_KYC', 'COAPPLICANT_KYC', 'GUARANTOR_KYC', 'VEHICLE_DOCUMENT', 'BATTERY_DOCUMENT'];
            $doc_type   = [
                'PAN_CARD', 
                'AADHAR_CARD', 
                'AADHAR_FRONT', 
                'AADHAR_BACK',
                'ELECTRIC_BILL',
                'PERSON', 
                'VEHICLE_RTO', 
                'VEHICLE_RTO_TAX', 
                'VEHICLE_MMR', 
                'VEHICLE_INVOICE', 
                'VEHICLE_INSURANCE',
                'VEHICLE_IMAGE',
                'CHASIS_IMAGE',
                'MOTOR_CONTROLLER_IMAGE',
                'BATTERY_IMAGE',
                'URC_CERTIFICATE',
                'REGISTRATION_CERTIFICATE',
                'SIGNED_CHEQUE',
                'DRIVING_LICENCE',
                'DOH',
                'RENT_AGREEMENT',
                'NAME_DECLARATION',
                'BATTERY_INVOICE',
                'BATTERY_WARRANTY_DOCUMENT',
                'BATTERY_BANK_STATEMENT',
                'OTHER',
                'BORROWER_INCOME_PROOF_DOC',
                'COAPPLICANT_INCOME_PROOF_DOC',
                'GUARANTOR_INCOME_PROOF_DOC',
                'DOB_DECLARATION',
                'NOC_DOCUMENT',
                'ADDRESS_PROOF',
                'LOI_PERMIT',
                'VEHICLE_KEY_IMAGE',
            ];
            
                
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            // $currentStep = 19;
            date_default_timezone_set('Asia/Kolkata');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            } else {
                if (!$request->hasFile('file')) {
                    return $this->send_response("failed", "File is required", 422);
                }
                
                if (!isset($request->file) || empty($request->file)) {
                    return $this->send_response("failed", "File is required", 422);
                }
                
                if (!$request->file('file')->isValid()) {
                    return $this->send_response("failed", "Invalid file", 422);
                }
                
                $allowed_types = array("image/jpeg", "image/png", "image/jpg", "application/pdf");
                
                if (!in_array($request->file('file')->getMimeType(), $allowed_types)) {
                    return $this->send_response("failed", "Only JPG, PNG, JPEG, and PDF files are allowed", 422);
                }
                
                $max_size = 5 * 1024 * 1024; // 5 MB
                
                if(!isset($input['flag']) || empty($input['flag'])) {
                    $errorCount++;
                    $errorArray['flag'] = "Flag is required";
                    return $this->send_response("failed", $errorArray['flag'], 422);
                } elseif(!in_array($input['flag'], $flags)) {
                    $errorCount++;
                    $errorArray['flag'] = "Please put valid flag";
                    return $this->send_response("failed", $errorArray['flag'], 422);
                }

                if(!isset($input['doc_type']) || empty($input['doc_type'])) {
                    $errorCount++;
                    $errorArray['doc_type'] = "Document type is required";
                    return $this->send_response("failed", $errorArray['doc_type'], 422);
                } elseif(!in_array($input['doc_type'], $doc_type)) {
                    $errorCount++;
                    $errorArray['doc_type'] = "Please put valid document type";
                    return $this->send_response("failed", $errorArray['doc_type'], 422);
                }

                if ($request->file('file')->getSize() > $max_size) {
                    if(isset($input['doc_type']) && !empty($input['doc_type'])) {
                        $doc_type_name = strtolower($input['doc_type']);
                        $doc_type_name = str_replace('_', ' ', $doc_type_name);
                        $doc_type_name = ucfirst($doc_type_name);
                    } else {
                        $doc_type_name = '';
                    }

                    $response_msg = $doc_type_name . "file size must not exceed 5 MB";
                    return $this->send_response("failed", $response_msg, 422);
                }


                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }

                $user_id            = $request->user()->id;
                $borrower_id        = $this->get_borrower_id($request->user()->id);
                $borrower_primary   = $borrower_id;
                $loan_details       = LoanBasicDetails::find($borrower_id);
                if($loan_details) {
                    $borrower_id = $loan_details->borrower_id;
                } else {
                    $borrower_id = date('d-m-Y');
                }
                $file               = $request->file('file');

                // Generate a unique file name
                // $serviceApiController = new ServiceApiController;
                if($input['doc_type'] == 'OTHER' || $input['doc_type'] == "SIGNED_CHEQUE") {
                    $fileName = $input['doc_type'].'-'.$this->generate_random_string(16) . '.' . $file->getClientOriginalExtension();
                } else {
                    $fileName = $input['doc_type']. '.' . $file->getClientOriginalExtension();
                }

                // Canonical path - identical on both disks.
                // borrower/[borrower kyc, co applicant kyc, guarantor kyc, vehicle document]
                $relativeDir = 'Borrower/'.$borrower_id.'/'.$input['flag'];

                $diskName = config('filesystems.default', 'local');
                $isLocal  = config("filesystems.disks.{$diskName}.driver") === 'local';

                // The local disk is rooted at storage/app so it needs the
                // public/ segment; s3 is rooted at the bucket and must not.
                $path = $file->storeAs(
                    $isLocal ? 'public/'.$relativeDir : $relativeDir,
                    $fileName,
                    $diskName
                );

                if ($path === false) {
                    return $this->send_response("failed", "Unable to store file", 500);
                }

                if ($isLocal) {
                    // Group-writable so both www-data and ubuntu can manage it.
                    @chmod(storage_path('app/'.$path), 0664);
                }

                // Stored path stays the same regardless of disk.
                $url = '/storage/'.$relativeDir.'/'.$fileName;

                // A re-upload overwrites the previous key, so drop any cached
                // disk resolution for it.
                Cache::forget('file_disk:'.$relativeDir.'/'.$fileName);

                // Prepare parameters for cURL request
                // $localPath = $file->getPathname();

                // --- DB persistence for KYC doc types ---
                $kycDocTypes = ['AADHAR_FRONT', 'AADHAR_BACK', 'PAN_CARD'];
                $flag        = $input['flag'];
                $docType     = $input['doc_type'];

                if (in_array($docType, $kycDocTypes)) {
                    $kyc_docs = Documents::where('borrower_id', $borrower_primary)->latest()->first();
                    if (!$kyc_docs) {
                        $kyc_docs              = new Documents;
                        $kyc_docs->borrower_id = $borrower_primary;
                    }

                    if ($flag === 'BORROWER_KYC') {
                        if ($docType === 'AADHAR_FRONT') $kyc_docs->aadhar_front = $url;
                        if ($docType === 'AADHAR_BACK')  $kyc_docs->aadhar_back  = $url;
                        if ($docType === 'PAN_CARD')      $kyc_docs->pan_doc      = $url;

                    } elseif ($flag === 'COAPPLICANT_KYC') {
                        if ($docType === 'AADHAR_FRONT') $kyc_docs->coAadhar_front = $url;
                        if ($docType === 'AADHAR_BACK')  $kyc_docs->coAadhar_back  = $url;
                        if ($docType === 'PAN_CARD')      $kyc_docs->coPan_doc      = $url;

                    } elseif ($flag === 'GUARANTOR_KYC') {
                        if ($docType === 'AADHAR_FRONT') $kyc_docs->guarantor_aadhar_front = $url;
                        if ($docType === 'AADHAR_BACK')  $kyc_docs->guarantor_aadhar_back  = $url;
                        if ($docType === 'PAN_CARD')      $kyc_docs->guarantor_pan_doc      = $url;
                    }

                    $kyc_docs->save();
                }
                // --- end DB persistence ---

                return response()->json([
                    'status' => 'success',
                    'message' => "File uploaded successfully",
                    'file_url' => $url
                ], 200);
            }
        } catch (Exception $e) {
            // Handle errors
            return response()->json([
                'error' => 'failed',
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function borrower_doc_upload(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            $currentStep = 11;
            date_default_timezone_set('Asia/Kolkata');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            }

            $userId         = $request->user()->id;
            $borrower_id    = $this->get_borrower_id($request->user()->id);
            DB::beginTransaction();
            // if(!isset($input['aadhar_front']) || empty($input['aadhar_front'])){
            //     $errorCount++;
            //     $errorArray['aadhar_front'] = "Aadhar front is required";
            //     return $this->send_response("failed", $errorArray['aadhar_front'], 422);
            // }
            // if(!isset($input['aadhar_back']) || empty($input['aadhar_back'])){
            //     $errorCount++;
            //     $errorArray['aadhar_back'] = "Aadhar back is required";
            //     return $this->send_response("failed", $errorArray['aadhar_back'], 422);
            // }
            
            // if(!isset($input['pan_card']) || empty($input['pan_card'])){
            //     $errorCount++;
            //     $errorArray['pan_card'] = "Pan card is required";
            //     return $this->send_response("failed", $errorArray['pan_card'], 422);
            // }

            if(!isset($input['photo']) || empty($input['photo'])){
                $errorCount++;
                $errorArray['photo'] = "Photo is required";
                return $this->send_response("failed", $errorArray['photo'], 422);
            }
            
            // if(!isset($input['driving_license_img']) || empty($input['driving_license_img'])){
            //     $errorCount++;
            //     $errorArray['driving_license_img'] = "Driving licence photo is required";
            //     return $this->send_response("failed", $errorArray['driving_license_img'], 422);
            // }

            // if(!isset($input['borrower_signed_cheque']) || empty($input['borrower_signed_cheque'])){
            //     $errorCount++;
            //     $errorArray['borrower_signed_cheque'] = "Signed cheques are required";
            //     return $this->send_response("failed", $errorArray['borrower_signed_cheque'], 422);
            // } elseif(!is_array($input['borrower_signed_cheque']) || count($input['borrower_signed_cheque']) < 4) {
            //     $errorCount++;
            //     $errorArray['borrower_signed_cheque'] = "4 Signed cheques are required";
            //     return $this->send_response("failed", $errorArray['borrower_signed_cheque'], 422);
            // }

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }

            $loan_details = LoanBasicDetails::find($borrower_id);
            if (!$loan_details) {
                return $this->helper_controller->send_response('failed', "Borrower not found", 422);
            }
            $ip = $this->get_user_ip();
            if (isset($input['borrower_signed_cheque']) && is_array($input['borrower_signed_cheque']) && count($input['borrower_signed_cheque']) > 0) {

                if (count($input['borrower_signed_cheque']) !== 4) {
                    return $this->send_response("failed", "Exactly 4 signed cheque file paths are required if provided", 422);
                }

                $accountNumbers = $input['account_number'] ?? [];
                $chequeNumbers = $input['cheque_number'] ?? [];
                $ifscCodes = $input['ifsc_code'] ?? [];

                if (
                    count($accountNumbers) !== 4 ||
                    count($chequeNumbers) !== 4 ||
                    count($ifscCodes) !== 4
                ) {
                    return $this->send_response("failed", "Exactly 4 account numbers, cheque numbers, and IFSC codes are required", 422);
                }

                for ($i = 0; $i < 4; $i++) {
                    $data = [
                        'account_number' => $accountNumbers[$i] ?? null,
                        'cheque_no' => $chequeNumbers[$i] ?? null,
                        'ifsc' => $ifscCodes[$i] ?? null,
                        'file_path' => $input['borrower_signed_cheque'][$i] ?? null,
                    ];

                    // Validate input
                    $validator = Validator::make($data, [
                        'account_number' => 'required|string',
                        'cheque_no' => 'required|string',
                        'ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
                        'file_path' => 'required|string'
                    ]);

                    if ($validator->fails()) {
                        return $this->send_response("failed", "Validation failed for cheque #" . ($i + 1) . ": " . $validator->errors()->first(), 422);
                    }

                    // Check for duplicate cheque number
                    $exists = BorrowerCheques::where('borrower_id', $loan_details->id)
                        ->where('cheque_no', $data['cheque_no'])
                        ->where('status', '<>', 'deleted')
                        ->exists();

                    if ($exists) {
                        return $this->send_response("failed", "Cheque no {$data['cheque_no']} already exists for this borrower", 422);
                    }

                    // Extract relative path from public URL
                    $sourcePath = str_replace('/storage/', '', parse_url($data['file_path'], PHP_URL_PATH));

                    // Determine new path
                    $chequeNo = $data['cheque_no'];
                    $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
                    $targetDir = "Borrower/{$loan_details->borrower_id}/Borrower_cheques";
                    $targetFileName = $chequeNo . '.' . $extension;
                    $targetPath = "{$targetDir}/{$targetFileName}";

                    // Copy file
                    $copied = Storage::disk('public')->copy($sourcePath, $targetPath);

                    if (!$copied) {
                        return $this->send_response("failed", "Failed to copy cheque file for cheque no {$chequeNo}", 500);
                    }

                    // Save record
                    $cheque = new BorrowerCheques();
                    $cheque->borrower_id = $loan_details->id;
                    $cheque->file = 'storage/' . $targetPath; // public URL path
                    $cheque->cheque_no = $chequeNo;
                    $cheque->ifsc = $data['ifsc'];
                    $cheque->account_number = $data['account_number'];
                    $cheque->status = 'active';
                    $cheque->ip = $ip;
                    $cheque->modified_by = auth()->id();
                    $cheque->save();
                }
            }

            $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
            if(!$kyc_docs) {
                $kyc_docs       = new Documents;
                $kyc_docs->borrower_id       = $borrower_id;


                // return response()->json([
                //     'status'=>'failed',
                //     'message'=>"Invalid request",
                // ], 422);
            }
            // $kyc_docs->aadhar_front      = $input['aadhar_front'];
            // $kyc_docs->aadhar_back       = $input['aadhar_back'];
            // $kyc_docs->pan_doc           = $input['pan_card'];
            $kyc_docs->borrower_avtar    = $input['photo'];
            $kyc_docs->urc_certificate   = isset($input['urc_certificate']) ? $input['urc_certificate'] : '';
            $kyc_docs->res_address_proof = isset($input['electric_bill']) ? $input['electric_bill'] : '';
            $kyc_docs->driving_license   = isset($input['driving_license_img']) ? $input['driving_license_img'] : '';
            $kyc_docs->per_address_proof = isset($input['address_proof_doc']) ? $input['address_proof_doc'] : '';
            $kyc_docs->loi_permit = isset($input['loi_permit']) ? $input['loi_permit'] : '';
            
            if(isset($input['borrower_signed_cheque']) && !empty($input['borrower_signed_cheque'])) {
                if(is_array($input['borrower_signed_cheque'])) {
                    $borrower_signed_cheque = implode(',', $input['borrower_signed_cheque']);
                } else {
                    $borrower_signed_cheque = $input['$borrower_signed_cheque'];
                }
                $kyc_docs->borrower_signed_cheque   = $borrower_signed_cheque;
            }

            if(isset($input['borrower_other_docs']) && !empty($input['borrower_other_docs'])) {
                if(is_array($input['borrower_other_docs'])) {
                    $borrower_other_docs = implode(',', $input['borrower_other_docs']);
                } else {
                    $borrower_other_docs = $input['$borrower_other_docs'];
                }
                $kyc_docs->borrower_other_docs      = $borrower_other_docs;
            }

            $borrower_kyc_detail = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
            if($borrower_kyc_detail){
                $borrower_kyc_detail->udyam_aadhaar_number = $request->udyam_aadhaar_number ?? null;
                $borrower_kyc_detail->save();
            }
            
            $kyc_docs->save();

            $application_step = [
                'step' => $currentStep,
                'kyc_doc_id' => $kyc_docs->id,
                'details' => "Borrower Documents Upload",
                'status' => "COMPLETED",
            ];

            $this->updateApplicationStep($userId, $borrower_id, $application_step);

            $this->logStep($borrower_id, $currentStep, 'Borrower Documents Upload', 'b2c');
            DB::commit();
            return response()->json([
                'status'=>'success',
                'message'=>"Borrower documents stored successfully",
                'data'=> ''
            ], 201);
        
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred :". $e->getMessage(), 422);
        }   
    }

    public function borrower_doc_upload_battery(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            $currentStep = 11;
            date_default_timezone_set('Asia/Kolkata');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            }
                
            // if(!isset($input['aadhar_front']) || empty($input['aadhar_front'])){
            //     $errorCount++;
            //     $errorArray['aadhar_front'] = "Aadhar front is required";
            //     return $this->send_response("failed", $errorArray['aadhar_front'], 422);
            // }
            // if(!isset($input['aadhar_back']) || empty($input['aadhar_back'])){
            //     $errorCount++;
            //     $errorArray['aadhar_back'] = "Aadhar back is required";
            //     return $this->send_response("failed", $errorArray['aadhar_back'], 422);
            // }
            
            // if(!isset($input['pan_card']) || empty($input['pan_card'])){
            //     $errorCount++;
            //     $errorArray['pan_card'] = "Pan card is required";
            //     return $this->send_response("failed", $errorArray['pan_card'], 422);
            // }
            
            if(!isset($input['photo']) || empty($input['photo'])){
                $errorCount++;
                $errorArray['photo'] = "Photo is required";
                return $this->send_response("failed", $errorArray['photo'], 422);
            }
            
            if ($errorCount > 0) {
                return $this->send_response("failed", "Invalid data", 422, $errorArray);
            }

            DB::beginTransaction();
        
            $userId         = $request->user()->id;
            $borrower_id    = $this->get_borrower_id($request->user()->id);
            $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
            if(!$kyc_docs) {
                $kyc_docs       = new Documents;
                $kyc_docs->borrower_id       = $borrower_id;
                
            }
            // $kyc_docs->aadhar_front      = $input['aadhar_front'];
            // $kyc_docs->aadhar_back       = $input['aadhar_back'];
            // $kyc_docs->pan_doc           = $input['pan_card'];
            $kyc_docs->borrower_avtar    = $input['photo'];
            
            $kyc_docs->save();
            $application_step = [
                'step' => $currentStep,
                'kyc_doc_id' => $kyc_docs->id,
                'details' => "Borrower Documents Upload",
                'status' => "COMPLETED",
            ];
            $this->updateApplicationStep($userId, $borrower_id, $application_step);
                
            $this->logStep($borrower_id, $currentStep, 'Borrower Documents Upload', 'b2c');
            DB::commit();
            return $this->send_response("success", "Borrower documents stored successfully", 201);
                
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', $e->getMessage(), 422);
        }    
    }
    
    public function co_applicant_doc_upload(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            $currentStep = 12;
            date_default_timezone_set('Asia/Kolkata');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            } else {
                DB::beginTransaction();
                // if(!isset($input['aadhar_front']) || empty($input['aadhar_front'])){
                //     $errorCount++;
                //     $errorArray['aadhar_front'] = "Aadhar front is required";
                //     return $this->send_response("failed", $errorArray['aadhar_front'], 422);
                // }
                // if(!isset($input['aadhar_back']) || empty($input['aadhar_back'])){
                //     $errorCount++;
                //     $errorArray['aadhar_back'] = "Aadhar back is required";
                //     return $this->send_response("failed", $errorArray['aadhar_back'], 422);
                // }
                
                // if(!isset($input['pan_card']) || empty($input['pan_card'])){
                //     $errorCount++;
                //     $errorArray['pan_card'] = "Pan back is required";
                //     return $this->send_response("failed", $errorArray['pan_card'], 422);
                // }

                if(!isset($input['photo']) || empty($input['photo'])){
                    $errorCount++;
                    $errorArray['photo'] = "Photo is required";
                    return $this->send_response("failed", $errorArray['photo'], 422);
                }

                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }

                $userId         = $request->user()->id;
                $borrower_id    = $this->get_borrower_id($request->user()->id);
                $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                if($kyc_docs) {

                    // $kyc_docs->coAadhar_front       = $input['aadhar_front'];
                    // $kyc_docs->coAadhar_back        = $input['aadhar_back'];
                    // $kyc_docs->coPan_doc            = $input['pan_card'];
                    $kyc_docs->coapplicant_avtar    = $input['photo'];
                    $kyc_docs->coResAddress_proof   = isset($input['electric_bill']) ? $input['electric_bill'] : '';
                    $kyc_docs->coPerAddress_proof   = isset($input['address_proof_doc']) ? $input['address_proof_doc'] : '';
                    
                    if($kyc_docs->save()) {
                        $application_step = [
                            'step' => $currentStep,
                            'kyc_doc_id' => $kyc_docs->id,
                            'details' => "Co-applicant Documents Upload",
                            'status' => "COMPLETED",
                        ];
            
                        $this->updateApplicationStep($userId, $borrower_id, $application_step);
                            
                        $this->logStep($borrower_id, $currentStep, 'Co-applicant Documents Upload', 'b2c');
                        DB::commit();
                        return response()->json([
                            'status'=>'success',
                            'message'=>"Co-applicant documents stored successfully",
                            'data'=> ''
                        ], 201);
                    } else {
                        DB::rollBack();
                        return response()->json([
                            'status'=>'failed',
                            'message'=>"Failed to store co-applicant documents",
                        ], 422);
                    }
                } else {
                    DB::rollBack();
                    return response()->json([
                        'status'=>'failed',
                        'message'=>"Invalid request",
                    ], 422);
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }
    
    public function guarantor_doc_upload(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            $currentStep = 13;
            date_default_timezone_set('Asia/Kolkata');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            } else {
                DB::beginTransaction();
                // if(!isset($input['aadhar_front']) || empty($input['aadhar_front'])){
                //     $errorCount++;
                //     $errorArray['aadhar_front'] = "Aadhar front is required";
                //     return $this->send_response("failed", $errorArray['aadhar_front'], 422);
                // }
                // if(!isset($input['aadhar_back']) || empty($input['aadhar_back'])){
                //     $errorCount++;
                //     $errorArray['aadhar_back'] = "Aadhar back is required";
                //     return $this->send_response("failed", $errorArray['aadhar_back'], 422);
                // }
                
                // if(!isset($input['pan_card']) || empty($input['pan_card'])){
                //     $errorCount++;
                //     $errorArray['pan_card'] = "Aadhar back is required";
                //     return $this->send_response("failed", $errorArray['pan_card'], 422);
                // }

                if(!isset($input['photo']) || empty($input['photo'])){
                    $errorCount++;
                    $errorArray['photo'] = "Photo is required";
                    return $this->send_response("failed", $errorArray['photo'], 422);
                }

                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }

                $userId         = $request->user()->id;
                $borrower_id    = $this->get_borrower_id($request->user()->id);
                $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();

                if($kyc_docs) {
                    // $kyc_docs->guarantor_aadhar_front       = $input['aadhar_front'];
                    // $kyc_docs->guarantor_aadhar_back        = $input['aadhar_back'];
                    // $kyc_docs->guarantor_pan_doc            = $input['pan_card'];
                    $kyc_docs->guarantor_avtar              = $input['photo'];
                    $kyc_docs->guarantor_coresaddress_proof = isset($input['electric_bill']) ? $input['electric_bill'] : '';
                    $kyc_docs->guarantor_peraddress_proof = isset($input['address_proof_doc']) ? $input['address_proof_doc'] : '';

                    if($kyc_docs->save()) {
                        $application_step = [
                            'step' => $currentStep,
                            'kyc_doc_id' => $kyc_docs->id,
                            'details' => "Guarantor Documents Upload",
                            'status' => "COMPLETED",
                        ];
            
                        $this->updateApplicationStep($userId, $borrower_id, $application_step);

                        $this->logStep($borrower_id, $currentStep, 'Guarantor Documents Upload', 'b2c');

                        DB::commit();
                        return response()->json([
                            'status'=>'success',
                            'message'=>"Guarantor documents stored successfully",
                            'data'=> ''
                        ], 201);
                    } else {
                        DB::rollBack();
                        return response()->json([
                            'status'=>'failed',
                            'message'=>"Failed to store guarantor documents",
                        ], 422);
                    }
                } else {
                        DB::rollBack();
                    return response()->json([
                        'status'=>'failed',
                        'message'=>"Invalid request",
                    ], 422);
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function vehicle_doc_upload(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            // $currentStep = 27;
            $currentStep = 22;
            date_default_timezone_set('Asia/Kolkata');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            }

            $userId         = $request->user()->id;
            $borrower_id    = $this->get_borrower_id($request->user()->id);
            
            DB::beginTransaction();

            if(!isset($input['rto_document']) || empty($input['rto_document'])){
                $errorCount++;
                $errorArray['rto_document'] = "RTO document is required";
                return $this->send_response("failed", $errorArray['rto_document'], 422);
            }

            if(!isset($input['rto_tax_receipt']) || empty($input['rto_tax_receipt'])){
                $errorCount++;
                $errorArray['rto_tax_receipt'] = "RTO tax receipt is required";
                return $this->send_response("failed", $errorArray['rto_tax_receipt'], 422);
            }
            
            if(!isset($input['mmr']) || empty($input['mmr'])){
                $errorCount++;
                $errorArray['mmr'] = "MMR is required";
                return $this->send_response("failed", $errorArray['mmr'], 422);
            }

            if(!isset($input['invoice']) || empty($input['invoice'])){
                $errorCount++;
                $errorArray['invoice'] = "Invoice is required";
                return $this->send_response("failed", $errorArray['invoice'], 422);
            }

            if(!isset($input['insurance']) || empty($input['insurance'])){
                $errorCount++;
                $errorArray['insurance'] = "Insurance is required";
                return $this->send_response("failed", $errorArray['insurance'], 422);
            }

            if (!isset($input['insurance_policy_number']) || empty($input['insurance_policy_number'])) {
                $errorCount++;
                $errorArray['insurance_policy_number'] = "Insurance policy number is required";
                return $this->send_response("failed", $errorArray['insurance_policy_number'], 422);
            }

            if(!isset($input['vehicle_image']) || empty($input['vehicle_image'])){
                $errorCount++;
                $errorArray['vehicle_image'] = "Vehicle image is required";
                return $this->send_response("failed", $errorArray['vehicle_image'], 422);
            }

            // if(!isset($input['doh']) || empty($input['doh'])){
            //     $errorCount++;
            //     $errorArray['doh'] = "DOH is required";
            //     return $this->send_response("failed", $errorArray['doh'], 422);
            // }
            if(!isset($input['chasis_number']) || empty($input['chasis_number'])){
                $errorCount++;
                $errorArray['chasis_number'] = "Chasis number is required";
                return $this->send_response("failed", $errorArray['chasis_number'], 422);
            }
            if (!isset($input['chasis_number_img']) || empty($input['chasis_number_img'])) {
                $errorCount++;
                $errorArray['chasis_number_img'] = "Chasis number image is required";
                return $this->send_response("failed", $errorArray['chasis_number_img'], 422);
            }
            if(!isset($input['motor_controller']) || empty($input['motor_controller'])){
                $errorCount++;
                $errorArray['motor_controller'] = "Motor controller is required";
                return $this->send_response("failed", $errorArray['motor_controller'], 422);
            }
            if(!isset($input['battery_image']) || empty($input['battery_image'])){
                $errorCount++;
                $errorArray['battery_image'] = "Battery image is required";
                return $this->send_response("failed", $errorArray['battery_image'], 422);
            }
            
            if (!isset($input['down_payment']) || empty($input['down_payment'])) {
                $errorCount++;
                $errorArray['down_payment'] = "Down payment amount is required";
                return $this->send_response("failed", $errorArray['down_payment'], 422);
            }

            // if(!isset($input['vehicle_signed_cheque']) || empty($input['vehicle_signed_cheque'])){
            //     $errorCount++;
            //     $errorArray['vehicle_signed_cheque'] = "Signed cheque is required";
            //     return $this->send_response("failed", $errorArray['vehicle_signed_cheque'], 422);
            // } elseif(!is_array($input['vehicle_signed_cheque']) || count($input['vehicle_signed_cheque']) < 2) {
            //     $errorCount++;
            //     $errorArray['vehicle_signed_cheque'] = "2 Signed cheque is required";
            //     return $this->send_response("failed", $errorArray['vehicle_signed_cheque'], 422);
            // }

            $loan_details = LoanBasicDetails::find($borrower_id);

            if(!$loan_details) {
                return $this->send_response("failed", "Borrower not found, invalid request", 422);
            }

            if(($loan_details->product_id == 3) && ($loan_details->loan_amount <= 24000)) {
                if(!isset($input['registration_certificate']) || empty($input['registration_certificate'])){
                    $errorCount++;
                    $errorArray['registration_certificate'] = "Registration certificate(RC) is required";
                    return $this->send_response("failed", $errorArray['registration_certificate'], 422);
                }
            }
        
            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'policy_premium'    => ['nullable', 'numeric'],
                'policy_start_date' => ['nullable', 'date'],
                'policy_end_date'   => ['nullable', 'date', 'after:policy_start_date'],
            ]);
            if ($validator->fails()) {
                return $this->send_response("failed", $validator->errors()->first(), 422);
            }

            $ip = $this->get_user_ip();
            if (isset($input['vehicle_signed_cheque']) && is_array($input['vehicle_signed_cheque']) && count($input['vehicle_signed_cheque']) > 0) {

                if (count($input['vehicle_signed_cheque']) !== 2) {
                    return $this->send_response("failed", "Exactly 2 signed cheque file paths are required if provided", 422);
                }

                $accountNumbers = $input['account_number'] ?? [];
                $chequeNumbers = $input['cheque_number'] ?? [];
                $ifscCodes = $input['ifsc_code'] ?? [];

                if (
                    count($accountNumbers) !== 2 ||
                    count($chequeNumbers) !== 2 ||
                    count($ifscCodes) !== 2
                ) {
                    return $this->send_response("failed", "Exactly 2 account numbers, cheque numbers, and IFSC codes are required", 422);
                }

                for ($i = 0; $i < 2; $i++) {
                    $data = [
                        'account_number' => $accountNumbers[$i] ?? null,
                        'cheque_no' => $chequeNumbers[$i] ?? null,
                        'ifsc' => $ifscCodes[$i] ?? null,
                        'file_path' => $input['vehicle_signed_cheque'][$i] ?? null,
                    ];

                    // Validate input
                    $validator = Validator::make($data, [
                        'account_number' => 'required|string',
                        'cheque_no' => 'required|string',
                        'ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
                        'file_path' => 'required|string'
                    ]);

                    if ($validator->fails()) {
                        return $this->send_response("failed", "Validation failed for cheque #" . ($i + 1) . ": " . $validator->errors()->first(), 422);
                    }

                    // Check for duplicate cheque number
                    $exists = BorrowerCheques::where('borrower_id', $loan_details->id)
                        ->where('cheque_no', $data['cheque_no'])
                        ->where('status', '<>', 'deleted')
                        ->exists();

                    if ($exists) {
                        return $this->send_response("failed", "Cheque no {$data['cheque_no']} already exists for this borrower", 422);
                    }

                    // Extract relative path from public URL
                    $sourcePath = str_replace('/storage/', '', parse_url($data['file_path'], PHP_URL_PATH));

                    // Determine new path
                    $chequeNo = $data['cheque_no'];
                    $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
                    $targetDir = "Borrower/{$loan_details->borrower_id}/Borrower_cheques";
                    $targetFileName = $chequeNo . '.' . $extension;
                    $targetPath = "{$targetDir}/{$targetFileName}";

                    // Copy file
                    $copied = Storage::disk('public')->copy($sourcePath, $targetPath);

                    if (!$copied) {
                        return $this->send_response("failed", "Failed to copy cheque file for cheque no {$chequeNo}", 500);
                    }

                    // Save record
                    $cheque = new BorrowerCheques();
                    $cheque->borrower_id = $loan_details->id;
                    $cheque->file = 'storage/' . $targetPath; // public URL path
                    $cheque->cheque_no = $chequeNo;
                    $cheque->ifsc = $data['ifsc'];
                    $cheque->account_number = $data['account_number'];
                    $cheque->status = 'active';
                    $cheque->ip = $ip;
                    $cheque->modified_by = auth()->id();
                    $cheque->save();
                }
            }

            $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();

            if(!$kyc_docs) {
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Invalid request",
                ], 422);
            }
            $kyc_docs->rto_document     = $input['rto_document'];
            $kyc_docs->rto_tax_receipt  = $input['rto_tax_receipt'];
            $kyc_docs->mmr              = $input['mmr'];
            $kyc_docs->invoice          = $input['invoice'];
            $kyc_docs->insurance        = $input['insurance'];
            $kyc_docs->vehicle_image    = $input['vehicle_image'];
            $kyc_docs->doh              = isset($input['doh']) ? $input['doh'] : '';
            $kyc_docs->chasis_number    = $input['chasis_number_img']; // $input['chasis_number'];
            $kyc_docs->motor_controller = $input['motor_controller'];
            $kyc_docs->battery_image    = $input['battery_image'];
            $kyc_docs->registration_certificate = $input['registration_certificate'] ?? '';

            if(is_array($input['vehicle_signed_cheque'])) {
                $vehicle_signed_cheque = implode(',', $input['vehicle_signed_cheque']);
            } else {
                $vehicle_signed_cheque = $input['vehicle_signed_cheque'];
            }
            $kyc_docs->vehicle_signed_cheque    = $vehicle_signed_cheque ?? '';

            if(isset($input['vehicle_other_docs']) && !empty($input['vehicle_other_docs'])) {
                if(is_array($input['vehicle_other_docs'])) {
                    $vehicle_other_docs = implode(',', $input['vehicle_other_docs']);
                } else {
                    $vehicle_other_docs = $input['vehicle_other_docs'];
                }
            }
            $kyc_docs->vehicle_other_docs    = isset($vehicle_other_docs) ? $vehicle_other_docs : '';
            $kyc_docs->vehicle_key_img    = !empty($input['vehicle_key_image']) ? $input['vehicle_key_image'] : '';


            $kyc_docs->save();
            $application_step = [
                'step' => $currentStep,
                'kyc_doc_id' => $kyc_docs->id,
                'details' => "Vehicle Documents Upload",
                'status' => "COMPLETED",
            ];

            $this->updateApplicationStep($userId, $borrower_id, $application_step);

            $loan_details->down_payment = $input['down_payment'] ?? 0;
            $loan_details->save();

            /**
             * Code updated on: 20-01-2025
             * previously chasis_number(chasis image) input changed to chasis_number_image
             * insurance_policy_number and chasis number this two is new inputs added on 
             * 20-01-2025, as required in stashfin development and as well as our later development process
             * updated in live on 27-01-2025
             */
            $borrower_kyc_detail                    = BorrowerKyc::where('borrower_id', $borrower_id)->first();
            $borrower_kyc_detail->policy_number     = $request->insurance_policy_number ?? null;
            $borrower_kyc_detail->chasis_number     = $request->chasis_number ?? null;

            $borrower_kyc_detail->policy_premium    = $request->policy_premium ?? null;
            $borrower_kyc_detail->policy_start_date = $request->policy_start_date ?? null;
            $borrower_kyc_detail->policy_end_date   = $request->policy_end_date ?? null;
            $borrower_kyc_detail->vehicle_reg_no    = $request->vehicle_reg_no ?? null;
            $borrower_kyc_detail->save();

            $this->logStep($borrower_id, $currentStep, 'Vehicle Documents Upload', 'b2c');
    
            DB::commit();
            return response()->json([
                'status'=>'success',
                'message'=>"Vehicle documents stored successfully",
                'data'=> ''
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack(); 
            return response()->json([
                'status' => 'failed',
                'message' => $e->getMessage(),
            ], 422);
        } 
    }

    public function battery_doc_upload(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            // $currentStep = 27;
            $currentStep = 22;
            date_default_timezone_set('Asia/Kolkata');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            }

            if(!isset($input['invoice']) || empty($input['invoice'])){
                $errorCount++;
                $errorArray['invoice'] = "Invoice is required";
                return $this->send_response("failed", $errorArray['invoice'], 422);
            }

            if(!isset($input['warranty_card']) || empty($input['warranty_card'])){
                $errorCount++;
                $errorArray['warranty_card'] = "Warrenty card is required";
                return $this->send_response("failed", $errorArray['warranty_card'], 422);
            }

            // if(!isset($input['signed_cheque']) || empty($input['signed_cheque'])){
            //     $errorCount++;
            //     $errorArray['signed_cheque'] = "Signed cheque is required";
            //     return $this->send_response("failed", $errorArray['signed_cheque'], 422);
            // } elseif(!is_array($input['signed_cheque']) || count($input['signed_cheque']) < 2) {
            //     $errorCount++;
            //     $errorArray['signed_cheque'] = "2 Signed cheque is required";
            //     return $this->send_response("failed", $errorArray['signed_cheque'], 422);
            // }
            
            if(!isset($input['bank_statement']) || empty($input['bank_statement'])){
                $errorCount++;
                $errorArray['bank_statement'] = "Bank statement is required";
                return $this->send_response("failed", $errorArray['bank_statement'], 422);
            }

            if (!isset($input['noc_document']) || empty($input['noc_document'])) {
                $errorCount++;
                $errorArray['noc_document'] = "NOC document is required";
                return $this->send_response("failed", $errorArray['noc_document'], 422);
            }

            if (!isset($input['down_payment']) || empty($input['down_payment'])) {
                $errorCount++;
                $errorArray['down_payment'] = "Down payment amount is required";
                return $this->send_response("failed", $errorArray['down_payment'], 422);
            }

            if ($errorCount > 0) {
                return $this->send_response("failed", "Invalid data", 422, $errorArray);
            }

            DB::beginTransaction();
            $userId         = $request->user()->id;
            $borrower_id    = $this->get_borrower_id($request->user()->id);

            $loan_details = LoanBasicDetails::find($borrower_id);
            if (!$loan_details) {
                return $this->helper_controller->send_response('failed', "Borrower not found", 422);
            }

            $ip = $this->get_user_ip();
            if (isset($input['signed_cheque']) && is_array($input['signed_cheque']) && count($input['signed_cheque']) > 0) {

                if (count($input['signed_cheque']) !== 2) {
                    return $this->send_response("failed", "Exactly 2 signed cheque file paths are required if provided", 422);
                }

                $accountNumbers = $input['account_number'] ?? [];
                $chequeNumbers = $input['cheque_number'] ?? [];
                $ifscCodes = $input['ifsc_code'] ?? [];

                if (
                    count($accountNumbers) !== 2 ||
                    count($chequeNumbers) !== 2 ||
                    count($ifscCodes) !== 2
                ) {
                    return $this->send_response("failed", "Exactly 2 account numbers, cheque numbers, and IFSC codes are required", 422);
                }

                for ($i = 0; $i < 2; $i++) {
                    $data = [
                        'account_number' => $accountNumbers[$i] ?? null,
                        'cheque_no' => $chequeNumbers[$i] ?? null,
                        'ifsc' => $ifscCodes[$i] ?? null,
                        'file_path' => $input['signed_cheque'][$i] ?? null,
                    ];

                    // Validate input
                    $validator = Validator::make($data, [
                        'account_number' => 'required|string',
                        'cheque_no' => 'required|string',
                        'ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
                        'file_path' => 'required|string'
                    ]);

                    if ($validator->fails()) {
                        return $this->send_response("failed", "Validation failed for cheque #" . ($i + 1) . ": " . $validator->errors()->first(), 422);
                    }

                    // Check for duplicate cheque number
                    $exists = BorrowerCheques::where('borrower_id', $loan_details->id)
                        ->where('cheque_no', $data['cheque_no'])
                        ->where('status', '<>', 'deleted')
                        ->exists();

                    if ($exists) {
                        return $this->send_response("failed", "Cheque no {$data['cheque_no']} already exists for this borrower", 422);
                    }

                    // Extract relative path from public URL
                    $sourcePath = str_replace('/storage/', '', parse_url($data['file_path'], PHP_URL_PATH));

                    // Determine new path
                    $chequeNo = $data['cheque_no'];
                    $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
                    $targetDir = "Borrower/{$loan_details->borrower_id}/Borrower_cheques";
                    $targetFileName = $chequeNo . '.' . $extension;
                    $targetPath = "{$targetDir}/{$targetFileName}";

                    // Copy file
                    $copied = Storage::disk('public')->copy($sourcePath, $targetPath);

                    if (!$copied) {
                        return $this->send_response("failed", "Failed to copy cheque file for cheque no {$chequeNo}", 500);
                    }

                    // Save record
                    $cheque = new BorrowerCheques();
                    $cheque->borrower_id = $loan_details->id;
                    $cheque->file = 'storage/' . $targetPath; // public URL path
                    $cheque->cheque_no = $chequeNo;
                    $cheque->ifsc = $data['ifsc'];
                    $cheque->account_number = $data['account_number'];
                    $cheque->status = 'active';
                    $cheque->ip = $ip;
                    $cheque->modified_by = auth()->id();
                    $cheque->save();
                }
            }
            
            $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();

            if(!$kyc_docs) {
                return $this->send_response('failed', "Invalid request", 422);
            }
            
            $kyc_docs->invoice          = $input['invoice'];
            $kyc_docs->warrant_document = $input['warranty_card'];
            $kyc_docs->bank_statement   = $input['bank_statement'];
            $kyc_docs->noc_document     = $input['noc_document'] ?? null;

            if(is_array($input['signed_cheque'])) {
                $signed_cheque = implode(',', $input['signed_cheque']);
            } else {
                $signed_cheque = $input['signed_cheque'];
            }

            $kyc_docs->battery_signed_cheque    = $signed_cheque ?? '';

            $kyc_docs->save();
            $application_step = [
                'step' => $currentStep,
                'kyc_doc_id' => $kyc_docs->id,
                'details' => "Battery Documents Upload",
                'status' => "COMPLETED",
            ];

            $this->updateApplicationStep($userId, $borrower_id, $application_step);


            $loan_details = LoanBasicDetails::find($borrower_id);
            if($loan_details) {
                $loan_details->down_payment = $input['down_payment'] ?? 0;
                $loan_details->save();
            }
            
            $this->logStep($borrower_id, $currentStep, 'Battery Documents Upload', 'b2c');
            DB::commit();
            
            return $this->send_response('success', "Battery documents stored successfully", 201);
        } catch (Exception $e) {
            DB::rollBack();
            
            return $this->send_response('failed', $e->getMessage(), 422);
        } 
    }

    /**
     * Credit analytics by DIGITAP
     */
    // public function get_credit_score_details_old($data) {
    //     $errorArray = [];
    //     $errorCount = 0;
    //     $result_response = [];

    //     date_default_timezone_set('Asia/Kolkata');

    //     // $service_api    = new ServiceApiController;
    //     $ip             = $this->get_user_ip();
    //     $otp            = $this->generate_random_number(4);
    //     $client_ref_num = $this->generate_random_id();
        
    //     $mobile_no      = $data['mobile_no'];
    //     $name_parts     = explode(" ", $data['name']);
    //     $first_name     = $name_parts[0];
    //     $last_name      = $name_parts[count($name_parts) - 1];
    //     $dob            = date('Y-m-d', strtotime($data['dob']));
    //     $email          = $data['email'];
    //     $pan            = $data['pan'];
    //     $device_type    = 'web';

    //     $jsonRequestBody = json_encode([
    //         "client_ref_num" => $client_ref_num,
    //         "mobile_no" => $mobile_no,
    //         "name_lookup" => 0,
    //         "first_name" => $first_name,
    //         "last_name" => $last_name,
    //         "date_of_birth" => $dob,
    //         "email" => $email,
    //         "pan" => $pan,
    //         "consent_message" => "You hereby consent to CLIENT being appointed as your authorized representative to receive your Credit Information from Experian for the purpose of (End Use Purpose).",
    //         "consent_acceptance" => 'Yes',
    //         "device_type" => $device_type,
    //         "otp" => $otp,
    //         "timestamp" => date('dmY-H:i:s'),
    //         "device_ip" => $ip,
    //     ]);

    //     try{
    //         $curl = curl_init();
    //         if ($curl === false) {
    //             throw new Exception('Failed to initialize cURL');
    //         }

    //         curl_setopt_array($curl, array(
    //             CURLOPT_URL => 'https://api.digitap.ai/credit_analytics/request',
    //             CURLOPT_RETURNTRANSFER => true,
    //             CURLOPT_ENCODING => '',
    //             CURLOPT_MAXREDIRS => 10,
    //             CURLOPT_TIMEOUT => 0,
    //             CURLOPT_FOLLOWLOCATION => true,
    //             CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    //             CURLOPT_CUSTOMREQUEST => 'POST',
    //             CURLOPT_POSTFIELDS => $jsonRequestBody,
    //             CURLOPT_HTTPHEADER => array(
    //                 'Accept: application/json',
    //                 'Authorization: Basic '.$this->prod_authorization_key,
    //                 'Content-Type: application/json'
    //             ),
    //         ));

    //         $response = curl_exec($curl);
    //         if ($response === false) {
    //             throw new Exception('cURL Error: ' . curl_error($curl));
    //         }

    //         $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    //         curl_close($curl);
    //         // echo $response;
    //         // die();

    //         $result = json_decode($response);

    //         if (json_last_error() !== JSON_ERROR_NONE) {
    //             throw new Exception('JSON decode error: ' . json_last_error_msg());
    //         }
            
    //         if(isset($result->http_response_code) && $result->http_response_code == 200) {
    //             $api_status = "VALID";
    //         } else {
    //             $api_status = "INVALID";
    //         }

    //         $vendorServiceApi               = new VendorServiceApi;
    //         $vendorServiceApi->user_id      = $user_id;
    //         $vendorServiceApi->ip_address   = $ip;
    //         $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
    //         $vendorServiceApi->status       = $api_status;
    //         $vendorServiceApi->api_flag     = "CREDIT_SCORE_API_DIGITAP_EXPERIAN";
    //         $vendorServiceApi->request      = json_encode($input);
    //         $vendorServiceApi->response     = $response;
    //         $vendorServiceApi->save();

    //         if(isset($result->http_response_code) && $result->http_response_code == 200) {
    //             $api_status = "VALID";
    //             if($result->result_code == 101) {
    //                 $message = "Pan details fetched";
    //             } elseif($result->result_code == 103) {
    //                 $message = $result->message;
    //             } else {
    //                 $message = "";
    //             }
    //             $result_response = [
    //                 'status' => 'success',
    //                 'result_code' => isset($result->result_code) ? $result->result_code : '',
    //                 'message' => $message,
    //                 'data' => [
    //                     'BureauScore' => $result->result->result_json->INProfileResponse->SCORE->BureauScore,
    //                 ],
    //             ];
    //         } else {
    //             $api_status = "INVALID";
    //             $result_response = [
    //                 'status' => 'failed',
    //                 'message' => "No results",
    //                 'data' => [
    //                 ],
    //             ];
    //         }

    //     } catch (Exception $e) {
    //         $result_response = [
    //             'status' => 'error',
    //             'message' => $e->getMessage(),
    //             'data' => [],
    //         ];
    //     }

    //     return $result_response;
    // }

    /**
     * Credit analytics report by Signzy [experian]
     */
    public function get_credit_score_details($data) {
        $ip             = $this->get_user_ip();
        $phone = $data['mobile_no'];
        $user_id = $data['user_id'];
        $pan = $data['pan'];
        $pin = $data['pin'];
        $name_parts     = explode(" ", $data['name']);
        $first_name     = $name_parts[0];
        $last_name      = $name_parts[count($name_parts) - 1];
        $dob            = date('Y-m-d', strtotime($data['dob']));

        $jsonRequestBody = json_encode([
            "phoneNumber" => intval($phone),
            "pan" => $pan,
            "firstName" => $first_name,
            "lastName" => $last_name,
            "dateOfBirth" => $dob,
            "pincode" => intval($pin),
            "consent" => [
                "consentFlag" => true,
                "consentTimestamp" => strtotime(date('Y-m-d')),
                "consentIpAddress" => $ip
            ]
        ]);

        $result_response = [];

        try{
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api-preproduction.signzy.app/api/v3/bureau/experian-report',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS =>$jsonRequestBody,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Authorization: '. $this->signzy_auth_key
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }

            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            // echo $response;
            // die();
            $result = json_decode($response);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }
            
            if(isset($result->statusCode) && $result->statusCode == 200) {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi               = new VendorServiceApi;
            $vendorServiceApi->user_id      = $user_id;
            $vendorServiceApi->ip_address   = $ip;
            $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
            $vendorServiceApi->status       = $api_status;
            $vendorServiceApi->api_flag     = "CREDIT_SCORE_API_SIGNZY_EXPERIAN";
            $vendorServiceApi->request      = json_encode($data);
            $vendorServiceApi->response     = $response;
            $vendorServiceApi->save();

            if(isset($result->statusCode) && $result->statusCode == 200) {
                $api_status = "VALID";
                if(isset($result->data->jsonExperianReport->CAIS_Account->CAIS_Account_DETAILS[0]->CAIS_Account_History) && !empty($result->data->jsonExperianReport->CAIS_Account->CAIS_Account_DETAILS[0]->CAIS_Account_History)) {

                    $dpds = $result->data->jsonExperianReport->CAIS_Account->CAIS_Account_DETAILS[0]->CAIS_Account_History;
                    $dpd_data = $this->calculate_dpds($dpds);
                }
                if(isset($result->data->jsonExperianReport->CAIS_Account->CAIS_Account_DETAILS[0]->SuitFiledWillfulDefaultWrittenOffStatus) && !empty($result->data->jsonExperianReport->CAIS_Account->CAIS_Account_DETAILS[0]->SuitFiledWillfulDefaultWrittenOffStatus)) {
                    $is_willful_defaulter = true;
                } else {
                    $is_willful_defaulter = false;
                }
                $result_response = [
                    'status' => 'success',
                    'result_code' => isset($result->statusCode) ? $result->statusCode : '',
                    'message' => "Credit details fetched",
                    'data' => [
                        'BureauScore' => $result->data->jsonExperianReport->SCORE->FCIREXScore,
                        'is_willful_defaulter' => $is_willful_defaulter,
                        'dpd_in_3_month' => isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0,
                        'dpd_in_1_year' => isset($dpd_data['dpd_in_1_year']) ? $dpd_data['dpd_in_1_year'] : 0,
                    ],
                ];
            } else {
                $api_status = "INVALID";
                $result_response = [
                    'status' => 'failed',
                    'message' => isset($result->message) ? $result->message : "Credit history not found",
                ];
            }

            return $result_response;

        } catch (Exception $e) {
            $result_response = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function calculate_dpds($dpds) {
        $monthCount = 0;
        $dpd_in_3_month = 0;
        $dpd_in_1_year = 0;
        foreach ($dpds as $entry) {
             $monthCount++;
            $year = $entry->Year;
            $month = $entry->Month;
            $dpd = $entry->Days_Past_Due;
    
            if($monthCount < 4) {
                if($dpd > 30) {
                    $dpd_in_3_month++;
                }
            }
    
            if($monthCount <= 12) {
                if($dpd > 90) {
                    $dpd_in_1_year++;
                }
            } else {
                break;
            }
        }
    
        $data = [
            'dpd_in_3_month' => $dpd_in_3_month,
            'dpd_in_1_year' => $dpd_in_1_year,
        ];
        return $data;
    }

    // calculate Reducing Interest
    public function calculateEMI($principal, $annualInterestRate, $tenureMonths) {
        // Convert annual interest rate to monthly interest rate
        $monthlyInterestRate = ($annualInterestRate / 100) / 12;
        
        // Calculate EMI using the reducing balance formula
        $emi = ($principal * $monthlyInterestRate * pow((1 + $monthlyInterestRate), $tenureMonths)) / (pow((1 + $monthlyInterestRate), $tenureMonths) - 1);
        
        return $emi;
    }

    public function calculateFlatInterestEMI($principal, $annualInterestRate, $tenureInMonths) {
        $annualInterestRate = $annualInterestRate / 100; // Convert percentage to decimal
        $flatInterest = ($principal * $annualInterestRate * $tenureInMonths) / 12;
        $totalRepayment = $principal + $flatInterest;
        return $totalRepayment / $tenureInMonths; // Return the EMI
    }

    // public function generateRepaymentSchedule($loan_amount, $interest, $tenure) {
    //     $netLoanAmount      = $loan_amount; 
    //     $annualInterestRate = $interest;
    //     $tenure             = $tenure;

    //     $monthlyInterestRate = $annualInterestRate / 12 / 100;
    //     $monthlyPayment = $netLoanAmount / $tenure + ($netLoanAmount * $monthlyInterestRate);

    //     $schedule = [];
    //     $remainingBalance = $netLoanAmount;

    //     for ($month = 1; $month <= $tenure; $month++) {
    //         $interestPortion = $remainingBalance * $monthlyInterestRate;
    //         $principalPortion = $monthlyPayment - $interestPortion;
    //         $remainingBalance -= $principalPortion;

    //         $schedule[] = [
    //             'month' => $month,
    //             'principal' => round($principalPortion, 2),
    //             'interest' => round($interestPortion, 2),
    //             'total_payment' => round($monthlyPayment, 2),
    //             'remaining_balance' => round(max($remainingBalance, 0), 2)
    //         ];
    //     }

    //     return $schedule;
    // }
    function generateRepaymentSchedule($loan_amount, $interest, $tenure) {
        $netLoanAmount = $loan_amount;
        $annualInterestRate = $interest;
        $tenure = $tenure;

        // Total interest for the entire tenure
        $totalInterest = ($netLoanAmount * $annualInterestRate * $tenure) / 1200; // Divided by 1200 to account for % and annual to monthly conversion
        // Monthly interest amount (same for all EMIs)
        $monthlyInterest = $totalInterest / $tenure;
        // Monthly principal amount (same for all EMIs)
        $monthlyPrincipal = $netLoanAmount / $tenure;
        // Monthly payment (EMI)
        $monthlyPayment = $monthlyPrincipal + $monthlyInterest;
    
        $schedule = [];
        $remainingBalance = $netLoanAmount;
    
        for ($month = 1; $month <= $tenure; $month++) {
            $remainingBalance -= $monthlyPrincipal;
    
            $schedule[] = [
                'month' => $month,
                'principal' => round($monthlyPrincipal, 2),
                'interest' => round($monthlyInterest, 2),
                'total_payment' => round($monthlyPayment, 2),
                'remaining_balance' => round(max($remainingBalance, 0), 2)
            ];
        }
    
        return $schedule;
    }
    

    public function generateReducialRepaymentSchedule($loan_amount, $interest, $tenure) {
        $monthlyInterestRate = $interest / 12 / 100;
        $monthlyPayment = $loan_amount * $monthlyInterestRate / (1 - pow(1 + $monthlyInterestRate, -$tenure));

        $schedule = [];
        $remainingBalance = $loan_amount;

        for ($month = 1; $month <= $tenure; $month++) {
            $interestPortion = $remainingBalance * $monthlyInterestRate;
            $principalPortion = $monthlyPayment - $interestPortion;
            $remainingBalance -= $principalPortion;

            $schedule[] = [
                'month' => $month,
                'principal' => round($principalPortion, 2),
                'interest' => round($interestPortion, 2),
                'total_payment' => round($monthlyPayment, 2),
                'remaining_balance' => round(max($remainingBalance, 0), 2)
            ];
        }

        return $schedule;
    }

    
    public function rule_engine(Request $request) {
        try{

            $data = [];
            $product_list = [
                [
                    'product_id' => "1",
                    'name' => "E-Rickshaw (L3)",
                    'label' => "Apply Loan",
                    'icon' => asset('Assets/products/eRickshaw.png')
                ],
                [
                    'product_id' => "2",
                    'name' => "E-Auto (L5)",
                    'label' => "Apply Loan",
                    'icon' => asset('Assets/products/tuk-tuk.png')
                ],
                [
                    'product_id' => "3",
                    'name' => "E-Two Wheeler",
                    'label' => "Apply Loan",
                    'icon' => asset('Assets/products/two.png')
                ],
                [
                    'product_id' => "4",
                    'name' => "Battery",
                    'label' => "Apply Loan",
                    'icon' => asset('Assets/products/car-battery.png')
                ],
                [
                    'product_id' => "5",
                    'name' => "EKA L5-3S",
                    'label' => "Apply Loan",
                    'icon' => ''
                ],
                [
                    'product_id' => "6",
                    'name' => "EKA L5-6S",
                    'label' => "Apply Loan",
                    'icon' => ''
                ],
            ];

            $userId                     = $request->user()->id;
            $borrower_id                = $this->get_borrower_id($request->user()->id);
            $LoanApplicationSummary     = LoanApplicationSummary::where('user_id', $userId)
                                            ->where('borrower_id', $borrower_id)
                                            ->first();
            if(!$LoanApplicationSummary) {
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Invalid request",
                ], 422);
            }
            // $product_id = $LoanApplicationSummary->product_id;

            $loan_basic_details = LoanBasicDetails::find($borrower_id); 
            if(!$loan_basic_details) {
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Invalid request",
                ], 422);
            }
            $product_id     = $loan_basic_details->product_id;
            $loan_amount    = $loan_basic_details->loan_amount;
            $tenure         = $loan_basic_details->loan_term;
            $dealer_id      = $loan_basic_details->dealer_id;
            $model          = $loan_basic_details->model;
            $model_id       = $loan_basic_details->model_id;

            // ── dynamic product → shared service ──
            $dynamicProduct = \App\Models\OemDynamicProduct::where('oem_product_unq', $model_id)->first();
            if ($dynamicProduct) {
                /** @var \App\Services\Loan\DynamicOfferService $svc */
                $svc = app(\App\Services\Loan\DynamicOfferService::class);
                $out = $svc->resolve(
                    $dynamicProduct, $loan_basic_details, $LoanApplicationSummary, $borrower_id, 'b2c',
                    fn ($bid) => $this->sendSanctionMail($bid),
                    fn () => $this->updateApplicationStep($userId, $borrower_id, [
                        'step' => 9, 'rule_engine_id' => '', 'details' => 'Loan Offers Completed', 'status' => 'COMPLETED',
                    ]),
                );
                return response()->json($out['body'], $out['code']);
            }
            // // ── else legacy body continues unchanged ──

            $dealer_product_details = BorrowerLoanScheme::where('borrower_id', $borrower_id)->first();
            if(!$dealer_product_details) {
                $dealer_query = Dealer::join('product_models', 'dealers.id', '=', 'product_models.dealer_id')
                    ->where('dealers.dealer_unq', $dealer_id)
                    ->select(
                        'dealers.id as dealer_primary',
                        'product_models.interest',
                        'product_models.processing_fee',
                        'product_models.subvention_amount',
                        'product_models.fldg',
                        'product_models.iot',
                        'product_models.insurance_1st_year',
                        'product_models.insurance_2nd_year',
                        'product_models.interest_type'
                    );

                if (!empty($model_id)) {
                    $dealer_query->where('product_models.model_unq', $model_id);
                } else {
                    $dealer_query->where('product_models.name', $model);
                }

                $dealer_product_details = $dealer_query->first();
            }

            if (!$dealer_product_details) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Invalid dealer or product',
                ], 422);
            }

            // Extract product details
            $product_interest           = $dealer_product_details->interest;
            $product_processing_fee     = $dealer_product_details->processing_fee;
            $product_subvention_amount  = $dealer_product_details->subvention_amount;
            $product_fldg               = $dealer_product_details->fldg;
            $product_iot                = $dealer_product_details->iot;
            $product_insurance_1st_year = $dealer_product_details->insurance_1st_year;
            $product_insurance_2nd_year = $dealer_product_details->insurance_2nd_year;
            $product_interest_type      = !empty($dealer_product_details->interest_type) ? $dealer_product_details->interest_type : 'flat';

            $borrower_kyc_details = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
            if($borrower_kyc_details) {
                $gender             = $borrower_kyc_details->gender;
                $skip_co_applicant  = $borrower_kyc_details->skip_co_applicant;
                $skip_guarantor     = $borrower_kyc_details->skip_guarantor ?? false;
                $dob                = $borrower_kyc_details->dob_date;
                $dobTimestamp       = strtotime($dob);
                $age                = date('Y') - date('Y', $dobTimestamp);
                $cibil              = isset($LoanApplicationSummary->cibil_score) ? $LoanApplicationSummary->cibil_score : 0;
            }
            
            $borrower_address = BorrowerAddress::where('borrower_id', $borrower_id)->latest()->first();
            if($borrower_address) {
                $residence = $borrower_address->resident_type;
                $residence_stability = $borrower_address->years_living;
            }

            $field_inspection_details = FieldInspectionDetails::where('borrower_id', $borrower_id)->latest()->first();
            if($field_inspection_details) {
                $is_field_verified = $field_inspection_details->is_inspection_verified;
            }

            $co_applicant_details = CoapplicantKyc::where('borrower_id', $borrower_id)->latest()->first();
            if($co_applicant_details) {
                $co_applicant_id = $co_applicant_details->id;
            }
            $co_applicant_cibil = isset($LoanApplicationSummary->coappli_cibil_score) ? $LoanApplicationSummary->coappli_cibil_score : 0;

            $guarantor_details = Guarantor::where('borrower_id', $borrower_id)->latest()->first();
            if($guarantor_details) {
                $guarantor_id = $guarantor_details->id;
                $guarantor_cibil = isset($LoanApplicationSummary->guarantor_cibil_score) ? $LoanApplicationSummary->guarantor_cibil_score : 0;
            }

            if($product_id == 3) {
                $request['product'] = 'EV Two Wheeler';
            } elseif($product_id == 4) {
                $request['product'] = 'Battery';
            } else {
                $request['product'] = 'E-Rickshaw L3';
            }

            if(isset($request['product'])) {
                $data['product'] = $request['product'];
            }
            
            $data['loan_amount']                    = isset($loan_amount) ? intval($loan_amount) : 0;
            $data['tenure']                         = isset($tenure) ? $tenure : 0;
            $data['age']                            = isset($age) ? $age : 0;
            $data['cibil_score']                    = isset($cibil) ? $cibil : 0;
            $data['co_applicant_cibil']             = isset($co_applicant_cibil) ? $co_applicant_cibil : 0;
            $data['guarantor_cibil']                = isset($guarantor_cibil) ? $guarantor_cibil : 0;
            $data['co_applicant']                   = isset($co_applicant_id) ? $co_applicant_id : 0;
            $data['guarantor']                      = isset($guarantor_id) ? $guarantor_id : 0;
            $data['residence_type']                 = isset($residence) ? $residence : "";
            $data['gender']                         = isset($gender) ? $gender : "";
            $data['skip_co_applicant']              = isset($skip_co_applicant) ? $skip_co_applicant : false;
            $data['skip_guarantor']                 = isset($skip_guarantor) ? $skip_guarantor : false;
            $data['residence_stability']            = isset($residence_stability) ? $residence_stability : "";
            $data['field_investigation_verified']   = true; //isset($is_field_verified) ? $is_field_verified : false;
            
            $loanAmount = intval($data['loan_amount']);
            $loanTenure = $data['tenure'];
            
            $roi = $product_interest;
            $loan_amount = $loanAmount;
            $interest = $roi;
            $tenure = $loanTenure;
            if($tenure > 0 && $tenure <= 15) {
                $second_year_insurance = 0;
            } elseif($tenure > 15 && $tenure <= 24) {
                $second_year_insurance = $product_insurance_2nd_year;
            } else {
                $second_year_insurance = 0;
            }

            $net_loan_amount = $loan_amount + $product_iot + $second_year_insurance;

            $net_disbursal = $net_loan_amount - ($product_processing_fee + $product_subvention_amount + $product_insurance_1st_year + $product_fldg + $product_iot + $second_year_insurance);

            // $emi_chart_details = $this->helper_controller->generateReducingInterestSchedule($net_loan_amount, $interest, $tenure);

            $emi_chart_details = $this->generateInterestSchedule($net_loan_amount, $interest, $tenure, $product_interest_type);
            $emi_chart  = $emi_chart_details['schedule'];
            $emi_amount = $emi_chart_details['emi_amount'];
            $loan_offers = [
                [
                    'id' => "2",
                    'lender_id' => "3",
                    'lender' => "Offer 2",
                    'loan_amount' => intval($net_loan_amount),
                    'interest' => $interest,
                    "terms" => $data['tenure']." month",
                    "emi_per_month" => number_format($emi_amount, 2,".",""),
                    "loan_details_break_down" => [
                        'loan_amount' => intval($loan_amount),
                        'roi' => floatval($interest),
                        'tenure' => $tenure. ' months',
                        'iot_amount' => $product_iot,
                        'first_year_insurance_amount' => $product_insurance_1st_year,
                        'second_year_insurance_amount' => $second_year_insurance,
                        'processing_fee' => $product_processing_fee,
                        'subvention_amount' => $product_subvention_amount,
                        'fldg' => $product_fldg,
                        'net_loan_amount' => $net_loan_amount,
                        'net_disbursal' => $net_disbursal,
                        'emi' => round($emi_amount)
                    ],
                    "repayment_schedule"  => $emi_chart
                ]
            ];

            // $product_id = 1;
            if($product_id == '1') {
                $eligibility_check =  $this->rule_e_rickshaw_l3($data);
            } elseif($product_id == '2') {
                // $eligibility_check =  $this->rule_e_rickshaw_lithium_acid_l3($data);
                $eligibility_check =  $this->rule_e_auto_rickshaw_l5($data);
            } elseif($product_id == '3') {
                $eligibility_check =  $this->rule_ev_two_wheeler($data);
            } elseif($product_id == '4') {
                $eligibility_check =  $this->rule_battery($data);
            } elseif($product_id == '5' || $product_id == '6') {
                $eligibility_check =  $this->rule_eka($data);
            }

            if(isset($eligibility_check['is_eligible'])) {
                if($eligibility_check['is_eligible'] === true) {

                    // send sanction letter
                    $this->sendSanctionMail($borrower_id);
                    
                    $application_step = [
                        'step' => 9, //$currentStep,
                        'rule_engine_id' => '', //$loan_offer->id,
                        'details' => "Loan Offers Completed",
                        'status' => "COMPLETED",
                    ];
        
                    $this->updateApplicationStep($userId, $borrower_id, $application_step);
                    
                    $this->logStep($borrower_id, 9, 'Loan Offers Completed', 'b2c');
                    return response()->json([
                        'status' =>'success',
                        'message' => "You are eligible for loan",
                        'data' => $eligibility_check['data'],
                        'offers' => $loan_offers
                    ], 200);
                } else {

                    return response()->json([
                        'status' =>'failed',
                        'message' => "You are not eligible",
                        'reason' => isset($eligibility_check['reason']) ? $eligibility_check['reason'] : '',
                    ], 422);
                }
            } else {
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Invalid request",
                ], 422);
            }
        }  catch (Exception $e) {
            return response()->json([
                'status'=>'failed',
                'message'=> $e->getMessage(),
            ], 422);
        }
    }

    public function rule_e_rickshaw_l3($data){
        try {

            $min_loan_amount = 50000;
            // $max_loan_amount = 150000; // Disabled on 11-02-2025
            // $max_loan_amount = 157000; // disabled on 05-03-2025
            // $max_loan_amount = 180000; // Enabled on 05-03-2025
            $max_loan_amount = 192500; // Enabled on 01-06-2026
            
            $min_tenure = 6;
            // $max_tenure = 24; // Disabled on 11-02-2025
            $max_tenure = 30; // Enabled on 11-02-2025

            $min_age = 18;
            $max_age = 55;
            
            $min_cibil_score = 650;
            $min_cibil_score_coapplicant = 650;
            $min_cibil_score_guarantor = 650;
            $residence_stability_owned = 3;
            $residence_stability_rented = 2;
            
            $ltv = [
                'on_road' => 75,
                'ex_showroom' => 80
            ];
            $not_eligible_reason_count = 0;
            $not_eligible_reasons = [];
            $eligibility_count = 0;

            $is_eligible = false;
            if(isset($data['loan_amount']) && $data['loan_amount'] >= $min_loan_amount && $data['loan_amount'] <= $max_loan_amount) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['loan_amount'] = "Loan amount must be between ".$min_loan_amount ." and ".$max_loan_amount;
            }

            if(isset($data['tenure']) && $data['tenure'] >= $min_tenure && $data['tenure'] <= $max_tenure) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['tenure'] = "Tenure must be between ".$min_tenure ." and ".$max_tenure;
            }

            if(isset($data['age']) && $data['age'] >= $min_age && $data['age'] <= $max_age) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['age'] = "Age must be between ".$min_age ." and ".$max_age;
            }
            
            // $coapplicant_exist = true; co appplicant is mandatory

            if($data['skip_co_applicant'] !== true) {
                
                if(isset($data['co_applicant']) && !empty($data['co_applicant'])) {
                    $eligibility_count++;
                } else {
                    $not_eligible_reason_count++;
                    $not_eligible_reasons['co_applicant'] = "Co applicant is required";
                }
                
                // if(isset($data['co_applicant_cibil']) && ($data['co_applicant_cibil'] == 0 || $data['co_applicant_cibil'] >= $min_cibil_score_coapplicant)) {
                //     $eligibility_count++;
                // } else {
                //     $not_eligible_reason_count++;
                //     $not_eligible_reasons['co_applicant_cibil'] = "Minimum cibil score required is ".$min_cibil_score_coapplicant;
                // }
            }
            
            // if(isset($data['cibil_score']) && ($data['cibil_score'] == 0 || $data['cibil_score'] >= $min_cibil_score)) {
            //     $eligibility_count++;
            // } else {
            //     $not_eligible_reason_count++;
            //     $not_eligible_reasons['cibil_score'] = "Minimum cibil score required is ".$min_cibil_score;
            // }
            
            // if(isset($data['guarantor_cibil']) && $data['guarantor_cibil'] >= $min_cibil_score_guarantor) {
            //     $eligibility_count++;
            // } else {
            //     $not_eligible_reason_count++;
            //     $not_eligible_reasons['guarantor_cibil'] = "Minimum cibil score required is ".$min_cibil_score_guarantor;
            // }
            
            // $field_investigation_verified = true; // field inspection is mandatory
            if(isset($data['field_investigation_verified']) && !empty($data['field_investigation_verified']) && $data['field_investigation_verified'] == true) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['field_investigation_verified'] = "Field investigation must be verified";
            }

            if(isset($data['residence_type']) && $data['residence_type'] == "Owned") {
                if(isset($data['residence_stability']) && $data['residence_stability'] >= $residence_stability_owned) {
                    $eligibility_count++;
                } else {
                    $not_eligible_reason_count++;
                    $not_eligible_reasons['residence_stability'] = "Residence stability should be atleast ".$residence_stability_owned;
                }
            }
            if(isset($data['residence_type']) && $data['residence_type'] == "Rented") {
                if(isset($data['residence_stability']) && $data['residence_stability'] >= $residence_stability_rented) {
                    $eligibility_count++;
                } else {
                    $not_eligible_reason_count++;
                    $not_eligible_reasons['residence_stability'] = "Residence stability should be atleast ".$residence_stability_rented;
                }
            }

            if($not_eligible_reason_count == 0) {
                return [
                    'is_eligible' => true,
                    'data' => $data
                ];
            } else {
                return [
                    'is_eligible' => 'false',
                    'reason' => $not_eligible_reasons
                ];
            }
        } catch (Exception $e) {
            return [
                'is_eligible' => 'false',
                'reason' => $e->getMessage()
            ];
        }
    }

    public function rule_e_rickshaw_lithium_acid_l3($data){
        $min_loan_amount = 50000;
        $max_loan_amount = 175000;
        
        $min_tenure = 9;
        $max_tenure = 30;

        $min_age = 18;
        $max_age = 55;
        
        $min_cibil_score = 650;
        $min_cibil_score_coapplicant = 650;
        $min_cibil_score_guarantor = 650;
        $residence_stability_owned = 3;
        $residence_stability_rented = 2;

        $ltv = [
            'on_road' => 80,
            'ex_showroom' => 85
        ];

        $not_eligible_reason_count = 0;
        $not_eligible_reasons = [];
        $eligibility_count = 0;

        $is_eligible = false;
        if(isset($data['loan_amount']) && $data['loan_amount'] >= $min_loan_amount && $data['loan_amount'] <= $max_loan_amount) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['loan_amount'] = "Loan amount must be between ".$min_loan_amount ." and ".$max_loan_amount;
        }

        if(isset($data['tenure']) && $data['tenure'] >= $min_tenure && $data['tenure'] <= $max_tenure) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['tenure'] = "Tenure must be between ".$min_tenure ." and ".$max_tenure;
        }

        if(isset($data['age']) && $data['age'] >= $min_age && $data['age'] <= $max_age) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['age'] = "Age must be between ".$min_age ." and ".$max_age;
        }
        
        // $coapplicant_exist = true; co appplicant is mandatory

        // if(isset($data['co_applicant']) && !empty($data['co_applicant'])) {
        //     $eligibility_count++;
        // } else {
        //     $not_eligible_reason_count++;
        //     $not_eligible_reasons['co_applicant'] = "Co applicant is required";
        // }

        if(isset($data['cibil_score']) && $data['cibil_score'] >= $min_cibil_score) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['cibil_score'] = "Minimum cibil score required is ".$min_cibil_score;
        }

        if(isset($data['co_applicant_cibil']) && $data['co_applicant_cibil'] >= $min_cibil_score_coapplicant) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['co_applicant_cibil'] = "Minimum cibil score required for coapplicant is ".$min_cibil_score_coapplicant;
        }

        if(isset($data['guarantor_cibil']) && $data['guarantor_cibil'] >= $min_cibil_score_guarantor) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['guarantor_cibil'] = "Minimum cibil score required is ".$min_cibil_score_guarantor;
        }

        // $field_investigation_verified = true; // field inspection is mandatory
        if(isset($data['field_investigation_verified']) && !empty($data['field_investigation_verified'])) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['field_investigation_verified'] = "Field investigation must be verified";
        }

        if(isset($data['residence_type']) && $data['residence_type'] == "Owned") {
            if(isset($data['residence_stability']) && $data['residence_stability'] >= $residence_stability_owned) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['residence_stability'] = "Residence stability should be atleast ".$residence_stability_owned;
            }
        }
        if(isset($data['residence_type']) && $data['residence_type'] == "Rented") {
            if(isset($data['residence_stability']) && $data['residence_stability'] >= $residence_stability_rented) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['residence_stability'] = "Residence stability should be atleast ".$residence_stability_rented;
            }
        }

        if($not_eligible_reason_count == 0) {
            return [
                'is_eligible' => true,
                'data' => $data
            ];
        } else {
            return [
                'is_eligible' => 'false',
                'reason' => $not_eligible_reasons
            ];
        }
    }

    public function rule_e_auto_rickshaw_l5($data){
        // $min_loan_amount = 100000;
        // $min_loan_amount = 100000;
        $min_loan_amount = 50000;
        $max_loan_amount = 650000;
        
        // $min_tenure = 12;
        // $max_tenure = 36;
        $min_tenure = 6;
        $max_tenure = 60;

        $min_age = 18;
        $max_age = 55;
        
        $min_cibil_score = 650;
        $min_cibil_score_coapplicant = 650;
        $min_cibil_score_guarantor = 650;
        $residence_stability_owned = 3;
        $residence_stability_rented = 2;

        $ltv = [
            'on_road' => 85,
            'ex_showroom' => 90
        ];
        
        $not_eligible_reason_count = 0;
        $not_eligible_reasons = [];
        $eligibility_count = 0;

        $is_eligible = false;
        if(isset($data['loan_amount']) && $data['loan_amount'] >= $min_loan_amount && $data['loan_amount'] <= $max_loan_amount) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['loan_amount'] = "Loan amount must be between ".$min_loan_amount ." and ".$max_loan_amount;
        }

        if(isset($data['tenure']) && $data['tenure'] >= $min_tenure && $data['tenure'] <= $max_tenure) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['tenure'] = "Tenure must be between ".$min_tenure ." and ".$max_tenure;
        }

        if(isset($data['age']) && $data['age'] >= $min_age && $data['age'] <= $max_age) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['age'] = "Age must be between ".$min_age ." and ".$max_age;
        }
        
        // $coapplicant_exist = true; co appplicant is mandatory

        if($data['skip_co_applicant'] !== true) {
                
            if(isset($data['co_applicant']) && !empty($data['co_applicant'])) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['co_applicant'] = "Co applicant is required";
            }
            
            // if(isset($data['co_applicant_cibil']) && ($data['co_applicant_cibil'] == 0 || $data['co_applicant_cibil'] >= $min_cibil_score_coapplicant)) {
            //     $eligibility_count++;
            // } else {
            //     $not_eligible_reason_count++;
            //     $not_eligible_reasons['co_applicant_cibil'] = "Minimum cibil score required is ".$min_cibil_score_coapplicant;
            // }
        }

        // if(isset($data['cibil_score']) && $data['cibil_score'] >= $min_cibil_score) {
        //     $eligibility_count++;
        // } else {
        //     $not_eligible_reason_count++;
        //     $not_eligible_reasons['cibil_score'] = "Minimum cibil score required is ".$min_cibil_score;
        // }

        // if(isset($data['co_applicant_cibil']) && $data['co_applicant_cibil'] >= $min_cibil_score_coapplicant) {
        //     $eligibility_count++;
        // } else {
        //     $not_eligible_reason_count++;
        //     $not_eligible_reasons['co_applicant_cibil'] = "Minimum cibil score required is ".$min_cibil_score_coapplicant;
        // }

        // if(isset($data['guarantor_cibil']) && $data['guarantor_cibil'] >= $min_cibil_score_guarantor) {
        //     $eligibility_count++;
        // } else {
        //     $not_eligible_reason_count++;
        //     $not_eligible_reasons['guarantor_cibil'] = "Minimum cibil score required is ".$min_cibil_score_guarantor;
        // }

        // $field_investigation_verified = true; // field inspection is mandatory
        // if(isset($data['field_investigation_verified']) && !empty($data['field_investigation_verified'])) {
        //     $eligibility_count++;
        // } else {
        //     $not_eligible_reason_count++;
        //     $not_eligible_reasons['field_investigation_verified'] = "Field investigation must be verified";
        // }

        if(isset($data['residence_type']) && $data['residence_type'] == "Owned") {
            if(isset($data['residence_stability']) && $data['residence_stability'] >= $residence_stability_owned) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['residence_stability'] = "Residence stability should be atleast ".$residence_stability_owned;
            }
        }
        if(isset($data['residence_type']) && $data['residence_type'] == "Rented") {
            if(isset($data['residence_stability']) && $data['residence_stability'] >= $residence_stability_rented) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['residence_stability'] = "Residence stability should be atleast ".$residence_stability_rented;
            }
        }

        if($not_eligible_reason_count == 0) {
            return [
                'is_eligible' => true,
                'data' => $data
            ];
        } else {
            return [
                'is_eligible' => 'false',
                'reason' => $not_eligible_reasons
            ];
        }
    }

    public function rule_eka($data){
        $min_loan_amount = 50000;
        $max_loan_amount = 650000;
        
        $min_tenure = 6;
        $max_tenure = 60;

        $min_age = 18;
        $max_age = 55;
        
        $min_cibil_score = 650;
        $min_cibil_score_coapplicant = 650;
        $min_cibil_score_guarantor = 650;
        $residence_stability_owned = 3;
        $residence_stability_rented = 2;

        $ltv = [
            'on_road' => 85,
            'ex_showroom' => 90
        ];
        
        $not_eligible_reason_count = 0;
        $not_eligible_reasons = [];
        $eligibility_count = 0;

        $is_eligible = false;
        if(isset($data['loan_amount']) && $data['loan_amount'] >= $min_loan_amount && $data['loan_amount'] <= $max_loan_amount) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['loan_amount'] = "Loan amount must be between ".$min_loan_amount ." and ".$max_loan_amount;
        }

        if(isset($data['tenure']) && $data['tenure'] >= $min_tenure && $data['tenure'] <= $max_tenure) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['tenure'] = "Tenure must be between ".$min_tenure ." and ".$max_tenure;
        }

        if(isset($data['age']) && $data['age'] >= $min_age && $data['age'] <= $max_age) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['age'] = "Age must be between ".$min_age ." and ".$max_age;
        }
        
        // $coapplicant_exist = true; co appplicant is mandatory

        if($data['skip_co_applicant'] !== true) {
                
            if(isset($data['co_applicant']) && !empty($data['co_applicant'])) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['co_applicant'] = "Co applicant is required";
            }
            
            // if(isset($data['co_applicant_cibil']) && ($data['co_applicant_cibil'] == 0 || $data['co_applicant_cibil'] >= $min_cibil_score_coapplicant)) {
            //     $eligibility_count++;
            // } else {
            //     $not_eligible_reason_count++;
            //     $not_eligible_reasons['co_applicant_cibil'] = "Minimum cibil score required is ".$min_cibil_score_coapplicant;
            // }
        }

        // if(isset($data['cibil_score']) && $data['cibil_score'] >= $min_cibil_score) {
        //     $eligibility_count++;
        // } else {
        //     $not_eligible_reason_count++;
        //     $not_eligible_reasons['cibil_score'] = "Minimum cibil score required is ".$min_cibil_score;
        // }

        // if(isset($data['co_applicant_cibil']) && $data['co_applicant_cibil'] >= $min_cibil_score_coapplicant) {
        //     $eligibility_count++;
        // } else {
        //     $not_eligible_reason_count++;
        //     $not_eligible_reasons['co_applicant_cibil'] = "Minimum cibil score required is ".$min_cibil_score_coapplicant;
        // }

        // if(isset($data['guarantor_cibil']) && $data['guarantor_cibil'] >= $min_cibil_score_guarantor) {
        //     $eligibility_count++;
        // } else {
        //     $not_eligible_reason_count++;
        //     $not_eligible_reasons['guarantor_cibil'] = "Minimum cibil score required is ".$min_cibil_score_guarantor;
        // }

        // $field_investigation_verified = true; // field inspection is mandatory
        // if(isset($data['field_investigation_verified']) && !empty($data['field_investigation_verified'])) {
        //     $eligibility_count++;
        // } else {
        //     $not_eligible_reason_count++;
        //     $not_eligible_reasons['field_investigation_verified'] = "Field investigation must be verified";
        // }

        if(isset($data['residence_type']) && $data['residence_type'] == "Owned") {
            if(isset($data['residence_stability']) && $data['residence_stability'] >= $residence_stability_owned) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['residence_stability'] = "Residence stability should be atleast ".$residence_stability_owned;
            }
        }
        if(isset($data['residence_type']) && $data['residence_type'] == "Rented") {
            if(isset($data['residence_stability']) && $data['residence_stability'] >= $residence_stability_rented) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['residence_stability'] = "Residence stability should be atleast ".$residence_stability_rented;
            }
        }

        if($not_eligible_reason_count == 0) {
            return [
                'is_eligible' => true,
                'data' => $data
            ];
        } else {
            return [
                'is_eligible' => 'false',
                'reason' => $not_eligible_reasons
            ];
        }
    }

    public function rule_ev_two_wheeler($data){
        $min_loan_amount = 10000;
        $max_loan_amount = 120000;
        
        $min_tenure = 6;
        $max_tenure = 24;

        $min_age = 18;
        $max_age = 55;
        
        $min_cibil_score = 600;
        $min_cibil_score_coapplicant = 600;
        $min_cibil_score_guarantor = 600;
        $residence_stability_owned = 3;
        $residence_stability_rented = 2;

        $ltv = [
            'on_road' => 80,
            'ex_showroom' => 85
        ];
        
        $not_eligible_reason_count = 0;
        $not_eligible_reasons = [];
        $eligibility_count = 0;

        $is_eligible = false;
        if(isset($data['loan_amount']) && $data['loan_amount'] >= $min_loan_amount && $data['loan_amount'] <= $max_loan_amount) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['loan_amount'] = "Loan amount must be between ".$min_loan_amount ." and ".$max_loan_amount;
        }

        if(isset($data['tenure']) && $data['tenure'] >= $min_tenure && $data['tenure'] <= $max_tenure) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['tenure'] = "Tenure must be between ".$min_tenure ." and ".$max_tenure;
        }

        if(isset($data['age']) && $data['age'] >= $min_age && $data['age'] <= $max_age) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['age'] = "Age must be between ".$min_age ." and ".$max_age;
        }
        
        if($data['skip_co_applicant'] !== true) {
                
            if(isset($data['co_applicant']) && !empty($data['co_applicant'])) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['co_applicant'] = "Co applicant is required";
            }
            
            // if(isset($data['co_applicant_cibil']) && ($data['co_applicant_cibil'] == 0 || $data['co_applicant_cibil'] >= $min_cibil_score_coapplicant)) {
            //     $eligibility_count++;
            // } else {
            //     $not_eligible_reason_count++;
            //     $not_eligible_reasons['co_applicant_cibil'] = "Minimum cibil score required is ".$min_cibil_score_coapplicant;
            // }
        }

        // if(isset($data['cibil_score']) && $data['cibil_score'] >= $min_cibil_score) {
        //     $eligibility_count++;
        // } else {
        //     $not_eligible_reason_count++;
        //     $not_eligible_reasons['cibil_score'] = "Minimum cibil score required is ".$min_cibil_score;
        // }

        // if(isset($data['co_applicant_cibil']) && $data['co_applicant_cibil'] >= $min_cibil_score_coapplicant) {
        //     $eligibility_count++;
        // } else {
        //     $not_eligible_reason_count++;
        //     $not_eligible_reasons['co_applicant_cibil'] = "Minimum cibil score required is ".$min_cibil_score_coapplicant;
        // }

        // if(isset($data['guarantor_cibil']) && $data['guarantor_cibil'] >= $min_cibil_score_guarantor) {
        //     $eligibility_count++;
        // } else {
        //     $not_eligible_reason_count++;
        //     $not_eligible_reasons['guarantor_cibil'] = "Minimum cibil score required is ".$min_cibil_score_guarantor;
        // }

        // $field_investigation_verified = true; // field inspection is mandatory
        if(isset($data['field_investigation_verified']) && !empty($data['field_investigation_verified'])) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['field_investigation_verified'] = "Field investigation must be verified";
        }

        // if(isset($data['residence_type']) && $data['residence_type'] == "Owned") {
        //     if(isset($data['residence_stability']) && $data['residence_stability'] >= $residence_stability_owned) {
        //         $eligibility_count++;
        //     } else {
        //         $not_eligible_reason_count++;
        //         $not_eligible_reasons['residence_stability'] = "Residence stability should be atleast ".$residence_stability_owned;
        //     }
        // }
        // if(isset($data['residence_type']) && $data['residence_type'] == "Rented") {
        //     if(isset($data['residence_stability']) && $data['residence_stability'] >= $residence_stability_rented) {
        //         $eligibility_count++;
        //     } else {
        //         $not_eligible_reason_count++;
        //         $not_eligible_reasons['residence_stability'] = "Residence stability should be atleast ".$residence_stability_rented;
        //     }
        // }

        if($not_eligible_reason_count == 0) {
            return [
                'is_eligible' => true,
                'data' => $data
            ];
        } else {
            return [
                'is_eligible' => 'false',
                'reason' => $not_eligible_reasons
            ];
        }
    }

    public function rule_battery($data){
        $min_loan_amount = 5000;
        // $max_loan_amount = 25000;
        $max_loan_amount = 75000;
        
        $min_tenure = 6;
        // $max_tenure = 12;
        $max_tenure = 18;

        $min_age = 18;
        $max_age = 55;
        
        $min_cibil_score = 650;
        $min_cibil_score_coapplicant = 650;
        $min_cibil_score_guarantor = 650;
        $residence_stability_owned = 3;
        $residence_stability_rented = 2;

        $ltv = [
            'on_road' => 80,
            'ex_showroom' => 85
        ];
        
        $not_eligible_reason_count = 0;
        $not_eligible_reasons = [];
        $eligibility_count = 0;

        $is_eligible = false;
        if(isset($data['loan_amount']) && $data['loan_amount'] >= $min_loan_amount && $data['loan_amount'] <= $max_loan_amount) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['loan_amount'] = "Loan amount must be between ".$min_loan_amount ." and ".$max_loan_amount;
        }

        if(isset($data['tenure']) && $data['tenure'] >= $min_tenure && $data['tenure'] <= $max_tenure) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['tenure'] = "Tenure must be between ".$min_tenure ." and ".$max_tenure;
        }

        if(isset($data['age']) && $data['age'] >= $min_age && $data['age'] <= $max_age) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['age'] = "Age must be between ".$min_age ." and ".$max_age;
        }
        
        if($data['skip_co_applicant'] !== true) {
                
            if(isset($data['co_applicant']) && !empty($data['co_applicant'])) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['co_applicant'] = "Co applicant is required";
            }
            
            // if(isset($data['co_applicant_cibil']) && ($data['co_applicant_cibil'] == 0 || $data['co_applicant_cibil'] >= $min_cibil_score_coapplicant)) {
            //     $eligibility_count++;
            // } else {
            //     $not_eligible_reason_count++;
            //     $not_eligible_reasons['co_applicant_cibil'] = "Minimum cibil score required is ".$min_cibil_score_coapplicant;
            // }
        }
        
        if($data['skip_guarantor'] !== true) {     
            if(isset($data['guarantor']) && !empty($data['guarantor'])) {
                $eligibility_count++;
            } else {
                $not_eligible_reason_count++;
                $not_eligible_reasons['guarantor'] = "Guarantor is required";
            }
        }

        // $field_investigation_verified = true; // field inspection is mandatory
        if(isset($data['field_investigation_verified']) && !empty($data['field_investigation_verified'])) {
            $eligibility_count++;
        } else {
            $not_eligible_reason_count++;
            $not_eligible_reasons['field_investigation_verified'] = "Field investigation must be verified";
        }

        if($not_eligible_reason_count == 0) {
            return [
                'is_eligible' => true,
                'data' => $data
            ];
        } else {
            return [
                'is_eligible' => 'false',
                'reason' => $not_eligible_reasons
            ];
        }
    }

    public function get_dealer_details_and_models(Request $request) {
        $input      = $request->all();
        $user_id    = $request->user()->id;
        $errorArray = [];
        $errorCount = 0;
        date_default_timezone_set('Asia/Kolkata');

        // Validation starts
        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        } else {
            if(!isset($request->dealer_id) || empty($request->dealer_id)) {
                $errorCount++;
                $errorArray['dealer_id'] = "Dealer id is required";
                return $this->send_response("failed", "Dealer id is required", 422);
            } elseif (!ctype_alnum($request->dealer_id)) {
                $errorCount++;
                $errorArray['dealer_id'] = "Dealer id must be alphanumeric";
                return $this->send_response("failed", "Dealer id must be alphanumeric", 422);
            }

            if(!isset($request->business_type) || empty($request->business_type)) {
                $errorCount++;
                $errorArray['business_type'] = "Business type is required";
                return $this->send_response("failed", $errorArray['business_type'], 422);
            } elseif (!in_array($request->business_type, ['EV Two Wheeler', 'EV Three Wheeler', 'E-rickshaw', 'E-Auto P L5', 'E-Auto C Cargo'])) {
                $errorCount++;
                $errorArray['business_type'] = "Invalid business type";
                return $this->send_response("failed", $errorArray['business_type'], 422);
            }

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }
            // Validation ends

            $page           = urlencode(1);
            $dealer_id      = urlencode($input['dealer_id']);
            $business_type  = urlencode($input['business_type']);

            try{
                $curl = curl_init();
                if ($curl === false) {
                    throw new Exception('Failed to initialize cURL');
                }

                $api_url = "http://ec2-15-206-136-105.ap-south-1.compute.amazonaws.com/api/v1/b2b/customer/dealer/assets?page={$page}&dealer_code={$dealer_id}&business_type={$business_type}";

                curl_setopt_array($curl, array(
                    CURLOPT_URL => $api_url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                ));
        
                $response = curl_exec($curl);
                if ($response === false) {
                    throw new Exception('cURL Error: ' . curl_error($curl));
                }

                $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
                // echo $response;
                // die();

                $result = json_decode($response);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('JSON decode error: ' . json_last_error_msg());
                }


                $final_result = [];
                if($result->success == true) {
                    $models_data = $result->data->data;
                    if(is_array($models_data) && count($models_data) > 0) {
                        foreach($models_data as $model) {
                            $ex_showroom_price = $model->asset_details->ex_showroom_price;
                            $loan_percentage = isset($model->rules_ifsd[0]->loan_percentage) ? $model->rules_ifsd[0]->loan_percentage : 33;

                            $max_loan_amount = ($ex_showroom_price - (($ex_showroom_price * $loan_percentage) / 100));

                            $final_result[] = [
                                'id' => $model->asset_details->id,
                                'model_name' => $model->asset_details->model_name,
                                'manufacture' => $model->asset_details->manufacture_name,
                                'price' => $model->asset_details->ex_showroom_price,
                                'loan_percentage' => isset($model->rules_ifsd[0]->loan_percentage) ? $model->rules_ifsd[0]->loan_percentage : 33,
                                'max_loan_tenure' => isset($model->rules_ifsd[0]->max_tenure_time) ? $model->rules_ifsd[0]->max_tenure_time : 18,
                                'max_loan_amount' => $max_loan_amount,
                            ];           
                        }
                    }
                }

                if(count($final_result) > 0) {

                    $result_response = [
                        'status' => 'success',
                        'message' => 'Dealer asset list',
                        'data' => $final_result
                    ];
                } else {
                    $result_response = [
                        'status' => 'failed',
                        'message' => 'No asset listed',
                        'data' => []
                    ];
                }
                
            } catch (Exception $e) {
                $result_response = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'data' => [],
                ];
            }

            return response()->json($result_response, 200);
        }
    }

    // public function check_vkyc_result(Request $request) {
    //     $input      = $request->all();
    //     $user_id    = $request->user()->id;
    //     $borrower_id    = $this->get_borrower_id($request->user()->id);
    //     $errorArray = [];
    //     $errorCount = 0;
    //     date_default_timezone_set('Asia/Kolkata');

    //     $vkyc_result = DB::table('vkyc_api_req_details AS vard')
    //                     ->where('vard.user_id', $user_id)
    //                     ->where('vard.borrower_id', $borrower_id)
    //                     ->latest()
    //                     ->first();
                        
    //                     // 'vwr.status', 'vwr.transaction_id', 'vwr.state', 'vwr.scheduled_slot_time'
    //                     // ->leftJoin('vkyc_web_responses AS vwr', 'vard.application_number', '=', 'vwr.unique_id')
    //                     // ->where('vwr.status', 'APPROVED')
    //     if($vkyc_result) {
    //         $userData = json_decode($vkyc_result->request);
    //         $userData->mobile;

    //         $application_number = $vkyc_result->application_number;
    //         $vkyc_response = DB::table('vkyc_web_responses AS vwr')
    //                             ->where('vwr.unique_id', $application_number)
    //                             ->where('vwr.status', 'APPROVED')
    //                             ->latest()
    //                             ->first();
            
    //         $data = [
    //             'application_number' => $vkyc_result->application_number,
    //             'status' => isset($vkyc_response->status) ? $vkyc_response->status : '',
    //             'state' => isset($vkyc_response->state) ? $vkyc_response->state : '',
    //             'mobile' => $userData->mobile,
    //             'name' => $userData->name,
    //             'transaction_id' => isset($vkyc_response->transaction_id) ? $vkyc_response->transaction_id : '',
    //             'vkyc_url' => $vkyc_result->vkyc_url,
    //             'scheduled_slot_time' => isset($vkyc_response->scheduled_slot_time) ? $vkyc_response->scheduled_slot_time : '',
    //         ];

    //         if(isset($vkyc_response->status) && $vkyc_response->status == 'APPROVED') {

    //             $currentStep = 19;
    //             $application_step = [
    //                 'step' => $currentStep,
    //                 'vkyc_result_id' => $vkyc_result->id,
    //                 'details' => "VKYC Status",
    //                 'status' => "COMPLETED",
    //             ];

    //             $this->updateApplicationStep($user_id, $borrower_id, $application_step);

    //             return $this->send_response("success", "VKYC result fetched", 200, $data);
    //         } else {
    //             return $this->send_response("failed", "VKYC result fetched", 200, $data);
    //         }
    //     } else {
    //         return $this->send_response("failed", "No results found", 404);
    //     }
    // }

    public function check_vkyc_result(Request $request) {
        return $this->unified_check_vkyc_result($request, 'b2c');
    }

    public function match_address_with_borrower(Request $request) {
        // return $this->send_response('success', "Address matched successfully", 200);

        $input          = $request->all();
        $user_id        = $request->user()->id;
        $borrower_id    = $this->get_borrower_id($request->user()->id);
        $errorArray     = [];
        $errorCount     = 0;
        date_default_timezone_set('Asia/Kolkata');
        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        }
        
        if(!isset($request->city) || empty($request->city)) {
            $errorCount++;
            $errorArray['city'] = "City is required";
            return $this->send_response("failed", "City is required", 422);
        }

        if(!isset($request->district) || empty($request->district)) {
            $errorCount++;
            $errorArray['district'] = "District is required";
            return $this->send_response("failed", "District is required", 422);
        }

        if(!isset($request->pin) || empty($request->pin)) {
            $errorCount++;
            $errorArray['pin'] = "City is required";
            return $this->send_response("failed", "Pin code is required", 422);
        } elseif (!preg_match('/^[0-9]{6}$/', $request->pin)) {
            $errorCount++;
            $errorArray['pin'] = "Invalid Pin code format";
            return $this->send_response("failed", $errorArray['pin'], 422);
        }

        if(!isset($request->state) || empty($request->state)) {
            $errorCount++;
            $errorArray['state'] = "State is required";
            return $this->send_response("failed", "state is required", 422);
        }

        $vendor_service_api = VendorServiceApi::where('user_id', $user_id)
                                        ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
                                        ->where('status', "VALID")
                                        ->where('borrower_id', $borrower_id)
                                        ->where('applicant_type', "BORROWER")
                                        ->latest()
                                        ->first();
        if($vendor_service_api) {
            $response   = $vendor_service_api->response;
            $response   = base64_decode($response);
            $response   = unserialize($response);
            $data       = json_decode($response, 200);

            if($data['msg'] == 'success') {
                $dist   = $data['model']['address']['dist'];    // District
                $pc     = $data['model']['address']['pc'];      // Pin code
                $state  = $data['model']['address']['state'];   // State
                $vtc    = $data['model']['address']['vtc'];     // Village Town City
                $po     = $data['model']['address']['po'];      // Post office

                // $input['pin'] == $pc && 
                // if($input['state'] == $state && $input['city'] == $vtc) {
                
                if($input['district'] == $dist || $input['pin'] == $pc) {
                        return $this->send_response('success', "Address matched successfully", 200);
                } else {
                    return $this->send_response('failed', "City and state must be same as borrower", 422);
                }
            } else {
                return $this->send_response('failed', "Borrower address exist", 422);
            }
        } else {
            return $this->send_response('failed', "Invalid request, Borrower address not found", 422);
        }
    }

    // public function get_loan_user_details_for_esign(Request $request) {
    //     $input          = $request->all();
    //     $user_id        = $request->user()->id;
    //     $borrower_id    = $this->get_borrower_id($request->user()->id);
    //     $errorArray     = [];
    //     $errorCount     = 0;
    //     date_default_timezone_set('Asia/Kolkata');
    //     if($input == null) {
    //         return $this->send_response("failed", "Request body is empty", 422);
    //     }
        
    //     if(!isset($request->user_type) || empty($request->user_type)) {
    //         $errorCount++;
    //         $errorArray['user_type'] = "User type is required";
    //         return $this->send_response("failed", $errorArray['user_type'], 422);
    //     } elseif(!in_array($input['user_type'], ['BORROWER', 'COAPPLICANT', 'GUARANTOR'])) {
    //         $errorCount++;
    //         $errorArray['user_type'] = "Invalid user type";
    //         return $this->send_response("failed", $errorArray['user_type'], 422);
    //     }

    //     $result = [];
    //     $vendor_service_api = VendorServiceApi::where('user_id', $user_id)
    //                             ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
    //                             ->where('status', "VALID")
    //                             ->where('borrower_id', $borrower_id);
    //     if($input['user_type'] == "BORROWER") {

    //         $borrower_deails = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
    //         if($borrower_deails) {
    //             $name = $borrower_deails->name;
    //             $mobile = $borrower_deails->mobile;
    //             $email = $borrower_deails->email;

    //             $dist = '';
    //             $vendor_service_api = $vendor_service_api->where('applicant_type', "BORROWER")
    //                                     ->latest()
    //                                     ->first();
    //             if($vendor_service_api) {
    //                 $response   = $vendor_service_api->response;
    //                 $response   = base64_decode($response);
    //                 $response   = unserialize($response);
    //                 $data       = json_decode($response, 200);

    //                 if($data['msg'] == 'success') {
    //                     $dist   = $data['model']['address']['dist'];
    //                 }
    //             }
    //             $city = $dist; // $borrower_deails->name;

    //             $result = [
    //                 'name' => $name,
    //                 'mobile' => $mobile,
    //                 'email' => $email,
    //                 'city' => $city,
    //             ];
    //         }
    //     } elseif($input['user_type'] == "COAPPLICANT") {
    //         $borrower_deails = CoapplicantKyc::where('borrower_id', $borrower_id)->latest()->first();
    //         if($borrower_deails) {
    //             $name = $borrower_deails->name;
    //             $mobile = $borrower_deails->mobile;
    //             $email = $borrower_deails->email;

    //             $dist = '';
    //             $vendor_service_api = $vendor_service_api->where('applicant_type', "COAPPLICANT")
    //                                     ->latest()
    //                                     ->first();
    //             if($vendor_service_api) {
    //                 $response   = $vendor_service_api->response;
    //                 $response   = base64_decode($response);
    //                 $response   = unserialize($response);
    //                 $data       = json_decode($response, 200);

    //                 if($data['msg'] == 'success') {
    //                     $dist   = $data['model']['address']['dist'];
    //                 }
    //             }
    //             $city = $dist;  // $borrower_deails->name;
    //             $result = [
    //                 'name' => $name,
    //                 'mobile' => $mobile,
    //                 'email' => $email,
    //                 'city' => $city,
    //             ];
    //         }
    //     } elseif($input['user_type'] == "GUARANTOR") {
    //         $borrower_deails = Guarantor::where('borrower_id', $borrower_id)->latest()->first();
    //         if($borrower_deails) {
    //             $name = $borrower_deails->name;
    //             $mobile = $borrower_deails->mobile;
    //             $email = $borrower_deails->email;

    //             $dist = '';
    //             $vendor_service_api = $vendor_service_api->where('applicant_type', "GUARANTOR")
    //                                     ->latest()
    //                                     ->first();
    //             if($vendor_service_api) {
    //                 $response   = $vendor_service_api->response;
    //                 $response   = base64_decode($response);
    //                 $response   = unserialize($response);
    //                 $data       = json_decode($response, 200);

    //                 if($data['msg'] == 'success') {
    //                     $dist   = $data['model']['address']['dist'];
    //                 }
    //             }
    //             $city = $dist; // $borrower_deails->name;

    //             $result = [
    //                 'name' => $name,
    //                 'mobile' => $mobile,
    //                 'email' => $email,
    //                 'city' => $city,
    //             ];
    //         }
    //     }

    //     if(count($result) > 0) {
    //         return $this->send_response('success', "Data fetched successfully", 200, $result);
    //     } else {
    //         return $this->send_response('failed', "No result found", 422);

    //     }
    // }

    public function get_loan_user_details_for_esign(Request $request) {
        try {
            $input          = $request->all();
            $user_id        = $request->user()->id;
            $borrower_id    = $this->get_borrower_id($request->user()->id);
            $errorArray     = [];
            $errorCount     = 0;
            date_default_timezone_set('Asia/Kolkata');
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            }
            
            if (empty($borrower_id)) {
                return $this->send_response("failed", "Borrower not found", 422);
            }
            if(!isset($request->user_type) || empty($request->user_type)) {
                $errorCount++;
                $errorArray['user_type'] = "User type is required";
                return $this->send_response("failed", $errorArray['user_type'], 422);
            } elseif(!in_array($input['user_type'], ['BORROWER', 'COAPPLICANT', 'GUARANTOR'])) {
                $errorCount++;
                $errorArray['user_type'] = "Invalid user type";
                return $this->send_response("failed", $errorArray['user_type'], 422);
            }

            $api_detail = VendorServiceApi::where('borrower_id', $borrower_id)
                            ->where('applicant_type', $request->user_type)
                            ->where('api_flag', 'AADHAR_CARD_OCR_BACK')
                            ->where('status', 'VALID')
                            ->latest()
                            ->first();
            if(!$api_detail) {
                return $this->helper_controller->send_response('failed', "Aadhar OCR not done yet", 422);
            }

            $response   = $api_detail->response;
            $response   = base64_decode($response);
            $response   = unserialize($response);
            $data       = json_decode($response, true);

            $address = $data['result'][0]['details']['address'];

            $parts = [
                $address['house_number'] ?? '',
                $address['street'] ?? '',
                $address['locality'] ?? '',
                $address['landmark'] ?? '',
                $address['city'] ?? '',
                $address['district'] ?? '',
                $address['state'] ?? '',
                $address['pin'] ?? ''
            ];
            
            // Filter out empty parts and trim each part
            $filteredParts = array_filter(array_map('trim', $parts));

            // Join with comma and return
            $single_line_address = implode(', ', $filteredParts);

            // $single_line_address = $this->helper_controller->prepare_one_line_address_from_aadhar($address);
            
            $name = $mobile = $email = '';
            if($request->user_type == "BORROWER") {
                $borrower_details = BorrowerKyc::where('borrower_id', $request->borrower_id)->latest()->first();
                if($borrower_details) {
                    $name = $borrower_details->name;
                    $mobile = $borrower_details->mobile;
                    $email = $borrower_details->email;
                }      
            } elseif($request->user_type == "COAPPLICANT") {
                $coapplicant_details = CoapplicantKyc::where('borrower_id', $request->borrower_id)->latest()->first();
                if($coapplicant_details) {
                    $name = $coapplicant_details->name;
                    $mobile = $coapplicant_details->mobile;
                    $email = $coapplicant_details->email;
                }
            } elseif($request->user_type == "GUARANTOR") {
                $guarantor_details = Guarantor::where('borrower_id', $request->borrower_id)->latest()->first();
                if($guarantor_details) {
                    $name = $guarantor_details->name;
                    $mobile = $guarantor_details->mobile;
                    $email = $guarantor_details->email;

                }
            }

            // $city = $address['district'];
            $city = !empty($address['district']) ? $address['district'] : $address['street'];
            $result = [
                'name' => $name,
                'mobile' => $mobile,
                'email' => $email,
                'city' => $city,
            ];

            return $this->helper_controller->send_response('success', "Address details fetched", 200, $result);
        } catch (\Throwable $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    public function get_name_declaration_document(Request $request) {
        try{
            
            $input          = $request->all();
            $user_id        = $request->user()->id;
            $borrower_id    = $this->get_borrower_id($request->user()->id);
            $errorArray     = [];
            $errorCount     = 0;
            date_default_timezone_set('Asia/Kolkata');

            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            }
            
            if(!isset($input['applicant_type']) || empty($input['applicant_type'])) {
                $errorCount++;
                $errorArray['applicant_type'] = "Applicant type is required";
                return $this->send_response("failed", $errorArray['applicant_type'], 422);
            } elseif(!in_array($input['applicant_type'], ['BORROWER', 'COAPPLICANT', 'GUARANTOR'])) {
                $errorCount++;
                $errorArray['applicant_type'] = "Invalid applicant type";
                return $this->send_response("failed", $errorArray['applicant_type'], 422);
            }
            
            $applicant_type = $input['applicant_type'];
            if($applicant_type == "BORROWER") {

                $borrowerDetails = DB::table('loan_basic_details as lbd')
                                    ->leftJoin('borrower_kycs as bk', 'bk.borrower_id', '=', 'lbd.id')
                                    ->leftJoin('borrower_addresses as ba', 'ba.borrower_id', '=', 'lbd.id')
                                    ->select(
                                        'bk.name', 
                                        'ba.current_address', 
                                        'lbd.borrower_id', 
                                        'lbd.created_at'
                                    )
                                    ->where('lbd.id', $borrower_id)
                                    ->first();
        
                if($borrowerDetails) {
                    $application_borrower_id = $borrowerDetails->borrower_id;
                    $data = [
                        'borrower_id' => isset($borrowerDetails->borrower_id) ? $borrowerDetails->borrower_id : '',
                        'name' => isset($borrowerDetails->name) ? $borrowerDetails->name : '',
                        'address' => isset($borrowerDetails->current_address) ? $borrowerDetails->current_address : '',
                        'date' => date('d-m-Y', strtotime($borrowerDetails->created_at)),
                    ];
                }
            } elseif($applicant_type == "COAPPLICANT") {
                $coapplicant_details = DB::table('loan_basic_details as lbd')
                                    ->leftJoin('coapplicant_kycs as ck', 'ck.borrower_id', '=', 'lbd.id')
                                    ->leftJoin('coapplicant_occupations as co', 'co.borrower_id', '=', 'lbd.id')
                                    ->select(
                                        'ck.name', 
                                        'co.current_address', 
                                        'lbd.borrower_id', 
                                        'lbd.created_at'
                                    )
                                    ->where('lbd.id', $borrower_id)
                                    ->first();
        
                if($coapplicant_details) {
                    
                    $application_borrower_id = $coapplicant_details->borrower_id;
                    $data = [
                        'borrower_id' => isset($coapplicant_details->borrower_id) ? $coapplicant_details->borrower_id : '',
                        'name' => isset($coapplicant_details->name) ? $coapplicant_details->name : '',
                        'address' => isset($coapplicant_details->current_address) ? $coapplicant_details->current_address : '',
                        'date' => date('d-m-Y', strtotime($coapplicant_details->created_at)),
                    ];
                }
            } elseif($applicant_type == "GUARANTOR") {
                $guarantor_details = DB::table('loan_basic_details as lbd')
                                        ->leftJoin('guarantors as gt', 'gt.borrower_id', '=', 'lbd.id')
                                        ->leftJoin('guarantor_occupations as gto', 'gto.borrower_id', '=', 'lbd.id')
                                        ->select(
                                            'gt.name', 
                                            'gto.current_address', 
                                            'lbd.borrower_id', 
                                            'lbd.created_at'
                                        )
                                        ->where('lbd.id', $borrower_id)
                                        ->first();

                if($guarantor_details) {
                    $application_borrower_id = $guarantor_details->borrower_id;
                    $data = [
                        'borrower_id' => isset($guarantor_details->borrower_id) ? $guarantor_details->borrower_id : '',
                        'name' => isset($guarantor_details->name) ? $guarantor_details->name : '',
                        'address' => isset($guarantor_details->current_address) ? $guarantor_details->current_address : '',
                        'date' => date('d-m-Y', strtotime($guarantor_details->created_at)),
                    ];

                }
            } else {
                return $this->send_response("failed", "Invalid request", 422);
            }

            $pdf = Pdf::loadView('pdf.name-declaration', $data);

            $pdfPath = "name_declaration/{$application_borrower_id}/{$input['applicant_type']}/name_declaration.pdf";
            Storage::disk('public')->put($pdfPath, $pdf->output());

            $url = Storage::url($pdfPath);

            return response()->json([
                'status' => 'success',
                'message' => "Name declaration generated successfully",
                'file_url' => $url
            ], 200);
        }  catch (Exception  $e) {
            $result_response = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            return response()->json($result_response, 422);
        }
    }

    public function calculateEffectiveIRR($loan_amount, $flat_interest, $tenure)
    {
        $flatInterest = ($loan_amount * $flat_interest / 100) * ($tenure / 12);
        $totalRepaymentAmount = $loan_amount + $flatInterest;
    
        $low = 0;
        $high = 1;
        $accuracy = 0.00001;
        $maxIterations = 200;
        $iterations = 0;
    
        while ($high - $low > $accuracy && $iterations < $maxIterations) {
            $mid = ($low + $high) / 2;
            $monthlyInterestRate = $mid / 12;
    
            $emi = $loan_amount * $monthlyInterestRate / (1 - pow(1 + $monthlyInterestRate, -$tenure));
            $totalRepayment = $emi * $tenure;
    
            if ($totalRepayment < $totalRepaymentAmount) {
                $low = $mid;
            } else {
                $high = $mid;
            }
            $iterations++;
        }
    
        return round($mid * 100, 2);
    }
    
    public function generateReducingInterestScheduleOld($loan_amount, $flat_interest, $tenure)
    {
        $effectiveIRR = $this->calculateEffectiveIRR($loan_amount, $flat_interest, $tenure);
        $monthlyInterestRate = ($effectiveIRR / 100) / 12;
        $emi = ceil($loan_amount * $monthlyInterestRate / (1 - pow(1 + $monthlyInterestRate, -$tenure)));
    
        $schedule = [];
        $remainingBalance = $loan_amount;
        $totalInterest = 0;
    
        for ($month = 1; $month <= $tenure; $month++) {
            $interestPortion = $remainingBalance * $monthlyInterestRate;
            $roundedInterest = round($interestPortion);
            $principalPortion = $emi - $roundedInterest; // Ensuring Principal + Interest = EMI
    
            if ($month == $tenure) {
                $principalPortion = $remainingBalance;
                $emi = $principalPortion + $roundedInterest;
            }
    
            $remainingBalance -= $principalPortion;
            $totalInterest += $roundedInterest;
    
            $schedule[] = [
                'month' => $month,
                'principal' => round($principalPortion),
                'interest' => $roundedInterest,
                'total_payment' => round($emi),
                'remaining_balance' => max(0, round($remainingBalance))
            ];
        }
    
       
        $totalInterest = round(($loan_amount * $flat_interest / 100) * ($tenure / 12));
        $totalLoanAmount = $loan_amount + $totalInterest;
    
        return $schedule;
    } // disabled on 24-04-2025

    function generateReducingInterestSchedule($loan_amount, $flat_interest, $tenure)
    {
        $effectiveIRR = $this->calculateEffectiveIRR($loan_amount, $flat_interest, $tenure);
        $monthlyInterestRate = ($effectiveIRR / 100) / 12;
        $emi = $loan_amount * $monthlyInterestRate / (1 - pow(1 + $monthlyInterestRate, -$tenure));
        $emi = round($emi); // Whole rupee

        $schedule = [];
        $remainingBalance = $loan_amount;
        $actualPrincipalPaid = 0;
        $actualInterestPaid = 0;

        $totalInterest = round(($loan_amount * $flat_interest / 100) * ($tenure / 12));
        $totalLoanAmount = $loan_amount + $totalInterest;

        for ($month = 1; $month <= $tenure; $month++) {
            $interestPortion = round($remainingBalance * $monthlyInterestRate);
            $principalPortion = round($emi - $interestPortion);

            // Adjust final month to fix rounding errors
            if ($month == $tenure) {
                $principalPortion = $loan_amount - $actualPrincipalPaid;
                $interestPortion = $totalInterest - $actualInterestPaid;
                $emi = $principalPortion + $interestPortion;
            }

            $remainingBalance -= $principalPortion;
            $actualPrincipalPaid += $principalPortion;
            $actualInterestPaid += $interestPortion;

            $schedule[] = [
                'month' => $month,
                'principal' => $principalPortion,
                'interest' => $interestPortion,
                'total_payment' => $emi,
                'remaining_balance' => max(0, $remainingBalance)
            ];
        }

        return [
            'schedule' => $schedule,
            'total_interest' => $actualInterestPaid,
            'total_loan_amount' => $totalLoanAmount,
            'emi_amount' => $schedule[0]['total_payment'],
            'effective_irr' => round($effectiveIRR, 2)
        ];
    }

    public function skip_nach_step(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
                'skip_enach' => 'required|IN:yes,YES,Yes',
                'reason' => 'required|regex:/^[a-zA-Z0-9\s,.\-]+$/'
            ]);
         
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();

            $borrower_id = $request->borrower_id;
            $loan_details = LoanBasicDetails::find($request->borrower_id);
            if(!$loan_details) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }
            if($loan_details->status == 'REJECTED') {
                return $this->helper_controller->send_response('failed', "Invalid request, this applications is already rjected.", 422);
            }

            if($loan_details->status == 'CANCELLED') {
                return $this->helper_controller->send_response('failed', "Your application is on hold, please contact Finayo", 422);
            }

            $account_details = BorrowerAccountDetails::where('borrower_id', $request->borrower_id)->first();
            if(!$account_details) {
                return $this->helper_controller->send_response('failed', "Invalid request, bank details not available", 422);
            }
            $can_skip = 0;
            $reason = [];
            $ifsc_code = strtoupper($account_details->ifsc);
            if(empty($account_details->bank_code)) {
                $response = $this->helper_controller->get_ifsc_information($ifsc_code);
                $response = json_encode($response);
                $response = json_decode($response, true);
                // return $response;
                $ifsc_details = $response['original'];

                if ($ifsc_details && $ifsc_details['status'] === 'success') {
                    $bank_code = $ifsc_details['data']['bankcode'];
                    $account_details->account_type = empty($account_details->account_type) ? 'savings' : $account_details->account_type;
                    $account_details->ifsc = $ifsc_code;
                    $account_details->bank_code = $bank_code ?? null;
                    $account_details->save();        
                }
            } else {
                $bank_code = $account_details->bank_code;
            }
            
            $check_nach_availability = NpciBankList::where('bank_id', $bank_code)->first();
            if(!$check_nach_availability) {
                $can_skip++;
                array_push($reason, "Bank not listed in NPCI");
            }
            
            if($check_nach_availability && $check_nach_availability->card_flag == false) {
                $can_skip++;
                array_push($reason, "E-NACH using debit card not available");
            }

            if ($account_details->enach_status !== null) {
                $can_skip++;
                array_push($reason, "E-NACH status is ". $account_details->enach_status);   
            }

            if($can_skip === 0) {
                return $this->helper_controller->send_response('failed', "Can not skip, E-NACH step is required", 422);
            }

            $loan_details->skip_enach_step = true;
            $loan_details->enach_step_skip_reason = $request->reason;

            $application_step = [
                // 'step' => 27,
                'step' => 21,
                'account_details_id' => $account_details->id,
                'details' => "E-NACH step Skipped",
                'status' => "COMPLETED",
            ];
            
            $existing_application_steps = json_decode($loan_details->loan_application_steps);
            $existing_application_steps[] = $application_step;

            $updatedApplicationStep = json_encode($existing_application_steps);
            $loan_details->loan_application_steps = $updatedApplicationStep;
            $loan_details->save();


            $this->logStep($loan_details->id, 21, 'E-NACH step Skipped', 'b2c');
            DB::commit();

            return $this->helper_controller->send_response('success', "E-NACH step skipped successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Something went wrong: " . $e->getMessage(), 422);
        }
    }

    public function store_nach_registration_details(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id'
            ]);
         
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();
            $loan_details = LoanBasicDetails::find($request->borrower_id);
            if(!$loan_details) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }
            if($loan_details->status == 'REJECTED') {
                return $this->helper_controller->send_response('failed', "Invalid request, this applications is already rjected.", 422);
            }

            if($loan_details->status == 'CANCELLED') {
                return $this->helper_controller->send_response('failed', "Your application is on hold, please contact Finayo", 422);
            }
            
            $account_details = BorrowerAccountDetails::where('borrower_id', $request->borrower_id)->first();
            if(!$account_details) {
                return $this->helper_controller->send_response('failed', "Invalid request, bank details not available", 422);
            }

            $check_enach_request = EnachRequest::where('borrower_id', $request->borrower_id)->first();
            if(!$check_enach_request) {
                return $this->helper_controller->send_response('failed', "E-NACH registration not initiated", 422);
            }

            $application_step = [
                // 'step' => 27,
                'step' => 21,
                'account_details_id' => $account_details->id,
                'details' => "E-NACH step completed",
                'status' => "COMPLETED",
            ];
            
            $existing_application_steps = json_decode($loan_details->loan_application_steps);
            $existing_application_steps[] = $application_step;

            $updatedApplicationStep = json_encode($existing_application_steps);
            $loan_details->loan_application_steps = $updatedApplicationStep;
            $loan_details->save();

            
            // $this->logStep($loan_details->id, 27, 'E-NACH step completed', 'b2c');
            $this->logStep($loan_details->id, 21, 'E-NACH step completed', 'b2c');
            DB::commit();            
            return $this->helper_controller->send_response('success', "E-NACH step stored successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Something went wrong: " . $e->getMessage(), 422);
        }
    }

    public function distance_calculation_dealer_borrower_address($borrower_id, $address)
    {
        try {
            // Validate and build borrower address
            if (!$address || !isset($address['vtc'], $address['dist'], $address['pc'], $address['state'])) {
                return [
                    'status' => 'failed',
                    'message' => 'Invalid borrower address provided'
                ];
            }

            $borrower_address = $address['vtc'] . ', ' . $address['dist'] . ', ' . $address['pc'] . ', ' . $address['state'];
            $borrower_address = trim($borrower_address, " ,");

            // Fetch loan details
            $loan_basic_details = LoanBasicDetails::find($borrower_id);
            if (!$loan_basic_details) {
                return [
                    'status' => 'failed',
                    'message' => 'Invalid request, borrower not found'
                ];
            }

            // Fetch dealer details
            $dealer_details = Dealer::where('dealer_unq', $loan_basic_details->dealer_id)->first();
            if (!$dealer_details) {
                return [
                    'status' => 'failed',
                    'message' => 'Invalid request, dealer not found'
                ];
            }

            // Fetch dealer state name
            $dealer_state_name = '';
            if (!empty($dealer_details->state)) {
                $dealer_state = StateMaster::find($dealer_details->state);
                $dealer_state_name = $dealer_state ? $dealer_state->state : '';
            }

            $dealer_address = $dealer_details->address_line_1 . ', ' .
                    $dealer_details->address_line_2 . ', ' .
                    $dealer_details->city . ', ' .
                    $dealer_details->pincode . ', ' .
                    $dealer_state_name;
            $dealer_address = trim($dealer_address, " ,");

            // Compare fields
            $comparisons = [
                'city' => [
                    'borrower' => strtolower(trim($address['vtc'])),
                    'dealer'   => strtolower(trim($dealer_details->city)),
                ],
                'pincode' => [
                    'borrower' => trim($address['pc']),
                    'dealer'   => trim($dealer_details->pincode),
                ]
            ];

            $mismatches = [];
            foreach ($comparisons as $key => $pair) {
                if ($pair['borrower'] !== $pair['dealer']) {
                    $similarity = 0;
                    if ($key !== 'pincode') {
                        similar_text($pair['borrower'], $pair['dealer'], $similarity);
                    }
                    $mismatches[$key] = [
                        'borrower' => $pair['borrower'],
                        'dealer' => $pair['dealer'],
                        'match_percent' => $key !== 'pincode' ? round($similarity, 2) : ($pair['borrower'] === $pair['dealer'] ? 100 : 0),
                    ];
                }
            }

            if (empty($mismatches)) {

                // Distance calculation logic goes here (e.g., using some map API)
                return [
                    'status' => 'success',
                    'distance' => 0 // or actual distance
                ];
            }


            // Prepare JSON request
            $jsonRequest = json_encode([
                'fromLocation' => ['address' => $borrower_address],
                'toLocation'   => ['address' => $dealer_address],
                'uniqueId'     => strtoupper($this->generate_random_string(14))
            ]);

            // cURL Call
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://apidemo.digitap.work/ent/v1/get-distance',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonRequest,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $this->uat_authorization_key,
                    'Content-Type: application/json'
                ]
            ]);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            $responseData = json_decode($response);

            DB::beginTransaction();
            // Log vendor API request
            $vendor_service_api = new VendorServiceApi();
            $vendor_service_api->user_id        = auth()->id();
            $vendor_service_api->ip_address     = $this->helper_controller->get_user_ip();
            $vendor_service_api->date_time      = date('Y-m-d H:i:s');
            $vendor_service_api->status         = ($httpCode == 200) ? "VALID" : "INVALID";
            $vendor_service_api->api_flag       = "DISTANCE_BETWEEN_TWO_ADDRESSES";
            $vendor_service_api->request        = $jsonRequest;
            $vendor_service_api->response       = $response;
            $vendor_service_api->borrower_id    = $borrower_id;
            $vendor_service_api->applicant_type = "BORROWER";
            $vendor_service_api->save();
            DB::commit();

            if (
                isset($responseData->code, $responseData->model->message) &&
                $responseData->code == 200 &&
                strtolower($responseData->model->message) == 'success'
            ) {
                return [
                    'status'   => 'success',
                    'message'  => 'Distance calculated',
                    'distance' => $responseData->model->distance ?? null
                ];
            }

            return [
                'status'  => 'failed',
                'message' => $responseData->model->message ?? 'Failed to get distance'
            ];
        } catch (\Throwable $e) {
            // \Log::error('Distance calculation error', [
            //     'borrower_id' => $borrower_id,
            //     'exception'   => $e->getMessage(),
            //     'trace'       => $e->getTraceAsString()
            // ]);
            return [
                'status'  => 'failed',
                'message' => "An unknown error occurred: " . $e->getMessage()
            ];
        }
    }

}
