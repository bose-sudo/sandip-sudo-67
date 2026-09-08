<?php
// 13.200.65.87
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
use App\Models\DealerUserMap;
use App\Models\Documents;
use App\Models\EnachRequest;
use App\Models\FieldInspectionDetails;
use App\Models\Guarantor;
use App\Models\GuarantorOccupation;
use App\Models\LenderBorrowerGroup;
use App\Models\LenderDealerGroup;
use App\Models\LenderLoanSetting;
use App\Http\Controllers\HelperController;
use App\Models\LoanApplicationSummary;
use App\Models\LoanBasicDetails;
use App\Models\NpciBankList;
use App\Models\ProductModel;
use App\Models\StateMaster;
use App\Models\User;
use App\Models\VendorServiceApi;
use App\Traits\ApiResponse;
use App\Traits\SendsSanctionMail;
use App\Traits\CalculatesLoanSchedule;
use Exception;
use DateTime;
use Illuminate\Support\Facades\Storage;
use App\Services\TwilioService;
use Barryvdh\DomPDF\Facade\Pdf;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminLoanController extends Controller
{
    use ApiResponse;
    use CalculatesLoanSchedule;
    use SendsSanctionMail;
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

    public function __construct(TwilioService $twilio, HelperController $helper_controller) {
        // $this->middleware('auth:api', ['except' => ['verify_dealer_id', 'loan_terms', 'field_verification']]);
        $this->max_annual_income    = 10000000;
        $this->min_annual_income    = 36000;
        $this->max_monthly_income   = 800000;
        $this->min_monthly_income   = 3000;

        $uatClientId = config('services.digitap.uat.client_id');
        $uatSecret   = config('services.digitap.uat.client_secret');
        $this->uat_authorization_key = base64_encode($uatClientId . ':' . $uatSecret);

        $prodClientId = config('services.digitap.prod.client_id');
        $prodSecret   = config('services.digitap.prod.client_secret');
        
        $this->signzy_auth_key = config('services.signzy.prod.auth_key');
        $this->prod_authorization_key = base64_encode($prodClientId . ':' . $prodSecret);


        $this->twilio = $twilio;
        $this->helper_controller = $helper_controller;
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
                                        ->where('status', 'NEW')
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
        $currentStep        = 1;
        $isStepCompleted    = false;
        $nextStep           = 0;
        $step_status        = 'INCOMPLETE';

        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            return $this->send_response("failed", "Borrower id is required", 422);
        }

        $borrower_id        = $request->borrower_id;
        if($borrower_id != "Invalid") {

            $isApplicationExist = LoanBasicDetails::where('id', $borrower_id)
                                    ->where('status', '<>', 'CLOSED')->first();
            if($isApplicationExist) {
                $existing_application_steps = json_decode($isApplicationExist->loan_application_steps);
           
                return response()->json([
                    'status' => 'success',
                    'is_loan_exist' => true,
                    'skip_coapplicant_guarantor' => $isApplicationExist->skip_coapplicant_guarantor,
                    'product_id' => strval($isApplicationExist->product_id),
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
        
    }

    public function get_loan_status_by_product(Request $request) {
        try {
            $userId             = $request->user()->id;
            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                return $this->send_response("failed", "Borrower id is required", 422);
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
                        'product_id' => $isApplicationExist->product_id,
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
        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            // $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }
        
        $borrower_id        = $request->borrower_id;
        if($borrower_id === 'Invalid') {
            return $this->send_response('failed', "No application exist", 422);
        }

        $isApplicationExist = LoanBasicDetails::where('id', $borrower_id)
                                ->where('status', '<>', 'CLOSED')->first();
        if($isApplicationExist) {
            $existing_application_steps = json_decode($isApplicationExist->loan_application_steps);
            $result = [
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
        return 'Not in use';
        die();
        $userId = '';

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

    public function updateApplicationStep($borrower_id, $application_step) {
        $isApplicationExist = LoanBasicDetails::find($borrower_id);
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
    //         // return 'ok';
    //         $input = $request->all();
    //         $errorArray = [];
    //         $errorCount = 0;
    //         $step = '';
    //         date_default_timezone_set('Asia/Kolkata');
    //         // Validation start 
    //             if(!isset($request->user_id) || empty($request->user_id)) {
    //                 $errorCount++;
    //                 $errorArray['user_id'] = "User id is required";
    //                 return $this->send_response("failed", "User id is required", 422);
    //             }
                
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
                
    //             if(!isset($request->model_id) || empty($request->model_id)) {
    //                 $errorCount++;
    //                 $errorArray['model_id'] = "Model id is required";
    //                 return $this->send_response("failed", "Model id is required", 422);
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

    //             $productModel = ProductModel::where('model_unq', $request->model_id)->first();
    //             if(!$productModel) {
    //                 return $this->send_response("failed", "Invalid product model", 422);
    //             }

    //             if($request->loan_amount > $productModel->max_loan_amount) {
    //                 return $this->send_response("failed", "Max loan amount allowed for this product is ". $productModel->max_loan_amount, 422);
    //             }
    //         // Validation ends
            
    //         if($errorCount > 0) {
    //             return response()->json([
    //                 'status'=>'error',
    //                 'message'=>"Invalid data",
    //                 'error_list'=> $errorArray
    //             ], 422);

    //         }
    //         $userId             = $request->user_id;
    //         $nextStep           = 0;
    //         $currentStep        = 1;

    //         $preferred_language = $request->preferred_language;
    //         $dealer_id          = $request->dealer_id;
    //         $model              = $request->model;
    //         $loan_amount        = $request->loan_amount;
    //         $loan_term          = $request->loan_term;
    //         $product_id         = $request->product_id;
    //         $model_id           = $request->model_id;

    //         $current_step[] = [
    //             'step' => $currentStep,
    //             'details' => "Loan Basic Details",
    //             'status' => "COMPLETED",
    //         ];

    //         /**
    //          * StashFin Lender case where we need to skip co applicant and guarantor step
    //          * Created on 19-12-2024
    //          */
    //         $dealerDetails = Dealer::where('dealer_unq', $dealer_id)->first();
    //         if(!$dealerDetails) {
    //             return $this->send_response('failed', "Invalid dealer id", 422);
    //         }

    //         $lender_dealer_group = LenderDealerGroup::where('dealer_id', $dealerDetails->id)->first();
    //         if($lender_dealer_group) {
    //             $lender_id = $lender_dealer_group->lender_id;
    //             // $skip_coapplicant_guarantor = true;
    //         }
    //         /**
    //          * StashFin Lender case ends
    //          */

    //         $application_step = json_encode($current_step);
    //         DB::beginTransaction();

    //         $loanBasicDetails                           = new LoanBasicDetails;
    //         $loanBasicDetails->user_id                  = $userId;
    //         $loanBasicDetails->borrower_id              = '';
    //         $loanBasicDetails->loan_application_steps   = $application_step; 
    //         $loanBasicDetails->preferred_language       = $preferred_language;
    //         $loanBasicDetails->dealer_id                = $dealer_id;
    //         $loanBasicDetails->model                    = $model;
    //         $loanBasicDetails->loan_amount              = $loan_amount;
    //         $loanBasicDetails->loan_term                = $loan_term;
    //         $loanBasicDetails->status                   = "NEW";
    //         $loanBasicDetails->product_id               = $product_id;
    //         $loanBasicDetails->model_id                 = $model_id;
    //         $loanBasicDetails->admin_user_id            = $request->user()->id;
    //         $loanBasicDetails->lender_id                = $lender_id ?? null;
    //         // $loanBasicDetails->skip_coapplicant_guarantor = $skip_coapplicant_guarantor ?? false;

    //         $loanBasicDetails->save();
    //         $borrower_id = $this->generate_borrower_id($loanBasicDetails->id);

    //         // update loan application summary
    //         $loan_application_summary               = new LoanApplicationSummary;
    //         $loan_application_summary->borrower_id  = $loanBasicDetails->id;
    //         $loan_application_summary->user_id      = $userId;
    //         $loan_application_summary->product_id   = $product_id;
    //         $loan_application_summary->loan_amount  = $loan_amount;
    //         $loan_application_summary->tenure       = $loan_term;
    //         $loan_application_summary->save();

    //         $loan_scheme                        = new BorrowerLoanScheme();
    //         $loan_scheme->borrower_id           = $loanBasicDetails->id;
    //         $loan_scheme->product_id            = $productModel->id;
    //         $loan_scheme->ex_showroom_price     = $productModel->ex_showroom_price ?? 0;
    //         $loan_scheme->loan_to_value_ratio   = $productModel->loan_to_value_ratio ?? '';
    //         $loan_scheme->min_loan_amount       = $productModel->min_loan_amount ?? 0;
    //         $loan_scheme->max_loan_amount       = $productModel->max_loan_amount ?? 0;
    //         $loan_scheme->min_loan_tenure       = $productModel->min_loan_tenure ?? 0;
    //         $loan_scheme->max_loan_tenure       = $productModel->max_loan_tenure ?? 0;
    //         $loan_scheme->interest              = $productModel->interest ?? 0;
    //         $loan_scheme->processing_fee        = $productModel->processing_fee ?? 0;
    //         $loan_scheme->subvention_amount     = $productModel->subvention_amount ?? 0;
    //         $loan_scheme->fldg                  = $productModel->fldg ?? 0;
    //         $loan_scheme->iot                   = $productModel->iot ?? 0;
    //         $loan_scheme->insurance_1st_year    = $productModel->insurance_1st_year ?? 0;
    //         $loan_scheme->insurance_2nd_year    = $productModel->insurance_2nd_year ?? 0;
    //         $loan_scheme->interest_type         = $productModel->interest_type ?? null;
    //         $loan_scheme->save();
                
    //         DB::commit();
    //         return response()->json([
    //             'status'=>'success',
    //             'message'=>"Basic details stored successfully",
    //             'data'=> $loanBasicDetails
    //         ], 201);
        
    //     } catch (Exception $e) {
    //         DB::rollBack();            
    //         $this->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
    //     }
    // }

    public function storeLoanBasicDetails(Request $request) {
        $r = app(\App\Services\Loan\LoanBasicDetailsService::class)->store($request, 'admin');
        return $this->send_response($r['ok'] ? 'success' : 'failed', $r['message'], $r['code'], $r['data'] ?? null);
    }

    public function storeBorrowerDetails(Request $request)
    {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            $age = 0;
            date_default_timezone_set('Asia/Kolkata');

            // Validation starts

            $validator = Validator::make($input, [
                'borrower_id'               => 'required|integer|exists:loan_basic_details,id',
                'pan_number'                => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
                // 'is_pan_verified'           => 'required|in:1',
                'full_name'                 => 'required|string|max:255',
                'dob'                       => 'required|date',
                'gender'                    => 'required|in:Male,Female,MALE,FEMALE',
                'mobile_number'             => ['required', 'regex:/^(?:\+91|91)?[6-9]\d{9}$/'],
                'alternative_mobile_number' => ['nullable', 'regex:/^(?:\+91|91)?[6-9]\d{9}$/'],
                'email'                     => 'required|email',
                'marital_status'           => 'required|in:Unmarried,Married',
                'father_name'              => 'required|string|max:255',
                'consent_acceptance'       => 'required|in:Yes,true',
                'dl_no'                    => 'nullable|string',
                // 'is_licence_verified'      => 'required_if:dl_no,!=,null|in:1',
                'husband_name'             => 'nullable|string|max:255',
                
                'aadhar_front' => 'required|file|mimetypes:image/jpeg,image/png,image/jpg,application/pdf|max:5120', // 5MB
                'aadhar_back'  => 'required|file|mimetypes:image/jpeg,image/png,image/jpg,application/pdf|max:5120',
                'pan_doc'  => 'required|file|mimetypes:image/jpeg,image/png,image/jpg,application/pdf|max:5120',
            ], [
                'pan_number.regex'                 => 'Invalid PAN number format',
                'mobile_number.regex'             => 'Invalid mobile number format',
                'alternative_mobile_number.regex' => 'Invalid alternative mobile number format',
                // 'is_pan_verified.in'              => 'PAN must be validated',
                'is_licence_verified.required_if' => 'Driving Licence must be verified',
                // 'is_licence_verified.in'          => 'Driving Licence must be verified',
                'husband_name.required_if'        => 'Spouse name is required',
                'consent_acceptance.in'           => 'Consent acceptance is required',
            ]);

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            // Age check (after validation)
            $dob = $input['dob'];
            $dobTimestamp = strtotime($dob);
            $age = date('Y') - date('Y', $dobTimestamp);
            if (date('md') < date('md', $dobTimestamp)) {
                $age--;
            }

            if ($age < 18) {
                return $this->send_response('failed', 'Age must be greater than 18', 422);
            }

            if ($age > 55) {
                return $this->send_response('failed', 'Age must be less than 56', 422);
            }

            $loan_details = LoanBasicDetails::find($request->borrower_id);
            if (!$loan_details) {
                return $this->send_response('failed', "Invalid borrower", 422);
            }

            if ($loan_details->status == 'REJECTED') {
                return $this->send_response('failed', "Invalid request, the borrower is already rjected", 422);
            }

            if ($loan_details->status == 'CANCELLED') {
                return $this->send_response('failed', "Your application is on hold, please contact Finayo", 422);
            }

            // $aadhar_details = VendorServiceApi::where('borrower_id', $request->borrower_id)
            //                         ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
            //                         ->where('applicant_type', 'BORROWER')
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

            $pan_details = VendorServiceApi::where('borrower_id', $request->borrower_id)
                ->where('api_flag', 'PAN_DETAILS_VALIDATION')
                ->where('applicant_type', 'BORROWER')
                ->where('status', 'VALID')
                ->latest()->first();

            $panData = $pan_details->response;
            $panData = base64_decode($panData);
            $panData = unserialize($panData);
            $panData = json_decode($panData);
            if (isset($panData->result->dob) && !empty($panData->result->dob)) {
                $panDob = $panData->result->dob;
            }

            if (isset($aadharDob) && !empty($aadharDob) && isset($panDob) && !empty($panDob)) {
                $dob1 = new DateTime($aadharDob);
                $dob2 = DateTime::createFromFormat('d/m/Y', $panDob);
                $diffDays = abs($dob1->diff($dob2)->days);
                if ($diffDays > 89 && $diffDays < 1096) {
                    if (!$request->hasFile('dob_declaration')) {
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
            $aadhar_number  = $request->aadhar_number ?? '';
            $aadhar_number  = $aadhar_number;
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

            if ($request->is_pan_verified == '1') {
                $is_pan_verified = true;
                $pan_message = "Active";
            } else {
                $is_pan_verified = false;
                $pan_message = "Invalid";
            }
            if ($request->is_aadhar_verified == '1') {
                $is_aadhar_verified = true;
            } else {
                $is_aadhar_verified = false;
            }
            $is_mobile_number_verified = false;

            if (isset($input['is_licence_verified']) && $input['is_licence_verified'] == '1') {
                $dl_verified = true;
            } else {
                $dl_verified = false;
            }

            $userId             = '';
            $nextStep           = 0;
            $currentStep        = 2;
            $data['user_id']    = $userId;

            $borrower_id = $request->borrower_id;

            // $vendor_service_api = VendorServiceApi::where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
            //                                 ->where('status', "VALID")
            //                                 ->where('borrower_id', $borrower_id)
            //                                 ->where('applicant_type', "BORROWER")
            //                                 ->latest()
            //                                 ->first();
            // if($vendor_service_api) {
            //     $responseAadhar   = $vendor_service_api->response;
            //     $responseAadhar   = base64_decode($responseAadhar);
            //     $responseAadhar   = unserialize($responseAadhar);
            //     $dataAadhar       = json_decode($responseAadhar, 200);

            //     if($dataAadhar['msg'] == 'success') {
            //         $pc     = $dataAadhar['model']['address']['pc'];
            //         $data['pin'] = $pc;
            //     }
            // }
            DB::beginTransaction();
            $borrowerDetails                                = new BorrowerKyc;
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
            $borrowerDetails->gender                        = ucfirst(strtolower($gender));
            $borrowerDetails->email                         = $email;
            $borrowerDetails->cibil                         = isset($credit_score) ? $credit_score : 0;
            $borrowerDetails->postal                        = 0;
            $borrowerDetails->state                         = '';
            $borrowerDetails->country                       = '';
            $borrowerDetails->avtar                         = '';
            $borrowerDetails->is_driving_licence_verified   = $dl_verified;
            $borrowerDetails->is_electricity_verified       = false;
            $borrowerDetails->created_by                    = 0;
            $borrowerDetails->updated_by                    = 0;
            $borrowerDetails->alternate_number1             = $request->alternative_mobile_number ?? '';
            $borrowerDetails->alternate_number2             = '';
            $borrowerDetails->borrower_id                   = $borrower_id;
            $borrowerDetails->driving_license_no            = isset($input['dl_no']) ? $input['dl_no'] : '';

            if ($borrowerDetails->save()) {

                // if(isset($request->name_declaration) && !empty($request->name_declaration)) {
                //     $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                //     if(!$kyc_docs) {
                //         $kyc_docs                    = new Documents;
                //         $kyc_docs->borrower_id       = $borrower_id;
                //     }
                //     $kyc_docs->borrower_name_declaration = $request->name_declaration;
                // }
                $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                if (!$kyc_docs) {
                    $kyc_docs                    = new Documents;
                    $kyc_docs->borrower_id       = $borrower_id;
                }

                $uploadAadharFront = $this->general_file_upload($request->file('aadhar_front'), 'AADHAR_FRONT', 'BORROWER_KYC', $borrower_id);
                if ($uploadAadharFront['status'] === 'failed') {
                    DB::rollBack(); // roll back before returning
                    return $this->send_response('failed', $uploadAadharFront['message'], 422);
                }
                $aadhar_front = $uploadAadharFront['data'];
                $kyc_docs->aadhar_front = $aadhar_front;

                $uploadAadharBack = $this->general_file_upload($request->file('aadhar_back'), 'AADHAR_BACK', 'BORROWER_KYC', $borrower_id);
                if ($uploadAadharBack['status'] === 'failed') {
                    DB::rollBack(); // roll back before returning
                    return $this->send_response('failed', $uploadAadharBack['message'], 422);
                }
                $aadhar_back = $uploadAadharBack['data'];
                $kyc_docs->aadhar_back = $aadhar_back;

                $uploadPanDoc = $this->general_file_upload($request->file('pan_doc'), 'PAN_CARD', 'BORROWER_KYC', $borrower_id);
                if ($uploadPanDoc['status'] === 'failed') {
                    DB::rollBack(); // roll back before returning
                    return $this->send_response('failed', $uploadPanDoc['message'], 422);
                }
                $pan_doc = $uploadPanDoc['data'];
                $kyc_docs->pan_doc = $pan_doc;

                if ($request->hasFile('name_declaration') || $request->hasFile('dob_declaration')) {

                    if ($request->hasFile('name_declaration')) {
                        $uploadResultName = $this->general_file_upload($request->file('name_declaration'), 'NAME_DECLARATION', 'BORROWER_KYC', $borrower_id);
                        if ($uploadResultName['status'] === 'failed') {
                            DB::rollBack(); // roll back before returning
                            return $this->send_response('failed', $uploadResultName['message'], 422);
                        }
                        $nameDeclarationUrl = $uploadResultName['data'];
                        $kyc_docs->borrower_name_declaration = $nameDeclarationUrl; //$request->name_declaration;
                    }

                    if ($request->hasFile('dob_declaration')) {
                        $uploadResultDob = $this->general_file_upload($request->file('dob_declaration'), 'DOB_DECLARATION', 'BORROWER_KYC', $borrower_id);
                        if ($uploadResultDob['status'] === 'failed') {
                            DB::rollBack(); // roll back before returning
                            return $this->send_response('failed', $uploadResultDob['message'], 422);
                        }
                        $dobDeclarationUrl = $uploadResultDob['data'];
                        $kyc_docs->borrower_dob_declaration = $dobDeclarationUrl;
                    }
                }
                $kyc_docs->save();

                $application_step = [
                    'step' => $currentStep,
                    'borrower_details_id' => $borrowerDetails->id,
                    'details' => "Borrower Details",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($borrower_id, $application_step);

                // Update Loan Application summary
                $loan_application_summary = LoanApplicationSummary::where('borrower_id', $borrower_id)
                    ->first();
                if ($loan_application_summary) {
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

                DB::commit();
                return response()->json([
                    'status' => 'success',
                    'message' => "Borrower details stored successfully",
                    'data' => ''
                ], 201);
            } else {
                DB::rollBack();
                return response()->json([
                    'status' => 'failed',
                    'message' => "Failed to store borrower details",
                ], 400);
            }
        } catch (\Throwable $e) {
            DB::rollBack(); 
            return $this->send_response("failed", "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function storeBorrowerFamilyDetails(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        date_default_timezone_set('Asia/Kolkata');

        // Validation starts
            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }
            $loan_details       = LoanBasicDetails::find($request->borrower_id);
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

            if($request->type == "Rented") {
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
            }

            if($product_id != 4) {

                if (!isset($request->number_of_family_members) || empty($request->number_of_family_members)) {
                    $errorCount++;
                    $errorArray['number_of_family_members'] = "Number of family members is required";
                    return $this->send_response("failed", "Number of family members is required", 422);
                } elseif(!is_numeric($request->number_of_family_members)) {
                    $errorCount++;
                    $errorArray['number_of_family_members'] = "Number of family members must be numeric";
                    return $this->send_response("failed", "Number of family members must be numeric", 422);
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

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }

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
            $presentDistanceResponse = $this->distance_calculation_dealer_borrower_address($request->borrower_id, $presentAddress);
            if ($presentDistanceResponse['status'] === 'failed') {
                return $this->helper_controller->send_response('failed', $presentDistanceResponse['message'], 422);
            }

            $permanentDistanceResponse = $this->distance_calculation_dealer_borrower_address($request->borrower_id, $permanentAddress);
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

        // Validation ends
        
        $resident_type              = $request->resident_type;
        $current_address            = $request->current_address;
        $permanent_address          = $request->permanent_address;
        $years_living               = $request->years_living;
        $number_of_family_members   = $request->number_of_family_members ?? 0;
        $number_of_earning_members  = $request->number_of_earning_members;
        $family_income              = $request->family_income;
        
        $userId             = '';
        $nextStep           = 0;
        $currentStep        = 3;
 
        $borrower_id                                = $request->borrower_id;
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
        if($borrowerFamilyDetails->save()) {
            // $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
            // if(!$kyc_docs) {
            //     $kyc_docs                    = new Documents;
            //     $kyc_docs->borrower_id       = $borrower_id;
            // }
            // $kyc_docs->borrower_rent_doc = $request->rent_agreement;
            // $kyc_docs->save();
            if($request->hasFile('rent_agreement')) {

                $uploadResultRentAgreement = $this->general_file_upload($request->file('rent_agreement'), 'RENT_AGREEMENT', 'BORROWER_KYC', $borrower_id);
                if ($uploadResultRentAgreement['status'] === 'failed') {
                    DB::rollBack(); // roll back before returning
                    return $this->send_response('failed', $uploadResultRentAgreement['message'], 422);
                }
                $rent_agreement_doc = $uploadResultRentAgreement['data'];
                $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                if(!$kyc_docs) {
                    $kyc_docs                    = new Documents;
                    $kyc_docs->borrower_id       = $borrower_id;
                }
                $kyc_docs->borrower_rent_doc = $rent_agreement_doc;
                $kyc_docs->save();
            }

            $application_step = [
                'step' => $currentStep,
                'borrower_family_details_id' => $borrowerFamilyDetails->id,
                'details' => "Borrower Family Details",
                'status' => "COMPLETED",
            ];

            $this->updateApplicationStep($borrower_id, $application_step);

            // Update Loan Application Summary
            $loan_application_summary = LoanApplicationSummary::where('borrower_id', $borrower_id)
                                                ->first();
            if($loan_application_summary) {

                $loan_application_summary->resience_type        = $resident_type;
                $loan_application_summary->residence_stability  = $years_living;
                $loan_application_summary->field_verification   = false;
                $loan_application_summary->save();
            }

            return response()->json([
                'status'=>'success',
                'message'=>"Borrower family details stored successfully",
                'data'=> ''
            ], 201);
        } else {
            return response()->json([
                'status'=>'failed',
                'message'=>"Failed to store borrower family details",
            ], 400);
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

            // Validation starts
                if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                    $errorCount++;
                    $errorArray['borrower_id'] = "Borrower id is required";
                    return $this->send_response("failed", "Borrower id is required", 422);
                }
                $loan_details       = LoanBasicDetails::find($request->borrower_id);
                if(!$loan_details) {
                    return $this->send_response("failed", "Loan details not found", 422);
                }

                $product_id = $loan_details->product_id;

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

                if (!isset($request->monthly_income) || empty($request->monthly_income)) {
                    $errorCount++;
                    $errorArray['monthly_income'] = "Monthly income is required";
                    return $this->send_response("failed", $errorArray['monthly_income'], 422);
                } elseif (!is_numeric($request->monthly_income)) {
                    $errorCount++;
                    $errorArray['monthly_income'] = "Monthly income must be numeric";
                    return $this->send_response("failed", $errorArray['monthly_income'], 422);
                } else {
                    if($request->monthly_income < 5000) {
                        $errorCount++;
                        $errorArray['monthly_income'] = "Monthly income must be atleast 5000";
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
                    if($request->expected_monthly_income < 5000) {
                        $errorCount++;
                        $errorArray['expected_monthly_income'] = "Expected monthly income must be atleast 5000";
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
                    } elseif($request->total_yearly_income < 60000) {
                        $errorCount++;
                        $errorArray['total_yearly_income'] = "Total yearly income must be atleast 60000";
                        return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                    }
                }

                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }

                if ($request->hasFile('borrower_income_proof_doc')) {
                    $uploadResponse = $this->general_file_upload(
                        $request->file('borrower_income_proof_doc'),
                        'BORROWER_INCOME_PROOF_DOC',
                        'BORROWER_KYC',
                        $loan_details->borrower_id
                    );

                    if ($uploadResponse['status'] === 'failed') {
                        return $this->helper_controller->send_response('failed', $uploadResponse['message'], 422);
                    }

                    $borrower_income_proof_url = $uploadResponse['data'];
                } else {
                    if($request->monthly_income > 12000) {
                        return $this->helper_controller->send_response('failed', "Income proof document is required", 422);
                    }
                }
            // Validation ends

            $occupation_type            = $request->occupation_type;
            $current_occupation         = $request->current_occupation;
            $driving_experience         = $request->driving_experience ?? 0;
            $vehicle_type               = $request->vehicle_type ?? '';
            $monthly_income             = $request->monthly_income;
            $expected_monthly_income    = $request->expected_monthly_income;
            $other_income               = $request->other_income;
            $extra_source_of_income     = $request->extra_source_of_income;
            $total_yearly_income        = $request->total_yearly_income;
            
            $userId             = '';
            $nextStep           = 0;
            $currentStep        = 4;

            $borrower_id                                    = $request->borrower_id;
            $borrowerOccupation                             = new BorrowerOccupation;
            $borrowerOccupation->occupation_type            = $occupation_type;
            $borrowerOccupation->current_occupation         = $current_occupation;
            $borrowerOccupation->driving_experience         = $driving_experience ?? 0;
            $borrowerOccupation->vehicle_type               = $vehicle_type ?? '';
            $borrowerOccupation->monthly_earning            = $monthly_income;
            $borrowerOccupation->expected_monthly_earning   = $expected_monthly_income;
            $borrowerOccupation->extra_source_income        = $other_income;
            $borrowerOccupation->source_income_area         = $extra_source_of_income;
            $borrowerOccupation->total_income               = $total_yearly_income;
            $borrowerOccupation->current_city               = '';
            $borrowerOccupation->borrower_id                = $borrower_id;
            $borrowerOccupation->save();

            if(isset($borrower_income_proof_url)) {
                $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                if (!$kyc_docs) {
                    $kyc_docs                    = new Documents();
                    $kyc_docs->borrower_id       = $borrower_id;
                }
                $kyc_docs->borrower_income_proof_doc = $borrower_income_proof_url; // $request->borrower_income_proof_doc;
                $kyc_docs->save();
            }

            $application_step = [
                'step' => $currentStep,
                'borrower_occupation_id' => $borrowerOccupation->id,
                'details' => "Borrower Occupation",
                'status' => "COMPLETED",
            ];

            $this->updateApplicationStep($borrower_id, $application_step);

            DB::commit();

            return response()->json([
                'status'=>'success',
                'message'=>"Borrower occupation details stored successfully",
                'data'=> ''
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function skip_co_applicant(Request $request) {
        // skip_co_applicant
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        date_default_timezone_set('Asia/Kolkata');
        
        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }

        /**
         * Lender loan settings part start
         * added on 03-02-2025
         */
        $loan_basic_details = LoanBasicDetails::find($request->borrower_id);
        if(!$loan_basic_details) {
            return $this->send_response("failed", "Invalid request", 422);
        }

        if (!empty($loan_basic_details->lender_id)) {
            $setting = LenderLoanSetting::where('lender_id', $loan_basic_details->lender_id)->first();
            if(!$setting) {
                $lenderBorrowerGroup = LenderBorrowerGroup::where('borrower_id', $request->borrower_id)->first();
                if($lenderBorrowerGroup) {
                    $setting = LenderLoanSetting::where('lender_id', $lenderBorrowerGroup->lender_id)->first();
                }
            }

            if ($setting?->co_applicant_step === 'required') {
                return $this->send_response("failed", "Co-applicant step is required, cannot skip.", 422);
            }
        }

        // Lender loan settings part ends
        if(!isset($input['skip_coapplicant']) || empty($input['skip_coapplicant'])) {
            $errorCount++;
            $errorArray['skip_coapplicant'] = "Skip consent is required";
            return $this->send_response("failed", $errorArray['skip_coapplicant'], 422);
        }

        $userId             = '';
        $nextStep           = 0;
        $currentStep        = 4;
        $borrower_id        = $request->borrower_id;
        $borrower_details   = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
        if(!$borrower_details) {
            return $this->send_response('failed', "Invalid request, borrower not found", 422);
        }

        $borrower_details->skip_co_applicant = true;
        if($borrower_details->save()) {

            $application_step = [
                'step' => 5,
                'co_applicant_details_id' => $borrower_details->id,
                'details' => "Co-Applicant Details Skipped",
                'status' => "COMPLETED",
            ];
            
            $this->updateApplicationStep($borrower_id, $application_step);

            $application_step = [
                'step' => 6,
                'co_applicant_occupation_id' => $borrower_details->id,
                'details' => "Co-Applicant Occupation Details Skipped",
                'status' => "COMPLETED",
            ];
            
            $this->updateApplicationStep($borrower_id, $application_step);
            
            return response()->json([
                'status'=>'success',
                'message'=>"Co applicant details skipped successfully",
                'data'=> ''
            ], 201);
        } else {
            return response()->json([
                'status'=>'failed',
                'message'=>"Failed to update details, try again",
            ], 400);
        }
    }

    // public function storeCoApplicantDetails(Request $request) {
    //     $input = $request->all();
    //     $errorArray = [];
    //     $errorCount = 0;
    //     date_default_timezone_set('Asia/Kolkata');
    //     $step = '';
    //     DB::beginTransaction();
    //     // validation starts
    //         if (!isset($request->borrower_id) || empty($request->borrower_id)) {
    //             $errorCount++;
    //             $errorArray['borrower_id'] = "Borrower id is required";
    //             return $this->send_response("failed", "Borrower id is required", 422);
    //         }
    //         // if (!isset($request->aadhar_number) || empty($request->aadhar_number)) {
    //         //     $errorCount++;
    //         //     $errorArray['aadhar_number'] = "Aadhar number is required";
    //         //     return $this->send_response("failed", $errorArray['aadhar_number'], 422);
    //         // } elseif (!preg_match('/^[0-9]{12}$/', $request->aadhar_number)) {
    //         //     $errorCount++;
    //         //     $errorArray['aadhar_number'] = "Invalid Aadhar number format";
    //         //     return $this->send_response("failed", $errorArray['aadhar_number'], 422);
    //         // }

    //         // if(!isset($request->is_aadhar_verified) || empty($request->is_aadhar_verified)) {
    //         //     $errorCount++;
    //         //     $errorArray['aadhar_number'] = "Aadhar verification is required";
    //         //     return $this->send_response("failed", "Aadhar verification is required", 422);
    //         // } else {
    //         //     if($request->is_aadhar_verified == 0) {
    //         //         $errorCount++;
    //         //         $errorArray['aadhar_number'] = "Aadhar must be validated";
    //         //         return $this->send_response("failed", "Aadhar must be validated", 422);
    //         //     }
    //         // }
        
    //         if (!isset($request->pan_number) || empty($request->pan_number)) {
    //             $errorCount++;
    //             $errorArray['pan_number'] = "Pan number is required";
    //             return $this->send_response("failed", $errorArray['pan_number'], 422);
    //         } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $request->pan_number)) {
    //             $errorCount++;
    //             $errorArray['pan_number'] = "Invalid Pan number format";
    //             return $this->send_response("failed", $errorArray['pan_number'], 422);
    //         }

    //         if(!isset($request->is_pan_verified) || empty($request->is_pan_verified)) {
    //             $errorCount++;
    //             $errorArray['pan_number'] = "Pan verification is required";
    //             return $this->send_response("failed", "Pan verification is required", 422);
    //         } else {
    //             if($request->is_pan_verified == 0) {
    //                 $errorCount++;
    //                 $errorArray['pan_number'] = "Pan must be validated";
    //                 return $this->send_response("failed", "Pan must be validated", 422);
    //             }
    //         }
        
    //         if (!isset($request->full_name) || empty($request->full_name)) {
    //             $errorCount++;
    //             $errorArray['full_name'] = "Full name is required";
    //             return $this->send_response("failed", $errorArray['full_name'], 422);
    //         }
        
    //         if (!isset($request->dob) || empty($request->dob)) {
    //             $errorCount++;
    //             $errorArray['dob'] = "Date of birth is required";
    //             return $this->send_response("failed", $errorArray['dob'], 422);
    //         } elseif (!strtotime($request->dob)) {
    //             $errorCount++;
    //             $errorArray['dob'] = "Invalid date of birth";
    //             return $this->send_response("failed", $errorArray['dob'], 422);
    //         } else {
    //             // Calculate age
    //             date_default_timezone_set('Asia/Kolkata');
    //             $dobTimestamp = strtotime($request->dob);
    //             $age = date('Y') - date('Y', $dobTimestamp);
    //             if (date('md') < date('md', $dobTimestamp)) {
    //                 $age--;
    //             }
    //             if ($age < 21) {
    //                 $errorCount++;
    //                 $errorArray['dob'] = "Age must be greater than 21";
    //                 return $this->send_response("failed", $errorArray['dob'], 422);
    //             }
    //             if ($age > 55) {
    //                 $errorCount++;
    //                 $errorArray['dob'] = "Age must be less than 56";
    //                 return $this->send_response("failed", "Age must be less than 56", 422);
    //             }
    //         }
        
    //         if (!isset($request->gender) || empty($request->gender)) {
    //             $errorCount++;
    //             $errorArray['gender'] = "Gender is required";
    //             return $this->send_response("failed", $errorArray['gender'], 422);
    //         }
        
    //         if (!isset($request->mobile_number) || empty($request->mobile_number)) {
    //             $errorCount++;
    //             $errorArray['mobile_number'] = "Mobile number is required";
    //             return $this->send_response("failed", $errorArray['mobile_number'], 422);
    //         } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $request->mobile_number)) {
    //             $errorCount++;
    //             $errorArray['mobile_number'] = "Invalid mobile number format";
    //             return $this->send_response("failed", $errorArray['mobile_number'], 422);
    //         }

    //         if (!isset($request->relation_with_borrower) || empty($request->relation_with_borrower)) {
    //             $errorCount++;
    //             $errorArray['relation_with_borrower'] = "Relation with borrower is required";
    //             return $this->send_response("failed", $errorArray['relation_with_borrower'], 422);
    //         }
        
    //         if (!isset($request->email) || empty($request->email)) {
    //             $errorCount++;
    //             $errorArray['email'] = "Email ID is required";
    //             return $this->send_response("failed", $errorArray['email'], 422);
    //         } elseif (!filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
    //             $errorCount++;
    //             $errorArray['email'] = "Invalid email format";
    //             return $this->send_response("failed", $errorArray['email'], 422);
    //         }

    //         if (!isset($request->father_name) || empty($request->father_name)) {
    //             $errorCount++;
    //             $errorArray['father_name'] = "Father's name is required";
    //             return $this->send_response("failed", $errorArray['father_name'], 422);
    //         }
        
    //         if ($errorCount > 0) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => "Invalid data",
    //                 'error_list' => $errorArray
    //             ], 422);
    //         }

    //         $loan_details = LoanBasicDetails::find($request->borrower_id);
    //         if(!$loan_details) {
    //             return $this->send_response('failed', "Invalid borrower", 422);
    //         }

    //         if($loan_details->status == 'REJECTED') {
    //             return $this->send_response('failed', "Invalid request, the borrower is already rjected", 422);
    //         }

    //         // $aadhar_details = VendorServiceApi::where('borrower_id', $request->borrower_id)
    //         //                         ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
    //         //                         ->where('applicant_type', 'COAPPLICANT')
    //         //                         ->where('status', 'VALID')
    //         //                         ->latest()->first();
    //         // if(!$aadhar_details) {
    //         //     return $this->send_response('failed', "Verify aadhar first", 422);
    //         // }
    //         // $aadharData = $aadhar_details->response;
    //         // $aadharData = base64_decode($aadharData);
    //         // $aadharData = unserialize($aadharData);
    //         // $aadharData = json_decode($aadharData);
    //         // if(isset($aadharData->model->dob) && !empty($aadharData->model->dob)) {
    //         //     $aadharDob = date('Y-m-d', strtotime($aadharData->model->dob));
    //         // }

    //         $pan_details = VendorServiceApi::where('borrower_id', $request->borrower_id)
    //                                 ->where('api_flag', 'PAN_DETAILS_VALIDATION')
    //                                 ->where('applicant_type', 'COAPPLICANT')
    //                                 ->where('status', 'VALID')
    //                                 ->latest()->first();

    //         $panData = $pan_details->response;
    //         $panData = base64_decode($panData);
    //         $panData = unserialize($panData);
    //         $panData = json_decode($panData);
    //         if(isset($panData->result->dob) && !empty($panData->result->dob)) {
    //             $panDob = $panData->result->dob;
    //         }

    //         if(isset($aadharDob) && !empty($aadharDob) && isset($panDob) && !empty($panDob)) {
    //             $dob1 = new DateTime($aadharDob);
    //             $dob2 = DateTime::createFromFormat('d/m/Y', $panDob);
    //             $diffDays = abs($dob1->diff($dob2)->days);
    //                 if($diffDays > 89 && $diffDays < 1096) {
    //                     if(!$request->hasFile('dob_declaration')) {
    //                         return $this->send_response('failed', "DOB declaration is required", 422);
    //                     }
    //                 } elseif ($diffDays >= 1096) {
    //                     return $this->send_response('failed', "Date of birth difference in aadhar and pan is more than 3 years, please change the Co-Applicant", 422);
    //                 }
    //         }
    //     // Validation Ends

    //     $aadhar_number              = $request->aadhar_number ?? '';
    //     $pan_number                 = $request->pan_number;
    //     $full_name                  = $request->full_name;
    //     $dob                        = date('Y-m-d', strtotime($request->dob));
    //     $gender                     = $request->gender;
    //     $mobile_number              = $request->mobile_number;
    //     $relation_with_borrower     = $request->relation_with_borrower;
    //     $email                      = $request->email;
    //     $father_name                = $request->father_name;

    //     if($request->is_pan_verified == '1') {
    //         $is_pan_verified = true;
    //         $pan_message = "Active";
    //     } else {
    //         $is_pan_verified = false;
    //         $pan_message = "Invalid";
    //     }
    //     if($request->is_aadhar_verified == '1') {
    //         $is_aadhar_verified = true;
            
    //     } else {
    //         $is_aadhar_verified = false;
    //     }
    //     $is_mobile_number_verified = false;

    //     $userId             = '';
    //     $nextStep           = 0;
    //     $currentStep        = 5;

    //     $borrower_id = $request->borrower_id;
    //     $checkData = [
    //         'pan' => $request->pan_number,
    //         // 'aadhar' => $request->aadhar_number,
    //         'contact_number' => $request->mobile_number
    //     ];
    //     $checkDuplicate = $this->check_if_kyc_data_is_similar($checkData, $borrower_id, "COAPPLICANT");
    //     if($checkDuplicate !== true) {
    //         return $checkDuplicate;
    //     }

    //     $coapplicant_details                            = new CoapplicantKyc;
    //     $coapplicant_details->name                      = $full_name;
    //     $coapplicant_details->mobile                    = $mobile_number;
    //     $coapplicant_details->is_verified_mobile        = $is_mobile_number_verified;
    //     $coapplicant_details->pan                       = $pan_number;
    //     $coapplicant_details->is_verified_pan           = $is_pan_verified;
    //     $coapplicant_details->pan_message               = $pan_message;
    //     $coapplicant_details->aadhar                    = $aadhar_number;
    //     $coapplicant_details->is_verified_aadhar        = $is_aadhar_verified;
    //     $coapplicant_details->asset_value               = 0;
    //     $coapplicant_details->loan_amount               = 0;
    //     $coapplicant_details->down_payment              = 0;
    //     $coapplicant_details->dob_date                  = $dob;
    //     $coapplicant_details->father_name               = $father_name;
    //     $coapplicant_details->relation_with_borrower    = $relation_with_borrower;
    //     $coapplicant_details->gender                    = ucfirst(strtolower($gender));
    //     $coapplicant_details->email                     = $email;
    //     $coapplicant_details->cibil                     = 0;
    //     $coapplicant_details->postal                    = 0;
    //     $coapplicant_details->state                     = '';
    //     $coapplicant_details->country                   = '';
    //     $coapplicant_details->avtar                     = '';
    //     $coapplicant_details->alternate_number1         = '';
    //     $coapplicant_details->alternate_number2         = '';
    //     $coapplicant_details->borrower_id               = $borrower_id;
    //     if($coapplicant_details->save()) {
    //         // if(isset($request->name_declaration) && !empty($request->name_declaration)) {
    //         //     $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
    //         //     if(!$kyc_docs) {
    //         //         $kyc_docs                    = new Documents;
    //         //         $kyc_docs->borrower_id       = $borrower_id;
    //         //     }
    //         //     $kyc_docs->coapplicant_name_declaration = $request->name_declaration;
    //         // }

    //         if($request->hasFile('name_declaration') || $request->hasFile('dob_declaration')) {
    //             $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
    //             if(!$kyc_docs) {
    //                 $kyc_docs                    = new Documents;
    //                 $kyc_docs->borrower_id       = $borrower_id;
    //             }

    //             if($request->hasFile('name_declaration')) {
    //                 $uploadResultName = $this->general_file_upload($request->file('name_declaration'), 'NAME_DECLARATION', 'COAPPLICANT_KYC', $borrower_id);
    //                 if ($uploadResultName['status'] === 'failed') {
    //                     DB::rollBack(); // roll back before returning
    //                     return $this->send_response('failed', $uploadResultName['message'], 422);
    //                 }
    //                 $nameDeclarationUrl = $uploadResultName['data'];
    //                 $kyc_docs->coapplicant_name_declaration = $nameDeclarationUrl; //$request->name_declaration;
    //             }

    //             if($request->hasFile('dob_declaration')) {
    //                 $uploadResultDob = $this->general_file_upload($request->file('dob_declaration'), 'DOB_DECLARATION', 'COAPPLICANT_KYC', $borrower_id);
    //                 if ($uploadResultDob['status'] === 'failed') {
    //                     DB::rollBack(); // roll back before returning
    //                     return $this->send_response('failed', $uploadResultDob['message'], 422);
    //                 }
    //                 $dobDeclarationUrl = $uploadResultDob['data'];
    //                 $kyc_docs->coapplicant_dob_declaration = $dobDeclarationUrl;
    //             }
    //             $kyc_docs->save();
    //         }

    //         $application_step = [
    //             'step' => $currentStep,
    //             'co_applicant_details_id' => $coapplicant_details->id,
    //             'details' => "Co-Applicant Details",
    //             'status' => "COMPLETED",
    //         ];

    //         $this->updateApplicationStep($borrower_id, $application_step);

    //         // Update Loan Application Summary
    //         $loan_application_summary = LoanApplicationSummary::where('borrower_id', $borrower_id)->first();
    //         if($loan_application_summary) {

    //             $loan_application_summary->co_applicant_id        = $coapplicant_details->id;
    //             $loan_application_summary->save();
    //         }

    //         DB::commit();
    //         return response()->json([
    //             'status'=>'success',
    //             'message'=>"Coapplicant details stored successfully",
    //             'data'=> ''
    //         ], 201);
    //     } else {
    //         DB::rollBack();
    //         return response()->json([
    //             'status'=>'failed',
    //             'message'=>"Failed to store coapplicant details",
    //         ], 400);
    //     }
    // }

    public function storeCoApplicantDetails(Request $request)
    {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            date_default_timezone_set('Asia/Kolkata');
            $step = '';
            DB::beginTransaction();
            // validation starts

            $validator = Validator::make($input, [
                'borrower_id'             => 'required|integer|exists:loan_basic_details,id',
                'pan_number'              => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
                'is_pan_verified'         => 'required|in:Yes',
                'full_name'               => ['required', 'regex:/^[a-zA-Z\s]+$/'],
                'dob'                     => 'required|date',
                'gender'                  => 'required|in:Male,Female,Other',
                'mobile_number'           => ['required', 'regex:/^(?:\+91|91)?[6-9]\d{9}$/'],
                'relation_with_borrower'  => 'required|regex:/^[a-zA-Z\s]+$/|max:100',
                'email'                   => 'required|email',
                'father_name'             => 'required|regex:/^[a-zA-Z\s]+$/',
                'aadhar_front' => 'required|file|mimetypes:image/jpeg,image/png,image/jpg,application/pdf|max:5120', // 5MB
                'aadhar_back'  => 'required|file|mimetypes:image/jpeg,image/png,image/jpg,application/pdf|max:5120',
                'pan_doc'  => 'required|file|mimetypes:image/jpeg,image/png,image/jpg,application/pdf|max:5120',
            ], [
                'borrower_id.exists'              => 'Invalid borrower ID',
                'pan_number.regex'               => 'Invalid PAN number format',
                'is_pan_verified.required'       => 'PAN verification is required',
                'is_pan_verified.in'             => 'PAN must be validated',
                'full_name.regex'                => 'Full name must contain only letters and spaces',
                'dob.date'                       => 'Invalid date of birth format',
                'mobile_number.regex'            => 'Invalid mobile number format',
                'relation_with_borrower.regex' => 'Relation must contain only letters and spaces',
                'father_name.required'           => 'Father\'s name is required',
                'father_name.regex'              => 'Father\'s name must contain only letters and spaces',
            ]);

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }

            $loan_details = LoanBasicDetails::find($request->borrower_id);
            if (!$loan_details) {
                return $this->send_response('failed', "Invalid borrower", 422);
            }

            if ($loan_details->status == 'REJECTED') {
                return $this->send_response('failed', "Invalid request, the borrower is already rjected", 422);
            }

            if ($loan_details->status == 'CANCELLED') {
                return $this->send_response('failed', "Your application is on hold, please contact Finayo", 422);
            }

            // $aadhar_details = VendorServiceApi::where('borrower_id', $request->borrower_id)
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

            $pan_details = VendorServiceApi::where('borrower_id', $request->borrower_id)
                ->where('api_flag', 'PAN_DETAILS_VALIDATION')
                ->where('applicant_type', 'COAPPLICANT')
                ->where('status', 'VALID')
                ->latest()->first();

            $panData = $pan_details->response;
            $panData = base64_decode($panData);
            $panData = unserialize($panData);
            $panData = json_decode($panData);
            if (isset($panData->result->dob) && !empty($panData->result->dob)) {
                $panDob = $panData->result->dob;
            }

            if (isset($aadharDob) && !empty($aadharDob) && isset($panDob) && !empty($panDob)) {
                $dob1 = new DateTime($aadharDob);
                $dob2 = DateTime::createFromFormat('d/m/Y', $panDob);
                $diffDays = abs($dob1->diff($dob2)->days);
                if ($diffDays > 89 && $diffDays < 1096) {
                    if (!$request->hasFile('dob_declaration')) {
                        return $this->send_response('failed', "DOB declaration is required", 422);
                    }
                } elseif ($diffDays >= 1096) {
                    return $this->send_response('failed', "Date of birth difference in aadhar and pan is more than 3 years, please change the Co-Applicant", 422);
                }
            }
            // Validation Ends

            $aadhar_number              = $request->aadhar_number ?? '';
            $pan_number                 = $request->pan_number;
            $full_name                  = $request->full_name;
            $dob                        = date('Y-m-d', strtotime($request->dob));
            $gender                     = $request->gender;
            $mobile_number              = $request->mobile_number;
            $relation_with_borrower     = $request->relation_with_borrower;
            $email                      = $request->email;
            $father_name                = $request->father_name;

            if ($request->is_pan_verified == '1') {
                $is_pan_verified = true;
                $pan_message = "Active";
            } else {
                $is_pan_verified = false;
                $pan_message = "Invalid";
            }
            if ($request->is_aadhar_verified == '1') {
                $is_aadhar_verified = true;
            } else {
                $is_aadhar_verified = false;
            }
            $is_mobile_number_verified = false;

            $userId             = '';
            $nextStep           = 0;
            $currentStep        = 5;

            $borrower_id = $request->borrower_id;
            $checkData = [
                'pan' => $request->pan_number,
                // 'aadhar' => $request->aadhar_number,
                'contact_number' => $request->mobile_number
            ];
            $checkDuplicate = $this->check_if_kyc_data_is_similar($checkData, $borrower_id, "COAPPLICANT");
            if ($checkDuplicate !== true) {
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
            $coapplicant_details->gender                    = ucfirst(strtolower($gender));
            $coapplicant_details->email                     = $email;
            $coapplicant_details->cibil                     = 0;
            $coapplicant_details->postal                    = 0;
            $coapplicant_details->state                     = '';
            $coapplicant_details->country                   = '';
            $coapplicant_details->avtar                     = '';
            $coapplicant_details->alternate_number1         = '';
            $coapplicant_details->alternate_number2         = '';
            $coapplicant_details->borrower_id               = $borrower_id;
            if ($coapplicant_details->save()) {

                $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                if (!$kyc_docs) {
                    $kyc_docs                    = new Documents;
                    $kyc_docs->borrower_id       = $borrower_id;
                }

                $uploadAadharFront = $this->general_file_upload($request->file('aadhar_front'), 'AADHAR_FRONT', 'COAPPLICANT_KYC', $borrower_id);
                if ($uploadAadharFront['status'] === 'failed') {
                    DB::rollBack(); // roll back before returning
                    return $this->send_response('failed', $uploadAadharFront['message'], 422);
                }
                $aadhar_front = $uploadAadharFront['data'];
                $kyc_docs->coAadhar_front = $aadhar_front;

                $uploadAadharBack = $this->general_file_upload($request->file('aadhar_back'), 'AADHAR_BACK', 'COAPPLICANT_KYC', $borrower_id);
                if ($uploadAadharBack['status'] === 'failed') {
                    DB::rollBack(); // roll back before returning
                    return $this->send_response('failed', $uploadAadharBack['message'], 422);
                }
                $aadhar_back = $uploadAadharBack['data'];
                $kyc_docs->coAadhar_back = $aadhar_back;

                $uploadPanDoc = $this->general_file_upload($request->file('pan_doc'), 'PAN_CARD', 'COAPPLICANT_KYC', $borrower_id);
                if ($uploadPanDoc['status'] === 'failed') {
                    DB::rollBack(); // roll back before returning
                    return $this->send_response('failed', $uploadPanDoc['message'], 422);
                }
                $pan_doc = $uploadPanDoc['data'];
                $kyc_docs->coPan_doc = $pan_doc;

                if ($request->hasFile('name_declaration') || $request->hasFile('dob_declaration')) {

                    if ($request->hasFile('name_declaration')) {
                        $uploadResultName = $this->general_file_upload($request->file('name_declaration'), 'NAME_DECLARATION', 'COAPPLICANT_KYC', $borrower_id);
                        if ($uploadResultName['status'] === 'failed') {
                            DB::rollBack(); // roll back before returning
                            return $this->send_response('failed', $uploadResultName['message'], 422);
                        }
                        $nameDeclarationUrl = $uploadResultName['data'];
                        $kyc_docs->coapplicant_name_declaration = $nameDeclarationUrl; //$request->name_declaration;
                    }

                    if ($request->hasFile('dob_declaration')) {
                        $uploadResultDob = $this->general_file_upload($request->file('dob_declaration'), 'DOB_DECLARATION', 'COAPPLICANT_KYC', $borrower_id);
                        if ($uploadResultDob['status'] === 'failed') {
                            DB::rollBack(); // roll back before returning
                            return $this->send_response('failed', $uploadResultDob['message'], 422);
                        }
                        $dobDeclarationUrl = $uploadResultDob['data'];
                        $kyc_docs->coapplicant_dob_declaration = $dobDeclarationUrl;
                    }
                }
                $kyc_docs->save();

                $application_step = [
                    'step' => $currentStep,
                    'co_applicant_details_id' => $coapplicant_details->id,
                    'details' => "Co-Applicant Details",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($borrower_id, $application_step);

                // Update Loan Application Summary
                $loan_application_summary = LoanApplicationSummary::where('borrower_id', $borrower_id)->first();
                if ($loan_application_summary) {

                    $loan_application_summary->co_applicant_id        = $coapplicant_details->id;
                    $loan_application_summary->save();
                }

                DB::commit();
                return response()->json([
                    'status' => 'success',
                    'message' => "Coapplicant details stored successfully",
                    'data' => ''
                ], 201);
            } else {
                DB::rollBack();
                return response()->json([
                    'status' => 'failed',
                    'message' => "Failed to store coapplicant details",
                ], 422);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->send_response("failed", "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function storeCoApplicantOccupation(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            date_default_timezone_set('Asia/Kolkata');
            DB::beginTransaction();
            $step = '';

            // Validation starts
                if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                    $errorCount++;
                    $errorArray['borrower_id'] = "Borrower id is required";
                    return $this->send_response("failed", "Borrower id is required", 422);
                }

                $loan_details = LoanBasicDetails::find($request->borrower_id);
                if(!$loan_details) {
                    return $this->send_response("failed", "Invalid request", 422);
                }

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
                        $errorArray['monthly_income'] = "Monthly income must be atleast 3000";
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
                        $errorArray['expected_monthly_income'] = "Expected monthly income must be atleast 3000";
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
                        $errorArray['total_yearly_income'] = "Total yearly income must be atleast 36000";
                        return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                    }
                }

                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }

                if ($request->hasFile('coapplicant_income_proof_doc')) {
                    $uploadResponse = $this->general_file_upload(
                        $request->file('coapplicant_income_proof_doc'),
                        'COAPPLICANT_INCOME_PROOF_DOC',
                        'COAPPLICANT_KYC',
                        $loan_details->borrower_id
                    );
                
                    if ($uploadResponse['status'] === 'failed') {
                        return $this->helper_controller->send_response('failed', $uploadResponse['message'], 422);
                    }
                
                    $coapplicant_income_proof_url = $uploadResponse['data'];
                }

            // Validation ends
            $occupation_type = $request->occupation_type;
            $current_occupation = $request->current_occupation;
            $monthly_income = $request->monthly_income;
            $expected_monthly_income = $request->expected_monthly_income;
            $other_income = $request->other_income;
            $extra_source_of_income = $request->extra_source_of_income;
            $total_yearly_income = $request->total_yearly_income;

            $userId             = '';
            $nextStep           = 0;
            $currentStep        = 6;

            $borrower_id = $request->borrower_id;
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
            $coapplicantOccupation->save();

            if(isset($coapplicant_income_proof_url)) {
                $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                if (!$kyc_docs) {
                    $kyc_docs                    = new Documents;
                    $kyc_docs->borrower_id       = $borrower_id;
                }
                $kyc_docs->coapplicant_income_proof_doc = $coapplicant_income_proof_url;
                $kyc_docs->save();
            }

            $application_step = [
                'step' => $currentStep,
                'co_applicant_occupation_id' => $coapplicantOccupation->id,
                'details' => "Co-Applicant Occupation",
                'status' => "COMPLETED",
            ];

            $this->updateApplicationStep($borrower_id, $application_step);

            DB::commit();
            return response()->json([
                'status'=>'success',
                'message'=>"Coapplicant occupation details stored successfully",
                'data'=> ''
            ], 201);
        
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
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
            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }

            /**
             * Lender loan settings part start
             * added on 03-02-2025
             */
            $loan_basic_details = LoanBasicDetails::find($request->borrower_id);
            if(!$loan_basic_details) {
                return $this->send_response("failed", "Invalid request", 422);
            }

            $setting = null;
            if (!empty($loan_basic_details->lender_id)) {
                $setting = LenderLoanSetting::where('lender_id', $loan_basic_details->lender_id)->first();
                $lenderBorrowerGroup = LenderBorrowerGroup::where('borrower_id', $request->borrower_id)->first();
                if($lenderBorrowerGroup) {
                    $setting = LenderLoanSetting::where('lender_id', $lenderBorrowerGroup->lender_id)->first();
                }
                // if ($setting?->guarantor_step === 'required') {
                //     return $this->send_response("failed", "Guarantor step is required, cannot skip.", 422);
                // }
            }
            
            if ($loan_basic_details->product_id != 4 && $setting?->guarantor_step === 'required') {
                return $this->send_response('failed', "Guarantor is required", 422);
            }
            // Lender loan settings part ends

            if(!isset($input['skip_guarantor']) || empty($input['skip_guarantor'])) {
                $errorCount++;
                $errorArray['skip_guarantor'] = "Skip consent is required";
                return $this->send_response("failed", $errorArray['skip_guarantor'], 422);
            }
            
            DB::beginTransaction();
            $nextStep           = 0;
            $currentStep        = 7;
            $borrower_id        = $request->borrower_id;
            $borrower_details   = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
            if(!$borrower_details) {
                return $this->send_response('failed', "Invalid request, borrower not found", 422);
            }
            
            $borrower_details->skip_guarantor = true;
            $borrower_details->save();
                
            $application_step = [
                'step' => $currentStep,
                'loan_basic_details_id' => $borrower_details->id,
                'details' => "Guarantor Details Skipped",
                'status' => "COMPLETED",
            ];
            
            $this->updateApplicationStep($borrower_id, $application_step);
            
            $currentStep = 8;
            $application_step = [
                'step' => $currentStep,
                'loan_basic_details_id' => $borrower_details->id,
                'details' => "Guarantor Occupation Details Skipped",
                'status' => "COMPLETED",
            ];
            
            $this->updateApplicationStep($borrower_id, $application_step);
            
            DB::commit();
            return $this->send_response('success', "Guarantor details skipped successfully", 201);
            
        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred", 422);
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

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }
            
            if(!isset($input['skip_iot']) || empty($input['skip_iot'])) {
                $errorCount++;
                $errorArray['skip_iot'] = "Skip consent is required";
                return $this->send_response("failed", $errorArray['skip_iot'], 422);
            }
            
            DB::beginTransaction();
            $nextStep           = 0;
            $currentStep        = 28;
            $borrower_id        = $request->borrower_id;
            $loan_details       = LoanBasicDetails::find($borrower_id);
            if(!$loan_details) {
                return $this->send_response("failed", "Loan details not found", 422);
            }
            
            $product_id = $loan_details->product_id;
            
            if($product_id != '4') {
                return $this->send_response("failed", "Unable to proceed with this request", 422);
            }
                
            $application_step = [
                'step' => 28,
                'loan_basic_details_id' => $loan_details->id,
                'details' => "IOT Details Skipped",
                'status' => "COMPLETED",
            ];
            
            $this->updateApplicationStep($borrower_id, $application_step);

            DB::commit();
            return $this->send_response('success', "IOT details skipped successfully", 201);
            
        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred", 422);
        }
    }

    // public function storeGuarantorDetails(Request $request) {
    //     $input = $request->all();
    //     $errorArray = [];
    //     date_default_timezone_set('Asia/Kolkata');
    //     $errorCount = 0;
    //     $step = '';
    //     DB::beginTransaction();
    //     // Validation starts
    //         if (!isset($request->borrower_id) || empty($request->borrower_id)) {
    //             $errorCount++;
    //             $errorArray['borrower_id'] = "Borrower id is required";
    //             return $this->send_response("failed", "Borrower id is required", 422);
    //         }

    //         // if (!isset($request->aadhar_number) || empty($request->aadhar_number)) {
    //         //     $errorCount++;
    //         //     $errorArray['aadhar_number'] = "Aadhar number is required";
    //         //     return $this->send_response("failed", $errorArray['aadhar_number'], 422);
    //         // } elseif (!preg_match('/^[0-9]{12}$/', $request->aadhar_number)) {
    //         //     $errorCount++;
    //         //     $errorArray['aadhar_number'] = "Invalid Aadhar number format";
    //         //     return $this->send_response("failed", $errorArray['aadhar_number'], 422);
    //         // }

    //         // if(!isset($request->is_aadhar_verified) || empty($request->is_aadhar_verified)) {
    //         //     $errorCount++;
    //         //     $errorArray['aadhar_number'] = "Aadhar verification is required";
    //         //     return $this->send_response("failed", "Aadhar verification is required", 422);
    //         // } else {
    //         //     if($request->is_aadhar_verified == 0) {
    //         //         $errorCount++;
    //         //         $errorArray['aadhar_number'] = "Aadhar must be validated";
    //         //         return $this->send_response("failed", "Aadhar must be validated", 422);
    //         //     }
    //         // }
        
    //         if (!isset($request->pan_number) || empty($request->pan_number)) {
    //             $errorCount++;
    //             $errorArray['pan_number'] = "Pan number is required";
    //             return $this->send_response("failed", $errorArray['pan_number'], 422);
    //         } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $request->pan_number)) {
    //             $errorCount++;
    //             $errorArray['pan_number'] = "Invalid Pan number format";
    //             return $this->send_response("failed", $errorArray['pan_number'], 422);
    //         }

    //         if(!isset($request->is_pan_verified) || empty($request->is_pan_verified)) {
    //             $errorCount++;
    //             $errorArray['pan_number'] = "Pan verification is required";
    //             return $this->send_response("failed", "Pan verification is required", 422);
    //         } else {
    //             if($request->is_pan_verified == 0) {
    //                 $errorCount++;
    //                 $errorArray['pan_number'] = "Pan must be validated";
    //                 return $this->send_response("failed", "Pan must be validated", 422);
    //             }
    //         }
        
    //         if (!isset($request->full_name) || empty($request->full_name)) {
    //             $errorCount++;
    //             $errorArray['full_name'] = "Full name is required";
    //             return $this->send_response("failed", $errorArray['full_name'], 422);
    //         }
        
    //         if (!isset($request->dob) || empty($request->dob)) {
    //             $errorCount++;
    //             $errorArray['dob'] = "Date of birth is required";
    //             return $this->send_response("failed", $errorArray['dob'], 422);
    //         } elseif (!strtotime($request->dob)) {
    //             $errorCount++;
    //             $errorArray['dob'] = "Invalid date of birth";
    //             return $this->send_response("failed", $errorArray['dob'], 422);
    //         } else {
    //             // Calculate age
    //             date_default_timezone_set('Asia/Kolkata');
    //             $dobTimestamp = strtotime($request->dob);
    //             $age = date('Y') - date('Y', $dobTimestamp);
    //             if (date('md') < date('md', $dobTimestamp)) {
    //                 $age--;
    //             }
    //             if ($age < 21) {
    //                 $errorCount++;
    //                 $errorArray['dob'] = "Age must be greater than 21";
    //                 return $this->send_response("failed", $errorArray['dob'], 422);
    //             }
    //             if ($age > 55) {
    //                 $errorCount++;
    //                 $errorArray['dob'] = "Age must be less than 56";
    //                 return $this->send_response("failed", "Age must be less than 56", 422);
    //             }
    //         }
        
    //         if (!isset($request->gender) || empty($request->gender)) {
    //             $errorCount++;
    //             $errorArray['gender'] = "Gender is required";
    //             return $this->send_response("failed", $errorArray['gender'], 422);
    //         }
        
    //         if (!isset($request->mobile_number) || empty($request->mobile_number)) {
    //             $errorCount++;
    //             $errorArray['mobile_number'] = "Mobile number is required";
    //             return $this->send_response("failed", $errorArray['mobile_number'], 422);
    //         } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $request->mobile_number)) {
    //             $errorCount++;
    //             $errorArray['mobile_number'] = "Invalid mobile number format";
    //             return $this->send_response("failed", $errorArray['mobile_number'], 422);
    //         }

    //         if (!empty($request->alternative_mobile_number) && !preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $request->alternative_mobile_number)) {
    //             $errorCount++;
    //             $errorArray['alternative_mobile_number'] = "Invalid alternative mobile number format";
    //             return $this->send_response("failed", $errorArray['alternative_mobile_number'], 422);
    //         }
        
    //         if (!isset($request->email) || empty($request->email)) {
    //             $errorCount++;
    //             $errorArray['email'] = "Email ID is required";
    //             return $this->send_response("failed", $errorArray['email'], 422);
    //         } elseif (!filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
    //             $errorCount++;
    //             $errorArray['email'] = "Invalid email format";
    //             return $this->send_response("failed", $errorArray['email'], 422);
    //         }

    //         if (!isset($request->father_name) || empty($request->father_name)) {
    //             $errorCount++;
    //             $errorArray['father_name'] = "Father's name is required";
    //             return $this->send_response("failed", $errorArray['father_name'], 422);
    //         }
        
    //         if ($errorCount > 0) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => "Invalid data",
    //                 'error_list' => $errorArray
    //             ], 422);
    //         }

    //         $loan_details = LoanBasicDetails::find($request->borrower_id);
    //         if(!$loan_details) {
    //             return $this->send_response('failed', "Invalid borrower", 422);
    //         }

    //         if($loan_details->status == 'REJECTED') {
    //             return $this->send_response('failed', "Invalid request, the borrower is already rjected", 422);
    //         }

    //         // $aadhar_details = VendorServiceApi::where('borrower_id', $request->borrower_id)
    //         //                         ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
    //         //                         ->where('applicant_type', 'GUARANTOR')
    //         //                         ->where('status', 'VALID')
    //         //                         ->latest()->first();
    //         // if(!$aadhar_details) {
    //         //     return $this->send_response('failed', "Verify aadhar first", 422);
    //         // }
    //         // $aadharData = $aadhar_details->response;
    //         // $aadharData = base64_decode($aadharData);
    //         // $aadharData = unserialize($aadharData);
    //         // $aadharData = json_decode($aadharData);
    //         // if(isset($aadharData->model->dob) && !empty($aadharData->model->dob)) {
    //         //     $aadharDob = date('Y-m-d', strtotime($aadharData->model->dob));
    //         // }

    //         $pan_details = VendorServiceApi::where('borrower_id', $request->borrower_id)
    //                                 ->where('api_flag', 'PAN_DETAILS_VALIDATION')
    //                                 ->where('applicant_type', 'GUARANTOR')
    //                                 ->where('status', 'VALID')
    //                                 ->latest()->first();

    //         $panData = $pan_details->response;
    //         $panData = base64_decode($panData);
    //         $panData = unserialize($panData);
    //         $panData = json_decode($panData);
    //         if(isset($panData->result->dob) && !empty($panData->result->dob)) {
    //             $panDob = $panData->result->dob;
    //         }

    //         if(isset($aadharDob) && !empty($aadharDob) && isset($panDob) && !empty($panDob)) {
    //             $dob1 = new DateTime($aadharDob);
    //             $dob2 = DateTime::createFromFormat('d/m/Y', $panDob);
    //             $diffDays = abs($dob1->diff($dob2)->days);
    //                 if($diffDays > 89 && $diffDays < 1096) {
    //                     if(!$request->hasFile('dob_declaration')) {
    //                         return $this->send_response('failed', "DOB declaration is required", 422);
    //                     }
    //                 } elseif ($diffDays >= 1096) {
    //                     return $this->send_response('failed', "Date of birth difference in aadhar and pan is more than 3 years, please change the Guarantor", 422);
    //                 }
    //         }
    //     // Validation ends

    //     $aadhar_number              = $request->aadhar_number ?? '';
    //     $pan_number                 = $request->pan_number;
    //     $full_name                  = $request->full_name;
    //     $dob                        = date('Y-m-d', strtotime($request->dob));
    //     $gender                     = $request->gender;
    //     $mobile_number              = $request->mobile_number;
    //     $relation_with_borrower     = $request->relation_with_borrower;
    //     $email                      = $request->email;
    //     $father_name                = $request->father_name;

    //     if($request->is_pan_verified == '1') {
    //         $is_pan_verified = true;
    //         $pan_message = "Active";
    //     } else {
    //         $is_pan_verified = false;
    //         $pan_message = "Invalid";
    //     }
    //     if($request->is_aadhar_verified == '1') {
    //         $is_aadhar_verified = true;
            
    //     } else {
    //         $is_aadhar_verified = false;
    //     }

    //     $userId             = '';
    //     $nextStep           = 0;
    //     $currentStep        = 7;

    //     $borrower_id = $request->borrower_id;
    //     $checkData = [
    //         'pan' => $request->pan_number,
    //         // 'aadhar' => $request->aadhar_number,
    //         'contact_number' => $request->mobile_number
    //     ];
    //     $checkDuplicate = $this->check_if_kyc_data_is_similar($checkData, $borrower_id, "GUARANTOR");
    //     if($checkDuplicate !== true) {
    //         return $checkDuplicate;
    //     }

    //     $guarantor_details = new Guarantor;
    //     $guarantor_details->name                = $full_name;
    //     $guarantor_details->mobile              = $mobile_number;
    //     $guarantor_details->is_verified_mobile  = isset($is_mobile_number_verified) ? $is_mobile_number_verified : false;
    //     $guarantor_details->pan                 = $pan_number;
    //     $guarantor_details->is_verified_pan     = $is_pan_verified;
    //     $guarantor_details->pan_message         = $pan_message;
    //     $guarantor_details->aadhar              = $aadhar_number;
    //     $guarantor_details->is_verified_aadhar  = $is_aadhar_verified;
    //     $guarantor_details->asset_value         = 0;
    //     $guarantor_details->loan_amount         = 0;
    //     $guarantor_details->down_payment        = 0;
    //     $guarantor_details->dob_date            = $dob;
    //     $guarantor_details->father_name         = isset($father_name) ? $father_name : '';
    //     $guarantor_details->relation_with_borrower = isset($relation_with_borrower) ? $relation_with_borrower : '';
    //     $guarantor_details->gender              = ucfirst(strtolower($gender));
    //     $guarantor_details->email               = $email;
    //     $guarantor_details->cibil               = 0;
    //     $guarantor_details->postal              = 0;
    //     $guarantor_details->state               = '';
    //     $guarantor_details->country             = '';
    //     $guarantor_details->avtar               = '';
    //     $guarantor_details->alternate_number1   = $request->alternative_mobile_number ?? '';
    //     $guarantor_details->alternate_number2   = '';
    //     $guarantor_details->borrower_id         = $borrower_id;
    //     if($guarantor_details->save()) {

    //         // if(isset($request->name_declaration) && !empty($request->name_declaration)) {
    //         //     $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
    //         //     if(!$kyc_docs) {
    //         //         $kyc_docs                    = new Documents;
    //         //         $kyc_docs->borrower_id       = $borrower_id;
    //         //     }
    //         //     $kyc_docs->guarantor_name_declaration = $request->name_declaration;
    //         // }
    //         if($request->hasFile('name_declaration') || $request->hasFile('dob_declaration')) {
    //             $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
    //             if(!$kyc_docs) {
    //                 $kyc_docs                    = new Documents;
    //                 $kyc_docs->borrower_id       = $borrower_id;
    //             }

    //             if($request->hasFile('name_declaration')) {
    //                 $uploadResultName = $this->general_file_upload($request->file('name_declaration'), 'NAME_DECLARATION', 'GUARANTOR_KYC', $borrower_id);
    //                 if ($uploadResultName['status'] === 'failed') {
    //                     DB::rollBack(); // roll back before returning
    //                     return $this->send_response('failed', $uploadResultName['message'], 422);
    //                 }
    //                 $nameDeclarationUrl = $uploadResultName['data'];
    //                 $kyc_docs->guarantor_name_declaration = $nameDeclarationUrl; //$request->name_declaration;
    //             }

    //             if($request->hasFile('dob_declaration')) {
    //                 $uploadResultDob = $this->general_file_upload($request->file('dob_declaration'), 'DOB_DECLARATION', 'GUARANTOR_KYC', $borrower_id);
    //                 if ($uploadResultDob['status'] === 'failed') {
    //                     DB::rollBack(); // roll back before returning
    //                     return $this->send_response('failed', $uploadResultDob['message'], 422);
    //                 }
    //                 $dobDeclarationUrl = $uploadResultDob['data'];
    //                 $kyc_docs->guarantor_dob_declaration = $dobDeclarationUrl;
    //             }
    //             $kyc_docs->save();
    //         }

    //         $application_step = [
    //             'step' => $currentStep,
    //             'guarantor_details_id' => $guarantor_details->id,
    //             'details' => "Guarantor Details",
    //             'status' => "COMPLETED",
    //         ];

    //         $this->updateApplicationStep($borrower_id, $application_step);

    //         // Update Loan Application Summary
    //         $loan_application_summary = LoanApplicationSummary::where('borrower_id', $borrower_id)->first();
    //         if($loan_application_summary) {

    //             $loan_application_summary->guarantor_id        = $guarantor_details->id;
    //             $loan_application_summary->save();
    //         }

    //         DB::commit();
    //         return response()->json([
    //             'status'=>'success',
    //             'message'=>"Guatantor details stored successfully",
    //             'data'=> ''
    //         ], 201);
    //     } else {
    //         DB::rollBack();
    //         return response()->json([
    //             'status'=>'failed',
    //             'message'=>"Failed to store guatantor details",
    //         ], 400);
    //     }

    // }

    public function storeGuarantorDetails(Request $request)
    {
        try {
            $input = $request->all();
            $errorArray = [];
            date_default_timezone_set('Asia/Kolkata');
            $errorCount = 0;
            $step = '';
            DB::beginTransaction();
            // Validation starts
            $validator = Validator::make($request->all(), [
                'borrower_id'              => 'required|integer|exists:loan_basic_details,id',
                'pan_number'               => ['required','regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
                'is_pan_verified'          => 'required|in:Yes',
                'full_name'                => ['required','regex:/^[a-zA-Z\s]+$/'],
                'dob'                      => 'required|date',
                'gender'                   => 'required|in:Male,Female,Other',
                'mobile_number'            => ['required','regex:/^(?:\+91|91)?[6-9]\d{9}$/'],
                'alternative_mobile_number' => ['nullable','regex:/^(?:\+91|91)?[6-9]\d{9}$/'],
                'email'                    => 'required|email',
                'father_name'              => ['required','regex:/^[a-zA-Z\s]+$/'],
                'aadhar_front' => 'required|file|mimetypes:image/jpeg,image/png,image/jpg,application/pdf|max:5120', // 5MB
                'aadhar_back'  => 'required|file|mimetypes:image/jpeg,image/png,image/jpg,application/pdf|max:5120',
                'pan_doc'  => 'required|file|mimetypes:image/jpeg,image/png,image/jpg,application/pdf|max:5120',
            ], [
                'borrower_id.exists'                 => 'Invalid borrower ID',
                'pan_number.regex'                  => 'Invalid PAN number format',
                'is_pan_verified.in'                => 'PAN must be validated',
                'full_name.regex'                   => 'Full name must contain only alphabets and spaces',
                'dob.date'                          => 'Invalid date of birth format',
                'gender.in'                         => 'Gender must be Male or Female',
                'mobile_number.regex'               => 'Invalid mobile number format',
                'alternative_mobile_number.regex'   => 'Invalid alternative mobile number format',
                'email.email'                       => 'Invalid email format',
                'father_name.regex'                 => 'Father\'s name must contain only alphabets and spaces',
            ]);


            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            $dob = strtotime($request->dob);
            $age = date('Y') - date('Y', $dob);
            if (date('md') < date('md', $dob)) {
                $age--;
            }

            if ($age < 21) {
                return $this->send_response('failed', 'Age must be greater than 21', 422);
            }

            if ($age > 55) {
                return $this->send_response('failed', 'Age must be less than 56', 422);
            }

            $loan_details = LoanBasicDetails::find($request->borrower_id);
            if (!$loan_details) {
                return $this->send_response('failed', "Invalid borrower", 422);
            }

            if ($loan_details->status == 'REJECTED') {
                return $this->send_response('failed', "Invalid request, the borrower is already rjected", 422);
            }

            if ($loan_details->status == 'CANCELLED') {
                return $this->send_response('failed', "Your application is on hold, please contact Finayo", 422);
            }

            // $aadhar_details = VendorServiceApi::where('borrower_id', $request->borrower_id)
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

            $pan_details = VendorServiceApi::where('borrower_id', $request->borrower_id)
                ->where('api_flag', 'PAN_DETAILS_VALIDATION')
                ->where('applicant_type', 'GUARANTOR')
                ->where('status', 'VALID')
                ->latest()->first();

            $panData = $pan_details->response;
            $panData = base64_decode($panData);
            $panData = unserialize($panData);
            $panData = json_decode($panData);
            if (isset($panData->result->dob) && !empty($panData->result->dob)) {
                $panDob = $panData->result->dob;
            }

            if (isset($aadharDob) && !empty($aadharDob) && isset($panDob) && !empty($panDob)) {
                $dob1 = new DateTime($aadharDob);
                $dob2 = DateTime::createFromFormat('d/m/Y', $panDob);
                $diffDays = abs($dob1->diff($dob2)->days);
                if ($diffDays > 89 && $diffDays < 1096) {
                    if (!$request->hasFile('dob_declaration')) {
                        return $this->send_response('failed', "DOB declaration is required", 422);
                    }
                } elseif ($diffDays >= 1096) {
                    return $this->send_response('failed', "Date of birth difference in aadhar and pan is more than 3 years, please change the Guarantor", 422);
                }
            }
            // Validation ends

            $aadhar_number              = $request->aadhar_number ?? '';
            $pan_number                 = $request->pan_number;
            $full_name                  = $request->full_name;
            $dob                        = date('Y-m-d', strtotime($request->dob));
            $gender                     = $request->gender;
            $mobile_number              = $request->mobile_number;
            $relation_with_borrower     = $request->relation_with_borrower;
            $email                      = $request->email;
            $father_name                = $request->father_name;

            if ($request->is_pan_verified == '1') {
                $is_pan_verified = true;
                $pan_message = "Active";
            } else {
                $is_pan_verified = false;
                $pan_message = "Invalid";
            }
            if ($request->is_aadhar_verified == '1') {
                $is_aadhar_verified = true;
            } else {
                $is_aadhar_verified = false;
            }

            $userId             = '';
            $nextStep           = 0;
            $currentStep        = 7;

            $borrower_id = $request->borrower_id;
            $checkData = [
                'pan' => $request->pan_number,
                // 'aadhar' => $request->aadhar_number,
                'contact_number' => $request->mobile_number
            ];
            $checkDuplicate = $this->check_if_kyc_data_is_similar($checkData, $borrower_id, "GUARANTOR");
            if ($checkDuplicate !== true) {
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
            $guarantor_details->gender              = ucfirst(strtolower($gender));
            $guarantor_details->email               = $email;
            $guarantor_details->cibil               = 0;
            $guarantor_details->postal              = 0;
            $guarantor_details->state               = '';
            $guarantor_details->country             = '';
            $guarantor_details->avtar               = '';
            $guarantor_details->alternate_number1   = $request->alternative_mobile_number ?? '';
            $guarantor_details->alternate_number2   = '';
            $guarantor_details->borrower_id         = $borrower_id;
            if ($guarantor_details->save()) {
                $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                if (!$kyc_docs) {
                    $kyc_docs                    = new Documents;
                    $kyc_docs->borrower_id       = $borrower_id;
                }
                
                $uploadAadharFront = $this->general_file_upload($request->file('aadhar_front'), 'AADHAR_FRONT', 'GUARANTOR_KYC', $borrower_id);
                if ($uploadAadharFront['status'] === 'failed') {
                    DB::rollBack(); // roll back before returning
                    return $this->send_response('failed', $uploadAadharFront['message'], 422);
                }
                $aadhar_front = $uploadAadharFront['data'];
                $kyc_docs->guarantor_aadhar_front = $aadhar_front;

                $uploadAadharBack = $this->general_file_upload($request->file('aadhar_back'), 'AADHAR_BACK', 'GUARANTOR_KYC', $borrower_id);
                if ($uploadAadharBack['status'] === 'failed') {
                    DB::rollBack(); // roll back before returning
                    return $this->send_response('failed', $uploadAadharBack['message'], 422);
                }
                $aadhar_back = $uploadAadharBack['data'];
                $kyc_docs->guarantor_aadhar_back = $aadhar_back;

                $uploadPanDoc = $this->general_file_upload($request->file('pan_doc'), 'PAN_CARD', 'GUARANTOR_KYC', $borrower_id);
                if ($uploadPanDoc['status'] === 'failed') {
                    DB::rollBack(); // roll back before returning
                    return $this->send_response('failed', $uploadPanDoc['message'], 422);
                }
                $pan_doc = $uploadPanDoc['data'];
                $kyc_docs->guarantor_pan_doc = $pan_doc;

                if ($request->hasFile('name_declaration') || $request->hasFile('dob_declaration')) {

                    if ($request->hasFile('name_declaration')) {
                        $uploadResultName = $this->general_file_upload($request->file('name_declaration'), 'NAME_DECLARATION', 'GUARANTOR_KYC', $borrower_id);
                        if ($uploadResultName['status'] === 'failed') {
                            DB::rollBack(); // roll back before returning
                            return $this->send_response('failed', $uploadResultName['message'], 422);
                        }
                        $nameDeclarationUrl = $uploadResultName['data'];
                        $kyc_docs->guarantor_name_declaration = $nameDeclarationUrl; //$request->name_declaration;
                    }

                    if ($request->hasFile('dob_declaration')) {
                        $uploadResultDob = $this->general_file_upload($request->file('dob_declaration'), 'DOB_DECLARATION', 'GUARANTOR_KYC', $borrower_id);
                        if ($uploadResultDob['status'] === 'failed') {
                            DB::rollBack(); // roll back before returning
                            return $this->send_response('failed', $uploadResultDob['message'], 422);
                        }
                        $dobDeclarationUrl = $uploadResultDob['data'];
                        $kyc_docs->guarantor_dob_declaration = $dobDeclarationUrl;
                    }
                }
                $kyc_docs->save();

                $application_step = [
                    'step' => $currentStep,
                    'guarantor_details_id' => $guarantor_details->id,
                    'details' => "Guarantor Details",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($borrower_id, $application_step);

                // Update Loan Application Summary
                $loan_application_summary = LoanApplicationSummary::where('borrower_id', $borrower_id)->first();
                if ($loan_application_summary) {

                    $loan_application_summary->guarantor_id        = $guarantor_details->id;
                    $loan_application_summary->save();
                }

                DB::commit();
                return response()->json([
                    'status' => 'success',
                    'message' => "Guatantor details stored successfully",
                    'data' => ''
                ], 201);
            } else {
                DB::rollBack();
                return response()->json([
                    'status' => 'failed',
                    'message' => "Failed to store guatantor details",
                ], 400);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->send_response("failed", "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }
    
    public function storeGuarantorOccupation(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            date_default_timezone_set('Asia/Kolkata');
            $errorCount = 0;
            DB::beginTransaction();
            $step = '';

            // Validation starts
                if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                    $errorCount++;
                    $errorArray['borrower_id'] = "Borrower id is required";
                    return $this->send_response("failed", "Borrower id is required", 422);
                }

                $loan_details       = LoanBasicDetails::find($request->borrower_id);
                if(!$loan_details) {
                    return $this->send_response("failed", "Loan details not found", 422);
                }

                $product_id = $loan_details->product_id;

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
                        $errorArray['monthly_income'] = "Monthly income must be atleast 12000";
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
                            $errorArray['expected_monthly_income'] = "Expected monthly income must be atleast 12000";
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
                        $errorArray['total_yearly_income'] = "Total yearly income must be atleast 144000";
                        return $this->send_response("failed", $errorArray['total_yearly_income'], 422);
                    }
                }

                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }

                if ($request->hasFile('guarantor_income_proof_doc')) {
                    $uploadResponse = $this->general_file_upload(
                        $request->file('guarantor_income_proof_doc'),
                        'GUARANTOR_INCOME_PROOF_DOC',
                        'GUARANTOR_KYC',
                        $loan_details->borrower_id
                    );
                
                    if ($uploadResponse['status'] === 'failed') {
                        return $this->helper_controller->send_response('failed', $uploadResponse['message'], 422);
                    }
                
                    $guarantor_income_proof_url = $uploadResponse['data'];
                }
            // Validation ends

            $occupation_type            = $request->occupation_type;
            $current_occupation         = $request->current_occupation;
            $monthly_income             = $request->monthly_income ?? 0;
            $expected_monthly_income    = $request->expected_monthly_income ?? $monthly_income;
            $other_income               = $request->other_income ?? 0;
            $extra_source_of_income     = $request->extra_source_of_income;
            $total_yearly_income        = $request->total_yearly_income;

            $userId             = '';
            $nextStep           = 0;
            $currentStep        = 8;

            $borrower_id                                    = $request->borrower_id;
            $gurantorOccupation                             = new GuarantorOccupation;
            $gurantorOccupation->occupation_type            = $occupation_type;
            $gurantorOccupation->current_occupation         = $current_occupation;
            $gurantorOccupation->driving_experience         = isset($driving_experience) ? $driving_experience : 0;
            $gurantorOccupation->vehicle_type               = isset($vehicle_type) ? $vehicle_type : 'No Vehicle';
            $gurantorOccupation->monthly_earning            = $monthly_income;
            $gurantorOccupation->expected_monthly_earning   = $expected_monthly_income;
            $gurantorOccupation->extra_source_income        = $other_income ?? 0;
            $gurantorOccupation->source_income_area         = $extra_source_of_income;
            $gurantorOccupation->total_income               = $total_yearly_income;
            $gurantorOccupation->current_city               = '';
            $gurantorOccupation->borrower_id                = $borrower_id;
            
            $gurantorOccupation->resident_type              = $request->resident_type;
            $gurantorOccupation->current_address            = $request->current_address;
            $gurantorOccupation->years_living               = $request->years_living;
            $gurantorOccupation->permanent_address          = $request->permanent_address;

            $resident_stability_check = $this->check_resident_stability($borrower_id, $request->resident_type);
            if($resident_stability_check) {
                if(isset($resident_stability_check['status']) && $resident_stability_check['status'] !== true) {
                    return $this->send_response("failed", $resident_stability_check['message'], 422);
                }
            } else {
                return $this->send_response("failed", 'Borrower or guarantor or co applicant must have owned house', 422);
            }

            $gurantorOccupation->save();

            if(isset($guarantor_income_proof_url)) {

                $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
                if (!$kyc_docs) {
                    $kyc_docs                    = new Documents;
                    $kyc_docs->borrower_id       = $borrower_id;
                }
                $kyc_docs->guarantor_income_proof_doc = $guarantor_income_proof_url;
                $kyc_docs->save();
            }

            $application_step = [
                'step' => $currentStep,
                'guarantor_occupation_id' => $gurantorOccupation->id,
                'details' => "Guarantor Occupation",
                'status' => "COMPLETED",
            ];

            $this->updateApplicationStep($borrower_id, $application_step);

            DB::commit();
            return response()->json([
                'status'=>'success',
                'message'=>"Guarantor occupation details stored successfully",
                'data'=> ''
            ], 201);
        
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function check_resident_stability($borrower_id, $guarantor_resident_type) {
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

        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }

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
       
        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }

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
        $userId             = '';
        $borrower_id        = $request->borrower_id;

        $loan_basic_details                 = LoanBasicDetails::find($borrower_id);
        if(!$loan_basic_details) {
            return $this->send_response("failed", "Borrower not found", 422);
        }
        
        $loan_basic_details->lender_consent = $consent;

        if($loan_basic_details->save()) {
            $application_step = [
                'step' => 10, //$currentStep,
                'loan_basic_details' => $loan_basic_details->id,
                'details' => "Lender Consent",
                'status' => "COMPLETED",
            ];

            $this->updateApplicationStep($borrower_id, $application_step);

            return $this->send_response('success', "Lender consent updated", 200);
        } else {
            return $this->send_response('failed', "Failed to updated consent, try again", 400);
        }
    }

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
            // if (!isset($request->user_id) || empty($request->user_id)) {
            //     $errorArray['user_id'] = "user id is required";
            //     return $this->send_response("failed", $errorArray['user_id'], 422);
            // }

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }
            $loan_details       = LoanBasicDetails::find($request->borrower_id);
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
                $link_2 = 'http://13.200.65.87/reference/'.$short_link_2;
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
            $borrower_kyc = BorrowerKyc::where('borrower_id', $request->borrower_id)->first();
            if (!$borrower_kyc) {
                return $this->helper_controller->send_response('failed', 'Invalid request', 422);
            }

            // Collect all relevant phone numbers
            $numbers_used_in_this_application[] = $borrower_kyc->mobile;

            // Add co-applicant's number if it exists
            $coapplicant_kyc = CoapplicantKyc::where('borrower_id', $request->borrower_id)->first();
            if ($coapplicant_kyc) {
                $numbers_used_in_this_application[] = $coapplicant_kyc->mobile;
            }

            // Add guarantor's number if it exists
            $guarantor_kyc = Guarantor::where('borrower_id', $request->borrower_id)->first();
            if ($guarantor_kyc) {
                $numbers_used_in_this_application[] = $guarantor_kyc->mobile;
            }

            // Check if the input number is already used in the current application
            if (in_array($input['mobile_1'], $numbers_used_in_this_application, true)) {
                return $this->helper_controller->send_response('failed', "Borrower's, co-applicant's, or guarantor's number cannot be used as a reference", 422);
            }

            // 2. Ensure the number is a unique reference number (hasn't been used in previous applications)
            // $reference_exists = BorrowerReferenceDetails::where('mobile_1', $input['mobile_1'])
            //     ->orWhere('mobile_2', $input['mobile_1'])
            //     ->exists();

            // if ($reference_exists) {
            //     $response_text = $input['mobile_1'] . " has already been used in another application";
            //     return $this->helper_controller->send_response('failed', $response_text, 422);
            // }

            // if(isset($input['mobile_2']) && !empty($input['mobile_2'])) {
            //     if (in_array($input['mobile_2'], $numbers_used_in_this_application, true)) {
            //         return $this->helper_controller->send_response('failed', "Borrower's, co-applicant's, or guarantor's number cannot be used as a reference", 422);
            //     }
    
            //     // 2. Ensure the number is a unique reference number (hasn't been used in previous applications)
            //     $reference_exists = BorrowerReferenceDetails::where('mobile_1', $input['mobile_2'])
            //         ->orWhere('mobile_2', $input['mobile_2'])
            //         ->exists();
    
            //     if ($reference_exists) {
            //         $response_text = $input['mobile_2'] . " has already been used in another application";
            //         return $this->helper_controller->send_response('failed', $response_text, 422);
            //     }
            // }
            $existing_reference = BorrowerReferenceDetails::where(function ($query) use ($input) {
                $query->where('mobile_1', $input['mobile_1'])
                      ->orWhere('mobile_2', $input['mobile_1']);
            })
            ->where('borrower_id', '!=', $request->borrower_id) // Ignore current borrower's record
            ->exists();

            if ($existing_reference) {
                $response_text = $input['mobile_1'] . " has already been used in another application";
                return $this->helper_controller->send_response('failed', $response_text, 422);
            }

            if (isset($input['mobile_2']) && !empty($input['mobile_2'])) {
                if (in_array($input['mobile_2'], $numbers_used_in_this_application, true)) {
                    return $this->helper_controller->send_response('failed', "Borrower's, co-applicant's, or guarantor's number cannot be used as a reference", 422);
                }

                $existing_reference = BorrowerReferenceDetails::where(function ($query) use ($input) {
                    $query->where('mobile_1', $input['mobile_2'])
                          ->orWhere('mobile_2', $input['mobile_2']);
                })
                ->where('borrower_id', '!=', $request->borrower_id) // Ignore current borrower's record
                ->exists();

                if ($existing_reference) {
                    $response_text = $input['mobile_2'] . " has already been used in another application";
                    return $this->helper_controller->send_response('failed', $response_text, 422);
                }
            }

            /**
             * Reference unique validation end.
             */
            
            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }
            
            $short_link_1 = $this->generate_short_url_link();
            $link_1 = 'http://13.200.65.87/reference/'.$short_link_1;
            $mobile_1 = '+91'. $input['mobile_1'];
            $message_1 = "Click the link to verify as a reference in Finayo. ". $link_1;
                
            DB::beginTransaction();
            // $userId                                     = $request->user_id;
            $borrower_id                                = $request->borrower_id;
            $reference_details = BorrowerReferenceDetails::where('borrower_id', $borrower_id)->latest()->first();
            $is_new_record = false;

            if(!$reference_details) {
                $reference_details                      = new BorrowerReferenceDetails;
                $reference_details->borrower_id         = $borrower_id;
                $is_new_record = true;
            }

            // $reference_details->reference_1             = $input['name_1'];
            // $reference_details->mobile_1                = $input['mobile_1'];
            // $reference_details->relation_1              = $input['relation_1'];
            // $reference_details->address_1               = $input['address_1'];
            // $reference_details->shortend_url_1          = $short_link_1;
            // $reference_details->is_verified_1           = true; // disable in live
            // $reference_details->reference_2             = $input['name_2'] ?? '';
            // $reference_details->mobile_2                = $input['mobile_2'] ?? '';
            // $reference_details->relation_2              = $input['relation_2'] ?? '';
            // $reference_details->address_2               = $input['address_2'] ?? '';
            // $reference_details->shortend_url_2          = $short_link_2 ?? '';
            // $reference_details->is_verified_2           = true; // disable in live
            // $reference_details->save();
            
            // Reference 1
            if (
                $is_new_record ||
                $reference_details->mobile_1 !== $input['mobile_1'] ||
                $reference_details->is_verified_1 != true
            ) {
                $reference_details->reference_1    = $input['name_1'];
                $reference_details->mobile_1       = $input['mobile_1'];
                $reference_details->relation_1     = $input['relation_1'];
                $reference_details->address_1      = $input['address_1'];
                $reference_details->shortend_url_1 = $short_link_1;
                $reference_details->is_verified_1           = true;
            }

            // Reference 2
            if (!empty($input['mobile_2'])) {
                if (
                    $is_new_record ||
                    $reference_details->mobile_2 !== $input['mobile_2'] ||
                    $reference_details->is_verified_2 != true
                ) {
                    $reference_details->reference_2    = $input['name_2'];
                    $reference_details->mobile_2       = $input['mobile_2'];
                    $reference_details->relation_2     = $input['relation_2'];
                    $reference_details->address_2      = $input['address_2'];
                    $reference_details->shortend_url_2 = $short_link_2;
                    $reference_details->is_verified_2           = true;
                }
            }

            $reference_details->save();

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

            $this->updateApplicationStep($borrower_id, $application_step);

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
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
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

    public function get_reference_list(Request $request) {

        try {
            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                // $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }

            $borrower_id    = $request->borrower_id;
            $loan_details       = LoanBasicDetails::find($request->borrower_id);
            if(!$loan_details) {
                return $this->send_response("failed", "Loan details not found", 422);
            }
            $product_id = $loan_details->product_id;
            
            $reference      = BorrowerReferenceDetails::where('borrower_id', $borrower_id)->latest()->first();
            $currentStep    = 15;
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
                        
                        $this->updateApplicationStep($borrower_id, $application_step);
                    }

                    $resultData = [
                        'reference_1' => $reference->reference_1,
                        'mobile_1' => $reference->mobile_1,
                        'relation_1' => $reference->relation_1,
                        'address_1' => $reference->address_1,
                        'is_verified_mobile_1' => $reference->is_verified_mobile_1,
                        'is_reference_verified_1' => $reference->is_verified_1,
                        'link_1' => 'http://13.200.65.87/reference/'.$reference->shortend_url_1
                    ];
                } else {

                    if($reference->is_verified_1 == true && $reference->is_verified_2 == true) {
                        $application_step = [
                            'step' => $currentStep,
                            'reference_details_id' => $reference->id,
                            'details' => "Reference Verification",
                            'status' => "COMPLETED",
                        ];
                        
                        $this->updateApplicationStep($borrower_id, $application_step);
                    }
                    $resultData = [
                        'reference_1' => $reference->reference_1,
                        'mobile_1' => $reference->mobile_1,
                        'relation_1' => $reference->relation_1,
                        'address_1' => $reference->address_1,
                        'is_verified_mobile_1' => $reference->is_verified_mobile_1,
                        'is_reference_verified_1' => $reference->is_verified_1,
                        'link_1' => 'http://13.200.65.87/reference/'.$reference->shortend_url_1,
                        'reference_2' => $reference->reference_2,
                        'mobile_2' => $reference->mobile_2,
                        'relation_2' => $reference->relation_2,
                        'address_2' => $reference->address_2,
                        'is_verified_mobile_2' => $reference->is_verified_mobile_2,
                        'is_reference_verified_2' => $reference->is_verified_2,
                        'link_2' => 'http://13.200.65.87/reference/'.$reference->shortend_url_2,
                    ];
                }
                
                return $this->send_response('success', "Reference details fetched successfully", 200, $resultData);
            } else {
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Reference not found",
                ], 400);
            
            }
        } catch (Exception $e) {
            return $this->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function store_field_verification_details(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            $currentStep = 16;
            date_default_timezone_set('Asia/Kolkata');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            } else {
                if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                    $errorCount++;
                    $errorArray['borrower_id'] = "Borrower id is required";
                    return $this->send_response("failed", "Borrower id is required", 422);
                }

                if(!isset($input['name']) || empty($input['name'])) {
                    $errorCount++;
                    $errorArray['name'] = "Name is required";
                    return $this->send_response("failed", $errorArray['name'], 422);
                }
                
                if (!isset($input['mobile']) || empty($input['mobile'])) {
                    $errorCount++;
                    $errorArray['mobile'] = "Mobile number is required";
                    return $this->send_response("failed", $errorArray['mobile'], 422);
                } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $input['mobile'])) {
                    $errorCount++;
                    $errorArray['mobile'] = "Invalid mobile number format";
                    return $this->send_response("failed", $errorArray['mobile'], 422);
                }

                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }

                $validator = Validator::make($request->all(), [
                    'dealer_shop_id' => ['required', 'integer', 'exists:dealer_shops,id'],
                ]);

                if ($validator->fails()) {
                    return $this->send_response('failed', $validator->errors()->first(), 422);
                }

                $userId      = '';
                $borrower_id = $request->borrower_id;
                
                do {
                    $short_link = $this->generate_random_string(6);
                } while(
                    FieldInspectionDetails::where('verification_url', $short_link)
                            ->orWhere('verification_url', $short_link)
                            ->exists()
                );

            $field_inspection_details = FieldInspectionDetails::where('borrower_id', $borrower_id)->latest()->first();
            if(!$field_inspection_details) {
                $field_inspection_details                           = new FieldInspectionDetails;
                $field_inspection_details->borrower_id              = $borrower_id;
            }
                $field_inspection_details->name                     = $input['name'];
                $field_inspection_details->mobile                   = $input['mobile'];
                $field_inspection_details->is_inspection_verified   = false; // true; // default is false for live env
                $field_inspection_details->verification_url         = $short_link;
                $field_inspection_details->dealer_shop_id           = $request->dealer_shop_id;
                $field_inspection_details->access_token             = null;
                $field_inspection_details->distance                 = null;
                if($field_inspection_details->save()) {

                    // $link = 'http://13.200.65.87/field-inspecction/'.$short_link;
                    $link = 'https://play.google.com/store/apps/details?id=com.finayo_inspector';
                    try {
                        $mobile = '+91'. $input['mobile'];
                        // $message = "Click the below link for address verification in Finayo. ". $link;
                        $message = "Please download the Finayo Inspector app from the link below to complete your field verification: ". $link;
                        // $this->twilio->sendSms($mobile, $message);

                        $application_step = [
                            'step' => $currentStep,
                            'field_inspection_details_id' => $field_inspection_details->id,
                            'details' => "Field Inspection Details",
                            'status' => "COMPLETED",
                        ];
            
                        $this->updateApplicationStep($borrower_id, $application_step);

                        return response()->json([
                            'status' => 'success',
                            'message' => 'Click the link for address verification in Finayo.',
                            'link' => $link,
                            'step' => $step
                        ], 200);
                    } catch (\Exception $e) {
                        return response()->json([
                            'status' => 'error',
                            'message' => $e->getMessage()
                        ], 403);
                    }

                    return response()->json([
                        'status'=>'success',
                        'message'=>"Field inspection details stored successfully",
                        'data'=> ''
                    ], 201);
                } else {
                    return response()->json([
                        'status'=>'failed',
                        'message'=>"Failed to store field inspection details",
                    ], 400);
                }
            }
        } catch (Exception $e) {
            return $this->send_response('failed', $e->getMessage(), 422);
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
            
            if(!isset($input['verifcation_code']) || empty($input['verifcation_code'])) {
                $errorCount++;
                $errorArray['verifcation_code'] = "Verification code is required";
                return $this->send_response("failed", $errorArray['verifcation_code'], 200);
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
            $verifcation_code = $input['verifcation_code'];

            $field_inspection_details = FieldInspectionDetails::where('verification_url', $verifcation_code)->first();
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
                $vendor_service_api = VendorServiceApi::where('borrower_id', $borrower_id)
                                        ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
                                        ->where('status', "VALID")
                                        ->where('applicant_type', "BORROWER")
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
                                $field_inspection_details = FieldInspectionDetails::where('verification_url', $verifcation_code)->first();
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
                    
                                $this->updateApplicationStep($borrower_id, $application_step);

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

    public function get_field_verification_status(Request $request) {
        $input = $request->all();
        $userId         = '';

        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            // $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }
        
        $borrower_id    = $request->borrower_id;

        $field_verification_details = FieldInspectionDetails::where('borrower_id', $borrower_id)->latest()->first();
        if($field_verification_details) {
            return response()->json([
                'status'=>'success',
                'message'=>"Field inspection details fetched successfully",
                'data'=> [
                    'name' => $field_verification_details->name,
                    'mobile' => $field_verification_details->mobile,
                    'is_verified' => true, //$field_verification_details->is_inspection_verified,
                ]
            ], 200);
        } else {
            return response()->json([
                'status'=>'failed',
                'message'=>"Not found",
            ], 400);
        }
    }

    public function address_verification_api($data) {        
        date_default_timezone_set('Asia/Kolkata');

        // $service_api    = new ServiceApiController;
        $ip             = $this->get_user_ip();
        $unique_id = $this->generate_random_id(4);

        if(isset($data['longitude']) && isset($data['latitude'])) {
            $longitude  = $data['longitude'];
            $latitude   = $data['latitude'];
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
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        date_default_timezone_set('Asia/Kolkata');

        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }

        /**
         * Code Updated On: 20-01-2025
         * This validation part Validator::make(), specially the account details is added on 20-01-2025
         * as required in stashfin module developement as well as our later development process
         */
        $validator = Validator::make($request->all(), [
            'account_holder_name' => ['required', 'regex:/^[a-zA-Z\s]+$/', 'max:255'],
            'account_number' => 'required|regex:/^[0-9]+$/',
            'confirm_account_number' => 'required|regex:/^[0-9]+$/|same:account_number',
            'ifsc_code' => ['required', 'regex:/^[a-zA-Z0-9]+$/', 'max:25'],
            'bank_name' => ['required', 'regex:/^[a-zA-Z\s]+$/', 'max:255'],
            'branch_code' => ['nullable', 'regex:/^[a-zA-Z0-9\s]+$/', 'max:255'],
            'emi_debit_consent' => 'required|in:YES,NO',
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
        $userId         = '';
        $borrower_id    = $request->borrower_id;

        $loan_basic_details = LoanBasicDetails::find($borrower_id);

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
            $borrower_account_detail->ifsc                  = $request->ifsc_code;
            $borrower_account_detail->bank_name             = $request->bank_name ?? '';
            $borrower_account_detail->branch_code           = $request->branch_code ?? '';
            $borrower_account_detail->save();

            $currentStep = 20;
            $application_step = [
                'step' => $currentStep,
                'loan_basic_details_id' => $loan_basic_details->id,
                'details' => "EMI Debit Consent",
                'status' => "COMPLETED",
            ];

            $this->updateApplicationStep($borrower_id, $application_step);

            return response()->json([
                'status' => 'success',
                'message' => "Emi debit consent stored",
                'data' => ''
            ], 200);
        } else {
            return response()->json([
                'status' => 'failed',
                'message' => "Invalid request",
            ], 400);
        }
    }

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

                if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                    $errorCount++;
                    $errorArray['borrower_id'] = "Borrower id is required";
                    return $this->send_response("failed", "Borrower id is required", 422);
                }

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
                
                if ($request->file('file')->getSize() > $max_size) {
                    return $this->send_response("failed", "File size must not exceed 5 MB", 422);
                }

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
                
                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }
                
                $user_id            = '';
                $borrower_id        = $request->borrower_id;
                $loan_details       = LoanBasicDetails::find($borrower_id);
                if($loan_details) {
                    $borrower_id = $loan_details->borrower_id;
                } else {
                    return $this->send_response("failed", "Borrower not found", 422);
                }
                $file               = $request->file('file');

                // Generate a unique file name
                // $serviceApiController = new ServiceApiController;
                if($input['doc_type'] == 'OTHER' || $input['doc_type'] == "SIGNED_CHEQUE") {
                    $fileName = $input['doc_type'].'-'.$this->generate_random_string(16) . '.' . $file->getClientOriginalExtension();
                } else {
                    $fileName = $input['doc_type']. '.' . $file->getClientOriginalExtension();
                }

                // Store the uploaded file with the new file name in storage/app/public directory
                // borrower/[borrower kyc, co applicant kyc, guarantor kyc, vehicle document]
                $path = $file->storeAs('public/Borrower/'.$borrower_id.'/'.$input['flag'], $fileName);

                // Determine the full path
                $fullPath = storage_path('app/'.$path);

                // Set the correct permissions for the file
                chmod($fullPath, 0644);

                // Ensure the directory has the correct permissions (if necessary)
                $directory = dirname($fullPath);
                if (!is_dir($directory)) {
                    File::makeDirectory($directory, 0775, true, true);
                } else {
                    chmod($directory, 0775);
                }

                // Get the URL of the uploaded file
                $url = Storage::url($path);

                // Prepare parameters for cURL request
                // $localPath = $file->getPathname();

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
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        $currentStep = 11;
        date_default_timezone_set('Asia/Kolkata');
        
        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        } else {

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }

            if(!isset($input['aadhar_front']) || empty($input['aadhar_front'])){
                $errorCount++;
                $errorArray['aadhar_front'] = "Aadhar front is required";
                return $this->send_response("failed", $errorArray['aadhar_front'], 422);
            }
            if(!isset($input['aadhar_back']) || empty($input['aadhar_back'])){
                $errorCount++;
                $errorArray['aadhar_back'] = "Aadhar back is required";
                return $this->send_response("failed", $errorArray['aadhar_back'], 422);
            }
            
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

            if(!isset($input['borrower_signed_cheque']) || empty($input['borrower_signed_cheque'])){
                $errorCount++;
                $errorArray['borrower_signed_cheque'] = "Signed cheques are required";
                return $this->send_response("failed", $errorArray['borrower_signed_cheque'], 422);
            } elseif(!is_array($input['borrower_signed_cheque']) || count($input['borrower_signed_cheque']) < 4) {
                $errorCount++;
                $errorArray['borrower_signed_cheque'] = "4 Signed cheques are required";
                return $this->send_response("failed", $errorArray['borrower_signed_cheque'], 422);
            }

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }

            $userId         = '';
            $borrower_id    = $request->borrower_id;
            $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
            if(!$kyc_docs) {
                $kyc_docs       = new Documents;
                $kyc_docs->borrower_id       = $borrower_id;


                // return response()->json([
                //     'status'=>'failed',
                //     'message'=>"Invalid request",
                // ], 400);
            }
            // $kyc_docs->aadhar_front      = $input['aadhar_front'];
            // $kyc_docs->aadhar_back        = $input['aadhar_back'];
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
            
            if($kyc_docs->save()) {
                $application_step = [
                    'step' => $currentStep,
                    'kyc_doc_id' => $kyc_docs->id,
                    'details' => "Borrower Documents Upload",
                    'status' => "COMPLETED",
                ];
    
                $this->updateApplicationStep($borrower_id, $application_step);

                return response()->json([
                    'status'=>'success',
                    'message'=>"Borrower documents stored successfully",
                    'data'=> ''
                ], 201);
            } else {
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Failed to store borrower documents",
                ], 400);
            }
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

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
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
        
            $borrower_id    = $request->borrower_id;
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
            $this->updateApplicationStep($borrower_id, $application_step);
                
            DB::commit();
            return $this->send_response("success", "Borrower documents stored successfully", 201);
                
        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', $e->getMessage(), 422);
        }    
    }
    
    public function co_applicant_doc_upload(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        $currentStep = 12;
        date_default_timezone_set('Asia/Kolkata');
        
        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        } else {

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
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

            $userId         = '';
            $borrower_id    = $request->borrower_id;
            $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();
            if($kyc_docs) {

                // $kyc_docs->coAadhar_front       = $input['aadhar_front'];
                // $kyc_docs->coAadhar_back         = $input['aadhar_back'];
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
        
                    $this->updateApplicationStep($borrower_id, $application_step);
                    
                    return response()->json([
                        'status'=>'success',
                        'message'=>"Co-applicant documents stored successfully",
                        'data'=> ''
                    ], 201);
                } else {
                    return response()->json([
                        'status'=>'failed',
                        'message'=>"Failed to store co-applicant documents",
                    ], 400);
                }
            } else {
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Invalid request",
                ], 400);
            }
        }
    }
    
    public function guarantor_doc_upload(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        $currentStep = 13;
        date_default_timezone_set('Asia/Kolkata');
        
        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        } else {

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
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

            $userId         = '';
            $borrower_id    = $request->borrower_id;
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
        
                    $this->updateApplicationStep($borrower_id, $application_step);

                    return response()->json([
                        'status'=>'success',
                        'message'=>"Guarantor documents stored successfully",
                        'data'=> ''
                    ], 201);
                } else {
                    return response()->json([
                        'status'=>'failed',
                        'message'=>"Failed to store guarantor documents",
                    ], 400);
                }
            } else {
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Invalid request",
                ], 400);
            }
        }
    }

    public function vehicle_doc_upload(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            // $currentStep = 28;
            $currentStep = 22;
            date_default_timezone_set('Asia/Kolkata');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            } else {

                if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                    $errorCount++;
                    $errorArray['borrower_id'] = "Borrower id is required";
                    return $this->send_response("failed", "Borrower id is required", 422);
                }

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
                    $errorArray['insurance_policy_number'] = "Insurance is required";
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

                // if(!isset($input['registration_certificate']) || empty($input['registration_certificate'])){
                //     $errorCount++;
                //     $errorArray['registration_certificate'] = "Registration certificate(RC) is required";
                //     return $this->send_response("failed", $errorArray['registration_certificate'], 422);
                // }

                if(!isset($input['vehicle_signed_cheque']) || empty($input['vehicle_signed_cheque'])){
                    $errorCount++;
                    $errorArray['vehicle_signed_cheque'] = "Signed cheque is required";
                    return $this->send_response("failed", $errorArray['vehicle_signed_cheque'], 422);
                } elseif(!is_array($input['vehicle_signed_cheque']) || count($input['vehicle_signed_cheque']) < 2) {
                    $errorCount++;
                    $errorArray['vehicle_signed_cheque'] = "2 Signed cheque is required";
                    return $this->send_response("failed", $errorArray['vehicle_signed_cheque'], 422);
                }
                $loan_details = LoanBasicDetails::find($request->borrower_id);

                if($loan_details) {
                    if(($loan_details->product_id == 3) && ($loan_details->loan_amount <= 24000)) {
                        if(!isset($input['registration_certificate']) || empty($input['registration_certificate'])){
                            $errorCount++;
                            $errorArray['registration_certificate'] = "Registration certificate(RC) is required";
                            return $this->send_response("failed", $errorArray['registration_certificate'], 422);
                        }
                    }
                }

                if ($errorCount > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Invalid data",
                        'error_list' => $errorArray
                    ], 422);
                }

                $userId         = '';
                $borrower_id    = $request->borrower_id;
                $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();

                if($kyc_docs) {
                    $kyc_docs->rto_document     = $input['rto_document'];
                    $kyc_docs->rto_tax_receipt  = $input['rto_tax_receipt'];
                    $kyc_docs->mmr              = $input['mmr'];
                    $kyc_docs->invoice          = $input['invoice'];
                    $kyc_docs->insurance        = $input['insurance'];
                    $kyc_docs->vehicle_image    = $input['vehicle_image'];
                    $kyc_docs->doh              = isset($input['doh']) ? $input['doh'] : '';
                    $kyc_docs->chasis_number    = $input['chasis_number_img'];
                    $kyc_docs->motor_controller = $input['motor_controller'];
                    $kyc_docs->battery_image    = $input['battery_image'];
                    $kyc_docs->registration_certificate    = $input['registration_certificate'] ?? '';

                    if(is_array($input['vehicle_signed_cheque'])) {
                        $vehicle_signed_cheque = implode(',', $input['vehicle_signed_cheque']);
                    } else {
                        $vehicle_signed_cheque = $input['vehicle_signed_cheque'];
                    }
                    $kyc_docs->vehicle_signed_cheque    = $vehicle_signed_cheque;

                    if(isset($input['vehicle_other_docs']) && !empty($input['vehicle_other_docs'])) {
                        if(is_array($input['vehicle_other_docs'])) {
                            $vehicle_other_docs = implode(',', $input['vehicle_other_docs']);
                        } else {
                            $vehicle_other_docs = $input['vehicle_other_docs'];
                        }
                    }
                    $kyc_docs->vehicle_other_docs    = isset($vehicle_other_docs) ? $vehicle_other_docs : '';
                    $kyc_docs->vehicle_key_img    = $input['vehicle_key_img'] ?? '';

                    $loan_details->down_payment = $input['down_payment'] ?? 0;
                    $loan_details->save();

                    /**
                     * Code updated on: 20-01-2025
                     * previously chasis_number(chasis image) input changed to chasis_number_image
                     * insurance_policy_number and chasis number this two is new inputs added on 
                     * 20-01-2025, as required in stashfin development and as well as our later development process
                     */
                    $borrower_kyc_detail = BorrowerKyc::where('borrower_id', $borrower_id)->first();
                    $borrower_kyc_detail->policy_number = $request->insurance_policy_number ?? null;
                    $borrower_kyc_detail->chasis_number = $request->chasis_number ?? null;
                    $borrower_kyc_detail->save();

                    if($kyc_docs->save()) {
                        $application_step = [
                            'step' => $currentStep,
                            'kyc_doc_id' => $kyc_docs->id,
                            'details' => "Vehicle Documents Upload",
                            'status' => "COMPLETED",
                        ];
            
                        $this->updateApplicationStep($borrower_id, $application_step);

                        return response()->json([
                            'status'=>'success',
                            'message'=>"Vehicle documents stored successfully",
                            'data'=> ''
                        ], 201);
                    } else {
                        return response()->json([
                            'status'=>'failed',
                            'message'=>"Failed to store vehicle documents",
                        ], 400);
                    }
                } else {
                    return response()->json([
                        'status'=>'failed',
                        'message'=>"Invalid request",
                    ], 400);
                }
            }
        } catch (Exception $e) {
            return response()->json([
                'status' => 'failed',
                'message' => $e->getMessage(),
            ], 400);
        } 
    }

    public function battery_doc_upload(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            $currentStep = 28;
            date_default_timezone_set('Asia/Kolkata');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            }

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
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

            if(!isset($input['signed_cheque']) || empty($input['signed_cheque'])){
                $errorCount++;
                $errorArray['signed_cheque'] = "Signed cheque is required";
                return $this->send_response("failed", $errorArray['signed_cheque'], 422);
            } elseif(!is_array($input['signed_cheque']) || count($input['signed_cheque']) < 2) {
                $errorCount++;
                $errorArray['signed_cheque'] = "2 Signed cheque is required";
                return $this->send_response("failed", $errorArray['signed_cheque'], 422);
            }
            
            if(!isset($input['bank_statement']) || empty($input['bank_statement'])){
                $errorCount++;
                $errorArray['bank_statement'] = "Bank statement is required";
                return $this->send_response("failed", $errorArray['bank_statement'], 422);
            }

            if ($errorCount > 0) {
                return $this->send_response("failed", "Invalid data", 422, $errorArray);
            }

            DB::beginTransaction();
            $borrower_id    = $request->borrower_id;
            $kyc_docs       = Documents::where('borrower_id', $borrower_id)->first();

            if(!$kyc_docs) {
                return $this->send_response('failed', "Invalid request", 422);
            }
            
            $kyc_docs->invoice          = $input['invoice'];
            $kyc_docs->warrant_document = $input['warranty_card'];
            $kyc_docs->bank_statement   = $input['bank_statement'];

            if(is_array($input['signed_cheque'])) {
                $signed_cheque = implode(',', $input['signed_cheque']);
            } else {
                $signed_cheque = $input['signed_cheque'];
            }

            $kyc_docs->battery_signed_cheque    = $signed_cheque;

            $kyc_docs->save();
            $application_step = [
                'step' => $currentStep,
                'kyc_doc_id' => $kyc_docs->id,
                'details' => "Battery Documents Upload",
                'status' => "COMPLETED",
            ];

            $this->updateApplicationStep($borrower_id, $application_step);

            DB::commit();
            
            return $this->send_response('success', "Battery documents stored successfully", 201);
        } catch (Exception $e) {
            DB::rollBack();
            
            return $this->send_response('failed', $e->getMessage(), 422);
        } 
    }

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
    
    // public function rule_engine(Request $request) {
    //     try{
    //         if (!isset($request->borrower_id) || empty($request->borrower_id)) {
    //             // $errorCount++;
    //             $errorArray['borrower_id'] = "Borrower id is required";
    //             return $this->send_response("failed", "Borrower id is required", 422);
    //         }

    //         $data = [];
    //         $product_list = [
    //             [
    //                 'product_id' => "1",
    //                 'name' => "E-Rickshaw (L3)",
    //                 'label' => "Apply Loan",
    //                 'icon' => asset('Assets/products/eRickshaw.png')
    //             ],
    //             [
    //                 'product_id' => "2",
    //                 'name' => "E-Auto (L5)",
    //                 'label' => "Apply Loan",
    //                 'icon' => asset('Assets/products/tuk-tuk.png')
    //             ],
    //             [
    //                 'product_id' => "3",
    //                 'name' => "E-Two Wheeler",
    //                 'label' => "Apply Loan",
    //                 'icon' => asset('Assets/products/two.png')
    //             ],
    //             [
    //                 'product_id' => "4",
    //                 'name' => "Battery",
    //                 'label' => "Apply Loan",
    //                 'icon' => asset('Assets/products/car-battery.png')
    //             ],
    //             [
    //                 'product_id' => "5",
    //                 'name' => "EKA L5-3S",
    //                 'label' => "Apply Loan",
    //                 'icon' => ''
    //             ],
    //             [
    //                 'product_id' => "6",
    //                 'name' => "EKA L5-6S",
    //                 'label' => "Apply Loan",
    //                 'icon' => ''
    //             ],
    //         ];

    //         $userId                     = '';
    //         $borrower_id                = $request->borrower_id;
    //         $LoanApplicationSummary     = LoanApplicationSummary::where('borrower_id', $borrower_id)->first();
    //         if(!$LoanApplicationSummary) {
    //             return response()->json([
    //                 'status'=>'failed',
    //                 'message'=>"Invalid request",
    //             ], 422);
    //         }
    //         // $product_id = $LoanApplicationSummary->product_id;

    //         // $loan_basic_details = LoanBasicDetails::find($borrower_id); 
    //         $loan_basic_details = LoanBasicDetails::select([
    //             'loan_basic_details.*',
    //             'loan_basic_details.dealer_id as dealer_unq',
    //             'dealers.id as dealer_id',
    //         ])
    //         ->leftJoin('dealers', 'loan_basic_details.dealer_id', '=', 'dealers.dealer_unq')
    //         ->where('loan_basic_details.id', $borrower_id)->first();
            
    //         if(!$loan_basic_details) {
    //             return response()->json([
    //                 'status'=>'failed',
    //                 'message'=>"Invalid request",
    //             ], 422);
    //         }
    //         $product_id     = $loan_basic_details->product_id;
    //         $loan_amount    = $loan_basic_details->loan_amount;
    //         $tenure         = $loan_basic_details->loan_term;
    //         $dealer_id      = $loan_basic_details->dealer_id;
    //         $model          = $loan_basic_details->model;

    //         $dealer_product_details = BorrowerLoanScheme::where('borrower_id', $borrower_id)->first();
    //         if(!$dealer_product_details) {
    //             $product_query = ProductModel::where('dealer_id', $dealer_id);
    //             if($loan_basic_details->model_id != '') {
    //                 $product_query->where('model_unq', $loan_basic_details->model_id);
    //             } else {
    //                 $product_query->where('name', $loan_basic_details->model);
    //             }
    //             $dealer_product_details = $product_query->first();
    //         }

    //         if (!$dealer_product_details) {
    //             return response()->json([
    //                 'status' => 'failed',
    //                 'message' => 'Invalid dealer or product',
    //             ], 422);
    //         }

    //         // Extract product details
    //         $product_interest           = $dealer_product_details->interest;
    //         $product_processing_fee     = $dealer_product_details->processing_fee;
    //         $product_subvention_amount  = $dealer_product_details->subvention_amount;
    //         $product_fldg               = $dealer_product_details->fldg;
    //         $product_iot                = $dealer_product_details->iot;
    //         $product_insurance_1st_year = $dealer_product_details->insurance_1st_year;
    //         $product_insurance_2nd_year = $dealer_product_details->insurance_2nd_year;
    //         $product_interest_type      = !empty($dealer_product_details->interest_type) ? $dealer_product_details->interest_type : 'flat';

    //         $borrower_kyc_details = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
    //         if($borrower_kyc_details) {
    //             $gender             = $borrower_kyc_details->gender;
    //             $skip_co_applicant  = $borrower_kyc_details->skip_co_applicant;
    //             $skip_guarantor     = $borrower_kyc_details->skip_guarantor ?? false;
    //             $dob                = $borrower_kyc_details->dob_date;
    //             $dobTimestamp       = strtotime($dob);
    //             $age                = date('Y') - date('Y', $dobTimestamp);
    //             $cibil              = isset($LoanApplicationSummary->cibil_score) ? $LoanApplicationSummary->cibil_score : 0;
    //         }
            
    //         $borrower_address = BorrowerAddress::where('borrower_id', $borrower_id)->latest()->first();
    //         if($borrower_address) {
    //             $residence = $borrower_address->resident_type;
    //             $residence_stability = $borrower_address->years_living;
    //         }

    //         $field_inspection_details = FieldInspectionDetails::where('borrower_id', $borrower_id)->latest()->first();
    //         if($field_inspection_details) {
    //             $is_field_verified = $field_inspection_details->is_inspection_verified;
    //         }

    //         $co_applicant_details = CoapplicantKyc::where('borrower_id', $borrower_id)->latest()->first();
    //         if($co_applicant_details) {
    //             $co_applicant_id = $co_applicant_details->id;
    //         }
    //         $co_applicant_cibil = isset($LoanApplicationSummary->coappli_cibil_score) ? $LoanApplicationSummary->coappli_cibil_score : 0;

    //         $guarantor_details = Guarantor::where('borrower_id', $borrower_id)->latest()->first();
    //         if($guarantor_details) {
    //             $guarantor_id = $guarantor_details->id;
    //             $guarantor_cibil = isset($LoanApplicationSummary->guarantor_cibil_score) ? $LoanApplicationSummary->guarantor_cibil_score : 0;
    //         }

    //         if($product_id == 3) {
    //             $request['product'] = 'EV Two Wheeler';
    //         } elseif($product_id == 4) {
    //             $request['product'] = 'Battery';
    //         } else {
    //             $request['product'] = 'E-Rickshaw L3';
    //         }
    //         $data['product'] = $request['product'];
            
    //         $data['loan_amount']                    = isset($loan_amount) ? intval($loan_amount) : 0;
    //         $data['tenure']                         = isset($tenure) ? $tenure : 0;
    //         $data['age']                            = isset($age) ? $age : 0;
    //         $data['cibil_score']                    = isset($cibil) ? $cibil : 0;
    //         $data['co_applicant_cibil']             = isset($co_applicant_cibil) ? $co_applicant_cibil : 0;
    //         $data['guarantor_cibil']                = isset($guarantor_cibil) ? $guarantor_cibil : 0;
    //         $data['co_applicant']                   = isset($co_applicant_id) ? $co_applicant_id : 0;
    //         $data['guarantor']                      = isset($guarantor_id) ? $guarantor_id : 0;
    //         $data['residence_type']                 = isset($residence) ? $residence : "";
    //         $data['gender']                         = isset($gender) ? $gender : "";
    //         $data['skip_co_applicant']              = isset($skip_co_applicant) ? $skip_co_applicant : false;
    //         $data['skip_guarantor']                 = isset($skip_guarantor) ? $skip_guarantor : false;
    //         $data['residence_stability']            = isset($residence_stability) ? $residence_stability : "";
    //         $data['field_investigation_verified']   = true; //isset($is_field_verified) ? $is_field_verified : false;
            
    //         $loanAmount = intval($data['loan_amount']);
    //         $loanTenure = $data['tenure'];
            
    //         $roi = $product_interest;
    //         $loan_amount = $loanAmount;
    //         $interest = $roi;
    //         $tenure = $loanTenure;
    //         if($tenure > 0 && $tenure <= 15) {
    //             $second_year_insurance = 0;
    //         } elseif($tenure > 15 && $tenure <= 24) {
    //             $second_year_insurance = $product_insurance_2nd_year;
    //         } else {
    //             $second_year_insurance = 0;
    //         }

    //         $net_loan_amount = $loan_amount + $product_iot + $second_year_insurance;

    //         $net_disbursal = $net_loan_amount - ($product_processing_fee + $product_subvention_amount + $product_insurance_1st_year + $product_fldg + $product_iot + $second_year_insurance);

    //         // $emi_amount = $this->calculateFlatInterestEMI($net_loan_amount, $interest, $tenure);
    //         // $emi_amount = $this->calculateEMI($net_loan_amount, $interest, $tenure); // reducial
    //         // $emi_chart_details = $this->helper_controller->generateReducingInterestSchedule($net_loan_amount, $interest, $tenure);
    //         $emi_chart_details = $this->generateInterestSchedule($net_loan_amount, $interest, $tenure, $product_interest_type);
    //         $emi_chart  = $emi_chart_details['schedule'];
    //         $emi_amount = $emi_chart_details['emi_amount'];

    //         $loan_offers = [
    //             // [
    //             //     'id' => "1",
    //             //     'lender_id' => "1",
    //             //     'lender' => "Offer 1",
    //             //     'loan_amount' => intval($data['loan_amount']),
    //             //     'interest' => "15%",
    //             //     "terms" => $data['tenure']." month",
    //             //     "emi_per_month" => number_format(($data['loan_amount'] + (($data['loan_amount'] * 15)/100))/$data['tenure'], 2,".",""),
    //             // ],
    //             [
    //                 'id' => "2",
    //                 'lender_id' => "3",
    //                 'lender' => "Offer 2",
    //                 'loan_amount' => intval($net_loan_amount),
    //                 'interest' => $interest,
    //                 "terms" => $data['tenure']." month",
    //                 "emi_per_month" => number_format($emi_amount, 2,".",""),
    //                 "loan_details_break_down" => [
    //                     'loan_amount' => intval($loan_amount),
    //                     'roi' => floatval($interest),
    //                     'tenure' => $tenure. ' months',
    //                     'iot_amount' => $product_iot,
    //                     'first_year_insurance_amount' => $product_insurance_1st_year,
    //                     'second_year_insurance_amount' => $second_year_insurance,
    //                     'processing_fee' => $product_processing_fee,
    //                     'subvention_amount' => $product_subvention_amount,
    //                     'fldg' => $product_fldg,
    //                     'net_loan_amount' => $net_loan_amount,
    //                     'net_disbursal' => $net_disbursal,
    //                     'emi' => ceil($emi_amount)
    //                 ],
    //                 "repayment_schedule"  => $emi_chart, //$this->generateReducingInterestSchedule($net_loan_amount, $interest, $tenure),
    //                 // "repayment_schedule"  => $this->generateRepaymentSchedule($net_loan_amount, $interest, $tenure),
    //                 // "repayment_schedule"  => $this->generateReducialRepaymentSchedule($net_loan_amount, $interest, $tenure), // reducial
    //             ],
    //             // [
    //             //     'id' => "3",
    //             //     'lender_id' => "3",
    //             //     'lender' => "Offer 3",
    //             //     'loan_amount' => intval($data['loan_amount']),
    //             //     'interest' => "19%",
    //             //     "terms" => $data['tenure']." month",
    //             //     "emi_per_month" => number_format(($data['loan_amount'] + (($data['loan_amount'] * 19)/100))/$data['tenure'], 2,".",""),
    //             // ],
    //         ];

    //         if($product_id == '1') {
    //             $eligibility_check =  $this->rule_e_rickshaw_l3($data);
    //         } elseif($product_id == '2') {
    //             $eligibility_check =  $this->rule_e_auto_rickshaw_l5($data);
    //         } elseif($product_id == '3') {
    //             $eligibility_check =  $this->rule_ev_two_wheeler($data);
    //         } elseif($product_id == '4') {
    //             $eligibility_check =  $this->rule_battery($data);
    //         } elseif($product_id == '5') {
    //             $eligibility_check =  $this->rule_eka($data);
    //         }

    //         if(isset($eligibility_check['is_eligible'])) {
    //             if($eligibility_check['is_eligible'] === true) {

    //                 $application_step = [
    //                     'step' => 9, //$currentStep,
    //                     'rule_engine_id' => '', //$loan_offer->id,
    //                     'details' => "Loan Offers Completed",
    //                     'status' => "COMPLETED",
    //                 ];
        
    //                 $this->updateApplicationStep($borrower_id, $application_step);
                    
    //                 return response()->json([
    //                     'status' =>'success',
    //                     'message' => "You are eligible for loan",
    //                     'data' => $eligibility_check['data'],
    //                     'offers' => $loan_offers
    //                 ], 200);
    //             } else {

    //                 return response()->json([
    //                     'status' =>'failed',
    //                     'message' => "You are not eligible",
    //                     'reason' => isset($eligibility_check['reason']) ? $eligibility_check['reason'] : '',
    //                 ], 422);
    //             }
    //         } else {
    //             return response()->json([
    //                 'status'=>'failed',
    //                 'message'=>"Invalid request",
    //             ], 422);
    //         }
    //     }  catch (Exception $e) {
    //         return response()->json([
    //             'status'=>'failed',
    //             'message'=> "An unknown error occured ". $e->getMessage(),
    //         ], 422);
    //     }
    // }
    public function rule_engine(Request $request)
    {
        try {
            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                // $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }

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

            $userId                     = '';
            $borrower_id                = $request->borrower_id;
            $LoanApplicationSummary     = LoanApplicationSummary::where('borrower_id', $borrower_id)->first();
            if (!$LoanApplicationSummary) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Invalid request",
                ], 422);
            }
            // $product_id = $LoanApplicationSummary->product_id;

            $loan_basic_details = LoanBasicDetails::find($borrower_id);
            if (!$loan_basic_details) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Invalid request",
                ], 422);
            }
            $product_id     = $loan_basic_details->product_id;
            $loan_amount    = $loan_basic_details->loan_amount;
            $tenure         = $loan_basic_details->loan_term;
            $dealer_id      = $loan_basic_details->dealer_id;
            $model          = $loan_basic_details->model;
            $model_id          = $loan_basic_details->model_id;

            // ── DYNAMIC PRODUCT ROUTING ──────────────────────────────────────────
            $dynamicProduct = \App\Models\OemDynamicProduct::where('oem_product_unq', $model_id)->first();
            if ($dynamicProduct) {
                return $this->resolveDynamicOffer($request, $dynamicProduct, $loan_basic_details, $LoanApplicationSummary, $borrower_id, $userId);
            }

            $dealer_product_details = BorrowerLoanScheme::where('borrower_id', $borrower_id)->first();
            if (!$dealer_product_details) {

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
            if ($borrower_kyc_details) {
                $gender             = $borrower_kyc_details->gender;
                $skip_co_applicant  = $borrower_kyc_details->skip_co_applicant;
                $skip_guarantor     = $borrower_kyc_details->skip_guarantor ?? false;
                $dob                = $borrower_kyc_details->dob_date;
                $dobTimestamp       = strtotime($dob);
                $age                = date('Y') - date('Y', $dobTimestamp);
                $cibil              = isset($LoanApplicationSummary->cibil_score) ? $LoanApplicationSummary->cibil_score : 0;
            }

            $borrower_address = BorrowerAddress::where('borrower_id', $borrower_id)->latest()->first();
            if ($borrower_address) {
                $residence = $borrower_address->resident_type;
                $residence_stability = $borrower_address->years_living;
            }

            $field_inspection_details = FieldInspectionDetails::where('borrower_id', $borrower_id)->latest()->first();
            if ($field_inspection_details) {
                $is_field_verified = $field_inspection_details->is_inspection_verified;
            }

            $co_applicant_details = CoapplicantKyc::where('borrower_id', $borrower_id)->latest()->first();
            if ($co_applicant_details) {
                $co_applicant_id = $co_applicant_details->id;
            }
            $co_applicant_cibil = isset($LoanApplicationSummary->coappli_cibil_score) ? $LoanApplicationSummary->coappli_cibil_score : 0;

            $guarantor_details = Guarantor::where('borrower_id', $borrower_id)->latest()->first();
            if ($guarantor_details) {
                $guarantor_id = $guarantor_details->id;
                $guarantor_cibil = isset($LoanApplicationSummary->guarantor_cibil_score) ? $LoanApplicationSummary->guarantor_cibil_score : 0;
            }

            if ($product_id == 3) {
                $request['product'] = 'EV Two Wheeler';
            } elseif ($product_id == 4) {
                $request['product'] = 'Battery';
            } else {
                $request['product'] = 'E-Rickshaw L3';
            }
            $data['product'] = $request['product'];

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
            if ($tenure > 0 && $tenure <= 15) {
                $second_year_insurance = 0;
            } elseif ($tenure > 15 && $tenure <= 24) {
                $second_year_insurance = $product_insurance_2nd_year;
            } else {
                $second_year_insurance = 0;
            }

            $net_loan_amount = $loan_amount + $product_iot + $second_year_insurance;

            $net_disbursal = $net_loan_amount - ($product_processing_fee + $product_subvention_amount + $product_insurance_1st_year + $product_fldg + $product_iot + $second_year_insurance);

            // $emi_amount = $this->calculateFlatInterestEMI($net_loan_amount, $interest, $tenure);
            // $emi_amount = $this->calculateEMI($net_loan_amount, $interest, $tenure); // reducial
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
                    "terms" => $data['tenure'] . " month",
                    "emi_per_month" => number_format($emi_amount, 2, ".", ""),
                    "loan_details_break_down" => [
                        'loan_amount' => intval($loan_amount),
                        'roi' => floatval($interest),
                        'tenure' => $tenure . ' months',
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
                    "repayment_schedule"  => $emi_chart,
                ]
            ];

            if ($product_id == '1') {
                $eligibility_check =  $this->rule_e_rickshaw_l3($data);
            } elseif ($product_id == '2') {
                $eligibility_check =  $this->rule_e_auto_rickshaw_l5($data);
            } elseif ($product_id == '3') {
                $eligibility_check =  $this->rule_ev_two_wheeler($data);
            } elseif ($product_id == '4') {
                $eligibility_check =  $this->rule_battery($data);
            } elseif ($product_id == '5' || $product_id == '6') {
                $eligibility_check =  $this->rule_eka($data);
            }

            if (isset($eligibility_check['is_eligible'])) {
                if ($eligibility_check['is_eligible'] === true) {

                    // send sanction letter
                    // $this->sendSanctionMail($borrower_id);

                    $application_step = [
                        'step' => 9, //$currentStep,
                        'rule_engine_id' => '', //$loan_offer->id,
                        'details' => "Loan Offers Completed",
                        'status' => "COMPLETED",
                    ];

                    $this->updateApplicationStep($borrower_id, $application_step);

                    // $this->logStep($borrower_id, 9, 'Loan Offers Completed', 'admin');
                    return response()->json([
                        'status' => 'success',
                        'message' => "You are eligible for loan",
                        'data' => $eligibility_check['data'],
                        'offers' => $loan_offers
                    ], 200);
                } else {

                    return response()->json([
                        'status' => 'failed',
                        'message' => "You are not eligible",
                        'reason' => isset($eligibility_check['reason']) ? $eligibility_check['reason'] : '',
                    ], 422);
                }
            } else {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Invalid request",
                ], 422);
            }
        } catch (Exception $e) {
            return response()->json([
                'status' => 'failed',
                'message' => "An unknown error occured", //$e->getMessage(),
            ], 422);
        }
    }

    private function resolveDynamicOffer(
        Request $request,
        \App\Models\OemDynamicProduct $product,
        $loan_basic_details,
        $LoanApplicationSummary,
        $borrower_id,
        $userId
    ) {
        try {
            $loan_amount = intval($loan_basic_details->loan_amount);
            $tenure      = intval($loan_basic_details->loan_term);
            $dealer_id   = $loan_basic_details->dealer_id;

            $dealerPk = \App\Models\Dealer::where('dealer_unq', $dealer_id)->value('id');

            // ---- gather facts ----
            $kyc     = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
            $address = BorrowerAddress::where('borrower_id', $borrower_id)->latest()->first();
            $coApp   = CoapplicantKyc::where('borrower_id', $borrower_id)->latest()->first();
            $guar    = Guarantor::where('borrower_id', $borrower_id)->latest()->first();
            $guarOcc = \App\Models\GuarantorOccupation::where('borrower_id', $borrower_id)->latest()->first();


            // state + pincode come from the address helper (same source legacy uses)
            $addr          = $this->helper_controller->user_address_details($borrower_id, 'BORROWER');
            $borrowerState = ($addr['status'] ?? '') === 'success' ? ($addr['data']['full_address']['state'] ?? '') : '';
            $borrowerPin   = ($addr['status'] ?? '') === 'success' ? ($addr['data']['full_address']['pc'] ?? '') : '';

            $gender = $kyc->gender ?? '';
            $cibil  = $LoanApplicationSummary->cibil_score ?? 0;

            $age = 0;
            if (!empty($kyc->dob_date)) {
                $ts  = strtotime($kyc->dob_date);
                $age = date('Y') - date('Y', $ts);
                if (date('md') < date('md', $ts)) $age--;
            }

            $skip_co  = $kyc->skip_co_applicant ?? false;
            $skip_gua = $kyc->skip_guarantor ?? false;

            $ctx = [
                'credit_score'         => $cibil,
                'gender'               => strtolower((string) $gender),
                'state'                => $borrowerState,
                'pincode'              => (string) $borrowerPin,
                'residence_type'       => strtolower((string) ($address->resident_type ?? '')),
                'years_at_address'     => (float) ($address->years_living ?? 0),
                'age'                  => $age,
                'has_co_applicant'     => !empty($coApp?->id) && !$skip_co,
                'has_guarantor'        => !empty($guar?->id) && !$skip_gua,
                'guarantor_owns_house' => $guarOcc ? strtolower((string) $guarOcc->resident_type) === 'owned' : false,
                'dealer_borrower_km'   => 0,
            ];

            // ---- resolve ----
            $resolver = app(\App\Domain\Lending\Bre\DynamicBreResolver::class);
            $out = $resolver->resolve($product, $dealerPk, $ctx, $loan_amount, $tenure, (int) $borrower_id, 'offer');

            if (!$out['eligible']) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => 'You are not eligible',
                    'reason'  => $out['reasons'],
                    'debug'   => $this->debugDump($product, $dealerPk, $ctx),  // TODO: remove after debugging
                ], 422);
            }

            $r = $out['resolved'];

            // ---- write scheme v2 (resolved per-borrower) ----
            $this->writeDynamicSchemeV2($borrower_id, $product, $r);

            // ---- EMI (shared generator, same as legacy) ----
            $emiData    = $this->generateInterestSchedule(
                $r['net_loan_amount'],
                $r['roi'],
                $tenure,
                $r['interest_type']
            );
            $emi_amount = $emiData['emi_amount'];
            $emi_chart  = $emiData['schedule'];

            $loan_offers = [[
                'id'        => "2",
                'lender_id' => "3",
                'lender'    => "Offer 2",
                'loan_amount' => intval($r['net_loan_amount']),
                'interest'  => $r['roi'],
                'terms'     => $tenure . " month",
                'emi_per_month' => number_format($emi_amount, 2, ".", ""),
                'loan_details_break_down' => [
                    'loan_amount'                 => intval($r['loan_amount']),
                    'roi'                         => floatval($r['roi']),
                    'tenure'                      => $tenure . ' months',
                    'iot_amount'                  => $r['components']['iot'],
                    'first_year_insurance_amount' => $r['components']['insurance_y1'],
                    'second_year_insurance_amount' => $r['components']['insurance_y2'],
                    'processing_fee'              => $r['processing_fee']['total'],
                    'subvention_amount'           => $r['components']['subvention'],
                    'fldg'                        => $r['components']['fldg'],
                    'net_loan_amount'             => $r['net_loan_amount'],
                    'net_disbursal'               => $r['net_disbursal'],
                    'emi'                         => round($emi_amount),
                ],
                'repayment_schedule' => $emi_chart,
            ]];

            // ---- sanction + step (same as legacy) ----
            // $this->sendSanctionMail($borrower_id);

            $application_step = [
                'step' => 9,
                'rule_engine_id' => '',
                'details' => "Loan Offers Completed",
                'status' => "COMPLETED",
            ];
            $this->updateApplicationStep($borrower_id, $application_step);
            // $this->logStep($borrower_id, 9, 'Loan Offers Completed', 'b2c');

            return response()->json([
                'status'  => 'success',
                'message' => "You are eligible for loan",
                'data'    => $ctx,
                'offers'  => $loan_offers,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'failed', 'message' => $e->getMessage()], 422);
        }
    }

    // TODO: DEBUG ONLY — remove after testing
    private function debugDump(\App\Models\OemDynamicProduct $product, ?int $dealerPk, array $ctx): array
    {
        $facts    = app(\App\Domain\Lending\Bre\Engine\FactExtractor::class)->normalize($ctx);
        $set      = app(\App\Domain\Lending\Bre\Engine\RuleSetCompiler::class)->compile($product, $dealerPk);
        $matcher  = app(\App\Domain\Lending\Bre\Engine\ConditionMatcher::class);

        $rulesTrace = [];
        foreach ($set->byType as $type => $rules) {
            foreach ($rules as $rule) {
                $condTrace = $rule->conditions->map(function ($c) use ($facts, $matcher) {
                    $factVal = $facts[$c->attribute] ?? '(missing)';
                    $pass    = array_key_exists($c->attribute, $facts)
                        && $matcher->allMatch(collect([$c]), $facts);
                    return [
                        'attribute' => $c->attribute,
                        'operator'  => $c->operator,
                        'expected'  => $c->value,
                        'borrower_has' => $factVal,
                        'pass'      => $pass,
                    ];
                })->all();

                $rulesTrace[] = [
                    'type'        => $type,
                    'priority'    => $rule->priority,
                    'output'      => $rule->output,
                    'all_pass'    => $matcher->allMatch($rule->conditions, $facts),
                    'conditions'  => $condTrace,
                ];
            }
        }

        return [
            'facts'          => $facts,
            'default_set_id' => $set->defaultSetId,
            'dealer_set_id'  => $set->dealerSetId,
            'rules'          => $rulesTrace,
        ];
    }

    private function writeDynamicSchemeV2(int $borrowerId, \App\Models\OemDynamicProduct $product, array $r): void
    {
        // archive current
        BorrowerLoanScheme::where('borrower_id', $borrowerId)->update(['is_current' => false]);

        $nextVersion = (BorrowerLoanScheme::withoutGlobalScope('current')
            ->where('borrower_id', $borrowerId)->max('version') ?? 0) + 1;

        $s = new BorrowerLoanScheme();
        $s->borrower_id        = $borrowerId;
        $s->version            = $nextVersion;
        $s->is_current         = true;
        $s->pricing_source     = 'oem_dynamic';
        $s->product_id         = $product->id;
        $s->ex_showroom_price  = $product->ex_showroom_price ?? 0;
        $s->loan_to_value_ratio = $product->loan_to_value_ratio ?? '';

        // resolved per-borrower values
        $s->min_loan_amount    = 0;
        $s->max_loan_amount    = $r['loan_amount'];              // approved amount
        $s->min_loan_tenure    = $r['tenure']['min'] ?? 0;
        $s->max_loan_tenure    = $r['tenure']['max'] ?? 0;
        $s->interest           = $r['roi'];
        $s->interest_type      = $r['interest_type'];
        $s->processing_fee     = $r['processing_fee']['total'];
        $s->subvention_amount  = $r['components']['subvention'];
        $s->fldg               = $r['components']['fldg'];
        $s->iot                = $r['components']['iot'];
        $s->insurance_1st_year = $r['components']['insurance_y1'];
        $s->insurance_2nd_year = $r['components']['insurance_y2'];

        // approved amount, total interest over schedule, monthly EMI
        $s->loan_amount    = $r['loan_amount'];
        $s->total_interest = 0; // $r['total_interest'];
        $s->loan_emi       = $r['emi_amount'];

        $s->save();
    }  

    public function rule_e_rickshaw_l3($data){
        try {

            $min_loan_amount = 50000;
            $max_loan_amount = 150000;
            
            $min_tenure = 6;
            $max_tenure = 24;

            $min_age = 21;
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

        $min_age = 21;
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
        $min_loan_amount = 100000;
        $max_loan_amount = 500000;
        
        $min_tenure = 12;
        $max_tenure = 36;

        $min_age = 21;
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
        $min_loan_amount = 100000;
        $max_loan_amount = 500000;
        
        $min_tenure = 12;
        $max_tenure = 36;

        $min_age = 21;
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

        $min_age = 21;
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
        $max_loan_amount = 25000;
        
        $min_tenure = 6;
        $max_tenure = 12;

        $min_age = 21;
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
        $user_id    = '';
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

    public function check_vkyc_result(Request $request) {
        $input      = $request->all();
        
        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            // $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }
        $user_id    = '';
        $borrower_id    = $request->borrower_id;
        $errorArray = [];
        $errorCount = 0;
        date_default_timezone_set('Asia/Kolkata');

        $vkyc_result = DB::table('vkyc_api_req_details AS vard')
                        ->where('vard.borrower_id', $borrower_id)
                        ->latest()
                        ->first();
                        
                        // 'vwr.status', 'vwr.transaction_id', 'vwr.state', 'vwr.scheduled_slot_time'
                        // ->leftJoin('vkyc_web_responses AS vwr', 'vard.application_number', '=', 'vwr.unique_id')
                        // ->where('vwr.status', 'APPROVED')
        if($vkyc_result) {
            $userData = json_decode($vkyc_result->request);
            $userData->mobile;

            $application_number = $vkyc_result->application_number;
            $vkyc_response = DB::table('vkyc_web_responses AS vwr')
                                ->where('vwr.unique_id', $application_number)
                                ->where('vwr.status', 'APPROVED')
                                ->latest()
                                ->first();
            
            $data = [
                'application_number' => $vkyc_result->application_number,
                'status' => isset($vkyc_response->status) ? $vkyc_response->status : '',
                'state' => isset($vkyc_response->state) ? $vkyc_response->state : '',
                'mobile' => $userData->mobile,
                'name' => $userData->name,
                'transaction_id' => isset($vkyc_response->transaction_id) ? $vkyc_response->transaction_id : '',
                // 'vkyc_url' => $vkyc_result->vkyc_url,
                'url' => $vkyc_result->vkyc_url,
                'scheduled_slot_time' => isset($vkyc_response->scheduled_slot_time) ? $vkyc_response->scheduled_slot_time : '',
            ];

            if(isset($vkyc_response->status) && $vkyc_response->status == 'APPROVED') {

                $currentStep = 19;
                $application_step = [
                    'step' => $currentStep,
                    'vkyc_result_id' => $vkyc_result->id,
                    'details' => "VKYC Status",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($borrower_id, $application_step);

                return $this->send_response("success", "VKYC result fetched", 200, $data);
            } else {
                $vkycGetApiDetails = VendorServiceApi::where('borrower_id', $borrower_id)
                                    ->where('api_flag', 'VKYC_CALL_DETAILS_USING_UNIQUE_ID_API')
                                    ->where('status', 'VALID')
                                    ->latest()
                                    ->first();
                if($vkycGetApiDetails) {
                    $response = $vkycGetApiDetails->response;
                    $result = json_decode($response);
                    if(isset($result->code) && $result->code == 200) {

                        // remove this part
                        // start
                        $data = [
                            'application_number' => $vkyc_result->application_number,
                            'url' => $vkyc_result->vkyc_url,
                            'status' => isset($result->status) ? $result->status : '',
                            'state' => isset($result->state) ? $result->state : '',
                            'mobile' => $userData->mobile ?? '',
                            'name' => $userData->name ?? '',
                            'transaction_id' => isset($result->transactionId) ? $result->transactionId : '',
                            'message' => isset($result->message) ? $result->message : 'No records exist for the given id',
                        ];  
                        return $this->send_response("failed", "VKYC result fetched", 200, $data);
                        // end


                        $api_status = "VALID";
                        
                        $data = [
                            'application_number' => $vkyc_result->application_number,
                            'url' => $vkyc_result->vkyc_url,
                            'status' => isset($result->status) ? $result->status : '',
                            'state' => isset($result->state) ? $result->state : '',
                            'mobile' => $userData->mobile ?? '',
                            'name' => $userData->name ?? '',
                            'transaction_id' => isset($result->transactionId) ? $result->transactionId : '',
                            'message' => isset($result->message) ? $result->message : 'No records exist for the given id',
                        ];
                        $currentStep = 19;
                        $application_step = [
                            'step' => $currentStep,
                            'vkyc_result_id' => $vkyc_result->id,
                            'details' => "VKYC Status",
                            'status' => "COMPLETED",
                        ];

                        $this->updateApplicationStep($borrower_id, $application_step);

                        return $this->send_response("success", "VKYC result fetched", 200, $data);

                    } else {
                        $api_status = "INVALID";
                        $data = [
                            'application_number' => $vkyc_result->application_number,
                            'url' => $vkyc_result->vkyc_url ?? '',
                            'status' => isset($result->status) ? $result->status : '',
                            'state' => isset($result->state) ? $result->state : '',
                            'mobile' => $userData->mobile ?? '',
                            'name' => $userData->name ?? '',
                            'transaction_id' => isset($result->transactionId) ? $result->transactionId : '',
                            'message' => isset($result->message) ? $result->message : 'No records exist for the given id',
                        ];  
                        return $this->send_response("failed", "VKYC result fetched", 200, $data);
                    }
                } else {
                    return $this->send_response("failed", "VKYC result fetched", 200, $data);
                }
            }
        } else {
            return $this->send_response("failed", "No results found", 404);
        }
    }

    public function match_address_with_borrower(Request $request) {
        // return $this->send_response('success', "Address matched successfully", 200);

        $input          = $request->all();

        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            // $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }
        $user_id        = '';
        $borrower_id    = $request->borrower_id;
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

        $vendor_service_api = VendorServiceApi::where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
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
                if($input['district'] == $dist) {
                    return $this->send_response('success', "Address matched successfully", 200);
                } else {
                    return $this->send_response('failed', "City and pincode must be same as borrower", 422);
                }
            } else {
                return $this->send_response('failed', "Borrower address does not exist", 422);
            }
        } else {
            return $this->send_response('failed', "Invalid request, Borrower address not found", 422);
        }
    }

    public function get_loan_user_details_for_esign(Request $request) {
        $input          = $request->all();

        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            // $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }
        $user_id        = '';
        $borrower_id    = $request->borrower_id;
        $errorArray     = [];
        $errorCount     = 0;
        date_default_timezone_set('Asia/Kolkata');
        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
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

        $result = [];
        $vendor_service_api = VendorServiceApi::where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
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
                $city = $dist; // $borrower_deails->name;

                $result = [
                    'name' => $name,
                    'mobile' => $mobile,
                    'email' => $email,
                    'city' => $city,
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
                $city = $dist;  // $borrower_deails->name;
                $result = [
                    'name' => $name,
                    'mobile' => $mobile,
                    'email' => $email,
                    'city' => $city,
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
                $city = $dist; // $borrower_deails->name;

                $result = [
                    'name' => $name,
                    'mobile' => $mobile,
                    'email' => $email,
                    'city' => $city,
                ];
            }
        }

        if(count($result) > 0) {
            return $this->send_response('success', "Data fetched successfully", 200, $result);
        } else {
            return $this->send_response('failed', "No result found", 422);

        }
    }

    public function get_name_declaration_document(Request $request) {
        try{
            
            $input          = $request->all();

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                // $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }
            $user_id        = '';
            $borrower_id    = $request->borrower_id;
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

    public function get_borrower_details(Request $request) {
        $input          = $request->all();
        $user_id        = '';
        $errorArray     = [];
        $errorCount     = 0;

        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }
        $borrower_id        = $request->borrower_id;
        
        if(!is_numeric($borrower_id)) {
            return $this->helper_controller->send_response('failed', "Invalid request, borrower not found", 422);
        }

        $loan_basic_details = LoanBasicDetails::find($borrower_id);
        if(!$loan_basic_details) {
            return $this->helper_controller->send_response("failed", "Invalid request", 422);
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

        date_default_timezone_set('Asia/Kolkata');

        $borrower_details = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
        if(!$borrower_details) {
            return $this->helper_controller->send_response('failed', "Invalid request, borrower not found", 422);
        }

        $name               = $borrower_details->name;
        $mobile             = $borrower_details->mobile;
        $pan                = $borrower_details->pan;
        $is_verifies_pan    = $borrower_details->is_verifies_pan;
        $aadhar             = $borrower_details->aadhar;
        $is_verified_aadhar = $borrower_details->is_verified_aadhar;
        $dob                = $borrower_details->dob_date;
        $spouse_name        = $borrower_details->husband_name;
        $father_name        = $borrower_details->father_name;
        $gender             = $borrower_details->gender;
        $email              = $borrower_details->email;
        $cibil              = $borrower_details->cibil;
        $skip_co_applicant  = $borrower_details->skip_co_applicant;
        $skip_guarantor     = $borrower_details->skip_guarantor;
        $created_at         = $borrower_details->created_at;
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
            'name' => $name,
            'mobile' => $mobile,
            'pan' => $pan,
            'is_verifies_pan' => $is_verifies_pan,
            'aadhar' => $this->helper_controller->maskAadharNumber($aadhar),
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
        $user_id        = '';
        $errorArray     = [];
        $errorCount     = 0;
        date_default_timezone_set('Asia/Kolkata');
        if($input == null) {
            return $this->helper_controller->send_response("failed", "Request body is empty", 422);
        }

        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }

        $borrower_id    = $request->borrower_id;
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
        // AADHAR_CARD_OCR
        // AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML

        $vendor_service_api = VendorServiceApi::where('api_flag', 'AADHAR_CARD_OCR')
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

    public function borrower_registration_details($borrower_id) {
        try {
            $loan_basic_details = LoanBasicDetails::find($borrower_id);
            if(!$loan_basic_details) {
                return $this->helper_controller->send_response("failed", "Borrower not found", 422);
            }
            
            $user_id = $loan_basic_details->user_id ?? '';
            $borrower_reg_details = User::find($user_id);
            if(!$borrower_reg_details) {
                return $this->helper_controller->send_response("failed", "Borrower registration details not found", 422);
            }
            
            $result = [
                'name' => $borrower_reg_details->name,
                'email' => $borrower_reg_details->email,
                'mobile' => $borrower_reg_details->mobile
            ];
            return $this->helper_controller->send_response("success", "Borrower registration details fetched", 200, $result);
        } catch (Exception $e) {
            return $this->helper_controller->send_response("success", $e->getMessage(), 422);
        }       
    }

    public function get_all_user_list(Request $request) {
        date_default_timezone_set('Asia/Kolkata');
        try {
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $numberOfRecords = $request->input('number_of_records', 10); // Default to 10 records per page
            $page = $request->input('page', 1); // Default to page 1
            $stepFilter = $request->input('step'); // Step filter, might be null

            // Validation (optional, can be expanded)
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'start_date' => 'date|nullable',
                'end_date' => 'date|nullable|after_or_equal:start_date',
                'borrower_name' => 'string|regex:/^[a-zA-Z\s]+$/|nullable', // Only characters and spaces
                'borrower_id' => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable', // Alphanumeric and hyphens
                'mobile_no' => 'digits:10|nullable',
                'pan_no' => 'string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|nullable',
                'aadhar_no' => 'string|min:4|max:12|nullable',
                'dob' => 'date|nullable',
                'dealer_id' => 'nullable|integer',
                'lsp_id' => 'nullable|integer',
                'product_type_id' => 'nullable|integer',
                'application_date' => 'date|nullable',
                'status' => 'nullable|string|in:NEW,APPROVED,REJECTED,HOLD,CLOSED,CANCELLED',
            ]);
    
            // Check if validation fails
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 400);
            }
    
            $query = User::query()
                ->leftJoin('loan_basic_details', 'users.id', '=', 'loan_basic_details.user_id')
                ->leftJoin('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
                ->leftJoin('dealers', 'loan_basic_details.dealer_id', '=', 'dealers.dealer_unq')
                ->leftJoin('field_inspection_details', 'loan_basic_details.id', '=', 'field_inspection_details.borrower_id')
                ->select(
                    'users.name as user_name',
                    'users.mobile as user_mobile',
                    'users.id as user_primary_key',
                    'borrower_kycs.name as borrower_name',
                    'loan_basic_details.borrower_id as borrower_unq',
                    'loan_basic_details.status',
                    'borrower_kycs.mobile as mobile_no',
                    'borrower_kycs.dob_date as date_of_birth',
                    'borrower_kycs.marital_status',
                    'borrower_kycs.husband_name as spouses_name',
                    'borrower_kycs.father_name',
                    'borrower_kycs.gender',
                    'borrower_kycs.email as email_id',
                    'borrower_kycs.pan as pan_no',
                    'borrower_kycs.aadhar as aadhar_no',
                    'borrower_kycs.driving_license_no',
                    'borrower_kycs.alternate_number1',
                    'loan_basic_details.id as borrower_primary',
                    'loan_basic_details.status as loan_status',
                    'loan_basic_details.loan_amount',
                    'loan_basic_details.dealer_id',
                    'loan_basic_details.model',
                    'loan_basic_details.loan_term',
                    'loan_basic_details.loan_application_steps',
                    'loan_basic_details.created_at as created_on',
                    'loan_basic_details.is_agreement_reviewed',
                    'loan_basic_details.agreement_preview_remarks',
                    'dealers.id as dealer_id',
                    'dealers.dealer_unq',
                    'field_inspection_details.meter_image',
                    'field_inspection_details.meter_verification_status',
                    'field_inspection_details.meter_remarks',
                )
                ->where('users.is_active', true);
            // Apply date filters if provided

            // Conditionally add the 'NEW' status filter if loan_basic_details exists
            // $query->where(function ($query) {
            //     $query->whereNotNull('loan_basic_details.id') // Ensure loan_basic_details exists
            //           ->where('loan_basic_details.status', 'NEW'); // Apply the 'NEW' status filter
            // });

            if ($request->filled('product_type_id')) {
                $query->where('product_id', $request->product_type_id);
            }

            if ($startDate && !empty($startDate)) {
                $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
                $query->where('loan_basic_details.created_at', '>=', $startDate);
            }
            
            // Filter by end date (end of the day in Asia/Kolkata timezone)
            if ($endDate && !empty($endDate)) {
                $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
                $query->where('loan_basic_details.created_at', '<=', $endDate);
            }

            if ($request->has('borrower_id') && !empty($request->input('borrower_id'))) {
                $query->where('loan_basic_details.borrower_id', 'like', '%' . $request->input('borrower_id') . '%');
            }
            
            if ($request->has('borrower_name') && !empty($request->input('borrower_name'))) {
                $borrowerName = strtolower($request->input('borrower_name'));
                $query->where(function($q) use ($borrowerName) {
                    $q->whereRaw('LOWER(borrower_kycs.name) like ?', ['%' . $borrowerName . '%'])
                      ->orWhereRaw('LOWER(users.name) like ?', ['%' . $borrowerName . '%']);
                });
            }

            if ($request->has('mobile_no') && !empty($request->input('mobile_no'))) {
                $mobileNo = $request->input('mobile_no');
                $query->where(function($q) use ($mobileNo) {
                    $q->where('borrower_kycs.mobile', 'like', '%' . $mobileNo . '%')
                      ->orWhere('users.mobile', 'like', '%' . $mobileNo . '%');
                });
            }
            if ($request->has('pan_no') && !empty($request->input('pan_no'))) {
                $query->where('borrower_kycs.pan', 'like', '%' . $request->input('pan_no') . '%');
            }
            if ($request->has('aadhar_no') && !empty($request->input('aadhar_no'))) {
                $query->where('borrower_kycs.aadhar', 'like', '%' . $request->input('aadhar_no') . '%');
            }
            if ($request->has('dob') && !empty($request->input('dob'))) {
                $query->whereDate('borrower_kycs.dob_date', $request->input('dob'));
            }
            
            if ($request->has('status') && !empty($request->input('status'))) {
                $query->where('loan_basic_details.status', $request->input('status'));
            }
            if ($request->has('application_date') && !empty($request->input('application_date'))) {
                $query->whereDate('loan_basic_details.created_at', $request->input('application_date'));
            }

            $year = $request->input('year', date('Y'));
            $month = $request->input('month', date('F'));
            
            if(isset($request->year)) {
                $year = $request->input('year', date('Y'));;
                $query->whereYear('loan_basic_details.created_at', $year);
            }
            
            if(isset($request->month)) {
                $year = $request->input('year', date('Y'));
                $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
                $query->whereMonth('loan_basic_details.created_at', $monthNumber);
            }
    
            // Fetch results before pagination
            $borrowers = $query->orderBy('users.id', 'desc')->get();

            // Transform the result to replace null or empty values and include step status
            $borrowers = $borrowers->map(function ($borrower) {
                if(isset($borrower->borrower_unq) && !empty($borrower->borrower_unq)) {
                    if ($borrower->loan_status !== 'NEW') {
                        return null; // Skip this borrower if the loan status is not 'NEW'
                    }
                }
                $stepStatus = '';
                $lastStep = null;
                if(isset($borrower->loan_application_steps)) {
                    $steps = json_decode($borrower->loan_application_steps, true);
                    $lastStep = end($steps);
                }
                
                $borrower_primary = $borrower->borrower_primary;

                $user_address_details = []; //$this->helper_controller->user_address_details($borrower_primary, "BORROWER");

                if(!empty($borrower->mobile_no)) {
                    $borrower_mobile = $borrower->mobile_no;
                } elseif(!empty($borrower->user_mobile)) {
                    $borrower_mobile = $borrower->user_mobile;
                } else {
                    $borrower_mobile = '';
                }

                if(!empty($borrower->borrower_name)) {
                    $borrower_name = $borrower->borrower_name;
                } elseif(!empty($borrower->user_name)) {
                    $borrower_name = $borrower->user_name;
                } else {
                    $borrower_name = '';
                }

                if(!empty($borrower->aadhar_no)) {
                    $borrower_aadhar = $this->helper_controller->maskAadharNumber($borrower->aadhar_no);
                } else {
                    $borrower_aadhar = '';
                }
                return [
                    'borrower_id' => $borrower->borrower_primary ?? '',
                    'borrower_unq' => $borrower->borrower_unq ?? '',
                    'user_id' => $borrower->user_primary_key ?? '',
                    'borrower_name' => $borrower_name ?? '',
                    'loan_status' => $borrower->status ?? '',
                    'mobile_no' => $borrower_mobile,
                    'alternate_number1' => $borrower->alternate_number1 ?? '',
                    'date_of_birth' => $borrower->date_of_birth ?? '',
                    'gender' => $borrower->gender ?? '',
                    'email_id' => $borrower->email_id ?? '',
                    'pan_no' => $borrower->pan_no ?? '',
                    'aadhar_no' => $borrower_aadhar ?? '',
                    'borrower_cibil' => $borrower_cibil ?? '',
                    'address' => $user_address_details ?? [],
                    'created_on' => $borrower->created_on ?? '',
                    'is_agreement_reviewed' => $borrower->is_agreement_reviewed ?? false,
                    'agreement_preview_remarks' => $borrower->agreement_preview_remarks ?? '',
                    'last_step' => $lastStep ? [
                        'application_step' => $lastStep['step'],
                        'details' => $lastStep['details'],
                        'status' => $lastStep['status'],
                    ] : null, // Adding last step
                    'agreement_preview_btn' => ($lastStep && $lastStep['step'] == 22 && $borrower->is_agreement_reviewed === false)
                        ? 'enable'
                        : 'disable',
                    'meter_image' => !empty($borrower->meter_image) ? url($borrower->meter_image) : '',
                    'meter_verification_status' => $borrower->meter_verification_status ?? '',
                    'meter_remarks' => $borrower->meter_remarks ?? '',
                ];
            });
            $borrowers = $borrowers->filter(fn($borrower) => $borrower !== null);
    
            // Filter by step status if provided
            if ($stepFilter !== null) {
                $borrowers = $borrowers->filter(function ($borrower) use ($stepFilter) {
                    return $borrower['step_status'] === 'step' . $stepFilter;
                });
            }
    
            // Convert collection to array for pagination
            $borrowers = $borrowers->values();
    
            // Paginate the filtered results
            $total = $borrowers->count();
            $borrowers = array_slice($borrowers->toArray(), ($page - 1) * $numberOfRecords, $numberOfRecords);
    
            // Prepare pagination links
            $paginatedData = [
                'current_page' => $page,
                'data' => $borrowers,
                'first_page_url' => url()->current() . '?page=1',
                'from' => ($page - 1) * $numberOfRecords + 1,
                'last_page' => (int)ceil($total / $numberOfRecords),
                'last_page_url' => url()->current() . '?page=' . (int)ceil($total / $numberOfRecords),
                'links' => [
                    [
                        'url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
                        'label' => '&laquo; Previous',
                        'active' => false
                    ],
                    [
                        'url' => url()->current() . '?page=1',
                        'label' => '1',
                        'active' => $page == 1
                    ],
                    [
                        'url' => url()->current() . '?page=' . $page,
                        'label' => (string)$page,
                        'active' => true
                    ],
                    [
                        'url' => $page < (int)ceil($total / $numberOfRecords) ? url()->current() . '?page=' . ($page + 1) : null,
                        'label' => 'Next &raquo;',
                        'active' => false
                    ]
                ],
                'next_page_url' => $page < (int)ceil($total / $numberOfRecords) ? url()->current() . '?page=' . ($page + 1) : null,
                'path' => url()->current(),
                'per_page' => $numberOfRecords,
                'prev_page_url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
                'to' => min($page * $numberOfRecords, $total),
                'total' => $total
            ];
    
            return $this->helper_controller->send_response('success', "Borrower details fetched", 200, $paginatedData);
    
        } catch (Exception $e) {
            // Handle errors
            return response()->json([
                'error' => 'An error occurred while fetching loan records.',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function borrower_doc_upload_admin(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $currentStep = 11;
    
        date_default_timezone_set('Asia/Kolkata');
    
        // Basic input validation
        if ($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        }
    
        // Validate required documents
        // 'aadhar_front', 'aadhar_back', 
        // , 'borrower_signed_cheque'
        // 'pan_card', 
        $requiredDocs = ['photo'];
        foreach ($requiredDocs as $doc) {
            $files = $request->file($doc); // Get the file(s)

            // Check if the document exists in the input and is either a file or an array of files
            if (!$files) {
                $errorArray[$doc] = ucfirst(str_replace('_', ' ', $doc)) . " is required";
                return $this->send_response("failed", $errorArray[$doc], 422);
            }

            // Normalize to array for uniform processing
            if (!is_array($files)) {
                $files = [$files]; // If it's a single file, put it in an array
            }

            // Iterate over all files (even if it's a single file)
            foreach ($files as $index => $file) {
                if (!$file->isValid()) {
                    $errorArray[$doc][$index] = ucfirst(str_replace('_', ' ', $doc)) . " at index $index is invalid or missing";
                    return $this->send_response("failed", $errorArray[$doc][$index], 422);
                }

                // Validate file type and size
                $allowed_types = ["image/jpeg", "image/png", "image/jpg", "application/pdf"];
                $max_size = 5 * 1024 * 1024; // 5 MB

                if (!in_array($file->getMimeType(), $allowed_types)) {
                    $errorArray[$doc][$index] = ucfirst(str_replace('_', ' ', $doc)) . " at index $index must be a valid file type (jpeg, png, jpg, pdf)";
                    return $this->send_response("failed", $errorArray[$doc][$index], 422);
                }

                if ($file->getSize() > $max_size) {
                    $errorArray[$doc][$index] = ucfirst(str_replace('_', ' ', $doc)) . " at index $index must not exceed 5MB";
                    return $this->send_response("failed", $errorArray[$doc][$index], 422);
                }
            }
        }
    
        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }
    
        $borrower_primary_key = $request->borrower_id;
        $loan_details = LoanBasicDetails::find($borrower_primary_key);
        if ($loan_details) {
            $borrower_id = $loan_details->borrower_id;
        } else {
            return $this->send_response("failed", "Borrower not found", 422);
        }
    
        // if (isset($input['borrower_signed_cheque']) && !is_array($input['borrower_signed_cheque'])) {
        //     $errorCount++;
        //     $errorArray['borrower_signed_cheque'] = "Signed cheques must be an array";
        //     return $this->send_response("failed", $errorArray['borrower_signed_cheque'], 422);
        // } elseif (count($input['borrower_signed_cheque']) < 4) {
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
    
        // Start DB transaction
        DB::beginTransaction();

        if (isset($input['borrower_signed_cheque']) && is_array($input['borrower_signed_cheque'])) {

            if (count($input['borrower_signed_cheque']) !== 4) {
                return $this->send_response("failed", "Exactly 4 signed cheque files are required if provided", 422);
            }
            $chequesToProcess = [];

            for ($i = 1; $i <= 4; $i++) {
                $accountKey = "signed_cheque_{$i}_account_number";
                $chequeKey = "signed_cheque_{$i}_cheque_number";
                $ifscKey = "signed_cheque_{$i}_ifsc_code";
                $fileIndex = $i - 1;

                // Check if all parts are present
                if (
                    !isset($input[$accountKey]) ||
                    !isset($input[$chequeKey]) ||
                    !isset($input[$ifscKey]) ||
                    !$request->hasFile("borrower_signed_cheque.{$fileIndex}")
                ) {
                    return $this->send_response("failed", "All details (account number, cheque number, IFSC, and file) are required for cheque #{$i}", 422);
                }

                // Prepare data
                $chequesToProcess[] = [
                    'index' => $i,
                    'account_number' => $input[$accountKey],
                    'cheque_no' => $input[$chequeKey],
                    'ifsc' => $input[$ifscKey],
                    'file' => $request->file("borrower_signed_cheque.{$fileIndex}"),
                ];
            }

            // Now validate and process each cheque
            foreach ($chequesToProcess as $chequeData) {
                $i = $chequeData['index'];

                $validator = Validator::make($chequeData, [
                    'account_number' => 'required|string',
                    'cheque_no' => 'required|string',
                    'ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
                    'file' => 'required|file|mimes:jpeg,png,pdf'
                ]);

                if ($validator->fails()) {
                    return $this->send_response("failed", "Validation failed for cheque #{$i}: " . $validator->errors()->first(), 422);
                }

                // Check for duplicates
                $exists = BorrowerCheques::where('borrower_id', $loan_details->id)
                    ->where('cheque_no', $chequeData['cheque_no'])
                    ->where('status', '<>', 'deleted')
                    ->exists();

                if ($exists) {
                    return $this->send_response('failed', "Cheque no {$chequeData['cheque_no']} already exists for this borrower", 422);
                }

                // Store file
                $extension  = $chequeData['file']->getClientOriginalExtension();
                $fileName   = $chequeData['cheque_no'] . '.' . $extension;
                $borrower_key = $loan_details->borrower_id;
                $path = "Borrower/{$borrower_key}/Borrower_cheques";
                $storedPath = Storage::disk('public')->putFileAs($path, $chequeData['file'], $fileName);

                // Save DB record
                $cheque                 = new BorrowerCheques();
                $cheque->borrower_id    = $loan_details->id;
                $cheque->file           = 'storage/' . $storedPath;
                $cheque->cheque_no      = $chequeData['cheque_no'];
                $cheque->ifsc           = $chequeData['ifsc'];
                $cheque->account_number = $chequeData['account_number'];
                $cheque->status         = 'active';
                $cheque->ip             = $this->get_user_ip();
                $cheque->modified_by    = auth()->id();
                $cheque->save();
            }
        }

        try {
            $userId = $request->user()->id;
            $kyc_docs = Documents::where('borrower_id', $borrower_primary_key)->first();
    
            if (!$kyc_docs) {
                $kyc_docs = new Documents();
                $kyc_docs->borrower_id = $borrower_primary_key;
            }
    
            // Upload documents
            $docFields = [
                // 'aadhar_front' => ['column' => 'aadhar_front', 'doctype' => 'AADHAR_FRONT'],
                // 'aadhar_back' => ['column' => 'aadhar_back', 'doctype' => 'AADHAR_BACK'],
                // 'pan_card' => ['column' => 'pan_doc', 'doctype' => 'PAN_CARD'],
                'photo' => ['column' => 'borrower_avtar', 'doctype' => 'PERSON'],
                'electric_bill' => ['column' => 'res_address_proof', 'doctype' => 'ELECTRIC_BILL'], // Optional
                'urc_certificate' => ['column' => 'urc_certificate', 'doctype' => 'URC_CERTIFICATE'], // Optional
                'borrower_signed_cheque' => ['column' => 'borrower_signed_cheque', 'doctype' => 'SIGNED_CHEQUE'], // Array
                'borrower_other_docs' => ['column' => 'borrower_other_docs', 'doctype' => 'OTHER'], // Optional (Array)
                'driving_license_img' => ['column' => 'driving_license', 'doctype' => 'DRIVING_LICENCE'], // Optional
            ];

            foreach ($docFields as $inputKey => $data) {
                if (isset($input[$inputKey])) {
                    // Prepare to store URLs
                    $fileUrls = [];

                    // Handle arrays (e.g., for signed cheques and other documents)
                    if (is_array($input[$inputKey])) {
                        foreach ($input[$inputKey] as $file) {
                            // $url = $this->general_file_upload($file, $data['doctype'], 'BORROWER_KYC', $borrower_id);
                            // if ($url) {
                            //     $fileUrls[] = $url; // Collect each URL
                            // }
                            $uploadResult = $this->general_file_upload($file, $data['doctype'], 'BORROWER_KYC', $borrower_id);
                            if ($uploadResult['status'] === 'failed') {
                                DB::rollBack(); // roll back before returning
                                return $this->send_response('failed', $uploadResult['message'], 422);
                            }
                            $fileUrls[] = $uploadResult['data'];
                        }
                        $kyc_docs->{$data['column']} = implode(',', $fileUrls); // Store URLs as a comma-separated string
                    } else {
                        // Handle single file uploads
                        // $kyc_docs->{$data['column']} = $this->general_file_upload($input[$inputKey], $data['doctype'], 'BORROWER_KYC', $borrower_id);
                        $uploadResult = $this->general_file_upload($input[$inputKey], $data['doctype'], 'BORROWER_KYC', $borrower_id);
                        if ($uploadResult['status'] === 'failed') {
                            DB::rollBack();
                            return $this->send_response('failed', $uploadResult['message'], 422);
                        }
                        $kyc_docs->{$data['column']} = $uploadResult['data'];
                    }
                }
            }

            $borrower_kyc_detail = BorrowerKyc::where('borrower_id', $request->borrower_id)->latest()->first();
            if($borrower_kyc_detail){
                $borrower_kyc_detail->udyam_aadhaar_number = $request->udyam_aadhaar_number ?? null;
                $borrower_kyc_detail->save();
            }
    
            if ($kyc_docs->save()) {
                // Update application step
                $application_step = [
                    'step' => $currentStep,
                    'kyc_doc_id' => $kyc_docs->id,
                    'details' => "Borrower Documents Upload",
                    'status' => "COMPLETED",
                ];
                $this->updateApplicationStep($borrower_primary_key, $application_step);
    
                DB::commit(); // Commit the transaction
    
                return response()->json([
                    'status' => 'success',
                    'message' => "Borrower documents stored successfully",
                    'data' => ''
                ], 201);
            } else {
                DB::rollBack(); // Rollback transaction on failure
                return response()->json([
                    'status' => 'failed',
                    'message' => "Failed to store borrower documents",
                ], 400);
            }
        } catch (Exception $e) {
            DB::rollBack(); // Rollback transaction on exception
            return response()->json([
                'error' => 'failed',
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function borrower_doc_upload_battery_admin(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        $currentStep = 11;
        date_default_timezone_set('Asia/Kolkata');
        
        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        }

        // 'aadhar_front', 'aadhar_back', 
        // 'pan_card', 
        $requiredDocs = ['photo'];
        foreach ($requiredDocs as $doc) {
            $files = $request->file($doc); // Get the file(s)

            // Check if the document exists in the input and is either a file or an array of files
            if (!$files) {
                $errorArray[$doc] = ucfirst(str_replace('_', ' ', $doc)) . " is required";
                return $this->send_response("failed", $errorArray[$doc], 422);
            }

            // Normalize to array for uniform processing
            if (!is_array($files)) {
                $files = [$files]; // If it's a single file, put it in an array
            }

            // Iterate over all files (even if it's a single file)
            foreach ($files as $index => $file) {
                if (!$file->isValid()) {
                    $errorArray[$doc][$index] = ucfirst(str_replace('_', ' ', $doc)) . " at index $index is invalid or missing";
                    return $this->send_response("failed", $errorArray[$doc][$index], 422);
                }

                // Validate file type and size
                $allowed_types = ["image/jpeg", "image/png", "image/jpg", "application/pdf"];
                $max_size = 5 * 1024 * 1024; // 5 MB

                if (!in_array($file->getMimeType(), $allowed_types)) {
                    $errorArray[$doc][$index] = ucfirst(str_replace('_', ' ', $doc)) . " at index $index must be a valid file type (jpeg, png, jpg, pdf)";
                    return $this->send_response("failed", $errorArray[$doc][$index], 422);
                }

                if ($file->getSize() > $max_size) {
                    $errorArray[$doc][$index] = ucfirst(str_replace('_', ' ', $doc)) . " at index $index must not exceed 5MB";
                    return $this->send_response("failed", $errorArray[$doc][$index], 422);
                }
            }
        }
    
        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }
    
        $borrower_primary_key = $request->borrower_id;
        $loan_details = LoanBasicDetails::find($borrower_primary_key);
        if ($loan_details) {
            $borrower_id = $loan_details->borrower_id;
        } else {
            return $this->send_response("failed", "Borrower not found", 422);
        }
    
        if ($errorCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Invalid data",
                'error_list' => $errorArray
            ], 422);
        }
    
        // Start DB transaction
        DB::beginTransaction();
        try {
            $userId = $request->user()->id;
            $kyc_docs = Documents::where('borrower_id', $borrower_primary_key)->first();
    
            if (!$kyc_docs) {
                $kyc_docs = new Documents();
                $kyc_docs->borrower_id = $borrower_primary_key;
            }
    
            // Upload documents
            $docFields = [
                // 'aadhar_front' => ['column' => 'aadhar_front', 'doctype' => 'AADHAR_FRONT'],
                // 'aadhar_back' => ['column' => 'aadhar_back', 'doctype' => 'AADHAR_BACK'],
                // 'pan_card' => ['column' => 'pan_doc', 'doctype' => 'PAN_CARD'],
                'photo' => ['column' => 'borrower_avtar', 'doctype' => 'PERSON']
            ];

            foreach ($docFields as $inputKey => $data) {
                if (isset($input[$inputKey])) {
                    // Prepare to store URLs
                    $fileUrls = [];

                    // Handle arrays (e.g., for signed cheques and other documents)
                    if (is_array($input[$inputKey])) {
                        foreach ($input[$inputKey] as $file) {
                            // $url = $this->general_file_upload($file, $data['doctype'], 'BORROWER_KYC', $borrower_id);
                            // if ($url) {
                            //     $fileUrls[] = $url; // Collect each URL
                            // }
                            $uploadResult = $this->general_file_upload($file, $data['doctype'], 'BORROWER_KYC', $borrower_id);
                            if ($uploadResult['status'] === 'failed') {
                                DB::rollBack(); // roll back before returning
                                return $this->send_response('failed', $uploadResult['message'], 422);
                            }
                            $fileUrls[] = $uploadResult['data'];
                        }
                        $kyc_docs->{$data['column']} = implode(',', $fileUrls); // Store URLs as a comma-separated string
                    } else {
                        // Handle single file uploads
                        // $kyc_docs->{$data['column']} = $this->general_file_upload($input[$inputKey], $data['doctype'], 'BORROWER_KYC', $borrower_id);
                        $uploadResult = $this->general_file_upload($input[$inputKey], $data['doctype'], 'BORROWER_KYC', $borrower_id);
                        if ($uploadResult['status'] === 'failed') {
                            DB::rollBack();
                            return $this->send_response('failed', $uploadResult['message'], 422);
                        }
                        $kyc_docs->{$data['column']} = $uploadResult['data'];
                    }
                }
            }
    
            if ($kyc_docs->save()) {
                // Update application step
                $application_step = [
                    'step' => $currentStep,
                    'kyc_doc_id' => $kyc_docs->id,
                    'details' => "Borrower Documents Upload",
                    'status' => "COMPLETED",
                ];
                $this->updateApplicationStep($borrower_primary_key, $application_step);
    
                DB::commit(); // Commit the transaction
    
                return response()->json([
                    'status' => 'success',
                    'message' => "Borrower documents stored successfully",
                    'data' => ''
                ], 201);
            } else {
                DB::rollBack(); // Rollback transaction on failure
                return response()->json([
                    'status' => 'failed',
                    'message' => "Failed to store borrower documents",
                ], 400);
            }
        } catch (Exception $e) {
            DB::rollBack(); // Rollback transaction on exception
            return response()->json([
                'error' => 'failed',
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function co_applicant_doc_upload_admin(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $currentStep = 12;
    
        date_default_timezone_set('Asia/Kolkata');
    
        // Basic input validation
        if ($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        }
    
        // Validate required documents
        // 'aadhar_front', 'aadhar_back', 
        // 'pan_card', 
        $requiredDocs = ['photo'];
        foreach ($requiredDocs as $doc) {
            if (!isset($input[$doc]) || empty($input[$doc])) {
                $errorCount++;
                $errorArray[$doc] = ucfirst(str_replace('_', ' ', $doc)) . " is required";
                return $this->send_response("failed", $errorArray[$doc], 422);
            }

            $file = $input[$doc];
            $allowed_types = ["image/jpeg", "image/png", "image/jpg", "application/pdf"];
            $max_size = 5 * 1024 * 1024; // 5 MB

            // Check if the file is present and valid
            if (!$file || !$file->isValid()) {
                $errorCount++;
                $errorArray[$doc] = ucfirst(str_replace('_', ' ', $doc)) . " is invalid or missing";
                return $this->send_response("failed", $errorArray[$doc], 422);
            }

            // Validate file type
            if (!in_array($file->getMimeType(), $allowed_types)) {
                $errorCount++;
                $errorArray[$doc] = ucfirst(str_replace('_', ' ', $doc)) . " must be a JPG, PNG, JPEG, or PDF file";
                return $this->send_response("failed", $errorArray[$doc], 422);
            }

            // Validate file size
            if ($file->getSize() > $max_size) {
                $errorCount++;
                $errorArray[$doc] = ucfirst(str_replace('_', ' ', $doc)) . " file size must not exceed 5 MB";
                return $this->send_response("failed", $errorArray[$doc], 422);
            }
        }
    
        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }
    
        $borrower_primary_key = $request->borrower_id;
        $loan_details = LoanBasicDetails::find($borrower_primary_key);
        if ($loan_details) {
            $borrower_id = $loan_details->borrower_id;
        } else {
            return $this->send_response("failed", "Borrower not found", 422);
        }

        $kyc_docs = Documents::where('borrower_id', $borrower_primary_key)->first();
        
        if ($kyc_docs) {
            // Start DB transaction
            DB::beginTransaction();
            try {
                // Map input keys to database columns and document types
                $docFields = [
                    // 'aadhar_front' => ['column' => 'coAadhar_front', 'doctype' => 'AADHAR_FRONT'],
                    // 'aadhar_back' => ['column' => 'coAadhar_back', 'doctype' => 'AADHAR_BACK'],
                    // 'pan_card' => ['column' => 'coPan_doc', 'doctype' => 'PAN_CARD'],
                    'photo' => ['column' => 'coapplicant_avtar', 'doctype' => 'PERSON'],
                    'electric_bill' => ['column' => 'coResAddress_proof', 'doctype' => 'ELECTRIC_BILL'], // Optional
                ];
    
                foreach ($docFields as $inputKey => $data) {
                    if (isset($input[$inputKey])) {
                        // Use both the database column and document type for the upload
                        // $kyc_docs->{$data['column']} = $this->general_file_upload($input[$inputKey], $data['doctype'], 'COAPPLICANT_KYC', $borrower_id);
                        $uploadResult = $this->general_file_upload($input[$inputKey], $data['doctype'], 'COAPPLICANT_KYC', $borrower_id);

                        if ($uploadResult['status'] === 'failed') {
                            DB::rollBack();
                            return $this->send_response('failed', $uploadResult['message'], 422);
                        }
                        $kyc_docs->{$data['column']} = $uploadResult['data'];
                    }
                }
    
                if ($kyc_docs->save()) {
                    // Update application step
                    $application_step = [
                        'step' => $currentStep,
                        'kyc_doc_id' => $kyc_docs->id,
                        'details' => "Co-applicant Documents Upload",
                        'status' => "COMPLETED",
                    ];
                    $this->updateApplicationStep($borrower_primary_key, $application_step);
    
                    DB::commit(); // Commit the transaction
    
                    return response()->json([
                        'status' => 'success',
                        'message' => "Co-applicant documents stored successfully",
                        'data' => ''
                    ], 201);
                } else {
                    DB::rollBack(); // Rollback transaction on failure
                    return response()->json([
                        'status' => 'failed',
                        'message' => "Failed to store co-applicant documents",
                    ], 422);
                }
            } catch (Exception $e) {
                DB::rollBack(); // Rollback transaction on exception
                return response()->json([
                    'error' => 'failed',
                    'message' => $e->getMessage()
                ], 422);
            }
        } else {
            return response()->json([
                'status' => 'failed',
                'message' => "Invalid request",
            ], 422);
        }
    }

    public function guarantor_doc_upload_admin(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $currentStep = 13;
    
        date_default_timezone_set('Asia/Kolkata');
    
        // Basic input validation
        if ($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        }
    
        // Validate required documents
        // 'aadhar_front', 'aadhar_back', 
        // 'pan_card', 
        $requiredDocs = ['photo'];
        foreach ($requiredDocs as $doc) {
            if (!isset($input[$doc]) || empty($input[$doc])) {
                $errorCount++;
                $errorArray[$doc] = ucfirst(str_replace('_', ' ', $doc)) . " is required";
                return $this->send_response("failed", $errorArray[$doc], 422);
            }

            $file = $input[$doc];
            $allowed_types = ["image/jpeg", "image/png", "image/jpg", "application/pdf"];
            $max_size = 5 * 1024 * 1024; // 5 MB

            // Check if the file is present and valid
            if (!$file || !$file->isValid()) {
                $errorCount++;
                $errorArray[$doc] = ucfirst(str_replace('_', ' ', $doc)) . " is invalid or missing";
                return $this->send_response("failed", $errorArray[$doc], 422);
            }

            // Validate file type
            if (!in_array($file->getMimeType(), $allowed_types)) {
                $errorCount++;
                $errorArray[$doc] = ucfirst(str_replace('_', ' ', $doc)) . " must be a JPG, PNG, JPEG, or PDF file";
                return $this->send_response("failed", $errorArray[$doc], 422);
            }

            // Validate file size
            if ($file->getSize() > $max_size) {
                $errorCount++;
                $errorArray[$doc] = ucfirst(str_replace('_', ' ', $doc)) . " file size must not exceed 5 MB";
                return $this->send_response("failed", $errorArray[$doc], 422);
            }
        }
    
        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }
    
        $borrower_primary_key = $request->borrower_id;
        $loan_details = LoanBasicDetails::find($borrower_primary_key);
        if ($loan_details) {
            $borrower_id = $loan_details->borrower_id;
        } else {
            return $this->send_response("failed", "Borrower not found", 422);
        }

        $kyc_docs = Documents::where('borrower_id', $borrower_primary_key)->first();
        
        if ($kyc_docs) {
            // Start DB transaction
            DB::beginTransaction();
            try {
                // Map input keys to database columns and document types
                $docFields = [
                    // 'aadhar_front' => ['column' => 'guarantor_aadhar_front', 'doctype' => 'AADHAR_FRONT'],
                    // 'aadhar_back' => ['column' => 'guarantor_aadhar_back', 'doctype' => 'AADHAR_BACK'],
                    // 'pan_card' => ['column' => 'guarantor_pan_doc', 'doctype' => 'PAN_CARD'],
                    'photo' => ['column' => 'guarantor_avtar', 'doctype' => 'PERSON'],
                    'electric_bill' => ['column' => 'guarantor_coresaddress_proof', 'doctype' => 'ELECTRIC_BILL'], // Optional
                ];
    
                foreach ($docFields as $inputKey => $data) {
                    if (isset($input[$inputKey])) {
                        // Use both the database column and document type for the upload
                        // $kyc_docs->{$data['column']} = $this->general_file_upload($input[$inputKey], $data['doctype'], 'GUARANTOR_KYC', $borrower_id);
                        $uploadResult = $this->general_file_upload($input[$inputKey], $data['doctype'], 'GUARANTOR_KYC', $borrower_id);
                        if ($uploadResult['status'] === 'failed') {
                            DB::rollBack();
                            return $this->send_response('failed', $uploadResult['message'], 422);
                        }
                        $kyc_docs->{$data['column']} = $uploadResult['data'];
                    }
                }
    
                if ($kyc_docs->save()) {
                    // Update application step
                    $application_step = [
                        'step' => $currentStep,
                        'kyc_doc_id' => $kyc_docs->id,
                        'details' => "Guarantor Documents Upload",
                        'status' => "COMPLETED",
                    ];
                    $this->updateApplicationStep($borrower_primary_key, $application_step);
    
                    DB::commit(); // Commit the transaction
    
                    return response()->json([
                        'status' => 'success',
                        'message'=>"Guarantor documents stored successfully",
                        'data' => ''
                    ], 201);
                } else {
                    DB::rollBack(); // Rollback transaction on failure
                    return response()->json([
                        'status' => 'failed',
                        'message'=>"Failed to store guarantor documents",
                    ], 422);
                }
            } catch (Exception $e) {
                DB::rollBack(); // Rollback transaction on exception
                return response()->json([
                    'error' => 'failed',
                    'message' => $e->getMessage()
                ], 422);
            }
        } else {
            return response()->json([
                'status' => 'failed',
                'message' => "Invalid request",
            ], 422);
        }
    }

    public function vehicle_doc_upload_admin(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            // $currentStep = 28;
            $currentStep = 22;
            date_default_timezone_set('Asia/Kolkata');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            }

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }

            $borrower_primary_key = $request->borrower_id;
            $loan_details = LoanBasicDetails::find($borrower_primary_key);
            
            if($loan_details) {
                if(($loan_details->product_id == 3) && ($loan_details->loan_amount <= 24000)) {
                    if(!isset($input['registration_certificate']) || empty($input['registration_certificate'])){
                        $errorCount++;
                        $errorArray['registration_certificate'] = "Registration certificate(RC) is required";
                        return $this->send_response("failed", $errorArray['registration_certificate'], 422);
                    }
                }
            } else {
                return $this->send_response("failed", "Borrower not found", 422);
            }
            if (!isset($input['insurance_policy_number']) || empty($input['insurance_policy_number'])) {
                $errorCount++;
                $errorArray['insurance_policy_number'] = "Insurance is required";
                return $this->send_response("failed", $errorArray['insurance_policy_number'], 422);
            }
            if (!isset($input['chasis_number']) || empty($input['chasis_number'])) {
                $errorCount++;
                $errorArray['chasis_number'] = "Chasis number is required";
                return $this->send_response("failed", $errorArray['chasis_number'], 422);
            }
            $borrower_id = $loan_details->borrower_id;

            $requiredDocs = ['rto_document', 'rto_tax_receipt', 'mmr', 'invoice', 'insurance', 'vehicle_image', 'chasis_number_img', 'motor_controller', 'battery_image'];
            // , 'registration_certificate', 'vehicle_signed_cheque'

            foreach ($requiredDocs as $doc) {
                $files = $request->file($doc); // Get the file(s)
    
                // Check if the document exists in the input and is either a file or an array of files
                if (!$files) {
                    $errorArray[$doc] = ucfirst(str_replace('_', ' ', $doc)) . " is required";
                    return $this->send_response("failed", $errorArray[$doc], 422);
                }
    
                // Normalize to array for uniform processing
                if (!is_array($files)) {
                    $files = [$files]; // If it's a single file, put it in an array
                }
    
                // Iterate over all files (even if it's a single file)
                foreach ($files as $index => $file) {
                    if (!$file->isValid()) {
                        $errorArray[$doc][$index] = ucfirst(str_replace('_', ' ', $doc)) . " at index $index is invalid or missing";
                        return $this->send_response("failed", $errorArray[$doc][$index], 422);
                    }
    
                    // Validate file type and size
                    $allowed_types = ["image/jpeg", "image/png", "image/jpg", "application/pdf"];
                    $max_size = 5 * 1024 * 1024; // 5 MB
    
                    if (!in_array($file->getMimeType(), $allowed_types)) {
                        $errorArray[$doc][$index] = ucfirst(str_replace('_', ' ', $doc)) . " at index $index must be a valid file type (jpeg, png, jpg, pdf)";
                        return $this->send_response("failed", $errorArray[$doc][$index], 422);
                    }
    
                    if ($file->getSize() > $max_size) {
                        $errorArray[$doc][$index] = ucfirst(str_replace('_', ' ', $doc)) . " at index $index must not exceed 5MB";
                        return $this->send_response("failed", $errorArray[$doc][$index], 422);
                    }
                }
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
            if (!isset($input['down_payment']) || empty($input['down_payment'])) {
                $errorCount++;
                $errorArray['down_payment'] = "Down payment amount is required";
                return $this->send_response("failed", $errorArray['down_payment'], 422);
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
            
            DB::beginTransaction();

            if (isset($input['vehicle_signed_cheque']) && is_array($input['vehicle_signed_cheque'])) {

                if (count($input['vehicle_signed_cheque']) !== 2) {
                    return $this->send_response("failed", "Exactly 2 signed cheque files are required if provided", 422);
                }
                $chequesToProcess = [];

                for ($i = 1; $i <= 2; $i++) {
                    $accountKey = "signed_cheque_{$i}_account_number";
                    $chequeKey = "signed_cheque_{$i}_cheque_number";
                    $ifscKey = "signed_cheque_{$i}_ifsc_code";
                    $fileIndex = $i - 1;

                    // Check if all parts are present
                    if (
                        !isset($input[$accountKey]) ||
                        !isset($input[$chequeKey]) ||
                        !isset($input[$ifscKey]) ||
                        !$request->hasFile("vehicle_signed_cheque.{$fileIndex}")
                    ) {
                        return $this->send_response("failed", "All details (account number, cheque number, IFSC, and file) are required for cheque #{$i}", 422);
                    }

                    // Prepare data
                    $chequesToProcess[] = [
                        'index' => $i,
                        'account_number' => $input[$accountKey],
                        'cheque_no' => $input[$chequeKey],
                        'ifsc' => $input[$ifscKey],
                        'file' => $request->file("vehicle_signed_cheque.{$fileIndex}"),
                    ];
                }

                // Now validate and process each cheque
                foreach ($chequesToProcess as $chequeData) {
                    $i = $chequeData['index'];

                    $validator = Validator::make($chequeData, [
                        'account_number' => 'required|string',
                        'cheque_no' => 'required|string',
                        'ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
                        'file' => 'required|file|mimes:jpeg,png,pdf'
                    ]);

                    if ($validator->fails()) {
                        return $this->send_response("failed", "Validation failed for cheque #{$i}: " . $validator->errors()->first(), 422);
                    }

                    // Check for duplicates
                    $exists = BorrowerCheques::where('borrower_id', $loan_details->id)
                        ->where('cheque_no', $chequeData['cheque_no'])
                        ->where('status', '<>', 'deleted')
                        ->exists();

                    if ($exists) {
                        return $this->send_response('failed', "Cheque no {$chequeData['cheque_no']} already exists for this borrower", 422);
                    }

                    // Store file
                    $extension  = $chequeData['file']->getClientOriginalExtension();
                    $fileName   = $chequeData['cheque_no'] . '.' . $extension;
                    $borrower_key = $loan_details->borrower_id;
                    $path = "Borrower/{$borrower_key}/Borrower_cheques";
                    $storedPath = Storage::disk('public')->putFileAs($path, $chequeData['file'], $fileName);

                    // Save DB record
                    $cheque                 = new BorrowerCheques();
                    $cheque->borrower_id    = $loan_details->id;
                    $cheque->file           = 'storage/' . $storedPath;
                    $cheque->cheque_no      = $chequeData['cheque_no'];
                    $cheque->ifsc           = $chequeData['ifsc'];
                    $cheque->account_number = $chequeData['account_number'];
                    $cheque->status         = 'active';
                    $cheque->ip             = $this->get_user_ip();
                    $cheque->modified_by    = auth()->id();
                    $cheque->save();
                }
            }

            $kyc_docs       = Documents::where('borrower_id', $borrower_primary_key)->first();

            if(!$kyc_docs) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            $docFields = [
                'rto_document' => ['column' => 'rto_document', 'doctype'=> 'VEHICLE_RTO'],
                'rto_tax_receipt' => ['column' => 'rto_tax_receipt', 'doctype'=> 'VEHICLE_RTO_TAX'],
                'mmr' => ['column' => 'mmr', 'doctype'=> 'VEHICLE_MMR'],
                'invoice' => ['column' => 'invoice', 'doctype'=> 'VEHICLE_INVOICE'],
                'insurance' => ['column' => 'insurance', 'doctype'=> 'VEHICLE_INSURANCE'],
                'vehicle_image' => ['column' => 'vehicle_image', 'doctype'=> 'VEHICLE_IMAGE'],
                'doh' => ['column' => 'doh', 'doctype'=> 'DOH'],
                'chasis_number_img' => ['column' => 'chasis_number', 'doctype'=> 'CHASIS_IMAGE'],
                'motor_controller' => ['column' => 'motor_controller', 'doctype'=> 'MOTOR_CONTROLLER_IMAGE'],
                'battery_image' => ['column' => 'battery_image', 'doctype'=> 'BATTERY_IMAGE'],
                'registration_certificate' => ['column' => 'registration_certificate', 'doctype'=> 'REGISTRATION_CERTIFICATE'],
                'vehicle_signed_cheque' => ['column' => 'vehicle_signed_cheque', 'doctype'=> 'SIGNED_CHEQUE'],
                'vehicle_other_docs' => ['column' => 'vehicle_other_docs', 'doctype'=> 'OTHER'],
            ];
            foreach ($docFields as $inputKey => $data) {
                if (isset($input[$inputKey])) {
                    // Prepare to store URLs
                    $fileUrls = [];

                    // Handle arrays (e.g., for signed cheques and other documents)
                    if (is_array($input[$inputKey])) {
                        foreach ($input[$inputKey] as $file) {
                            // $url = $this->general_file_upload($file, $data['doctype'], 'VEHICLE_DOCUMENT', $borrower_id);
                            // if ($url) {
                            //     $fileUrls[] = $url; // Collect each URL
                            // }
                            $uploadResult = $this->general_file_upload($file, $data['doctype'], 'VEHICLE_DOCUMENT', $borrower_id);
                            if ($uploadResult['status'] === 'failed') {
                                DB::rollBack(); // roll back before returning
                                return $this->send_response('failed', $uploadResult['message'], 422);
                            }
                            $fileUrls[] = $uploadResult['data'];
                        }
                        $kyc_docs->{$data['column']} = implode(',', $fileUrls); // Store URLs as a comma-separated string
                    } else {
                        // Handle single file uploads
                        // $kyc_docs->{$data['column']} = $this->general_file_upload($input[$inputKey], $data['doctype'], 'VEHICLE_DOCUMENT', $borrower_id);
                        $uploadResult = $this->general_file_upload($input[$inputKey], $data['doctype'], 'VEHICLE_DOCUMENT', $borrower_id);
                        if ($uploadResult['status'] === 'failed') {
                            DB::rollBack();
                            return $this->send_response('failed', $uploadResult['message'], 422);
                        }
                        $kyc_docs->{$data['column']} = $uploadResult['data'];
                    }
                }
            }

            if($kyc_docs->save()) {
                $application_step = [
                    'step' => $currentStep,
                    'kyc_doc_id' => $kyc_docs->id,
                    'details' => "Vehicle Documents Upload",
                    'status' => "COMPLETED",
                ];
    
                $this->updateApplicationStep($borrower_primary_key, $application_step);

                $loan_details->down_payment = $input['down_payment'] ?? 0;
                $loan_details->save();

                /**
                 * Code updated on: 20-01-2025
                 * previously chasis_number(chasis image) input changed to chasis_number_image
                 * insurance_policy_number and chasis number this two is new inputs added on 
                 * 20-01-2025, as required in stashfin development and as well as our later development process
                 */
                $borrower_kyc_detail = BorrowerKyc::where('borrower_id', $borrower_primary_key)->first();
                $borrower_kyc_detail->policy_number = $request->insurance_policy_number ?? null;
                $borrower_kyc_detail->chasis_number = $request->chasis_number ?? null;
                $borrower_kyc_detail->policy_premium    = $request->policy_premium ?? null;
                $borrower_kyc_detail->policy_start_date = $request->policy_start_date ?? null;
                $borrower_kyc_detail->policy_end_date   = $request->policy_end_date ?? null;
                $borrower_kyc_detail->save();

                DB::commit(); // Commit the transaction
                return response()->json([
                    'status'=>'success',
                    'message'=>"Vehicle documents stored successfully",
                    'data'=> ''
                ], 201);
            } else {
                DB::rollBack(); // Rollback transaction on failure
                return response()->json([
                    'status'=>'failed',
                    'message'=>"Failed to store vehicle documents",
                ], 400);
            }
            
        } catch (Exception $e) {
            DB::rollBack(); // Rollback transaction on exception
            return response()->json([
                'status' => 'failed',
                'message' => $e->getMessage(),
            ], 422);
        } 
    }

    public function battery_doc_upload_admin(Request $request) {
        try {
            $input = $request->all();
            $errorArray = [];
            $errorCount = 0;
            $step = '';
            // $currentStep = 28;
            $currentStep = 22;
            date_default_timezone_set('Asia/Kolkata');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            }

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }
            $loan_details = LoanBasicDetails::find($request->borrower_id);
            if(!$loan_details) {
                return $this->helper_controller->send_response('failed', "Borrower not found", 422);
            }

            $requiredDocs = ['invoice', 'warranty_card', 'signed_cheque', 'bank_statement'];
            foreach ($requiredDocs as $doc) {
                $files = $request->file($doc); // Get the file(s)
    
                // Check if the document exists in the input and is either a file or an array of files
                if (!$files) {
                    $errorArray[$doc] = ucfirst(str_replace('_', ' ', $doc)) . " is required";
                    return $this->send_response("failed", $errorArray[$doc], 422);
                }
    
                // Normalize to array for uniform processing
                if (!is_array($files)) {
                    $files = [$files]; // If it's a single file, put it in an array
                }
    
                // Iterate over all files (even if it's a single file)
                foreach ($files as $index => $file) {
                    if (!$file->isValid()) {
                        $errorArray[$doc][$index] = ucfirst(str_replace('_', ' ', $doc)) . " at index $index is invalid or missing";
                        return $this->send_response("failed", $errorArray[$doc][$index], 422);
                    }
    
                    // Validate file type and size
                    $allowed_types = ["image/jpeg", "image/png", "image/jpg", "application/pdf"];
                    $max_size = 5 * 1024 * 1024; // 5 MB
    
                    if (!in_array($file->getMimeType(), $allowed_types)) {
                        $errorArray[$doc][$index] = ucfirst(str_replace('_', ' ', $doc)) . " at index $index must be a valid file type (jpeg, png, jpg, pdf)";
                        return $this->send_response("failed", $errorArray[$doc][$index], 422);
                    }
    
                    if ($file->getSize() > $max_size) {
                        $errorArray[$doc][$index] = ucfirst(str_replace('_', ' ', $doc)) . " at index $index must not exceed 5MB";
                        return $this->send_response("failed", $errorArray[$doc][$index], 422);
                    }
                }
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

            if (!isset($input['down_payment']) || empty($input['down_payment'])) {
                $errorCount++;
                $errorArray['down_payment'] = "Down payment amount is required";
                return $this->send_response("failed", $errorArray['down_payment'], 422);
            }

            if ($errorCount > 0) {
                return $this->send_response("failed", "Invalid data", 422, $errorArray);
            }

            DB::beginTransaction();

            if (isset($input['signed_cheque']) && is_array($input['signed_cheque'])) {

                if (count($input['signed_cheque']) !== 2) {
                    return $this->send_response("failed", "Exactly 2 signed cheque files are required if provided", 422);
                }
                $chequesToProcess = [];

                for ($i = 1; $i <= 2; $i++) {
                    $accountKey = "signed_cheque_{$i}_account_number";
                    $chequeKey = "signed_cheque_{$i}_cheque_number";
                    $ifscKey = "signed_cheque_{$i}_ifsc_code";
                    $fileIndex = $i - 1;

                    // Check if all parts are present
                    if (
                        !isset($input[$accountKey]) ||
                        !isset($input[$chequeKey]) ||
                        !isset($input[$ifscKey]) ||
                        !$request->hasFile("signed_cheque.{$fileIndex}")
                    ) {
                        return $this->send_response("failed", "All details (account number, cheque number, IFSC, and file) are required for cheque #{$i}", 422);
                    }

                    // Prepare data
                    $chequesToProcess[] = [
                        'index' => $i,
                        'account_number' => $input[$accountKey],
                        'cheque_no' => $input[$chequeKey],
                        'ifsc' => $input[$ifscKey],
                        'file' => $request->file("signed_cheque.{$fileIndex}"),
                    ];
                }

                // Now validate and process each cheque
                foreach ($chequesToProcess as $chequeData) {
                    $i = $chequeData['index'];

                    $validator = Validator::make($chequeData, [
                        'account_number' => 'required|string',
                        'cheque_no' => 'required|string',
                        'ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
                        'file' => 'required|file|mimes:jpeg,png,pdf'
                    ]);

                    if ($validator->fails()) {
                        return $this->send_response("failed", "Validation failed for cheque #{$i}: " . $validator->errors()->first(), 422);
                    }

                    // Check for duplicates
                    $exists = BorrowerCheques::where('borrower_id', $loan_details->id)
                        ->where('cheque_no', $chequeData['cheque_no'])
                        ->where('status', '<>', 'deleted')
                        ->exists();

                    if ($exists) {
                        return $this->send_response('failed', "Cheque no {$chequeData['cheque_no']} already exists for this borrower", 422);
                    }

                    // Store file
                    $extension  = $chequeData['file']->getClientOriginalExtension();
                    $fileName   = $chequeData['cheque_no'] . '.' . $extension;
                    $borrower_key = $loan_details->borrower_id;
                    $path = "Borrower/{$borrower_key}/Borrower_cheques";
                    $storedPath = Storage::disk('public')->putFileAs($path, $chequeData['file'], $fileName);

                    // Save DB record
                    $cheque                 = new BorrowerCheques();
                    $cheque->borrower_id    = $loan_details->id;
                    $cheque->file           = 'storage/' . $storedPath;
                    $cheque->cheque_no      = $chequeData['cheque_no'];
                    $cheque->ifsc           = $chequeData['ifsc'];
                    $cheque->account_number = $chequeData['account_number'];
                    $cheque->status         = 'active';
                    $cheque->ip             = $this->get_user_ip();
                    $cheque->modified_by    = auth()->id();
                    $cheque->save();
                }
            }

            $borrower_primary_key = $request->borrower_id;
            $borrower_id = $loan_details->borrower_id;

            $kyc_docs       = Documents::where('borrower_id', $borrower_primary_key)->first();

            if(!$kyc_docs) {
                return $this->send_response('failed', "Invalid request", 422);
            }
            $docFields = [
                'invoice' => ['column' => 'invoice', 'doctype'=>'BATTERY_INVOICE'],
                'warranty_card' => ['column' => 'warrant_document', 'doctype'=>'BATTERY_WARRANTY_DOCUMENT'],
                'bank_statement' => ['column' => 'bank_statement', 'doctype'=>'BATTERY_BANK_STATEMENT'],
                'signed_cheque' => ['column' => 'battery_signed_cheque', 'doctype'=>'SIGNED_CHEQUE'],
            ];

            foreach ($docFields as $inputKey => $data) {
                if (isset($input[$inputKey])) {
                    // Prepare to store URLs
                    $fileUrls = [];

                    // Handle arrays (e.g., for signed cheques and other documents)
                    if (is_array($input[$inputKey])) {
                        foreach ($input[$inputKey] as $file) {
                            // $url = $this->general_file_upload($file, $data['doctype'], 'BATTERY_DOCUMENT', $borrower_id);
                            // if ($url) {
                            //     $fileUrls[] = $url; // Collect each URL
                            // }
                            $uploadResult = $this->general_file_upload($file, $data['doctype'], 'BATTERY_DOCUMENT', $borrower_id);
                            if ($uploadResult['status'] === 'failed') {
                                DB::rollBack(); // roll back before returning
                                return $this->send_response('failed', $uploadResult['message'], 422);
                            }
                            $fileUrls[] = $uploadResult['data'];
                        }
                        $kyc_docs->{$data['column']} = implode(',', $fileUrls); // Store URLs as a comma-separated string
                    } else {
                        // Handle single file uploads
                        // $kyc_docs->{$data['column']} = $this->general_file_upload($input[$inputKey], $data['doctype'], 'BATTERY_DOCUMENT', $borrower_id);
                        $uploadResult = $this->general_file_upload($input[$inputKey], $data['doctype'], 'BATTERY_DOCUMENT', $borrower_id);
                        if ($uploadResult['status'] === 'failed') {
                            DB::rollBack();
                            return $this->send_response('failed', $uploadResult['message'], 422);
                        }
                        $kyc_docs->{$data['column']} = $uploadResult['data'];
                    }
                }
            }

            $kyc_docs->save();
            $application_step = [
                'step' => $currentStep,
                'kyc_doc_id' => $kyc_docs->id,
                'details' => "Battery Documents Upload",
                'status' => "COMPLETED",
            ];

            $this->updateApplicationStep($borrower_primary_key, $application_step);

            $loan_details->down_payment = $input['down_payment'] ?? 0;
            $loan_details->save();
            
            DB::commit();
            
            return $this->send_response('success', "Battery documents stored successfully", 201);
        } catch (Exception $e) {
            DB::rollBack();
            
            return $this->send_response('failed', $e->getMessage(), 422);
        } 
    }
    
    // public function general_file_upload($file, $docType, $flag, $borrower_id) {
    //     // Perform file validation and upload
    //     $allowed_types = ["image/jpeg", "image/png", "image/jpg", "application/pdf"];
    //     $max_size = 5 * 1024 * 1024; // 5 MB
    
    //     if (!$file->isValid()) {
    //         return $this->send_response("failed", "Invalid file", 422);
    //     }
    
    //     if (!in_array($file->getMimeType(), $allowed_types)) {
    //         return $this->send_response("failed", "Only JPG, PNG, JPEG, and PDF files are allowed", 422);
    //     }
    
    //     if ($file->getSize() > $max_size) {
    //         return $this->send_response("failed", "$docType file size must not exceed 5 MB", 422);
    //     }
    
    //     $user_id = request()->user()->id;
            
    //     // Generate a unique file name
    //     $fileName = ($docType == 'OTHER' || $docType == "SIGNED_CHEQUE")
    //         ? $docType . '-' . $this->generate_random_string(16) . '.' . $file->getClientOriginalExtension()
    //         : $docType . '.' . $file->getClientOriginalExtension();
    
    //     // Store the uploaded file
    //     $path = $file->storeAs('public/Borrower/' . $borrower_id . '/' . $flag, $fileName);
        
    //     // Determine the full path
    //     $fullPath = storage_path('app/' . $path);
    
    //     // Set the correct permissions for the file
    //     @chmod($fullPath, 0644);
    
    //     // Ensure the directory has the correct permissions
    //     $directory = dirname($fullPath);
    //     if (!is_dir($directory)) {
    //         File::makeDirectory($directory, 0775, true, true);
    //     } else {
    //         @chmod($directory, 0775);
    //     }
    
    //     // Get the URL of the uploaded file
    //     return Storage::url($path);
    // } // disabled on 17-04-2025

    public function general_file_upload($file, $docType, $flag, $borrower_id) {
        $allowed_types = ["image/jpeg", "image/png", "image/jpg", "application/pdf"];
        $max_size = 5 * 1024 * 1024; // 5 MB
    
        if (!$file->isValid()) {
            return [
                'status' => 'failed',
                'message' => 'Invalid file'
            ];
        }
    
        if (!in_array($file->getMimeType(), $allowed_types)) {
            return [
                'status' => 'failed',
                'message' => 'Only JPG, PNG, JPEG, and PDF files are allowed'
            ];
        }
    
        if ($file->getSize() > $max_size) {
            return [
                'status' => 'failed',
                'message' => "$docType file size must not exceed 5 MB"
            ];
        }
    
        try {
            $fileName = ($docType == 'OTHER' || $docType == "SIGNED_CHEQUE")
                ? $docType . '-' . $this->generate_random_string(16) . '.' . $file->getClientOriginalExtension()
                : $docType . '.' . $file->getClientOriginalExtension();
    
            // $path = $file->storeAs('public/Borrower/' . $borrower_id . '/' . $flag, $fileName);
            $filePath = 'public/Borrower/' . $borrower_id . '/' . $flag;
            $storedPath = $file->storeAs($filePath, $fileName);
            // $fullPath = storage_path('app/' . $path);
    
            // @chmod($fullPath, 0644);
    
            // $directory = dirname($fullPath);
            // if (!is_dir($directory)) {
            //     File::makeDirectory($directory, 0775, true, true);
            // } else {
            //     @chmod($directory, 0775);
            // }
    
            return [
                'status' => 'success',
                'data' => Storage::url($storedPath)
            ];
        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'message' => 'File upload failed: ' . $e->getMessage()
            ];
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
    
    public function generateReducingInterestSchedule($loan_amount, $flat_interest, $tenure)
    {
        $effectiveIRR = $this->calculateEffectiveIRR($loan_amount, $flat_interest, $tenure);
        $monthlyInterestRate = ($effectiveIRR / 100) / 12;
        $emi = $loan_amount * $monthlyInterestRate / (1 - pow(1 + $monthlyInterestRate, -$tenure));
    
        $schedule = [];
        $remainingBalance = $loan_amount;
        $totalInterest = 0;
    
        for ($month = 1; $month <= $tenure; $month++) {
            $interestPortion = $remainingBalance * $monthlyInterestRate;
            $principalPortion = $emi - $interestPortion;
    
            if ($month == $tenure) {
                $principalPortion = $remainingBalance;
                $emi = $principalPortion + $interestPortion;
            }
    
            $remainingBalance -= $principalPortion;
            $totalInterest += $interestPortion;
    
            $schedule[] = [
                'month' => $month,
                'principal' => round($principalPortion),
                'interest' => ceil($interestPortion),
                'total_payment' => ceil($emi),
                'remaining_balance' => max(0, round($remainingBalance))
            ];
        }
    
       
        $totalInterest = round(($loan_amount * $flat_interest / 100) * ($tenure / 12));
        $totalLoanAmount = $loan_amount + $totalInterest;
    
        return $schedule;
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
            if($check_nach_availability->card_flag == false) {
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
                    'dealer'   => strtolower(trim('Rahara')),
                ],
                'district' => [
                    'borrower' => strtolower(trim($address['dist'])),
                    'dealer'   => strtolower(trim('North 24 Parganas')),
                ],
                'pincode' => [
                    'borrower' => trim($address['pc']),
                    'dealer'   => trim('700118'),
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
