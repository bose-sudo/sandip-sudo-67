<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\WebController;
use App\Models\BorrowerIotDetails;
use App\Models\BorrowerKyc;
use App\Models\CoapplicantKyc;
use App\Models\Guarantor;
use App\Models\LoanApplicationSummary;
use App\Models\LoanBasicDetails;
use App\Models\VendorServiceApi;
use App\Models\VkycApiReqDetails;
use App\Models\VkycWebResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use CURLFile;
use Illuminate\Http\Request;
use App\Services\TwilioService;
use App\Traits\DigilockerVerificationTrait;
use Exception;
use DateTime;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\HelperController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ServiceApiController extends Controller
{
    use DigilockerVerificationTrait;

    private $helper_controller;
    private $client_id;
    private $client_secret;
    private $prod_authorization_key;
    private $uat_authorization_key;
    private $loco_nav_auth_key;
    private $signzy_auth_key;
    protected $twilio;

    public function __construct(TwilioService $twilio, HelperController $helper_controller) {
        $this->middleware('auth:api', ['except' => ['store_vkyc_data', 'generate_url_unified_kyc', 'generate_url_unified_kyc_staus']]);

        $uatClientId = config('services.digitap.uat.client_id');
        $uatSecret   = config('services.digitap.uat.client_secret');
        $this->uat_authorization_key = base64_encode($uatClientId . ':' . $uatSecret);

        $prodClientId = config('services.digitap.prod.client_id');
        $prodSecret   = config('services.digitap.prod.client_secret');

        $this->prod_authorization_key = base64_encode($prodClientId . ':' . $prodSecret);

        $this->signzy_auth_key = config('services.signzy.prod.auth_key');
        $this->loco_nav_auth_key = config('services.loconav.prod.auth_key');

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

    public function digilocker_generate_url(Request $request) {
        return $this->unified_digilocker_generate_url($request, 'b2c');
    }
    public function digilocker_status(Request $request) {
       return $this->unified_digilocker_status($request, 'b2c');
    }
    public function digilocker_details(Request $request) {
       return $this->unified_digilocker_details($request, 'b2c');
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
     * Method to get user IP address
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

    /**
     * Aadhar Verification API Send OTP
     * Created By: Mir Akib Javed
     * Date: 10-05-2024
     */
    public function aadhar_verify(Request $request) {
        try{
            $input      = $request->all();
            $user_id    = $request->user()->id;
            $errorArray = [];
            $errorCount = 0;
            $ip         = $this->get_user_ip();
            $uniqueId   = $this->generate_random_id();

            if($input == null) {
                $errorCount++;
                $errorArray['body'] = "Request body is empty";
                return $this->send_response("failed", $errorArray['body'], 422);
            } else {
                if (!isset($input['aadhar']) || empty($input['aadhar'])) {
                    $errorCount++;
                    $errorArray['aadhar'] = "Aadhar number is required";
                    return $this->send_response("failed", $errorArray['aadhar'], 422);
                } elseif (!preg_match('/^[0-9]{12}$/', $input['aadhar'])) {
                    $errorCount++;
                    $errorArray['aadhar'] = "Invalid Aadhar number format";
                    return $this->send_response("failed", $errorArray['aadhar'], 422);
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
                
                $borrower_id = $this->get_borrower_id($request->user()->id);

                DB::beginTransaction();
                $this->helper_controller->store_app_log('create', "Aadhar verification api initiated for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id);
            }

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }

            $jsonRequestBody = json_encode([
                "uniqueId" => $uniqueId,
                "uid" => $input['aadhar']
            ]);

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://svc.digitap.ai/ent/v3/kyc/intiate-kyc-auto',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonRequestBody,
                CURLOPT_HTTPHEADER => array(
                    'Authorization: '. $this->prod_authorization_key,
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            // echo $response;

            $result = json_decode($response);

            if($result->code == 200 && $result->msg == "success") {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = $user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "AADHAR_CARD_VERIFICATION_OKYC_PAPERLESS_XML";
            $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
            $vendorServiceApi->response         = base64_encode(serialize($response));
            // $borrower_id                        = $this->get_borrower_id($request->user()->id);
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $input['applicant_type'];
            $vendorServiceApi->save();

            // return $response;
            if(isset($result->code) && $result->code == 200) {
                $message = "OTP sent to registered number";
            
                $result_response = [
                    'status' => 'success',
                    'message' => $message,
                    'data' => [
                        'transactionId' => isset($result->model->transactionId) ? $result->model->transactionId : '',
                        'fwdp' => isset($result->model->fwdp) ? $result->model->fwdp : '',
                        'codeVerifier' => isset($result->model->codeVerifier) ? $result->model->codeVerifier : '',
                        'uidai_message' => isset($result->model->uidaiResponse->message) ? $result->model->uidaiResponse->message : '',
                    ],
                ];
                
                $this->helper_controller->store_app_log('create', "Aadhar verification OTP sent to registered number", "BORROWER", $borrower_id);
            } else {
                $message = isset($result->msg) ? $result->msg : "No results";
                $result_response = [
                    'status' => 'failed',
                    'message' => $message ?? "No results",
                    'data' => [
                        'transactionId' => '',
                        'fwdp' => '',
                        'codeVerifier' => '',
                        'uidai_message' => '',
                    ],
                ];
                
                $this->helper_controller->store_app_log('create', "API response: ". $message, "BORROWER", $borrower_id);
            }
            DB::commit();
            return response()->json($result_response, 200);
        } catch (Exception $e) {
            DB::rollBack();
            $result_response = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            return response()->json($result_response, 422);
        }
    }

    /**
     * Aadhar Verification Resend OTP Api
     * Created By: Mir Akib Javed
     * Date: 11-05-2024
     */
    public function verify_aadhar_resend_otp(Request $request) {
        try {
            $input      = $request->all();
            $user_id    = $request->user()->id;
            $errorArray = [];
            $errorCount = 0;
            $ip         = $this->get_user_ip();
            $uniqueId   = $this->generate_random_id();
            
            if($input == null) {
                $errorCount++;
                $errorArray['body'] = "Request body is empty";
                return $this->send_response("failed", $errorArray['body'], 422);
            } else {

                if (!isset($input['aadhar']) || empty($input['aadhar'])) {
                    $errorCount++;
                    $errorArray['aadhar'] = "Aadhar number is required";
                    return $this->send_response("failed", $errorArray['aadhar'], 422);
                } elseif (!preg_match('/^[0-9]{12}$/', $input['aadhar'])) {
                    $errorCount++;
                    $errorArray['aadhar'] = "Invalid Aadhar number format";
                    return $this->send_response("failed", $errorArray['aadhar'], 422);
                }

                if(!isset($input['transactionId']) || empty($input['transactionId'])) {
                    $errorCount++;
                    $errorArray['transactionId'] = "Transation is required";
                    return $this->send_response("failed", $errorArray['transactionId'], 422);
                }
        
                if(!isset($input['fwdp']) || empty($input['fwdp'])) {
                    $errorCount++;
                    $errorArray['fwdp'] = "fwdp is required";
                    return $this->send_response("failed", $errorArray['fwdp'], 422);
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

            }
            
            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }
            
            $borrower_id = $this->get_borrower_id($request->user()->id);

            DB::beginTransaction();
            $this->helper_controller->store_app_log('create', "Aadhar verification resend OTP api initiated for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id);
            
            $jsonRequestBody = json_encode([
                "uniqueId" => $uniqueId,
                "uid" => $input['aadhar'],
                "transactionId" => $input['transactionId'],
                "fwdp" => $input['fwdp']
            ]);

            $curl = curl_init();
            curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://svc.digitap.ai/ent/v3/kyc/resend-otp',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonRequestBody,
                CURLOPT_HTTPHEADER => array(
                    'Authorization: '.$this->prod_authorization_key,
                    'Content-Type: application/json'
                ),
            ));
            
            $response = curl_exec($curl);

            curl_close($curl);
            // echo $response;
            // die();

            $result = json_decode($response);
            
            if(isset($result->code) && $result->code == 200 && isset($result->msg) && $result->msg == "success") {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = $user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "AADHAR_CARD_VERIFICATION_RESEND_OTP_OKYC_PAPERLESS_XML";
            $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
            $vendorServiceApi->response         = base64_encode(serialize($response));
            // $borrower_id                        = $this->get_borrower_id($request->user()->id);
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $input['applicant_type'];
            $vendorServiceApi->save();

            // return $response;
            if($result->code == 200) {
                $message = "OTP sent to registered number";
            
                $result_response = [
                    'status' => 'success',
                    'message' => $message,
                    'data' => [
                        'transactionId' => isset($result->model->transactionId) ? $result->model->transactionId : '',
                        'fwdp' => isset($result->model->fwdp) ? $result->model->fwdp : '',
                        'codeVerifier' => isset($result->model->codeVerifier) ? $result->model->codeVerifier : '',
                        'uidai_message' => isset($result->model->uidaiResponse->message) ? $result->model->uidaiResponse->message : '',
                    ],
                ];

                $this->helper_controller->store_app_log('create', "Aadhar verification OTP resent to registered mobile number", "BORROWER", $borrower_id);
            } else {
                $message = "No results";
                $result_response = [
                    'status' => 'failed',
                    'message' => $message,
                    'data' => [
                    ],
                ];
                $this->helper_controller->store_app_log('create', "Api response ". $message, "BORROWER", $borrower_id);
            }

            DB::commit();
            return response()->json($result_response, 200);
        } catch (Exception $e) {
            DB::rollBack();
            $result_response = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            return response()->json($result_response, 422);
        }
    }

    /**
     * Aadhar Verification API With OTP
     * Created By: Mir Akib Javed
     * Date: 10-05-2024
     */
    public function verify_aadhar_using_otp(Request $request) {
        try {
            $input      = $request->all();
            $user_id    = $request->user()->id;
            $errorArray = [];
            $errorCount = 0;
            $ip         = $this->get_user_ip();
            $share_code = $this->generate_random_string(4);
            
            if($input == null) {
                $errorCount++;
                $errorArray['body'] = "Request body is empty";
                return $this->send_response("failed", $errorArray['body'], 422);
            } else {
                
                if(!isset($input['transactionId']) || empty($input['transactionId'])) {
                    $errorCount++;
                    $errorArray['transactionId'] = "Transation id is required";
                    return $this->send_response("failed", $errorArray['transactionId'], 422);
                }
        
                if(!isset($input['fwdp']) || empty($input['fwdp'])) {
                    $errorCount++;
                    $errorArray['fwdp'] = "fwdp is required";
                    return $this->send_response("failed", $errorArray['fwdp'], 422);
                }
                
                if(!isset($input['codeVerifier']) || empty($input['codeVerifier'])) {
                    $errorCount++;
                    $errorArray['codeVerifier'] = "Code verifier is required";
                    return $this->send_response("failed", $errorArray['codeVerifier'], 422);
                }
        
                if(!isset($input['otp']) || empty($input['otp'])) {
                    $errorCount++;
                    $errorArray['otp'] = "OTP is required";
                    return $this->send_response("failed", $errorArray['otp'], 422);
                } elseif(!is_numeric($input['otp'])) {
                    $errorCount++;
                    $errorArray['otp'] = "OTP must be numeric";
                    return $this->send_response("failed", $errorArray['otp'], 422);
                } elseif (!preg_match('/^\d{6}$/', $input['otp'])) {
                    $errorCount++;
                    $errorArray['otp'] = "Invalid OTP format. OTP must be a 6-digit number";
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
            }

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }

            $borrower_id = $this->get_borrower_id($request->user()->id);

            DB::beginTransaction();
            $this->helper_controller->store_app_log('create', "Aadhar verification with OTP initiated for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id);

            $jsonRequestBody = json_encode([
                "transactionId" => $input['transactionId'],
                "fwdp" => $input['fwdp'],
                "codeVerifier" => $input['codeVerifier'],
                "otp" => $input['otp'],
                "shareCode" => $share_code,
                "isSendPdf" => true
            ]);

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://svc.digitap.ai/ent/v3/kyc/submit-otp',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonRequestBody,
                CURLOPT_HTTPHEADER => array(
                    'Authorization: '. $this->prod_authorization_key,
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            // echo $response;
            // die();

            $result = json_decode($response);

            $response_aadhar = '';
            if(isset($result->code) && $result->code == 200 && $result->msg == "success") {
                $api_status = "VALID";
                $response_aadhar = $result->model->adharNumber;
                $result->model->adharNumber = $this->maskAadharNumber($result->model->adharNumber);
                $response = json_encode($result, true);
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = $user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML";
            $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
            $vendorServiceApi->response         = base64_encode(serialize($response));
            
            // $borrower_id                        = $this->get_borrower_id($request->user()->id);
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $input['applicant_type'];

            $vendorServiceApi->save();

            if(isset($result->code) && $result->code == 200) {
                $message = "Aadhar data fetched";
            
                $address = '';
                if(!empty($result->model->address->house)){
                    $address .= $result->model->address->house;
                }
            
                if(!empty($decode_json->model->address->street)){
                    if(!empty($decode_json->model->address->house)) {
                        $address .= ', ';
                    }
                    $address .= $decode_json->model->address->street;
                }
            
                if(!empty($result->model->address->landmark)){
                    if(!empty($decode_json->model->address->street)) {
                        $address .= ', ';
                    }
                    $address .= $result->model->address->landmark;
                }
            
                if(!empty($result->model->address->loc)){
                    if(!empty($decode_json->model->address->landmark)) {
                        $address .= ', ';
                    }
                    $address .= $result->model->address->loc;
                }
            
                if(!empty($result->model->address->po)){
                    if(!empty($decode_json->model->address->loc)) {
                        $address .= ', ';
                    }
                    $address .= $result->model->address->po;
                }
            
                if(!empty($result->model->address->dist)){
                    if(!empty($decode_json->model->address->po)) {
                        $address .= ', ';
                    }
                    $address .= $result->model->address->dist;
                }
            
                if(!empty($result->model->address->subdist)){
                    if(!empty($decode_json->model->address->dist)) {
                        $address .= ', ';
                    }
                    $address .= $result->model->address->subdist;
                }
            
                if(!empty($result->model->address->vtc)){
                    if(!empty($decode_json->model->address->subdist)) {
                        $address .= ', ';
                    }
                    $address .= $result->model->address->vtc;
                }
            
                if(!empty($result->model->address->pc)){
                    $address .= ', '.$result->model->address->pc;
                }
            
                if(!empty($result->model->address->state)){
                    $address .= ', '.$result->model->address->state;
                }
            
                if(!empty($result->model->address->country)){
                    $address .= ', '.$result->model->address->country;
                }
            
                $result_response = [
                    'code' => $result->code,
                    'status' => 'success',
                    'message' => $message,
                    'data' => [
                        "adharNumber" => $response_aadhar,
                        "uniqueId" => $result->model->uniqueId,
                        "maskedAdharNumber" => $result->model->adharNumber,
                        "name" => $result->model->name,
                        "gender" => $result->model->gender,
                        "dob" => $result->model->dob,
                        "careOf" => $result->model->careOf,
                        "father_name" => $this->helper_controller->getFatherName($result->model->careOf),
                        "husband_name" => $this->helper_controller->getHusbandName($result->model->careOf),
                        "uniqueId" => $result->model->uniqueId,
                        "address" => $address,
                        "full_address" => $result->model->address,
                        "msg" => $result->msg,
                    ],
                ];

                $this->helper_controller->store_app_log('create', "Aadhar verification with OTP successful for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);

            } elseif(isset($result->code) && $result->code == 500){
                $message = $result->msg;
                $result_response = [
                    'code' => $result->code,
                    'status' => 'failed',
                    'message' => $message,
                    'errorCode' => $result->errorCode,
                    'data' => [
                    ],
                ];
                $this->helper_controller->store_app_log('create', "Aadhar verification with OTP failed for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
            } else {
                $message = "No results";
                $result_response = [
                    'status' => 'failed',
                    'message' => $message,
                    'data' => [
                    ],
                ];
                $this->helper_controller->store_app_log('create', "Aadhar verification with OTP failed for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
            }
            DB::commit();
            // return $response;
            return response()->json($result_response, 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred", 422);
        }
    }

    /**
     * PAN Verification API
     * Created By: Mir Akib Javed
     * Date: 10-05-2024
     */
    public function pan_verification(Request $request) {
        try {
            $input      = $request->all();
            $user_id    = $request->user()->id;
            $errorArray = [];
            $errorCount = 0;
            $ip         = $this->get_user_ip();
            $client_ref_num = $this->generate_random_id();
            date_default_timezone_set('Asia/Kolkata');

            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            } else {
                
                if (!isset($input['pan']) || empty($input['pan'])) {
                    $errorCount++;
                    $errorArray['pan'] = "Pan number is required";
                    return $this->send_response("failed", $errorArray['pan'], 422);
                } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $input['pan'])) {
                    $errorCount++;
                    $errorArray['pan'] = "Invalid Pan number format";
                    return $this->send_response("failed", $errorArray['pan'], 422);
                }
                
                if (!isset($input['name']) || empty($input['name'])) {
                    $errorCount++;
                    $errorArray['name'] = "Name is required";
                    return $this->send_response("failed", $errorArray['name'], 422);
                }
                
                if (!isset($input['dob']) || empty($input['dob'])) {
                    $errorCount++;
                    $errorArray['dob'] = "Date of birth is required";
                    return $this->send_response("failed", $errorArray['dob'], 422);
                } elseif (!strtotime($input['dob'])) {
                    $errorCount++;
                    $errorArray['dob'] = "Invalid date of birth";
                    return $this->send_response("failed", $errorArray['dob'], 422);
                } else {
                    // Calculate age
                    $dobTimestamp = strtotime($input['dob']);
                    $age = date('Y') - date('Y', $dobTimestamp);
                    if (date('md') < date('md', $dobTimestamp)) {
                        $age--;
                    }
                    if ($age < 21) {
                        $errorCount++;
                        $errorArray['dob'] = "Age must be greater than 21";
                        return $this->send_response("failed", $errorArray['dob'], 422);
                    }
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
            }
            
            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }
            
            $borrower_id = $this->get_borrower_id($request->user()->id);

            DB::beginTransaction();
            $this->helper_controller->store_app_log('create', "PAN verification api initiated for". strtolower($input['applicant_type']), "BORROWER", $borrower_id);
            
            $jsonRequestBody = json_encode([
                "client_ref_num" => $client_ref_num,
                "pan" => $input['pan'],
                "name" => $input['name'],
                "dob" => date('d/m/Y', strtotime($input['dob'])),
            ]);

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://svc.digitap.ai/validation/kyc/v2/pan_basic',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS =>$jsonRequestBody,
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Basic '. $this->prod_authorization_key,
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            // echo $response;

            $result = json_decode($response);
            
            if(isset($result->http_response_code) && $result->http_response_code == 200) {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = $user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "PAN_BASIC_VALIDATION";
            $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
            $vendorServiceApi->response         = base64_encode(serialize($response));
            // $borrower_id                        = $this->get_borrower_id($request->user()->id);
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $input['applicant_type'];
            $vendorServiceApi->save();

            if(isset($result->http_response_code) && $result->http_response_code == 200) {
                $api_status = "VALID";
                if($result->result_code == 101) {
                    $is_active = true;
                    $message = "Pan details fetched";

                    $this->helper_controller->store_app_log('create', "PAN verification successful for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                    
                } elseif($result->result_code == 103) {
                    $is_active = false;
                    $message = $result->message;

                    $this->helper_controller->store_app_log('create', "PAN verification failed for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                } else {
                    $is_active = false;
                    $message = "";

                    $this->helper_controller->store_app_log('create', "PAN verification failed for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                }
                $result_response = [
                    'status' => 'success',
                    'is_active' => $is_active,
                    'result_code' => isset($result->result_code) ? $result->result_code : '',
                    'message' => $message,
                    'data' => [
                        'pan' => isset($result->result->pan) ? $result->result->pan : '',
                        'status' => isset($result->result->status) ? $result->result->status : '',
                        'status_code' => isset($result->result->status_code) ? $result->result->status_code : '',
                        'name' => isset($result->result->name) ? $result->result->name : '',
                        'dob' => isset($result->result->dob) ? $result->result->dob : '',
                    ],
                ];
            } else {
                $api_status = "INVALID";
                $result_response = [
                    'status' => 'failed',
                    'is_active' => false,
                    'message' => "No results",
                    'data' => [
                        'pan' => '',
                        'status' => '',
                        'status_code' => '',
                        'name' => '',
                        'dob' => '',
                    ],
                ];
                $this->helper_controller->store_app_log('create', "PAN verification failed for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
            }
            DB::commit();
            return response()->json($result_response, 200);
        } catch(Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred", 422);
        }
    }

    public function pan_details_verification(Request $request) {
        try {
            $input      = $request->all();
            $user_id    = $request->user()->id;
            $errorArray = [];
            $errorCount = 0;
            $ip         = $this->get_user_ip();
            $client_ref_num = $this->generate_random_id();
            date_default_timezone_set('Asia/Kolkata');

            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            } else {
                
                if (!isset($input['pan']) || empty($input['pan'])) {
                    $errorCount++;
                    $errorArray['pan'] = "Pan number is required";
                    return $this->send_response("failed", $errorArray['pan'], 422);
                } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $input['pan'])) {
                    $errorCount++;
                    $errorArray['pan'] = "Invalid Pan number format";
                    return $this->send_response("failed", $errorArray['pan'], 422);
                }
                
                if (!isset($input['name']) || empty($input['name'])) {
                    $errorCount++;
                    $errorArray['name'] = "Name is required";
                    return $this->send_response("failed", $errorArray['name'], 422);
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
            }
            
            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }

            $borrower_id = $this->get_borrower_id($request->user()->id);

            DB::beginTransaction();
            $this->helper_controller->store_app_log('create', "PAN details verification api initiated for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id);
            
            $jsonRequestBody = json_encode([
                "client_ref_num" => $client_ref_num,
                "pan" => $input['pan'],
                "name" => $input['name'],
                "father" => 'yes'
            ]);

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://svc.digitap.ai/validation/kyc/v1/pan_details',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS =>$jsonRequestBody,
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Basic '. $this->prod_authorization_key,
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

            if(isset($result->http_response_code) && $result->http_response_code == 200) {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = $user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "PAN_DETAILS_VALIDATION";
            $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
            $vendorServiceApi->response         = base64_encode(serialize($response));
            // $borrower_id                        = $this->get_borrower_id($request->user()->id);
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $input['applicant_type'];
            $vendorServiceApi->save();

            if(isset($result->http_response_code) && $result->http_response_code == 200) {
                $api_status = "VALID";
                if($result->result_code == 101) {
                    $is_active = true;
                    $message = "Pan details fetched";

                    $this->helper_controller->store_app_log('create', "PAN verification successful for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                } else {
                    $is_active = false;
                    $message = isset($result->message) ? $result->message : 'Invalid pan data';

                    $this->helper_controller->store_app_log('create', "PAN verification failed for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                    DB::commit();

                    return $this->send_response('failed', $message, 422);
                }
                $result_response = [
                    'status' => 'success',
                    'is_active' => $is_active,
                    'result_code' => isset($result->result_code) ? $result->result_code : '',
                    'message' => $message,
                    'data' => [
                        'pan' => isset($result->result->pan) ? $result->result->pan : '',
                        'status' => isset($result->status) ? $result->status : 'success',
                        'status_code' => isset($result->result_code) ? $result->result_code : '',
                        'name' => isset($result->result->fullname) ? $result->result->fullname : '',
                        'dob' => isset($result->result->dob) ? $result->result->dob : '',
                        'name_match' => isset($result->result->name_match) ? $result->result->name_match : '',
                        'name_match_score' => isset($result->result->name_match_score) ? $result->result->name_match_score : '',
                    ],
                ];
            } else {
                $api_status = "INVALID";
                $result_response = [
                    'status' => 'failed',
                    'is_active' => false,
                    'message' => "No results",
                    'data' => [
                        'pan' => '',
                        'status' => '',
                        'status_code' => '',
                        'name' => '',
                        'dob' => '',
                        'name_match' => '',
                        'name_match_score' => '',
                    ],
                ];
                $this->helper_controller->store_app_log('create', "PAN verification failed for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
            }
            
            DB::commit();

            return response()->json($result_response, 200);
        } catch (Exception $e) {

            DB::rollBack();
            $result_response = [
                'status' => 'error',
                'message' => "Unknown error occurred", //$e->getMessage(),
                'data' => [],
            ];
            return response()->json($result_response, 422);
        }
    }

    /**
     * Credit score api
     * Created by: Mir Akib Javed
     * Date: 11-05-2024
     * DIGITAP [ not in use ]
     */
    // public function get_credit_score_experian(Request $request) {
    //     return $this->send_response("failed", "Api under maintanance", 422);
         
    //     $input      = $request->all();
    //     $user_id    = $request->user()->id;
    //     $errorArray = [];
    //     $errorCount = 0;
    //     $ip         = $this->get_user_ip();
    //     $client_ref_num = $this->generate_random_id();
    //     date_default_timezone_set('Asia/Kolkata');

    //     if($input == null) {
    //         return $this->send_response("failed", "Request body is empty", 422);
    //     } else {
    //         if (!isset($input['mobile_no']) || empty($input['mobile_no'])) {
    //             $errorCount++;
    //             $errorArray['mobile_no'] = "Mobile number is required";
    //             return $this->send_response("failed", $errorArray['mobile_no'], 422);
    //         } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $input['mobile_no'])) {
    //             $errorCount++;
    //             $errorArray['mobile_no'] = "Invalid mobile number format";
    //             return $this->send_response("failed", $errorArray['mobile_no'], 422);
    //         }

    //         $first_name = '';
    //         $last_name  = '';
    //         if (!isset($input['name']) || empty($input['name'])) {
    //             $errorCount++;
    //             $errorArray['name'] = "Name is required";
    //             return $this->send_response("failed", $errorArray['name'], 422);
    //         } else {
    //             $name_parts = explode(" ", $input['name']);
    //             $first_name = $name_parts[0];
    //             $last_name  = $name_parts[count($name_parts) - 1];
    //         }

    //         if (!isset($input['dob']) || empty($input['dob'])) {
    //             $errorCount++;
    //             $errorArray['dob'] = "Date of birth is required";
    //             return $this->send_response("failed", $errorArray['dob'], 422);
    //         } elseif (!strtotime($input['dob'])) {
    //             $errorCount++;
    //             $errorArray['dob'] = "Invalid date of birth";
    //             return $this->send_response("failed", $errorArray['dob'], 422);
    //         } else {
    //             // Calculate age
    //             $dobTimestamp = strtotime($input['dob']);
    //             $age = date('Y') - date('Y', $dobTimestamp);
    //             if (date('md') < date('md', $dobTimestamp)) {
    //                 $age--;
    //             }
    //             if ($age < 21) {
    //                 $errorCount++;
    //                 $errorArray['dob'] = "Age must be greater than 21";
    //                 return $this->send_response("failed", $errorArray['dob'], 422);
    //             }
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

    //         if (!isset($input['pan']) || empty($input['pan'])) {
    //             $errorCount++;
    //             $errorArray['pan'] = "Pan number is required";
    //             return $this->send_response("failed", $errorArray['pan'], 422);
    //         } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $input['pan'])) {
    //             $errorCount++;
    //             $errorArray['pan'] = "Invalid Pan number format";
    //             return $this->send_response("failed", $errorArray['pan'], 422);
    //         }

    //         if(!isset($input['consent_acceptance']) || empty($input['consent_acceptance'])) {
    //             $errorCount++;
    //             $errorArray['consent_acceptance'] = "Consent acceptance is required";
    //             return $this->send_response("failed", $errorArray['consent_acceptance'], 422);
    //         }
    //     }

    //     if ($errorCount > 0) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => "Invalid data",
    //             'error_list' => $errorArray
    //         ], 422);
    //     }

    //     $device_type = 'web';
    //     $jsonRequestBody = json_encode([
    //         "client_ref_num" => $client_ref_num,
    //         "mobile_no" => $input['mobile_no'],
    //         "name_lookup" => 0,
    //         "first_name" => $first_name,
    //         "last_name" => $last_name,
    //         "date_of_birth" => date('Y-m-d', strtotime($input['dob'])),
    //         "email" => $input['email'],
    //         "pan" => $input['pan'],
    //         "consent_message" => "You hereby consent to CLIENT being appointed as your authorized representative to receive your Credit Information from Experian for the purpose of (End Use Purpose).",
    //         "consent_acceptance" => $input['consent_acceptance'],
    //         "device_type" => $device_type,
    //         "otp" => $this->generate_random_number(4),
    //         "timestamp" => date('dmY-H:i:s'),
    //         "device_ip" => $ip,
    //     ]);

    //     $curl = curl_init();

    //     curl_setopt_array($curl, array(
    //     CURLOPT_URL => 'https://api.digitap.ai/credit_analytics/request',
    //     CURLOPT_RETURNTRANSFER => true,
    //     CURLOPT_ENCODING => '',
    //     CURLOPT_MAXREDIRS => 10,
    //     CURLOPT_TIMEOUT => 0,
    //     CURLOPT_FOLLOWLOCATION => true,
    //     CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    //     CURLOPT_CUSTOMREQUEST => 'POST',
    //     CURLOPT_POSTFIELDS => $jsonRequestBody,
    //     CURLOPT_HTTPHEADER => array(
    //         'Accept: application/json',
    //         'Authorization: Basic '.$this->prod_authorization_key,
    //         'Content-Type: application/json'
    //     ),
    //     ));

    //     $response = curl_exec($curl);

    //     curl_close($curl);
    //     // echo $response;
    //     // die();

    //     $result = json_decode($response);
        
    //     if(isset($result->http_response_code) && $result->http_response_code == 200) {
    //         $api_status = "VALID";
    //     } else {
    //         $api_status = "INVALID";
    //     }

    //     $vendorServiceApi               = new VendorServiceApi;
    //     $vendorServiceApi->user_id      = $user_id;
    //     $vendorServiceApi->ip_address   = $ip;
    //     $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
    //     $vendorServiceApi->status       = $api_status;
    //     $vendorServiceApi->api_flag     = "CREDIT_SCORE_API_DIGITAP_EXPERIAN";
    //     $vendorServiceApi->request      = base64_encode(serialize(json_encode($input)));
    //     $vendorServiceApi->response     = base64_encode(serialize($response));
    //     $borrower_id                    = $this->get_borrower_id($request->user()->id);
    //     $vendorServiceApi->borrower_id  = $borrower_id;
    //     $vendorServiceApi->save();

    //     if(isset($result->http_response_code) && $result->http_response_code == 200) {
    //         $api_status = "VALID";
    //         if($result->result_code == 101) {
    //             $message = "Pan details fetched";
    //         } elseif($result->result_code == 103) {
    //             $message = $result->message;
    //         } else {
    //             $message = "";
    //         }
    //         $result_response = [
    //             'status' => 'success',
    //             'result_code' => isset($result->result_code) ? $result->result_code : '',
    //             'message' => $message,
    //             'data' => [
    //                 'BureauScore' => $result->result->result_json->INProfileResponse->SCORE->BureauScore,
    //             ],
    //         ];
    //     } else {
    //         $api_status = "INVALID";
    //         $result_response = [
    //             'status' => 'failed',
    //             'message' => "No results",
    //             'data' => [
    //             ],
    //         ];
    //     }
    //     return response()->json($result_response, 200);
    // }

    /**
     * PAN OCR api
     * Created by: Mir Akib Javed
     * Date: 12-05-2024
     */
    public function pan_ocr_api(Request $request) {
        try {
            $input      = $request->all();
            $user_id    = $request->user()->id;
            $errorArray = [];
            $errorCount = 0;
            $ip         = $this->get_user_ip();
            $client_ref_num = $this->generate_random_id();
            date_default_timezone_set('Asia/Kolkata');

            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            } else {
       
                if (!isset($request->pan_image) || empty($request->pan_image)) {
                    return $this->send_response("failed", "Pan image is required", 422);
                }
        
                if (!$request->file('pan_image')->isValid()) {
                    return $this->send_response("failed", "Invalid pan image", 422);
                }
                
                $allowed_types = array("image/jpeg", "image/png", "image/jpg");
                
                if (!in_array($request->file('pan_image')->getMimeType(), $allowed_types)) {
                    return $this->send_response("failed", "Only JPG, PNG, and JPEG files are allowed", 422);
                }
                
                $max_size = 5 * 1024 * 1024; // 5 MB
                
                if ($request->file('pan_image')->getSize() > $max_size) {
                    return $this->send_response("failed", "Pan image size must not exceed 5 MB", 422);
                }
                
                if(!isset($input['user_type']) || empty($input['user_type'])) {
                    return $this->send_response("failed", "User type  is required", 422);
                } elseif(!in_array($input['user_type'], ["BORROWER", "COAPPLICANT", "GUARANTOR"])) {
                    return $this->send_response("failed", "User type can be only 'BORROWER' or 'COAPPLICANT' or 'GUARANTOR'", 422);
                }
                
                $borrower_id = $this->get_borrower_id($request->user()->id);

                DB::beginTransaction();
                $this->helper_controller->store_app_log('create', "PAN OCR verification api initiated for ". strtolower($input['user_type']), "BORROWER", $borrower_id);
                
                $user_type = $input['user_type'];
                
                // If validation passes, handle the file upload
                // Get the uploaded file
                $file = $request->file('pan_image');
                
                // Generate a unique file name
                $fileName = $this->generate_random_string(20) . '.' . $file->getClientOriginalExtension();

                // Store the uploaded file with the new file name in storage/app/public directory
                $path = $file->storeAs('public/pan_ocr/'.$user_type, $fileName);

                // Get the URL of the uploaded file
                $url = Storage::url($path);

                $clientRefId        = $this->generate_random_id(6);
                $isBlackWhiteCheck  = "yes";
                $confidence         = "yes";
                $fraudCheck         = "yes";

                // Prepare parameters for cURL request
                $localPath = $file->getPathname();

                // 'imageUrl' => new \CURLFile(public_path('storage/' . $path)),
                $jsonRequestBody = [
                    'imageUrl' => new \CURLFile($localPath),
                    'clientRefId' => $this->generate_random_id(6),
                    'isBlackWhiteCheck' => 'yes',
                    'confidence' => 'yes',
                    'fraudCheck' => 'yes',
                ];

                $curl = curl_init();

                curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://api.digitap.ai/ocr/v1/pan',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => $jsonRequestBody,
                    CURLOPT_HTTPHEADER => array(
                        'Authorization: Basic '.$this->prod_authorization_key
                    ),
                ));

                $response = curl_exec($curl);
                
                curl_close($curl);
                // echo $response;
                
                $result         = json_decode($response);
                // $borrower_id    = $this->get_borrower_id($request->user()->id);
                
                $status     = "failed";
                $message    = "Please upload valid pancard";
                $code       = 422;
                if(isset($result->status) && $result->status == "success" && isset($result->statusCode) && $result->statusCode == "200") {
                    $api_status = "VALID";
                } else {
                    $api_status = "INVALID";
                }
                
                $vendorServiceApi                   = new VendorServiceApi;
                $vendorServiceApi->user_id          = $user_id;
                $vendorServiceApi->ip_address       = $ip;
                $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
                $vendorServiceApi->status           = $api_status;
                $vendorServiceApi->api_flag         = "PAN_CARD_OCR";
                $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
                $vendorServiceApi->response         = base64_encode(serialize($response));
                $vendorServiceApi->borrower_id      = $borrower_id;
                $vendorServiceApi->applicant_type   = $input['user_type'];
                $vendorServiceApi->save();

                if(isset($result->status) && $result->status == "success" && isset($result->statusCode) && $result->statusCode == "200") {
                    if($result->result[0]->details->pan_no->conf > 90 && $result->result[0]->details->pan_no->value != '') {
                        // echo "file is ok";
                        $match_with = $result->result[0]->details->pan_no->value;
                        $verify_ocr_data = $this->ocr_details_match('Pan', $user_type, $match_with, $borrower_id);

                        if($verify_ocr_data === true) {
                            $status     = "success";
                            $api_status = "Valid";
                            $message    = "File uploaded successfully";
                            $code       = 200;

                            $this->helper_controller->store_app_log('create', "PAN OCR verification successful", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                        } else {
                            Storage::delete($path);
                            $status     = "failed";
                            $api_status = "Valid";
                            $message    = "OCR data did not match with user data";
                            $code       = 422;
                            $this->helper_controller->store_app_log('create', "PAN OCR data did not match with user data", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                        }
                    } else {
                        Storage::delete($path);
                        $status     = "failed";
                        $api_status = "Valid";
                        $message    = "Please upload valid pancard";
                        $code       = 422;
                        $this->helper_controller->store_app_log('create', "PAN OCR verification failed", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                    }
                } elseif(isset($result->status) && $result->status == 'failure' && isset($result->statusCode) && $result->statusCode == "501") {
                    Storage::delete($path);
                    $status     = "failed";
                    $api_status = "INVALID";
                    $message    = "Please upload valid pancard";
                    $code       = 422;
                    $this->helper_controller->store_app_log('create', "PAN OCR verification failed", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                } else {
                    Storage::delete($path);
                    $status     = "failed";
                    $api_status = "INVALID";
                    $message    = "Please upload valid pancard";
                    $code       = 422;

                    $this->helper_controller->store_app_log('create', "PAN OCR verification failed", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                }
                DB::commit();
                return $this->send_response($status, $message, $code);
            }
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred", 422);
        }
    }
    
    /**
     * Aadhar OCR api
     * Created by: Mir Akib Javed
     * Date: 12-05-2024
     */
    public function aadhar_ocr_api(Request $request) {
        try {
            $input      = $request->all();
            $user_id    = $request->user()->id;
            $errorArray = [];
            $errorCount = 0;
            $ip         = $this->get_user_ip();
            $client_ref_num = $this->generate_random_id();
            date_default_timezone_set('Asia/Kolkata');

            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            } else {

                $borrower_primary_key   = $this->get_borrower_id($request->user()->id);
                $loan_details           = LoanBasicDetails::find($borrower_primary_key);
                if(!$loan_details) {
                    return $this->send_response("failed", "Invalid request", 422);
                }
                if($loan_details->product_id == '4') {
                    $input['aadhar_type'] = 'AADHAR_FRONT';
                    // if(!isset($input['aadhar_type']) || empty($input['aadhar_type'])) {
                    // }
                }
                
                if (!isset($request->aadhar_image) || empty($request->aadhar_image)) {
                    return $this->send_response("failed", "Aadhar image is required", 422);
                }
                
                if (!$request->file('aadhar_image')->isValid()) {
                    return $this->send_response("failed", "Invalid aadhar_image image", 422);
                }
                
                $allowed_types = array("image/jpeg", "image/png", "image/jpg");
                
                if (!in_array($request->file('aadhar_image')->getMimeType(), $allowed_types)) {
                    return $this->send_response("failed", "Only JPG, PNG, and JPEG files are allowed", 422);
                }
                
                $max_size = 5 * 1024 * 1024; // 5 MB
                
                if ($request->file('aadhar_image')->getSize() > $max_size) {
                    return $this->send_response("failed", "Aadhar image size must not exceed 5 MB", 422);
                }
                
                if(!isset($input['user_type']) || empty($input['user_type'])) {
                    return $this->send_response("failed", "User type  is required", 422);
                } elseif(!in_array($input['user_type'], ["BORROWER", "COAPPLICANT", "GUARANTOR"])) {
                    return $this->send_response("failed", "User type can be only 'BORROWER' or 'COAPPLICANT' or 'GUARANTOR'", 422);
                }
                
                $borrower_id = $this->get_borrower_id($request->user()->id);

                DB::beginTransaction();
                $this->helper_controller->store_app_log('create', "Aadhar OCR verification api initiated for ". strtolower($input['user_type']), "BORROWER", $borrower_id);

                $user_type = $input['user_type'];
        
                // If validation passes, handle the file upload
                // Get the uploaded file
                $file = $request->file('aadhar_image');

                // Generate a unique file name
                $fileName = $this->generate_random_string(20) . '.' . $file->getClientOriginalExtension();

                // Store the uploaded file with the new file name in storage/app/public directory
                $path = $file->storeAs('public/aadhar_ocr/'.$user_type, $fileName);

                // Get the URL of the uploaded file
                $url = Storage::url($path);

                // Prepare parameters for cURL request
                $localPath = $file->getPathname();

                // 'imageUrl' => new \CURLFile(public_path('storage/' . $path)),
                $jsonRequestBody = [
                    'imageUrl' => new \CURLFile($localPath),
                    'clientRefId' => $this->generate_random_id(6),
                    'outputmaskedAadhaar' => 'yes',
                    'isBlackWhiteCheck' => 'yes',
                    'confidence' => 'yes',
                    'maskAadhaarNumber' => 'yes',
                    'fraudCheck' => 'yes'
                ];

                // print_r($jsonRequestBody);
                // die();

                $curl = curl_init();

                curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://api.digitap.ai/ocr/v1/aadhaar',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => $jsonRequestBody,
                    CURLOPT_HTTPHEADER => array(
                        'Authorization: Basic '. $this->prod_authorization_key
                    ),
                ));

                $response = curl_exec($curl);

                curl_close($curl);
                // echo $response;
                // die();
                $result = json_decode($response);
                
                $status         = "failed";
                $message        = "Please upload valid aadhar card";
                $code           = 422;
                // $borrower_id    = $this->get_borrower_id($request->user()->id);
                if(isset($result->status) && $result->status == "success" && isset($result->statusCode) && $result->statusCode == "200") {
                    $api_status     = "VALID";
                } else {
                    $api_status     = "INVALID";
                }

                $vendorServiceApi                   = new VendorServiceApi;
                $vendorServiceApi->user_id          = $user_id;
                $vendorServiceApi->ip_address       = $ip;
                $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
                $vendorServiceApi->status           = $api_status;
                $vendorServiceApi->api_flag         = "AADHAR_CARD_OCR";
                $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
                $vendorServiceApi->response         = base64_encode(serialize($response));
                // $borrower_id                        = $this->get_borrower_id($request->user()->id);
                $vendorServiceApi->borrower_id      = $borrower_id;
                $vendorServiceApi->applicant_type   = $input['user_type'];
                $vendorServiceApi->save();

                if(isset($result->status) && $result->status == "success" && isset($result->statusCode) && $result->statusCode == "200") {
                    if($result->result[0]->details->aadhaar->conf >= 90 && $result->result[0]->details->aadhaar->value!='') {
                        // echo "file is ok";
                        $match_with = $result->result[0]->details->name->value ?? ''; // user name in aadhar name

                        if($result->result[0]->type == 'aadhaar_back') {
                            $status     = "success";
                            $api_status = "VALID";
                            $message    = "File uploaded successfully";
                            $code       = 200;
                            
                            $this->helper_controller->store_app_log('create', "Aadhar OCR verification successful", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                        } else {
                            $verify_ocr_data = $this->ocr_details_match('Name', $user_type, $match_with, $borrower_id);
                            if($verify_ocr_data === true) {

                                $status     = "success";
                                $api_status = "VALID";
                                $message    = "File uploaded successfully";
                                $code       = 200;

                                $this->helper_controller->store_app_log('create', "Aadhar OCR verification successful", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                            } else {
                                Storage::delete($path);
                                $status     = "failed";
                                $api_status = "VALID";
                                $message    = "OCR data did not match with user data";
                                $code       = 422;

                                $this->helper_controller->store_app_log('create', "Aadhar OCR data did not match with user data", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                            }
                        }
                    } else {
                        Storage::delete($path);
                        $status     = "failed";
                        $api_status = "VALID";
                        $message    = "Please upload valid aadhar card";
                        $code       = 422;

                        $this->helper_controller->store_app_log('create', "Aadhar OCR verification failed", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                    }
                } elseif(isset($result->status) && $result->status == 'failure' && isset($result->statusCode) && $result->statusCode == "501") {
                    Storage::delete($path);
                    $status     = "failed";
                    $api_status = "INVALID";
                    $message    = isset($result->error) ? $result->error : "Please upload valid aadhar card";
                    $code       = 422;

                    $this->helper_controller->store_app_log('create', "Aadhar OCR verification failed", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                } else {
                    Storage::delete($path);
                    $status     = "failed";
                    $api_status = "INVALID";
                    $message    = "Please upload valid aadhar card";
                    $code       = 422;
                    $this->helper_controller->store_app_log('create', "Aadhar OCR verification failed", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                }

                DB::commit();
                return $this->send_response($status, $message, $code);
            }
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_reponse('failed', "Unknown error occurred", 422);
        }
    }

    /**
     * Driving Licence Verification api
     * Created by: Mir Akib Javed
     * Date: 12-05-2024
     */
    public function verify_driving_licence(Request $request) {
        try {
            $input      = $request->all();
            $user_id    = $request->user()->id;
            $errorArray = [];
            $errorCount = 0;
            $ip         = $this->get_user_ip();
            $client_ref_num = $this->generate_random_id(4);
            date_default_timezone_set('Asia/Kolkata');

            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            } else {
                $borrower_id = $this->get_borrower_id($request->user()->id);

                DB::beginTransaction();
                $this->helper_controller->store_app_log('create', "Driving licence verification initiated", "BORROWER", $borrower_id);

                if (!isset($input['dl_number']) || empty($input['dl_number'])) {
                    $errorCount++;
                    $errorArray['dl_number'] = "Driving license number is required";
                    return $this->send_response("failed", $errorArray['dl_number'], 422);
                } elseif (!preg_match('/^[A-Z]{2}[0-9]{13}$/', $input['dl_number'])) {
                    $errorCount++;
                    $errorArray['dl_number'] = "Invalid driving license number format";
                    return $this->send_response("failed", $errorArray['dl_number'], 422);
                }

                if (!isset($input['dob']) || empty($input['dob'])) {
                    $errorCount++;
                    $errorArray['dob'] = "Date of birth is required";
                    return $this->send_response("failed", $errorArray['dob'], 422);
                } elseif (!strtotime($input['dob'])) {
                    $errorCount++;
                    $errorArray['dob'] = "Invalid date of birth";
                    return $this->send_response("failed", $errorArray['dob'], 422);
                } else {
                    // Calculate age
                    $dobTimestamp = strtotime($input['dob']);
                    $age = date('Y') - date('Y', $dobTimestamp);
                    if (date('md') < date('md', $dobTimestamp)) {
                        $age--;
                    }
                    if ($age < 21) {
                        $errorCount++;
                        $errorArray['dob'] = "Age must be greater than 21";
                        return $this->send_response("failed", $errorArray['dob'], 422);
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

            $jsonRequestBody = json_encode([
                "client_ref_num" => $client_ref_num,
                "dl_number" => $input['dl_number'],
                "dob" => date('d/m/Y', strtotime($input['dob'])),
            ]);

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://svc.digitap.ai/validation/kyc/v1/dl',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonRequestBody,
                CURLOPT_HTTPHEADER => array(
                    'Authorization: '. $this->prod_authorization_key,
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            // echo $response;

            $result = json_decode($response);
            
            if(isset($result->http_response_code) && $result->http_response_code == 200) {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi               = new VendorServiceApi;
            $vendorServiceApi->user_id      = $user_id;
            $vendorServiceApi->ip_address   = $ip;
            $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
            $vendorServiceApi->status       = $api_status;
            $vendorServiceApi->api_flag     = "DL_VALIDATION_API";
            $vendorServiceApi->request      = base64_encode(serialize(json_encode($input)));
            $vendorServiceApi->response     = base64_encode(serialize($response));
            // $borrower_id                    = $this->get_borrower_id($request->user()->id);
            $vendorServiceApi->borrower_id  = $borrower_id;
            $vendorServiceApi->save();

            if(isset($result->http_response_code) && $result->http_response_code == 200) {
                $api_status = "VALID";
                if($result->result_code == 101) {
                    $is_valid = true;
                    $message = "Diriving licence details fetched";

                    $result_response = [
                        'status' => 'success',
                        'is_valid' => $is_valid,
                        'result_code' => isset($result->result_code) ? $result->result_code : '',
                        'message' => $message,
                        'data' => isset($result->result->details_of_driving_licence) ? $result->result->details_of_driving_licence : [],
                    ];

                    $this->helper_controller->store_app_log('create', "Driving licence verification successful", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                    DB::commit();
                    return response()->json($result_response, 200);
                } else {
                    $is_valid = false;
                    $message = isset($result->message) ? $result->message : "Licence verification failed";

                    $this->helper_controller->store_app_log('create', "Licence verification failed", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);

                    DB::commit();
                    return $this->helper_controller->send_reponse('failed', $message, 422);
                }

                // elseif($result->result_code == 102) {
                //     $is_valid = false;
                //     $message = isset($result->message) ? $result->message : "";
                // } elseif($result->result_code == 103) {
                //     $is_valid = false;
                // } 

            } else {
                $this->helper_controller->store_app_log('create', "Licence verification failed", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);

                DB::commit();
                return $this->helper_controller->send_reponse('failed', "Upload valid licence", 422);
            }
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred", 422);
        }
    }

    /**
     * IOT activation api
     * Created by: Mir Akib Javed
     */
    public function iot_activition_check(Request $request) {

        $input      = $request->all();
        $user_id    = $request->user()->id;
        $errorArray = [];
        $errorCount = 0;
        $ip         = $this->get_user_ip();
        $client_ref_num = $this->generate_random_id(4);
        $borrower_id = $this->get_borrower_id($request->user()->id);
        date_default_timezone_set('Asia/Kolkata');

        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        } else {

            DB::beginTransaction();
            $this->helper_controller->store_app_log('create', "IOT activation check initiated", "BORROWER", $borrower_id);


            if (!isset($input['vehicle_id']) || empty($input['vehicle_id'])) {
                $errorCount++;
                $errorArray['vehicle_id'] = "Vehicle id is required";
                return $this->send_response("failed", $errorArray['vehicle_id'], 422);
            }

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }
            $vehile_id = $input['vehicle_id'];

            try{
                $curl = curl_init();
                if ($curl === false) {
                    throw new Exception('Failed to initialize cURL');
                }
                curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://api.a.loconav.com/integration/api/v1/vehicles?deviceSerialNumber=' . $vehile_id,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                    CURLOPT_HTTPHEADER => array(
                        'User-Authentication: '.$this->loco_nav_auth_key
                    ),
                ));

                $response = curl_exec($curl);
                if ($response === false) {
                    throw new Exception('cURL Error: ' . curl_error($curl));
                }

                $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
                // echo $response;

                $result = json_decode($response);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('JSON decode error: ' . json_last_error_msg());
                }
                
                if(isset($result->success) && $result->success === true) {
                    $api_status = "VALID";
                } else {
                    $api_status = "INVALID";
                }

                $vendorServiceApi               = new VendorServiceApi;
                $vendorServiceApi->user_id      = $user_id;
                $vendorServiceApi->ip_address   = $ip;
                $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
                $vendorServiceApi->status       = $api_status;
                $vendorServiceApi->api_flag     = "IOT_VEHICLE_LIST_API";
                $vendorServiceApi->request      = json_encode($input);
                $vendorServiceApi->response     = $response;
                
                $vendorServiceApi->borrower_id  = $borrower_id;
                $vendorServiceApi->save();

                if(isset($result->data->vehicles[0]->vehicleUuid) && (count($result->data->vehicles) > 0) && !empty($result->data->vehicles[0]->vehicleUuid)) {
                    $vehicleUuid = $result->data->vehicles[0]->vehicleUuid;
                    $dataReq['vehicleUuid'] = $vehicleUuid;
                    $dataReq['user_id'] = $user_id;
                    $dataReq['borrower_id'] = $borrower_id;
                    $vehicle_details = $this->get_vehicle_iot_details($dataReq);
                    
                    if(isset($vehicle_details['status']) && $vehicle_details['status'] == 'success') {
                        $vehicle_status = "Active";
                        $message        = "IOT activated";
                        
                    } else {
                        $vehicle_status = "Inactive";
                        $message        = "IOT not activated";
                        $result_response = [
                            'status' => 'failed',
                            'message' => isset($vehicle_details['status']) ? $vehicle_details['status'] : $message,
                        ];

                        $this->helper_controller->store_app_log('create', "IOT not activated", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);

                        DB::commit();
                        return response()->json($result_response, 422);
                    }
                } else {
                    $this->helper_controller->store_app_log('create', "Invalid vechile id", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                    DB::commit();
                    return $this->send_response('failed', "Vehicle does not exist", 422);
                }

                if(isset($result->success) && $result->success === true) {
                    $application_step = 28;
                    $application_step = [
                        'step' => $application_step,
                        'vendor_service_api_id' => $vendorServiceApi->id,
                        'details' => "IOT Activation",
                        'status' => "COMPLETED",
                    ];
        
                    $this->updateApplicationStep($user_id, $borrower_id, $application_step);

                    $borrower_iot_details                   = new BorrowerIotDetails;
                    $borrower_iot_details->user_id          = $user_id;
                    $borrower_iot_details->borrower_id      = $borrower_id;
                    $borrower_iot_details->vehicle_id       = $vehile_id;
                    $borrower_iot_details->vehicle_uuid     = isset($vehicleUuid) ? $vehicleUuid : '';
                    $borrower_iot_details->status           = isset($result->success) ? $result->success : false;
                    $borrower_iot_details->api_response_id  = $vendorServiceApi->id;
                    $borrower_iot_details->save();

                    $result_response = [
                        'status' => 'success',
                        'message' => isset($message) ? $message : "IOT activated",
                    ];

                    $this->helper_controller->store_app_log('create', "IOT verification successful", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);

                    DB::commit();
                } else {
                    $result_response = [
                        'status' => 'failed',
                        'message' => isset($message) ? $message : "IOT not activated",
                    ];

                    $this->helper_controller->store_app_log('create', "IOT not activated", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                    
                    DB::commit();
                    return response()->json($result_response, 422);
                }

                return response()->json($result_response, 200);
            } catch (Exception $e) {
                DB::rollBack();
                
                $result_response = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'data' => [],
                ];
                return response()->json($result_response, 422);
            }
        }
    }

    private function get_vehicle_iot_details($dataReq) {
        $dataResponse = [];
        $user_id        = $dataReq['user_id'];
        $vehicleUuid    = $dataReq['vehicleUuid'];
        $borrower_id    = $dataReq['borrower_id'];
        $ip             = $this->get_user_ip();
        try{
            DB::beginTransaction();
            $this->helper_controller->store_app_log('create', "IOT get vehicles API initiated", "BORROWER", $borrower_id);

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }
            curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.a.loconav.com/integration/api/v1/vehicles/' . $vehicleUuid,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'User-Authentication: '.$this->loco_nav_auth_key
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }

            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            // echo $response;
            $result = json_decode($response);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }
            
            if(isset($result->success) && $result->success === true) {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi               = new VendorServiceApi;
            $vendorServiceApi->user_id      = $user_id;
            $vendorServiceApi->ip_address   = $ip;
            $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
            $vendorServiceApi->status       = $api_status;
            $vendorServiceApi->api_flag     = "IOT_VEHICLE_DETAILS_API";
            $vendorServiceApi->request      = json_encode($dataReq);
            $vendorServiceApi->response     = $response;
            $vendorServiceApi->borrower_id  = $borrower_id;
            $vendorServiceApi->save();

            if(isset($result->success) && $result->success === true) {
                $dataResponse = [
                    'status' => 'success'
                ];

                $this->helper_controller->store_app_log('create', "IOT vechile details fetched successfully", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
            } else {
                
                $dataResponse = [
                    'status' => 'failed'
                ];
                $this->helper_controller->store_app_log('create', "IOT vehicle details not found", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
            }

            DB::commit();
            return $dataResponse;

        } catch (Exception $e) {
            DB::rollBack();
            $dataResponse = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [],
            ];
            return $dataResponse;
        }
    }

    /**
     * Video Kyc Generate URL api
     * Created by: Mir Akib Javed
     */

    public function video_kyc_generate_url(Request $request) {
        $input              = $request->all();
        $user_id            = $request->user()->id;
        $errorArray         = [];
        $errorCount         = 0;
        $ip                 = $this->get_user_ip();
        $application_number = $this->generate_random_id();
        $currentStep        = 18;
        
        date_default_timezone_set('Asia/Kolkata');

        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        } else {
            $borrower_id = $this->get_borrower_id($request->user()->id);

            DB::beginTransaction();
            $this->helper_controller->store_app_log('create', "Video KYC url generation api initiated", "BORROWER", $borrower_id);

            if (!isset($input['mobile']) || empty($input['mobile'])) {
                $errorCount++;
                $errorArray['mobile'] = "Mobile number is required";
                return $this->send_response("failed", $errorArray['mobile'], 422);
            } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $input['mobile'])) {
                $errorCount++;
                $errorArray['mobile'] = "Invalid mobile number format";
                return $this->send_response("failed", $errorArray['mobile'], 422);
            }

            $first_name = '';
            $last_name  = '';
            if (!isset($input['name']) || empty($input['name'])) {
                $errorCount++;
                $errorArray['name'] = "Name is required";
                return $this->send_response("failed", $errorArray['name'], 422);
            } else {
                $name_parts = explode(" ", $input['name']);
                $first_name = $name_parts[0];
                $last_name  = $name_parts[count($name_parts) - 1];
            }

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }

            $jsonRequestBody = json_encode([
                "fname"=> $first_name,
                "lname"=> $last_name,
                "applicationNumber"=> $application_number,
                "mobile"=> $input['mobile'],
                "email"=> '',
                "skipOkyc"=> "TRUE",
                "sendSms"=> false,
                "agentEmailId"=> "",
                "randomAssignment"=> true
                // "randomAssignment"=> false
            ]);

            try{
                $curl = curl_init();
                if ($curl === false) {
                    throw new Exception('Failed to initialize cURL');
                }

                curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://api.digitap.ai/demo/v1/vkyc/okyc/user/activate',
                    // CURLOPT_URL => 'https://api.digitap.ai/demo/v1/vkyc/okyc/user/activate',
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
                        'ent_authorization: '.$this->prod_authorization_key,
                        'Content-Type: application/json'
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

                if(isset($result->code) && $result->code == 200) {
                    $api_status = "VALID";
                } else {
                    $api_status = "INVALID";
                }

                $vendorServiceApi               = new VendorServiceApi;
                $vendorServiceApi->user_id      = $user_id;
                $vendorServiceApi->ip_address   = $ip;
                $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
                $vendorServiceApi->status       = $api_status;
                $vendorServiceApi->api_flag     = "VKYC_GENERATE_URL_API";
                $vendorServiceApi->request      = json_encode($input);
                $vendorServiceApi->response     = $response;
                // $borrower_id                    = $this->get_borrower_id($request->user()->id);
                $vendorServiceApi->borrower_id  = $borrower_id;
                $vendorServiceApi->save();


                $result_response = [];
                if(isset($result->code) && $result->code == 200) {
                    $message = "Video KYC url generated";
                    $result_response = [
                        'status' => 'success',
                        'result_code' => isset($result->code) ? $result->code : '',
                        'message' => $message,
                        'data' => [
                            'application_number' => $application_number,
                            'url' => isset($result->model->url) ? $result->model->url : '',
                        ],
                    ];
                    
                    $this->helper_controller->store_app_log('create', "Video KYC url generated successfully", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);

                    // $borrower_id = $this->get_borrower_id($request->user()->id);

                    $vkyc_request_details                       = new VkycApiReqDetails;
                    $vkyc_request_details->user_id              = $user_id;
                    $vkyc_request_details->borrower_id          = $borrower_id;
                    $vkyc_request_details->application_number   = $application_number;
                    $vkyc_request_details->mobile               = $input['mobile'];
                    $vkyc_request_details->request              = json_encode($input);
                    $vkyc_request_details->vkyc_url             = isset($result->model->url) ? $result->model->url : '';
                    $vkyc_request_details->save();

                    try {
                        $mobile = '+91'. $input['mobile'];
                        $message = "Your Finayo VKYC application number is ". $application_number . " . Please click this link to start the video kyc process: ". $result->model->url;
                        $this->twilio->sendSms($mobile, $message);

                        $application_step = [
                            'step' => $currentStep,
                            'vkyc_request_details_id' => $vkyc_request_details->id,
                            'details' => "VKYC Generate URL",
                            'status' => "COMPLETED",
                        ];
    
                        $this->updateApplicationStep($user_id, $borrower_id, $application_step);
                        
                        $this->helper_controller->store_app_log('create', "VKYC url sent to ". $input['mobile'], "BORROWER", $borrower_id, ['vkyc_api_req_details' => $vkyc_request_details->id]);

                        DB::commit();
                        return response()->json([
                            'status' => 'success',
                            'result_code' => isset($result->code) ? $result->code : '',
                            'message' => "Video KYC url generated",
                            'data' => [
                                'application_number' => $application_number,
                                'url' => isset($result->model->url) ? $result->model->url : '',
                            ],
                        ], 200);
                    } catch (Exception $e) {
                        $this->helper_controller->store_app_log('create', "Failed to send SMS", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);

                        DB::commit();
                        return response()->json([
                            'status' => 'error',
                            'message' => $e->getMessage()
                        ], 422);
                    }

                } else {
                    $this->helper_controller->store_app_log('create', "Failed to generate VKYC url", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                    DB::commit();

                    $api_status = "INVALID";
                    $result_response = [
                        'status' => 'failed',
                        'message' => "No results",
                        'data' => [
                        ],
                    ];
                    return response()->json($result_response, 200);
                }
            } catch (Exception $e) {
                DB::rollBack();
                $result_response = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'data' => [],
                ];
                return response()->json($result_response, 200);
            }
        }
    }

    /**
     * VKYC get call status using unique ID
     */
    
    public function get_vkyc_status_by_application_number(Request $request) {
        $input      = $request->all();
        $user_id    = $request->user()->id;
        $errorArray = [];
        $errorCount = 0;
        $ip         = $this->get_user_ip();
        
        date_default_timezone_set('Asia/Kolkata');
        try {
            if($input == null){
                return $this->send_response("failed", "Request body is empty", 422);
            }
            $borrower_id = $this->get_borrower_id($request->user()->id);

            DB::beginTransaction();
            $this->helper_controller->store_app_log('create', "VKYC call details with application number, API initiated", "BORROWER", $borrower_id);

            if (!isset($input['application_number']) || empty($input['application_number'])) {
                $errorCount++;
                $errorArray['application_number'] = "Application number is required";
                return $this->send_response("failed", $errorArray['application_number'], 422);
            }

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }

            $jsonRequestBody = json_encode([
                "uniqueId" => $input['application_number']
            ]);
        
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api.digitap.ai/demo/v1/vkyc/additional-info/transaction/session-info-uniqueid',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonRequestBody,
                CURLOPT_HTTPHEADER => array(
                    'ent_authorization: '. $this->prod_authorization_key,
                    'Content-Type: application/json'
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

            $result_response = [];
            if(isset($result->code) && $result->code == 200) {
                $api_status = "VALID";
                $message = "Video KYC details fetched";
                $result_response = [
                    'status' => 'success',
                    'result_code' => isset($result->code) ? $result->code : '',
                    'message' => $message,
                    'data' => $result->model
                ];
            } else {
                $api_status = "INVALID";
                $message = isset($result->message) ? $result->message : 'No records exist for the given id';
                $result_response = [
                    'status' => 'failed',
                    'result_code' => isset($result->code) ? $result->code : '',
                    'message' => $message,
                ];
            }

            $vendorServiceApi               = new VendorServiceApi;
            $vendorServiceApi->user_id      = $user_id;
            $vendorServiceApi->ip_address   = $ip;
            $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
            $vendorServiceApi->status       = $api_status;
            $vendorServiceApi->api_flag     = "VKYC_CALL_DETAILS_USING_UNIQUE_ID_API";
            $vendorServiceApi->request      = json_encode($input);
            $vendorServiceApi->response     = $response;
            // $borrower_id                    = $this->get_borrower_id($request->user()->id);
            $vendorServiceApi->borrower_id  = $borrower_id;
            $vendorServiceApi->save();

            if(isset($result->model->vkycStatus) && $result->model->vkycStatus == "APPROVED") {
                $currentStep = 19;
                $application_step = [
                    'step' => $currentStep,
                    'vendor_service_api_id' => $vendorServiceApi->id,
                    'details' => "VKYC Status",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($user_id, $borrower_id, $application_step);

                $this->helper_controller->store_app_log('create', "Video KYC approved", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);

                DB::commit();
                return response()->json($result_response, 200);
            } else {
                $this->helper_controller->store_app_log('create', $message, "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                
                DB::commit();
                return response()->json($result_response, 422);
            }   
            
        } catch (Exception $e) {
            DB::rollBack();
            $result_response = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [],
            ];

            return response()->json($result_response, 422);
        }
    }

    /**
     * Vkyc get transaction details api
     * Created by: Mir Akib Javed
     */
    public function vkyc_transaction_details(Request $request) {
        $input      = $request->all();
        $user_id    = $request->user()->id;
        $errorArray = [];
        $errorCount = 0;
        $ip         = $this->get_user_ip();
        
        date_default_timezone_set('Asia/Kolkata');

        if($input == null){
            return $this->send_response("failed", "Request body is empty", 422);
        }
        $borrower_id = $this->get_borrower_id($request->user()->id);

        DB::beginTransaction();
        $this->helper_controller->store_app_log('create', "VKYC transaction details api initiated", "BORROWER", $borrower_id);

        if (!isset($input['transaction_id']) || empty($input['transaction_id'])) {
            $errorCount++;
            $errorArray['transaction_id'] = "Transaction id is required";
            return $this->send_response("failed", $errorArray['transaction_id'], 422);
        }

        if ($errorCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Invalid data",
                'error_list' => $errorArray
            ], 422);
        }

        $jsonRequestBody = json_encode([
            "transactionIds" => [
                $input['transaction_id']
            ]
        ]);
        
        try {
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api.digitap.ai/demo/v1/vkyc/additional-info/transaction/id-info',
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
                    'ent_authorization: '. $this->prod_authorization_key,
                    'Content-Type: application/json'
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

            if(isset($result->code) && $result->code == 200) {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi               = new VendorServiceApi;
            $vendorServiceApi->user_id      = $user_id;
            $vendorServiceApi->ip_address   = $ip;
            $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
            $vendorServiceApi->status       = $api_status;
            $vendorServiceApi->api_flag     = "VKYC_TRANSACTION_DETAILS_API";
            $vendorServiceApi->request      = json_encode($input);
            $vendorServiceApi->response     = $response;
            // $borrower_id                    = $this->get_borrower_id($request->user()->id);
            $vendorServiceApi->borrower_id  = $borrower_id;
            $vendorServiceApi->save();

            $result_response = [];
            if(isset($result->code) && $result->code == 200) {
                $api_status = "VALID";
                $message = "Video KYC details fetched";
                $result_response = [
                    'status' => 'success',
                    'result_code' => isset($result->code) ? $result->code : '',
                    'message' => $message,
                    'data' => $result->model->transactionDetailInformation
                ];

                $this->helper_controller->store_app_log('create', "Video KYC details fetched", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);

            } else {
                $api_status = "INVALID";
                $result_response = [
                    'status' => 'failed',
                    'message' => "No results",
                    'data' => [
                    ],
                ];

                $this->helper_controller->store_app_log('create', "Video KYC details not found", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
            }
            
            DB::commit();
            return response()->json($result_response, 200);
        } catch (Exception $e) {

            DB::rollBack();
            $result_response = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [],
            ];
            return response()->json($result_response, 422);
        }
    }

    /**
     * Vkyc store webhook response api
     * Created by: Mir Akib Javed
     */
    public function store_vkyc_data(request $request) {
        $input = $request->all();

        $authkey = '26904e1e46fccc97df08cf04d7ed5b43';
        $authToken = $request->header('authorization');

        if($authkey != $authToken) {
            return $this->send_response('error', "Invalid authorization key", 422);
        }

        if(!isset($input['transactionId']) || empty($input['transactionId'])){
            return $this->send_response("failed", "Transaction id is required", 422);
        }

        if(!isset($input['state']) || empty($input['state'])){
            return $this->send_response("failed", "State is required", 422);
        }

        if(!isset($input['status']) || empty($input['status'])){
            return $this->send_response("failed", "Status is required", 422);
        }

        if(!isset($input['uniqueId']) || empty($input['uniqueId'])){
            return $this->send_response("failed", "Unique id is required", 422);
        }

        $transactionId  = $input['transactionId'];
        $state          = $input['state'];
        $status         = $input['status'];
        $uniqueId       = $input['uniqueId'];

        $vkyc_web_response                  = new VkycWebResponse;
        $vkyc_web_response->transaction_id  = $transactionId; 
        $vkyc_web_response->state           = $state; 
        $vkyc_web_response->status          = $status; 
        $vkyc_web_response->unique_id       = $uniqueId; 
        // $vkyc_web_response->borrower_id =
        $vkyc_web_response->save();

        return $this->send_response('success', "Data stored successfully", 200);
    }

    /**
     * E-sign Api
     */
    public function generate_esign(Request $request) {
        try {
            $input              = $request->all();
            $user_id            = $request->user()->id;
            $errorArray         = [];
            $errorCount         = 0;
            $ip                 = $this->get_user_ip();
            date_default_timezone_set('Asia/Kolkata');
            $application_number = $this->generate_random_string(6);
            $unique_id = $application_number . date('dmY');
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
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

            if (!isset($request->city) || empty($request->city)) {
                $errorCount++;
                $errorArray['city'] = "City is required";
                return $this->send_response("failed", $errorArray['city'], 422);
            }

            if (!isset($request->mobile) || empty($request->mobile)) {
                $errorCount++;
                $errorArray['mobile'] = "Mobile number is required";
                return $this->send_response("failed", "Mobile number is required", 422);
            } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $request->mobile)) {
                $errorCount++;
                $errorArray['mobile'] = "Invalid mobile number format";
                return $this->send_response("failed", "Invalid mobile number format", 422);
            }

            if (!isset($request->name) || empty($request->name)) {
                $errorCount++;
                $errorArray['name'] = "Name is required";
                return $this->send_response("failed", $errorArray['name'], 422);
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

            // $web_controller = new WebController;
            $borrower_primary_key   = $this->get_borrower_id($request->user()->id);
            
            DB::beginTransaction();
            $this->helper_controller->store_app_log('create', "Esign url generation for " . strtolower($input['applicant_type']) . " initiated", "BORROWER", $borrower_primary_key);
            
            $loan_details           = LoanBasicDetails::find($borrower_primary_key);
            if(!$loan_details) {
                return $this->send_response("failed", $errorArray['borrower'], 422);
            }

            $borrower_id    = $loan_details->borrower_id;
            $product_id     = $loan_details->product_id;
            $skip_coapplicant_guarantor = $loan_details->skip_coapplicant_guarantor;


            $borrower_details = BorrowerKyc::where('borrower_id', $borrower_primary_key)->latest()->first();
            if(!$borrower_details) {
                return $this->send_response('failed', "Invalid request, borrower not found", 422);
            }
            $gender             = $borrower_details->gender;
            $skip_co_applicant  = $borrower_details->skip_co_applicant;
            $skip_guarantor     = $borrower_details->skip_guarantor;
            
            // $templateId = "ESIG28228601";
            if($input['applicant_type'] == "BORROWER") {
                // if($skip_coapplicant_guarantor === true) {
                //     // only borrower sign
                //     $templateId = 'ESIG28292223';
                // } elseif ($product_id == 4) {
                //     if($skip_guarantor === true) {
                //         // only borrower sign
                //         $templateId = 'ESIG28292223';
                //     } else {
                //         $templateId = 'ESIG28284955';
                //     }
                // } else {
                //     if($gender == 'Male' && $skip_co_applicant == true) {
                //         $templateId = 'ESIG28284955';
                //     } else {
                //         $templateId = 'ESIG28247910';
                //     }
                // }
                
                if ($skip_coapplicant_guarantor === true) {
                    $templateId = 'ESIG28292223';
                } elseif ($product_id == 4) {
                    $templateId = $skip_guarantor === true ? 'ESIG28292223' : 'ESIG28284955';
                } else {
                    $templateId = ($gender == 'Male' && $skip_co_applicant == true) ? 'ESIG28284955' : 'ESIG28247910';
                }

                // $currentStep = 21;
                $currentStep = 23;
                $data = $this->helper_controller->prepare_data_for_esign_doc($borrower_primary_key);
                $pdf = Pdf::loadView('pdf.agreement-2', $data);
                // $pdf = Pdf::loadView('pdf.agreement-2', $data)->setPaper('a4', 'portrait');
                $pdfPath = "e-sign/{$borrower_id}/".$input['applicant_type']."/agreement.pdf";
                Storage::disk('public')->put($pdfPath, $pdf->output());
                $filePath = storage_path('app/public/' . $pdfPath);
            }
            
            if($input['applicant_type'] == "COAPPLICANT") {
                $templateId = 'ESIG28258198';
                // $currentStep = 23;
                $currentStep = 25;
                $pdfPath = "e-sign/{$borrower_id}/COAPPLICANT/pre_signed_agreement.pdf";
                if (Storage::disk('public')->exists($pdfPath)) {
                    $filePath = storage_path('app/public/' . $pdfPath);
                } else {
                    $errorCount++;
                    $errorArray['esign_doc'] = "E-sign doc does not exist";
                    return $this->send_response("failed", $errorArray['esign_doc'], 422);
                }
            }
            
            if($input['applicant_type'] == "GUARANTOR") {
                // $currentStep = 25;
                $currentStep = 27;
                if($product_id == '4') {
                    if($skip_guarantor != true) {
                        $templateId = 'ESIG28225430';
                    }
                } else {
                    if($gender == 'Male' && $skip_co_applicant == true) {
                        $templateId = 'ESIG28225430';
                    } else {
                        $templateId = 'ESIG28234898';
                    }
                }

                $pdfPath = "e-sign/{$borrower_id}/GUARANTOR/pre_signed_agreement.pdf";
                if (Storage::disk('public')->exists($pdfPath)) {
                    $filePath = storage_path('app/public/' . $pdfPath);
                } else {
                    $errorCount++;
                    $errorArray['esign_doc'] = "E-sign doc does not exist";
                    return $this->send_response("failed", $errorArray['esign_doc'], 422);
                }
            }

            $file_name = "agreement.pdf";
            $jsonRequestBody = json_encode([
                "uniqueId" => $unique_id,
                "signers" => [
                    [
                        "email" => $input['email'],
                        "location" => $input['city'],
                        "mobile" => $input['mobile'],
                        "name" => $input['name']
                    ]
                ],
                "reason" => "Doc Testing",
                "templateId" => $templateId,
                "fileName" => $file_name,
                // "multiSignerDocId" => ""
            ]);

            // echo $jsonRequestBody;
            // die();

            // generate file upload url
            $get_esign_upload_url = $this->get_esign_upload_url($jsonRequestBody, $user_id, $input['applicant_type']);
            if(!isset($get_esign_upload_url) || $get_esign_upload_url['status'] !== true) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Unable to generate upload url'
                ], 422);
            }

            $file_path  = $filePath;
            $doc_id     = $get_esign_upload_url['doc_id'];
            $upload_url = $get_esign_upload_url['upload_url'];

            // Upload file to generate e-sign url
            $upload_doc = $this->upload_file_to_prepare_esign_doc($file_path, $doc_id, $upload_url, $user_id, $input['applicant_type']);

            if(isset($upload_doc) && $upload_doc['status'] === true) {
                $docId      = $upload_doc['docId'];
                $e_sign_url = $upload_doc['e_sign_url'];

                $data = [
                    'doc_id' => $docId,
                    'e_sign_url' => $e_sign_url,
                ];

                try {
                    $mobile = '+91'. $input['mobile'];
                    $message = "Your Finayo esign document id ". $docId . " . Please click this link to start the e-sign process: ". $e_sign_url;
                    $this->twilio->sendSms($mobile, $message);

                    $application_step = [
                        'step' => $currentStep,
                        'esign_id' => 0, // $loan_basic_details->id,
                        'details' => $input['applicant_type']. " Esign Generate URL",
                        'status' => "COMPLETED",
                    ];

                    $this->updateApplicationStep($user_id, $borrower_primary_key, $application_step);

                    $this->helper_controller->store_app_log('create', "E-sign url sent to ". $mobile, "BORROWER", $borrower_primary_key);                    

                    DB::commit();
                    return response()->json([
                        'status' => 'success',
                        'message' => 'Esign url generated',
                        'data' => $data
                    ], 200);
                } catch (Exception $e) {
                    $this->helper_controller->store_app_log('create', "Failed to sent e-sign url". $mobile, "BORROWER", $borrower_primary_key);

                    DB::commit();
                    return response()->json([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ], 422);
                }
                
            } else {
                $this->helper_controller->store_app_log('create', "Failed generate e-sign url". $mobile, "BORROWER", $borrower_id);
                
                DB::commit();
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Failed to generate Esign url',
                ], 422);
            }
        } catch (Exception $e) {
            DB::commit();
            return $this->send_response('failed', $e->getMessage(), 422);
        }
    }

    /**
     * Get Esign File Upload Url API
     */
    private function get_esign_upload_url($jsonRequestBody, $user_id, $applicant_type) {
        // Generate Upload URL Request
        $ip = $this->get_user_ip();
        date_default_timezone_set('Asia/Kolkata');

        $borrower_id = $this->get_borrower_id($user_id);

        DB::beginTransaction();
        $this->helper_controller->store_app_log('create', "E-sign file upload url generation for ". strtolower($applicant_type)." initiated", "BORROWER", $borrower_id);
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.digitap.ai/ent/v1/generate-esign',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonRequestBody,
            CURLOPT_HTTPHEADER => array(
                'Authorization: '. $this->prod_authorization_key,
                'Content-Type: application/json'
            ),
        ));
        
        $response = curl_exec($curl);
        
        curl_close($curl);
        // echo $response;
        // die();
        $result = json_decode($response);
        if(isset($result->code) && $result->code == 200) {
            $api_status         = 'VALID';
        } else {
            $api_status         = 'INVALID';
        }
        $vendorServiceApi                   = new VendorServiceApi;
        $vendorServiceApi->user_id          = $user_id;
        $vendorServiceApi->ip_address       = $ip;
        $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
        $vendorServiceApi->status           = $api_status;
        $vendorServiceApi->api_flag         = "GENERATE_ESIGN_FILE_UPLOAD_URL";
        $vendorServiceApi->request          = $jsonRequestBody;
        $vendorServiceApi->response         = $response;
        $vendorServiceApi->borrower_id      = $borrower_id;
        $vendorServiceApi->applicant_type   = $applicant_type;
        $vendorServiceApi->save();

        if(isset($result->code) && $result->code == 200) {
            $api_status         = 'VALID';
            $doc_id             = $result->model->docId;
            $file_upload_url    = $result->model->url;

            $data = [
                'status' => true,
                'doc_id' => $doc_id,
                'upload_url' => $file_upload_url
            ];

            $this->helper_controller->store_app_log('create', "E-sign file upload url generated", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
        } else {
            $this->helper_controller->store_app_log('create', "Failed to generate file upload url", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
            $api_status = 'INVALID';
            $data = [
                'status' => false
            ];
        }

        DB::commit();
        return $data;
    }

    /**
     * Upload File to prepare esign document
     */
    private function upload_file_to_prepare_esign_doc($file_path, $doc_id, $upload_url, $user_id, $applicant_type) {
        $ip = $this->get_user_ip();
        date_default_timezone_set('Asia/Kolkata');

        $borrower_id = $this->get_borrower_id($user_id);

        DB::beginTransaction();
        $this->helper_controller->store_app_log('create', "Upload agreement for ". strtolower($applicant_type)." esign, api initiated", "BORROWER", $borrower_id);

        $curl = curl_init();

        $data_input = json_encode([
            'file_path' => $file_path,
            'doc_id' => $doc_id,
            'upload_url' => $upload_url
        ]);

        curl_setopt_array($curl, array(
            CURLOPT_URL => $upload_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => [
                '' => new CURLFile($file_path)
            ],
        ));

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if($httpCode === 200) {
            $api_status         = 'VALID';

            $sign_url = 'https://sdk.digitap.ai/e-sign/templateesignprocess.html?docId='. $doc_id;

            $data = [
                'status' => true,
                'httpCode' => $httpCode,
                'docId' => $doc_id,
                'e_sign_url' => $sign_url
            ];
            
            $this->helper_controller->store_app_log('create', "E-sign url generated successfully", "BORROWER", $borrower_id);
        } else {
            $api_status         = 'INVALID';
            $data = [
                'status' => false,
                'httpCode' => $httpCode,
                'docId' => $doc_id
            ];
            $this->helper_controller->store_app_log('create', "Failed to generate E-sign url", "BORROWER", $borrower_id);
        }

        $vendorServiceApi                   = new VendorServiceApi;
        $vendorServiceApi->user_id          = $user_id;
        $vendorServiceApi->ip_address       = $ip;
        $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
        $vendorServiceApi->status           = $api_status;
        $vendorServiceApi->api_flag         = "GENERATE_ESIGN_URL";
        $vendorServiceApi->request          = $data_input;
        $vendorServiceApi->response         = json_encode($data);
        // $borrower_id                        = $this->get_borrower_id($user_id);
        $vendorServiceApi->borrower_id      = $borrower_id;
        $vendorServiceApi->applicant_type   = $applicant_type;
        $vendorServiceApi->save();

        DB::commit();
        return $data;
    }

    public function download_esign_doc_old(Request $request) {
        try {
            $input              = $request->all();
            $user_id            = $request->user()->id;
            $errorArray         = [];
            $errorCount         = 0;
            $ip                 = $this->get_user_ip();

            date_default_timezone_set('Asia/Kolkata');
            $application_number = $this->generate_random_string(6);
            $unique_id          = $application_number . date('dmY');

            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            }

            if (!isset($input['doc_id']) || empty($input['doc_id'])) {
                $errorCount++;
                $errorArray['doc_id'] = "Doc id is required";
                return $this->send_response("failed", $errorArray['doc_id'], 422);
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
            
            $doc_id             = $input['doc_id'];
            $applicant_type     = $input['applicant_type'];
            $jsonRequestBody    = json_encode([
                'docId' => $doc_id
            ]);

            $borrower_primary_key   = $this->get_borrower_id($request->user()->id);
            $loan_details           = LoanBasicDetails::find($borrower_primary_key);
            if(!$loan_details) {
                return $this->send_response("failed", "Loan details not found", 422);
            }
            $product_id = $loan_details->product_id;

            $borrower_details = BorrowerKyc::where('borrower_id', $borrower_primary_key)->latest()->first();
            if(!$borrower_details) {
                return $this->send_response('failed', "Invalid request, borrower not found", 422);
            }
            $gender                = $borrower_details->gender;
            $skip_co_applicant     = $borrower_details->skip_co_applicant;
            $skip_guarantor        = $borrower_details->skip_guarantor;
            $borrower_id           = $loan_details->borrower_id;

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api.digitap.ai/ent/v1/get-esign-doc',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonRequestBody,
                CURLOPT_HTTPHEADER => array(
                    'Authorization: '. $this->prod_authorization_key,
                    'Accept: application/json',
                    'Content-Type: application/json'
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

            if(isset($result->code) && $result->code == 200) {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = $user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "DOWNLOAD_ESIGN_DOCUMENT";
            $vendorServiceApi->request          = json_encode($input);
            $vendorServiceApi->response         = $response;
            $vendorServiceApi->borrower_id      = $borrower_primary_key;
            $vendorServiceApi->applicant_type   = $applicant_type;
            $vendorServiceApi->save();

            $result_response = [];
            if(isset($result->code) && $result->code == 200) {
                $api_status = "VALID";
                
                if(isset($result->model->signers[0]->state) && $result->model->signers[0]->state == 'signed') {
                    
                    if($result->model->signers[0]->name != $result->model->signers[0]->signerName) {
                        $result_response = [
                            'status' => 'failed',
                            'message' => "Please sign the document with your original aadhar"
                        ];
                        return response()->json($result_response, 422);
                    }

                    $url = $result->model->url;
                    $result_response = [
                        'status' => 'success',
                        'message' => "Signed document fetched",
                        'data' => [
                            'signed_doc_url' => $url,
                            'file_name' =>  $result->model->fileName,
                        ],
                    ];

                    $fileContents = file_get_contents($url);

                    // Generate a filename
                    $filename = basename($url);

                    // Store the file in the storage (public directory in this example)
                    if($applicant_type == "BORROWER") {
                        // $currentStep = 22;
                        $currentStep = 24;
                        if($gender != 'Male' && $skip_co_applicant == false) {

                            $path = "public/e-sign/{$borrower_id}/COAPPLICANT/pre_signed_agreement.pdf";
                            Storage::put($path, $fileContents);
                        } elseif($product_id == '4' && $skip_guarantor == true) {
                            $path = "public/e-sign/{$borrower_id}/BORROWER/final_signed_agreement.pdf";
                            Storage::put($path, $fileContents);

                        } else {
                            $path = "public/e-sign/{$borrower_id}/GUARANTOR/pre_signed_agreement.pdf";
                            Storage::put($path, $fileContents);
                        }
                    } elseif($applicant_type == "COAPPLICANT") {
                        // $currentStep = 24;
                        $currentStep = 26;

                        $path = "public/e-sign/{$borrower_id}/GUARANTOR/pre_signed_agreement.pdf";
                        Storage::put($path, $fileContents);
                    } elseif($applicant_type == "GUARANTOR") {
                        // $currentStep = 26;
                        $currentStep = 28;

                        $path = "public/e-sign/{$borrower_id}/BORROWER/final_signed_agreement.pdf";
                        Storage::put($path, $fileContents);
                    }

                    $directory = dirname(storage_path("app/{$path}"));
                    @chmod($directory, 0755);
                    @chmod(storage_path("app/{$path}"), 0644);
                    
                    $application_step = [
                        'step' => $currentStep,
                        'esign_id' => 0,
                        'details' => $applicant_type . " E-sign Status",
                        'status' => "COMPLETED",
                    ];
        
                    $this->updateApplicationStep($user_id, $borrower_primary_key, $application_step);
                    
                    return response()->json($result_response, 200);
                } else {
                    $result_response = [
                        'status' => 'failed',
                        'message' => "Document not signed yet"
                    ];
                    return response()->json($result_response, 200);
                }

            } else {
                $api_status = "INVALID";
                $result_response = [
                    'status' => 'failed',
                    'message' => "No results",
                    'data' => [
                    ],
                ];
            }

            return response()->json($result_response, 200);
        } catch (Exception $e) {
            return $this->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function get_credit_score_details(Request $request) {
        $input              = $request->all();
        $user_id            = $request->user()->id;
        $errorArray         = [];
        $errorCount         = 0;
        $ip                 = $this->get_user_ip();

        date_default_timezone_set('Asia/Kolkata');
        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
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

        $first_name = '';
        $last_name  = '';
        if (!isset($input['name']) || empty($input['name'])) {
            $errorCount++;
            $errorArray['name'] = "Name is required";
            return $this->send_response("failed", $errorArray['name'], 422);
        } else {

            $input['name'] = str_replace(".","",$input['name']);
            $name_parts = explode(" ", $input['name']);
            $first_name = $name_parts[0];
            $last_name  = $name_parts[count($name_parts) - 1];
        }

        if (!isset($input['dob']) || empty($input['dob'])) {
            $errorCount++;
            $errorArray['dob'] = "Date of birth is required";
            return $this->send_response("failed", $errorArray['dob'], 422);
        } elseif (!strtotime($input['dob'])) {
            $errorCount++;
            $errorArray['dob'] = "Invalid date of birth";
            return $this->send_response("failed", $errorArray['dob'], 422);
        } else {
            // Calculate age
            $dobTimestamp = strtotime($input['dob']);
            $age = date('Y') - date('Y', $dobTimestamp);
            if (date('md') < date('md', $dobTimestamp)) {
                $age--;
            }
            if ($age < 21) {
                $errorCount++;
                $errorArray['dob'] = "Age must be within 21 to 55";
                return $this->send_response("failed", $errorArray['dob'], 422);
            }

            if ($age > 55) {
                $errorCount++;
                $errorArray['dob'] = "Age must be within 21 to 55";
                return $this->send_response("failed", $errorArray['dob'], 422);
            }
        }

        if (!isset($input['pan']) || empty($input['pan'])) {
            $errorCount++;
            $errorArray['pan'] = "Pan number is required";
            return $this->send_response("failed", $errorArray['pan'], 422);
        } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $input['pan'])) {
            $errorCount++;
            $errorArray['pan'] = "Invalid Pan number format";
            return $this->send_response("failed", $errorArray['pan'], 422);
        }

        if(!isset($input['pin']) || empty($input['pin'])) {
            $errorCount++;
            $errorArray['pin'] = "City is required";
            return $this->send_response("failed", "Pin code is required", 422);
        } elseif (!preg_match('/^[0-9]{6}$/', $input['pin'])) {
            $errorCount++;
            $errorArray['pin'] = "Invalid Pin code format";
            return $this->send_response("failed", $errorArray['pin'], 422);
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

        $borrower_id = $this->get_borrower_id($request->user()->id); 

        DB::beginTransaction();
        $this->helper_controller->store_app_log('create', "Credit details api initiated for ". strtolower($input['applicant_type']), "BORROWER", $borrower_id);

        if($input['applicant_type'] == 'COAPPLICANT') {
            $borrower_kyc_details = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
            if($borrower_kyc_details) {
                $skip_co_applicant  = $borrower_kyc_details->skip_co_applicant;
                if($skip_co_applicant === true) {
                    $input['applicant_type'] = 'GUARANTOR';
                }
            }
        }

        $phone  = $input['mobile'];
        $pan    = $input['pan'];
        $pin    = $input['pin'];
        $dob    = date('Y-m-d', strtotime($input['dob']));
        $applicant_type = $input['applicant_type'];

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
                "consentIpAddress" => $ip,
                "consentMessageId" => "CM_1"
            ],
        ]);
        
        // "consent" => [
        //     "consentFlag" => true,
        //     "consentTimestamp" => strtotime(date('Y-m-d')),
        //     "consentIpAddress" => $ip
        // ]

        $result_response = [];

        try{
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                // CURLOPT_URL => 'https://api-preproduction.signzy.app/api/v3/bureau/experian-report',
                CURLOPT_URL => 'https://api.signzy.app/api/v3/bureau/experian-report',
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
            } elseif(isset($result->error) && isset($result->error->type) && $result->error->type == 'Not Found') {
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
            $vendorServiceApi->request      = json_encode($input);
            $vendorServiceApi->response     = $response;
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $input['applicant_type'];
            $vendorServiceApi->save();

            if(isset($result->statusCode) && $result->statusCode == 200) {
                $api_status = "VALID";
                if(isset($result->data->jsonExperianReport->CAIS_Account->CAIS_Account_DETAILS[0]->CAIS_Account_History) && !empty($result->data->jsonExperianReport->CAIS_Account->CAIS_Account_DETAILS[0]->CAIS_Account_History)) {

                    // $dpds = $result->data->jsonExperianReport->CAIS_Account->CAIS_Account_DETAILS[0]->CAIS_Account_History;
                    
                    $account_details = $result->data->jsonExperianReport->CAIS_Account->CAIS_Account_DETAILS;
                    $dpd_data = $this->calculate_dpds($account_details);
                }
                if(isset($result->data->jsonExperianReport->CAIS_Account->CAIS_Account_DETAILS[0]->SuitFiledWillfulDefaultWrittenOffStatus) && !empty($result->data->jsonExperianReport->CAIS_Account->CAIS_Account_DETAILS[0]->SuitFiledWillfulDefaultWrittenOffStatus)) {
                    $is_willful_defaulter = true;
                } else {
                    $is_willful_defaulter = false;
                }
                
                $message = '';
                $bureau_excel_report = $result->data->excelExperianReport;
                $fileContents = file_get_contents($bureau_excel_report);
                $update_loan_details = LoanBasicDetails::find($borrower_id);

                if($update_loan_details) {
                    $borrower_finayo_id = $update_loan_details->borrower_id;
                }

                if($applicant_type == 'BORROWER') {
                    $path = "public/bureau_report/{$borrower_finayo_id}/BORROWER/bureau-report-{$borrower_finayo_id}-borrower.xlsx";
                    Storage::put($path, $fileContents);

                    $message = "Right now you're not eligible for any loan, try again after few months";
                } elseif($applicant_type == 'COAPPLICANT') {
                    $path = "public/bureau_report/{$borrower_finayo_id}/COAPPLICANT/bureau-report-{$borrower_finayo_id}-coapplicant.xlsx";
                    Storage::put($path, $fileContents);

                    $message = "Unable to proceed, please change the co-applicant and try again";
                } elseif($applicant_type == 'GUARANTOR') {
                    $path = "public/bureau_report/{$borrower_finayo_id}/GUARANTOR/bureau-report-{$borrower_finayo_id}-guarantor.xlsx";
                    Storage::put($path, $fileContents);

                    $message = "Unable to proceed, please change the guarantor and try again";
                }

                if(isset($path)) {
                    $directory = dirname(storage_path("app/{$path}"));
                    @chmod($directory, 0755);
                    @chmod(storage_path("app/{$path}"), 0644);
                }
                
                $credit_score = $result->data->jsonExperianReport->SCORE->FCIREXScore;
                if($credit_score < 650) {
                    $status = 'failed';
                    if($applicant_type == 'BORROWER') {
                        $update_loan_details = LoanBasicDetails::find($borrower_id);
                        if($update_loan_details) {
                            $update_loan_details->status = 'REJECTED';
                            $update_loan_details->save();
                        }
                    } 

                    $loan_application_summary_details = LoanApplicationSummary::where('user_id', $user_id)
                                                    ->where('borrower_id', $borrower_id)
                                                    ->first();
                    if($applicant_type == 'BORROWER') {
                        $loan_application_summary_details->cibil_score = $credit_score;
                        $loan_application_summary_details->dpd_30_in_3_month = isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0 ; 
                        $loan_application_summary_details->dpd_90_in_1_year = isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0; 
                        $loan_application_summary_details->is_willful_defaulter = $is_willful_defaulter;
                                                            
                    } elseif($applicant_type == 'COAPPLICANT') {
                        $loan_application_summary_details->coappli_cibil_score = $credit_score;
                        $loan_application_summary_details->coappli_dpd_30 = isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0 ; 
                        $loan_application_summary_details->coappli_dpd_90 = isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0; 
                        $loan_application_summary_details->coappli_defaulter = $is_willful_defaulter;

                    } elseif($applicant_type == 'GUARANTOR') {
                        $loan_application_summary_details->guarantor_cibil_score = $credit_score;
                        $loan_application_summary_details->guarantor_dpd_30 = isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0 ; 
                        $loan_application_summary_details->guarantor_dpd_90 = isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0; 
                        $loan_application_summary_details->guarantor_defaulter = $is_willful_defaulter;

                    }
                    $loan_application_summary_details->save();

                    $result_response = [
                        'status' => $status,
                        'result_code' => isset($result->statusCode) ? $result->statusCode : '',
                        'message' => $message,
                        'data' => [
                            'BureauScore' => $credit_score,
                            'is_willful_defaulter' => $is_willful_defaulter ?? false,
                            'dpd_in_3_month' => isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0,
                            'dpd_in_1_year' => isset($dpd_data['dpd_in_1_year']) ? $dpd_data['dpd_in_1_year'] : 0,
                        ],
                    ];

                    $this->helper_controller->store_app_log('create', "File rejected due to low credit score", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                    
                    DB::commit();
                    
                    return response()->json($result_response, 422);

                } elseif($is_willful_defaulter == true) {
                    $status = 'failed';
                    $code = 422;
                } elseif(isset($dpd_data['dpd_in_3_month']) && $dpd_data['dpd_in_3_month'] > 1) {
                    $status = 'failed';
                } elseif(isset($dpd_data['dpd_in_1_year']) && $dpd_data['dpd_in_1_year'] > 0) {
                    $status = 'failed';
                    $code = 422;
                } else {
                    $status = 'success';
                    $message = "Credit details fetched";
                    $code = 200;
                }
                
                $loan_application_summary_details = LoanApplicationSummary::where('user_id', $user_id)
                                                    ->where('borrower_id', $borrower_id)
                                                    ->first();
                if($applicant_type == 'BORROWER') {
                    $loan_application_summary_details->cibil_score = $credit_score;
                    $loan_application_summary_details->dpd_30_in_3_month = isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0 ; 
                    $loan_application_summary_details->dpd_90_in_1_year = isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0; 
                    $loan_application_summary_details->is_willful_defaulter = $is_willful_defaulter;
                                                        
                } elseif($applicant_type == 'COAPPLICANT') {
                    $loan_application_summary_details->coappli_cibil_score = $credit_score;
                    $loan_application_summary_details->coappli_dpd_30 = isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0 ; 
                    $loan_application_summary_details->coappli_dpd_90 = isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0; 
                    $loan_application_summary_details->coappli_defaulter = $is_willful_defaulter;

                } elseif($applicant_type == 'GUARANTOR') {
                    $loan_application_summary_details->guarantor_cibil_score = $credit_score;
                    $loan_application_summary_details->guarantor_dpd_30 = isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0 ; 
                    $loan_application_summary_details->guarantor_dpd_90 = isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0; 
                    $loan_application_summary_details->guarantor_defaulter = $is_willful_defaulter;

                }
                $loan_application_summary_details->save();

                $result_response = [
                    'status' => $status,
                    'result_code' => isset($result->statusCode) ? $result->statusCode : '',
                    'message' => $message,
                    'data' => [
                        'BureauScore' => $credit_score,
                        'is_willful_defaulter' => $is_willful_defaulter,
                        'dpd_in_3_month' => isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0,
                        'dpd_in_1_year' => isset($dpd_data['dpd_in_1_year']) ? $dpd_data['dpd_in_1_year'] : 0,
                    ],
                ];

                $this->helper_controller->store_app_log('create', "Credit details fetched successfully", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);

            } elseif(isset($result->error) && isset($result->error->type) && $result->error->type == 'Not Found') {
                //  && $applicant_type != 'GUARANTOR'
                $api_status = "VALID";
                $status = 'success';
                $message = "No credit history";

                $loan_application_summary_details = LoanApplicationSummary::where('user_id', $user_id)
                                                    ->where('borrower_id', $borrower_id)
                                                    ->first();
                if($applicant_type == 'BORROWER') {
                    $loan_application_summary_details->cibil_score = 0;
                    $loan_application_summary_details->dpd_30_in_3_month =  0 ; 
                    $loan_application_summary_details->dpd_90_in_1_year =  0; 
                    $loan_application_summary_details->is_willful_defaulter = false;
                                                        
                } elseif($applicant_type == 'COAPPLICANT') {
                    $loan_application_summary_details->coappli_cibil_score = 0;
                    $loan_application_summary_details->coappli_dpd_30 = 0; 
                    $loan_application_summary_details->coappli_dpd_90 = 0; 
                    $loan_application_summary_details->coappli_defaulter = false;

                } elseif($applicant_type == 'GUARANTOR') {
                    $loan_application_summary_details->guarantor_cibil_score = 0;
                    $loan_application_summary_details->guarantor_dpd_30 = 0; 
                    $loan_application_summary_details->guarantor_dpd_90 = 0;
                    $loan_application_summary_details->guarantor_defaulter = false;

                }

                $loan_application_summary_details->save();

                $result_response = [
                    'status' => $status,
                    'result_code' => isset($result->error->statusCode) ? $result->error->statusCode : '',
                    'message' => $message,
                    'data' => [
                        'BureauScore' => 0,
                        'is_willful_defaulter' => false,
                        'dpd_in_3_month' => 0,
                        'dpd_in_1_year' => 0,
                    ],
                ];
                $this->helper_controller->store_app_log('create', "No credit history", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
                $code = 200;

            } else {
                $code = 200;
                $api_status = "INVALID";
                $result_response = [
                    'status' => 'failed',
                    'message' => isset($result->message) ? $result->message : "Credit history not found",
                ];

                $this->helper_controller->store_app_log('create', "No credit history", "BORROWER", $borrower_id, ['vendor_service_apis' => $vendorServiceApi->id]);
            }
            
            DB::commit();
            return response()->json($result_response, $code);
            
        } catch (Exception $e) {
            DB::rollBack();
            $result_response = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            return response()->json($result_response, 422);
        }
    }

    public function calculate_dpds($account_details) {
        // code enabled on 24-02-2025
        $dpd_in_3_month = 0;
        $dpd_in_1_year  = 0;
        $dpd_in_2_year  = 0;
        $dpd_in_3_year  = 0;

        $year_wise_dpd = []; // Example: ['2022' => 10, '2023' => 6, '2024' => 3]

        date_default_timezone_set('Asia/Kolkata');
        $currentYear  = date('Y');
        $currentMonth = date('m');

        foreach ($account_details as $account_history) {
            $dpds = $account_history->CAIS_Account_History;
            foreach ($dpds as $entry) {
                $year  = (int) $entry->Year;
                $month = (int) ($entry->Month ?? 0);
                $dpd   = (int) $entry->Days_Past_Due;

                // Skip invalid entries
                if (!$year || !$month) {
                    continue;
                }

                // Calculate the difference in months from the current month and year
                $monthsDifference = ($currentYear - $year) * 12 + ($currentMonth - $month);

                // Only consider the entries within the last 36 months (3 years)
                if ($monthsDifference >= 0 && $monthsDifference <= 36) {
                    if ($dpd > 0) {
                        // Count for the first 3 months
                        if ($monthsDifference <= 3) {
                            $dpd_in_3_month++;
                        }
                        // 1-year window (12 months)
                        if ($monthsDifference <= 12) {
                            $dpd_in_1_year++;
                        }
                        // 2-year window (24 months)
                        if ($monthsDifference <= 24) {
                            $dpd_in_2_year++;
                        }
                        // 3-year window (36 months)
                        $dpd_in_3_year++;

                        // Count year-wise
                        if (!isset($year_wise_dpd[$year])) {
                            $year_wise_dpd[$year] = 0;
                        }
                        $year_wise_dpd[$year]++;
                    }
                }
            }
        }

        ksort($year_wise_dpd); // Sort by year ascending

        return [
            'dpd_in_3_month'   => $dpd_in_3_month,
            'dpd_in_1_year'    => $dpd_in_1_year,
            'dpd_in_2_year'    => $dpd_in_2_year,
            'dpd_in_3_year'    => $dpd_in_3_year,
            'year_wise_dpd'    => $year_wise_dpd,
        ];
    }

    public function ocr_details_match($match_attribute, $user_type, $match_with, $borrower_id) {
        if(!in_array($user_type, ['BORROWER', 'COAPPLICANT', 'GUARANTOR'])) {
            return false;
        }

        if($match_attribute == 'Name') {
            $aadhar_details = VendorServiceApi::where('borrower_id', $borrower_id)
                                ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
                                ->where('status', "VALID")
                                ->where('applicant_type', $user_type)
                                ->latest()
                                ->first();
            if(!$aadhar_details) {
                return false;
            }

            $response   = $aadhar_details->response;
            $response   = base64_decode($response);
            $response   = unserialize($response);
            $data       = json_decode($response, true);
            if($data['msg'] == 'success') {
                $name   = $data['model']['name'];
                if($name == $match_with) {
                    return true;
                } else {
                    return false;
                }
            } else {
                return false;
            }

        } elseif($match_attribute == 'Pan') {
            if($user_type == 'BORROWER') {
                $pan_details = BorrowerKyc::where('borrower_id', $borrower_id)
                                ->latest()
                                ->first();
            } elseif($user_type == 'COAPPLICANT') {
                $pan_details = CoapplicantKyc::where('borrower_id', $borrower_id)
                                ->latest()
                                ->first();
                
            } elseif($user_type == 'GUARANTOR') {
                $pan_details = Guarantor::where('borrower_id', $borrower_id)
                                ->latest()
                                ->first();
            }

            if(!$pan_details) {
                return false;
            }

            $pan_number = $pan_details->pan;

            if($pan_number == $match_with) {
                return true;
            } else {
                return false;
            }
        }   
    }

    public function download_esign_doc(Request $request) {
        try {
            $input              = $request->all();
            $user_id            = $request->user()->id;
            $errorArray         = [];
            $errorCount         = 0;
            $ip                 = $this->get_user_ip();
            
            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            }
            
            $borrower_primary_key   = $this->get_borrower_id($request->user()->id);

            $loan_details           = LoanBasicDetails::find($borrower_primary_key);
            if(!$loan_details) {
                return $this->send_response("failed", "Loan details not found", 422);
            }

            $borrower_details = BorrowerKyc::where('borrower_id', $borrower_primary_key)->latest()->first();
            if(!$borrower_details) {
                return $this->send_response('failed', "Invalid request, borrower not found", 422);
            }
            $mobile_number = $borrower_details->mobile;

            date_default_timezone_set('Asia/Kolkata');
            $application_number = $this->generate_random_string(6);
            $unique_id          = $application_number . date('dmY');

            // if (!isset($input['doc_id']) || empty($input['doc_id'])) {
            //     $errorCount++;
            //     $errorArray['doc_id'] = "Doc id is required";
            //     return $this->send_response("failed", $errorArray['doc_id'], 422);
            // }

            if(!isset($input['applicant_type']) || empty($input['applicant_type'])) {
                $errorCount++;
                $errorArray['applicant_type'] = "Applicant type is required";
                return $this->send_response("failed", $errorArray['applicant_type'], 422);
            } elseif(!in_array($input['applicant_type'], ['BORROWER', 'COAPPLICANT', 'GUARANTOR'])) {
                $errorCount++;
                $errorArray['applicant_type'] = "Invalid applicant type";
                return $this->send_response("failed", $errorArray['applicant_type'], 422);
            }

            if($input['applicant_type'] == "COAPPLICANT") {
                $coapplicant_details = CoapplicantKyc::where('borrower_id', $borrower_primary_key)->latest()->first();
                if(!$coapplicant_details) {
                    return $this->helper_controller->send_response('failed', "Coapplicant details not found", 422);
                }
                $mobile_number = $coapplicant_details->mobile;
            }
            if($input['applicant_type'] == "GUARANTOR") {
                $gurantor_details = Guarantor::where('borrower_id', $borrower_primary_key)->latest()->first();
                if(!$gurantor_details) {
                    return $this->helper_controller->send_response('failed', "Guarantor not found", 422);
                }
                $mobile_number = $gurantor_details->mobile;
            }

            DB::beginTransaction();
            $this->helper_controller->store_app_log('create', "Download E-sign signed document, api initiated for ". strtolower($input['applicant_type']), "BORROWER", $borrower_primary_key);

            $previous_esign_details = VendorServiceApi::where('borrower_id', $borrower_primary_key)
                ->where('applicant_type', $input['applicant_type'])
                ->where('status', "VALID")
                ->where('api_flag', "GENERATE_ESIGN_URL")
                ->latest()
                ->first();

            // Determine if doc_id is provided
            $doc_id = $input['doc_id'] ?? null;

            if (!$doc_id) {
                if (!$previous_esign_details) {
                    return $this->helper_controller->send_response('failed', "Please generate esign", 422, ['state' => 'not exist']);
                }
            
                $esign_api_response = json_decode($previous_esign_details->response);
                $doc_id = $esign_api_response->docId;
                $e_sign_url = $esign_api_response->e_sign_url;
            } else {
                $doc_id = $esign_api_response->docId ?? $input['doc_id'];
                $e_sign_url = $esign_api_response->e_sign_url ?? '';
            }
            
            // If resend_url is set, send the SMS
            if (isset($input['resend_url']) && !empty($input['resend_url'])) {
                $mobile = '+91' . $mobile_number;
                $message = "Your Finayo esign document id $doc_id. Please click this link to start the e-sign process: $e_sign_url";
                $this->twilio->sendSms($mobile, $message);

                $this->helper_controller->store_app_log('create', "E-sign url re-sent to ". $mobile, "BORROWER", $borrower_primary_key, ['vendor_service_apis' => $previous_esign_details->id]);

                DB::commit();
                return response()->json([
                    'status' => 'success',
                    'message' => 'Esign url re-sent successfully',
                    'data' => [
                        'doc_id' => $doc_id,
                        'e_sign_url' => $e_sign_url,
                    ]
                ], 200);
            }
            
            $applicant_type     = $input['applicant_type'];
            $jsonRequestBody    = json_encode([
                'docId' => $doc_id
            ]);

            
            $product_id            = $loan_details->product_id;
            $skip_coapplicant_guarantor = $loan_details->skip_coapplicant_guarantor;
            
            $gender                = $borrower_details->gender;
            $skip_co_applicant     = $borrower_details->skip_co_applicant;
            $skip_guarantor        = $borrower_details->skip_guarantor;
            $borrower_id           = $loan_details->borrower_id;

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api.digitap.ai/ent/v1/get-esign-doc',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonRequestBody,
                CURLOPT_HTTPHEADER => array(
                    'Authorization: '. $this->prod_authorization_key,
                    'Accept: application/json',
                    'Content-Type: application/json'
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

            if(isset($result->code) && $result->code == 200) {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = $user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "DOWNLOAD_ESIGN_DOCUMENT";
            $vendorServiceApi->request          = json_encode($input);
            $vendorServiceApi->response         = $response;
            $vendorServiceApi->borrower_id      = $borrower_primary_key;
            $vendorServiceApi->applicant_type   = $applicant_type;
            $vendorServiceApi->save();

            $result_response = [];
            if(isset($result->code) && $result->code == 200) {
                $api_status = "VALID";
                
                if(isset($result->model->signers[0]->state) && $result->model->signers[0]->state == 'signed') {
                    
                    if($result->model->signers[0]->name != $result->model->signers[0]->signerName) {
                        $result_response = [
                            'status' => 'failed',
                            'message' => "Please sign the document with your original aadhar"
                        ];
                        
                        $this->helper_controller->store_app_log('create', "Sign does not matched", "BORROWER", $borrower_primary_key, ['vendor_service_apis' => $vendorServiceApi->id]);

                        DB::commit();
                        return response()->json($result_response, 422);
                    }

                    $url = $result->model->url;
                    $result_response = [
                        'status' => 'success',
                        'message' => "Signed document fetched",
                        'data' => [
                            'signed_doc_url' => $url,
                            'file_name' =>  $result->model->fileName,
                        ],
                    ];

                    $fileContents = file_get_contents($url);

                    // Generate a filename
                    $filename = basename($url);

                    // Store the file in the storage (public directory in this example)
                    if($applicant_type == "BORROWER") {
                        // $currentStep = 22;
                        $currentStep = 24;
                        if($skip_coapplicant_guarantor === true) {
                            $path = "public/e-sign/{$borrower_id}/BORROWER/final_signed_agreement.pdf";
                            Storage::put($path, $fileContents);
                            
                        } elseif($gender != 'Male' && $skip_co_applicant == false) {

                            $path = "public/e-sign/{$borrower_id}/COAPPLICANT/pre_signed_agreement.pdf";
                            Storage::put($path, $fileContents);
                        } elseif($product_id == '4' && $skip_guarantor == true) {
                            $path = "public/e-sign/{$borrower_id}/BORROWER/final_signed_agreement.pdf";
                            Storage::put($path, $fileContents);

                        } else {
                            $path = "public/e-sign/{$borrower_id}/GUARANTOR/pre_signed_agreement.pdf";
                            Storage::put($path, $fileContents);
                        }
                    } elseif($applicant_type == "COAPPLICANT") {
                        // $currentStep = 24;
                        $currentStep = 26;

                        $path = "public/e-sign/{$borrower_id}/GUARANTOR/pre_signed_agreement.pdf";
                        Storage::put($path, $fileContents);
                    } elseif($applicant_type == "GUARANTOR") {
                        // $currentStep = 26;
                        $currentStep = 28;

                        $path = "public/e-sign/{$borrower_id}/BORROWER/final_signed_agreement.pdf";
                        Storage::put($path, $fileContents);
                    }

                    $directory = dirname(storage_path("app/{$path}"));
                    @chmod($directory, 0755);
                    @chmod(storage_path("app/{$path}"), 0644);
                    
                    $application_step = [
                        'step' => $currentStep,
                        'esign_id' => 0,
                        'details' => $applicant_type . " E-sign Status",
                        'status' => "COMPLETED",
                    ];
        
                    $this->updateApplicationStep($user_id, $borrower_primary_key, $application_step);
                    
                    $this->helper_controller->store_app_log('create', "Signed document fetched", "BORROWER", $borrower_primary_key, ['vendor_service_apis' => $vendorServiceApi->id]);

                    DB::commit();
                    return response()->json($result_response, 200);
                } else {
                    $result_response = [
                        'status' => 'failed',
                        'message' => "Document not signed yet"
                    ];
                    $this->helper_controller->store_app_log('create', "Agreement not signed yet", "BORROWER", $borrower_primary_key, ['vendor_service_apis' => $vendorServiceApi->id]);

                    DB::commit();
                    return response()->json($result_response, 422);
                }

            } else {
                $api_status = "INVALID";
                $result_response = [
                    'status' => 'failed',
                    'message' => "No results",
                    'data' => [
                    ],
                ];

                $this->helper_controller->store_app_log('create', "No results found for esign document", "BORROWER", $borrower_primary_key, ['vendor_service_apis' => $vendorServiceApi->id]);
                DB::commit();
                return response()->json($result_response, 422);
            }
            DB::commit();
            return response()->json($result_response, 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', $e->getMessage(), 422);
        }
    }

    /**
     * New Aadhar verification generate url api
     */
    public function generate_url_unified_kyc(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
                'applicant_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR'
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $loan_details = LoanBasicDetails::where('id', $request->borrower_id)->first();
            if (!$loan_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }

            $borrower_id = $request->borrower_id;

            $jsonRequestBody = [
                'redirectionUrl' => 'https://insta.finayo.tech',
                'uniqueId'       => strtoupper($this->generate_random_string(15)),
                'expiryHours'    => 1, // 1 hour
            ];

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://svcdemo.digitap.work/kyc-unified/v1/generate-url/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($jsonRequestBody),
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Basic ' . $this->uat_authorization_key,
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            curl_close($curl);

            $resultData = json_decode($response);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }

            $api_status = 'INVALID';
            $status = 'failed';
            $result_code = 422;
            if ($httpCode == 200) {
                $result_code = 200;
                $status = 'success';
                $api_status = 'VALID';
            }

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = auth()->id() ?? 0;
            $vendorServiceApi->ip_address       = $this->helper_controller->get_user_ip();
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "AADHAR_UNIFIED_KYC_URL_GENERATE";
            $vendorServiceApi->request          = base64_encode(serialize(json_encode($jsonRequestBody)));
            $vendorServiceApi->response         = base64_encode(serialize($response));
            $vendorServiceApi->borrower_id      = $request->borrower_id;
            $vendorServiceApi->applicant_type   = $request->applicant_type;
            $vendorServiceApi->save();

            return $this->send_response($status, "URL generated successfully", $result_code, $resultData);
        } catch (Throwable $e) {
            return $this->send_response('success', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    /**
     * New Aadhar verification generate url api
     */
    public function generate_url_unified_kyc_staus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
                'applicant_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR',
                'unified_transaction_id' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $loan_details = LoanBasicDetails::find($request->borrower_id);
            if (!$loan_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }

            $borrower_id = $request->borrower_id;
            $unified_transaction_id = null;
            if($request->filled('unified_transaction_id')) {
                $unified_transaction_id = $request->unified_transaction_id;
            } else {
                $api_data = VendorServiceApi::where('borrower_id', $borrower_id)
                                ->where('api_flag',  'AADHAR_UNIFIED_KYC_URL_GENERATE')
                                ->where('applicant_type', $request->applicant_type)
                                ->latest()
                                ->first();
                if(!$api_data) {
                    return $this->helper_controller->send_response('failed', "Aadhar verification api not initiated.", 422);
                }

                $responseData   = $api_data->response;
                $responseData   = base64_decode($responseData);
                $responseData   = unserialize($responseData);
                $apiData        = json_decode($responseData, 200);

                $unified_transaction_id = $apiData['model']['unifiedTransactionId'];
            }

            if(!$unified_transaction_id) {
                return $this->helper_controller->send_response('failed', "please initiate the aadhar verification api first.", 422);
            }

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://svcdemo.digitap.work/kyc-unified/v1/' . $unified_transaction_id . '/details/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Basic ' . $this->uat_authorization_key
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

            $api_status = 'INVALID';
            $status = 'failed';
            $result_code = 422;
            if ($httpCode == 200) {
                $result_code = 200;
                $status = 'success';
                $api_status = 'VALID';
            }

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = auth()->id() ?? 0;
            $vendorServiceApi->ip_address       = $this->helper_controller->get_user_ip();
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "AADHAR_UNIFIED_KYC_STATUS";
            $vendorServiceApi->request          = base64_encode(serialize(json_encode($request->all())));
            $vendorServiceApi->response         = base64_encode(serialize($response));
            $vendorServiceApi->borrower_id      = $request->borrower_id;
            $vendorServiceApi->applicant_type   = $request->applicant_type;
            $vendorServiceApi->save();

            if (isset($result->code) && $result->code == 200) {
                $message = "Aadhar data fetched";

                $address = '';
                if (!empty($result->model->address->house)) {
                    $address     .= $result->model->address->house;
                }

                if (!empty($decode_json->model->address->street)) {
                    if (!empty($decode_json->model->address->house)) {
                        $address .= ', ';
                    }
                    $address .= $decode_json->model->address->street;
                }

                if (!empty($result->model->address->landmark)) {
                    if (!empty($decode_json->model->address->street)) {
                        $address .= ', ';
                    }
                    $address .= $result->model->address->landmark;
                }

                if (!empty($result->model->address->loc)) {
                    $address .= ', ' . $result->model->address->loc;
                }

                if (!empty($result->model->address->po)) {
                    $address .= ', ' . $result->model->address->po;
                }

                if (!empty($result->model->address->dist)) {
                    $address .= ', ' . $result->model->address->dist;
                }

                if (!empty($result->model->address->subdist)) {
                    $address .= ', ' . $result->model->address->subdist;
                }

                if (!empty($result->model->address->vtc)) {
                    $address .= ', ' . $result->model->address->vtc;
                }

                if (!empty($result->model->address->pc)) {
                    $address .= ', ' . $result->model->address->pc;
                }

                if (!empty($result->model->address->state)) {
                    $address .= ', ' . $result->model->address->state;
                }

                if (!empty($result->model->address->country)) {
                    $address .= ', ' . $result->model->address->country;
                }

                $aadharData = $result->model;
                $result_response = [
                    'code' => $result->code,
                    'status' => 'success',
                    'message' => $message,
                    'data' => [
                        "adharNumber" => $response_aadhar ?? '',
                        "uniqueId" => $result->model->uniqueId,
                        "maskedAdharNumber" => $result->model->maskedAdharNumber,
                        "name" => $result->model->name,
                        "gender" => $result->model->gender,
                        "dob" => $result->model->dob,
                        "careOf" => $result->model->careOf,
                        "uniqueId" => $result->model->uniqueId,
                        "address" => $address,
                        "full_address" => $result->model->address,
                        "msg" => $result->msg,
                    ],
                ];
            } elseif (isset($result->code) && $result->code == 500) {
                $message = $result->msg;
                $result_response = [
                    'code' => $result->code,
                    'status' => 'failed',
                    'message' => $message,
                    'errorCode' => $result->errorCode,
                    'data' => [],
                ];
            } else {
                $message = "No results";
                $result_response = [
                    'status' => 'failed',
                    'message' => $message,
                    'data' => [],
                ];
            }

            return $this->send_response($status, "Unified kyc status fetched successfully", $result_code, $result_response);
        } catch (Throwable $e) {
            return $this->send_response('success', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }
}
