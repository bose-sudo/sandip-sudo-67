<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\ApiDemoResponses;
use App\Http\Controllers\WebController;
use App\Models\BorrowerIotDetails;
use App\Models\BorrowerKyc;
use App\Models\CoapplicantKyc;
use App\Models\Guarantor;
use App\Models\LoanApplicationSummary;
use App\Http\Controllers\HelperController;
use App\Models\LoanBasicDetails;
use App\Models\VendorServiceApi;
use App\Models\VkycApiReqDetails;
use App\Models\VkycWebResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use CURLFile;
use Illuminate\Http\Request;
use App\Services\TwilioService;
use DateTime;
use Exception;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Storage;

class UatServiceApiController extends Controller
{
    private $client_id;
    private $client_secret;
    private $prod_authorization_key;
    private $uat_authorization_key;
    private $loco_nav_auth_key;
    private $signzy_auth_key;
    protected $twilio;
    protected $helper_controller;

    public function __construct(TwilioService $twilio, HelperController $helper_controller) {
        // $this->middleware('auth:api', ['except' => ['store_vkyc_data']]);

        $uatClientId = config('services.digitap.uat.client_id');
        $uatSecret   = config('services.digitap.uat.client_secret');
        $this->uat_authorization_key = base64_encode($uatClientId . ':' . $uatSecret);

        $prodClientId = config('services.digitap.prod.client_id');
        $prodSecret   = config('services.digitap.prod.client_secret');
        $this->prod_authorization_key = base64_encode($prodClientId . ':' . $prodSecret);

        $this->loco_nav_auth_key = config('services.loconav.prod.auth_key');
        $this->signzy_auth_key = config('services.signzy.prod.auth_key');

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
            $input        = $request->all();
            $executive_id = $request->user()->id;
            $errorArray = [];
            $errorCount = 0;
            $ip         = $this->get_user_ip();
            $uniqueId   = $this->generate_random_id();

            if($input == null) {
                $errorCount++;
                $errorArray['body'] = "Request body is empty";
                return $this->send_response("failed", $errorArray['body'], 422);
            } else {
                if(!isset($request->user_id) || empty($request->user_id)) {
                    $errorCount++;
                    $errorArray['user_id'] = "User id is required";
                    return $this->send_response("failed", "User id is required", 422);
                }

                if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                    $errorCount++;
                    $errorArray['borrower_id'] = "Borrower id is required";
                    return $this->send_response("failed", "Borrower id is required", 422);
                }

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

                $check_aadhar_exist = $this->helper_controller->check_similar_aadhar_data($input['aadhar'], $input['applicant_type'], $request->borrower_id);
                if (!$check_aadhar_exist['status']) {
                    return $this->helper_controller->send_response('failed', $check_aadhar_exist['message'], 422);
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
                "uniqueId" => $uniqueId,
                "uid" => $input['aadhar']
            ]);

            $api_response = ApiDemoResponses::where('doc_id', $input['aadhar'])->where('api_flag', 'AADHAR_CARD_VERIFICATION_OKYC_PAPERLESS_XML')->first();
            if(!$api_response) {
                return $this->helper_controller->send_response('failed', 'No response found in dummy record', 422);
            }
            
            // echo $api_response->response;
            // die();
            $response = $api_response->response;
            $result = json_decode($response);

            if($result->code == 200 && $result->msg == "success") {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = $request->user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "AADHAR_CARD_VERIFICATION_OKYC_PAPERLESS_XML";
            $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
            $vendorServiceApi->response         = base64_encode(serialize($response));
            $borrower_id                        = $request->borrower_id;
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $input['applicant_type'];
            $vendorServiceApi->executive_id     = $executive_id;
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
            } else {
                $message = "No results";
                $result_response = [
                    'status' => 'failed',
                    'message' => isset($result->msg) ? $result->msg : $message,
                    'data' => [
                        'transactionId' => '',
                        'fwdp' => '',
                        'codeVerifier' => '',
                        'uidai_message' => '',
                    ],
                ];
            }

            return response()->json($result_response, 200);
        } catch (Exception $e) {
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
        $input      = $request->all();
        $executive_id    = $request->user()->id;
        $errorArray = [];
        $errorCount = 0;
        $ip         = $this->get_user_ip();
        $uniqueId   = $this->generate_random_id();
        
        if($input == null) {
            $errorCount++;
            $errorArray['body'] = "Request body is empty";
            return $this->send_response("failed", $errorArray['body'], 422);
        } else {
            if(!isset($request->user_id) || empty($request->user_id)) {
                $errorCount++;
                $errorArray['user_id'] = "User id is required";
                return $this->send_response("failed", "User id is required", 422);
            }

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }

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
        $vendorServiceApi->user_id          = $request->user_id;
        $vendorServiceApi->ip_address       = $ip;
        $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
        $vendorServiceApi->status           = $api_status;
        $vendorServiceApi->api_flag         = "AADHAR_CARD_VERIFICATION_RESEND_OTP_OKYC_PAPERLESS_XML";
        $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
        $vendorServiceApi->response         = base64_encode(serialize($response));
        $borrower_id                        = $request->borrower_id;
        $vendorServiceApi->borrower_id      = $borrower_id;
        $vendorServiceApi->applicant_type   = $input['applicant_type'];
        $vendorServiceApi->executive_id     = $executive_id;
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
        } else {
            $message = "No results";
            $result_response = [
                'status' => 'failed',
                'message' => $message,
                'data' => [
                ],
            ];
        }

        return response()->json($result_response, 200);
    }

    /**
     * Aadhar Verification API With OTP
     * Created By: Mir Akib Javed
     * Date: 10-05-2024
     */
    public function verify_aadhar_using_otp(Request $request) {
        $input      = $request->all();
        $executive_id    = $request->user()->id;
        $errorArray = [];
        $errorCount = 0;
        $ip         = $this->get_user_ip();
        $share_code = $this->generate_random_string(4);
        
        if($input == null) {
            $errorCount++;
            $errorArray['body'] = "Request body is empty";
            return $this->send_response("failed", $errorArray['body'], 422);
        } else {
            if(!isset($request->user_id) || empty($request->user_id)) {
                $errorCount++;
                $errorArray['user_id'] = "User id is required";
                return $this->send_response("failed", "User id is required", 422);
            }

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }

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
                return $this->send_response("failed", $errorArray['otp'], 422);

            } elseif($input['otp'] != 346296) {
                $errorArray['otp'] = "Invalid OTP";
                return $this->send_response("failed", $errorArray['otp'], 422);
            }

            if(!isset($input['aadhar_number']) || empty($input['aadhar_number'])) {
                $errorCount++;
                $errorArray['aadhar_number'] = "Aadhar number is required";
                return $this->send_response("failed", $errorArray['aadhar_number'], 422);
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

        $jsonRequestBody = json_encode([
            "transactionId" => $input['transactionId'],
            "fwdp" => $input['fwdp'],
            "codeVerifier" => $input['codeVerifier'],
            "otp" => $input['otp'],
            "shareCode" => $share_code,
            "isSendPdf" => true
        ]);

        // if($applicant_type == "BORROWER") {
        //     $borrower_details = BorrowerKyc::where('borrower_id', $request->borrower_id)->first();
        //     if(!$borrower_details) {
        //         return $this->helper_controller->send_response('failed', "Borrower not found", 422);
        //     }
        //     $aadhar_number = $borrower_details->aadhar;
        // }
        // if($applicant_type == "COAPPLICANT") {
        //     $coapplicant_details = CoapplicantKyc::where('borrower_id', $request->borrower_id)->first();
        //     if(!$coapplicant_details) {
        //         return $this->helper_controller->send_response('failed', "Coapplicabt details not found", 422);
        //     }
        //     $aadhar_number = $coapplicant_details->aadhar;
        // }
        // if($applicant_type == "BORROWER") {
        //     $gurantor_details = Guarantor::where('borrower_id', $request->borrower_id)->first();
        //     if(!$gurantor_details) {
        //         return $this->helper_controller->send_response('failed', "Borrower not found", 422);
        //     }
        //     $aadhar_number = $gurantor_details->aadhar;
        // }

        $api_response = ApiDemoResponses::where('doc_id', $input['aadhar_number'])->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')->first();
        if(!$api_response) {
            return $this->helper_controller->send_response('failed', 'No response found in dummy record', 422);
        }

        $response = $api_response->response;
        $result = json_decode($response);
        // echo $api_response->response;
        // die();

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
        $vendorServiceApi->user_id          = $request->user_id;
        $vendorServiceApi->ip_address       = $ip;
        $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
        $vendorServiceApi->status           = $api_status;
        $vendorServiceApi->api_flag         = "AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML";
        $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
        $vendorServiceApi->response         = base64_encode(serialize($response));
        
        $borrower_id                        = $request->borrower_id;
        $vendorServiceApi->borrower_id      = $borrower_id;
        $vendorServiceApi->applicant_type   = $input['applicant_type'];
        $vendorServiceApi->executive_id      = $executive_id;

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
                $address .= ', '.$result->model->address->loc;
            }
        
            if(!empty($result->model->address->po)){
                if(!empty($decode_json->model->address->loc)) {
                    $address .= ', ';
                }
                $address .= ', '.$result->model->address->po;
            }
        
            if(!empty($result->model->address->dist)){
                if(!empty($decode_json->model->address->po)) {
                    $address .= ', ';
                }
                $address .= ', '.$result->model->address->dist;
            }
        
            if(!empty($result->model->address->subdist)){
                if(!empty($decode_json->model->address->dist)) {
                    $address .= ', ';
                }
                $address .= ', '.$result->model->address->subdist;
            }
        
            if(!empty($result->model->address->vtc)){
                if(!empty($decode_json->model->address->subdist)) {
                    $address .= ', ';
                }
                $address .= ', '.$result->model->address->vtc;
            }
        
            if(!empty($result->model->address->pc)){
                if(!empty($decode_json->model->address->vtc)) {
                    $address .= ', ';
                }
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
                    "adharNumber" => $input['aadhar_number'], //$response_aadhar,
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
        
        } else {
            $message = "No results";
            $result_response = [
                'status' => 'failed',
                'message' => $message,
                'data' => [
                ],
            ];
        }

        // return $response;
        return response()->json($result_response, 200);
    }

    /**
     * PAN Verification API
     * Created By: Mir Akib Javed
     * Date: 10-05-2024
     */
    public function pan_verification(Request $request) {
        $input      = $request->all();
        $executive_id = $request->user()->id;
        $errorArray = [];
        $errorCount = 0;
        $ip         = $this->get_user_ip();
        $client_ref_num = $this->generate_random_id();
        date_default_timezone_set('Asia/Kolkata');

        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        } else {
            if(!isset($request->user_id) || empty($request->user_id)) {
                $errorCount++;
                $errorArray['user_id'] = "User id is required";
                return $this->send_response("failed", "User id is required", 422);
            }

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
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
        $vendorServiceApi->user_id          = $request->user_id;
        $vendorServiceApi->ip_address       = $ip;
        $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
        $vendorServiceApi->status           = $api_status;
        $vendorServiceApi->api_flag         = "PAN_BASIC_VALIDATION";
        $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
        $vendorServiceApi->response         = base64_encode(serialize($response));
        $borrower_id                        = $request->borrower_id;
        $vendorServiceApi->borrower_id      = $borrower_id;
        $vendorServiceApi->applicant_type   = $input['applicant_type'];
        $vendorServiceApi->executive_id      = $executive_id;
        $vendorServiceApi->save();

        if(isset($result->http_response_code) && $result->http_response_code == 200) {
            $api_status = "VALID";
            if($result->result_code == 101) {
                $is_active = true;
                $message = "Pan details fetched";
            } elseif($result->result_code == 103) {
                $is_active = false;
                $message = $result->message;
            } else {
                $is_active = false;
                $message = "";
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
        }
        return response()->json($result_response, 200);
    }

    public function pan_details_verification(Request $request) {
        try{
            // return 'hi';
            $input      = $request->all();
            $executive_id    = $request->user()->id;
            $errorArray = [];
            $errorCount = 0;
            $ip         = $this->get_user_ip();
            $client_ref_num = $this->generate_random_id();
            date_default_timezone_set('Asia/Kolkata');

            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            } else {
                if(!isset($request->user_id) || empty($request->user_id)) {
                    $errorCount++;
                    $errorArray['user_id'] = "User id is required";
                    return $this->send_response("failed", "User id is required", 422);
                }

                if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                    $errorCount++;
                    $errorArray['borrower_id'] = "Borrower id is required";
                    return $this->send_response("failed", "Borrower id is required", 422);
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

                $check_pan_exist = $this->helper_controller->check_similar_pan_data($input['pan'], $input['applicant_type'], $request->borrower_id);
                if (!$check_pan_exist['status']) {
                    return $this->helper_controller->send_response('failed', $check_pan_exist['message'], 422);
                }
            }

            if ($errorCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid data",
                    'error_list' => $errorArray
                ], 422);
            }

            // $aadhar_details = VendorServiceApi::where('borrower_id', $request->borrower_id)
            //                         ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
            //                         ->where('applicant_type', $request->applicant_type)
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

            $jsonRequestBody = json_encode([
                "client_ref_num" => $client_ref_num,
                "pan" => $input['pan'],
                "name" => $input['name'],
                "father" => 'yes'
            ]);

            $api_response = ApiDemoResponses::where('doc_id', $input['pan'])->where('api_flag', 'PAN_DETAILS_VALIDATION')->first();
            if(!$api_response) {
                return $this->helper_controller->send_response('failed', 'No response found in dummy record', 422);
            }

            $response = $api_response->response;
            $result = json_decode($response);
            // echo $result;
            // die();

            if(isset($result->http_response_code) && $result->http_response_code == 200) {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = $request->user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "PAN_DETAILS_VALIDATION";
            $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
            $vendorServiceApi->response         = base64_encode(serialize($response));
            $borrower_id                        = $request->borrower_id;
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $input['applicant_type'];
            $vendorServiceApi->executive_id      = $executive_id;
            $vendorServiceApi->doc_id           = $input['pan'];
            $vendorServiceApi->save();

            if(isset($result->http_response_code) && $result->http_response_code == 200) {
                $api_status = "VALID";
                if($result->result_code == 101) {
                    $is_active = true;
                    $message = "Pan details fetched";
                } elseif($result->result_code == 103) {
                    $is_active = false;
                    $message = isset($result->message) ? $result->message : '';
                } else {
                    $is_active = false;
                    $message = isset($result->message) ? $result->message : '';
                }


                $panDob = $result->result->dob ?? null;

                if(isset($aadharDob) && !empty($aadharDob) && isset($panDob) && !empty($panDob)) {
                    $dob1 = new DateTime($aadharDob);
                    $dob2 = DateTime::createFromFormat('d/m/Y', $panDob);

                    // $diff = $dob1->diff($dob2);
                    // Calculate absolute day difference
                    $diffDays = abs($dob1->diff($dob2)->days);
                    // if ($diff->y >= 3) {
                    if ($diffDays >= 1096) {
                        $loan_details = LoanBasicDetails::find($request->borrower_id);
                        if($loan_details) {
                            if($request->applicant_type == 'BORROWER') {
                                    $loan_details->status = "REJECTED";
                                    $loan_details->admin_remarks = "Date of birth difference in aadhar and pan is more than 3 years.";
                                    $loan_details->save();
                                    $message = "You are not eligible for a loan";
                                
                            } else {
                                $message = "Date of birth difference in aadhar and pan is more than 3 years, please change the ". $request->applicant_type;
                            }

                            $result_response = [
                                'status' => 'failed',
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
                        
                            return response()->json($result_response, 422);
                        }
                    }
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
            }
            return response()->json($result_response, 200);
        } catch (Exception $e) {
            // return response()->json($result_response, 422);
            return $this->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
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
    //     $borrower_id                    = $request->borrower_id;
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
        $input      = $request->all();
        $executive_id    = $request->user()->id;
        $errorArray = [];
        $errorCount = 0;
        $ip         = $this->get_user_ip();
        $client_ref_num = $this->generate_random_id();
        date_default_timezone_set('Asia/Kolkata');

        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        } else {
            if(!isset($request->user_id) || empty($request->user_id)) {
                $errorCount++;
                $errorArray['user_id'] = "User id is required";
                return $this->send_response("failed", "User id is required", 422);
            }

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }

            if (!isset($request->pan_image) || empty($request->pan_image)) {
                return $this->send_response("failed", "Pan image is required", 422);
            }

            if (!isset($request->pan_number) || empty($request->pan_number)) {
                return $this->send_response("failed", "Pan number is required", 422);
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

            // $jsonRequestBody = [
            //     "imageUrl" => $url,
            //     "clientRefId" => $clientRefId,
            //     "isBlackWhiteCheck" => $isBlackWhiteCheck,
            //     "confidence" => $confidence,
            //     "fraudCheck" => $fraudCheck,
            // ];

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

            // if($user_type == "BORROWER") {
            //     $borrower_details = BorrowerKyc::where('borrower_id', $request->borrower_id)->first();
            //     if(!$borrower_details) {
            //         return $this->helper_controller->send_response('failed', "Borrower not found", 422);
            //     }
            //     $pan_number = $borrower_details->pan;
            // }
            // if($user_type == "COAPPLICANT") {
            //     $coapplicant_details = CoapplicantKyc::where('borrower_id', $request->borrower_id)->first();
            //     if(!$coapplicant_details) {
            //         return $this->helper_controller->send_response('failed', "Coapplicabt details not found", 422);
            //     }
            //     $pan_number = $coapplicant_details->pan;
            // }
            // if($user_type == "GUARANTOR") {
            //     $gurantor_details = Guarantor::where('borrower_id', $request->borrower_id)->first();
            //     if(!$gurantor_details) {
            //         return $this->helper_controller->send_response('failed', "Borrower not found", 422);
            //     }
            //     $pan_number = $gurantor_details->pan;
            // }

            $pan_number = $request->pan_number;
    
            $api_response = ApiDemoResponses::where('doc_id', $pan_number)->where('api_flag', 'PAN_CARD_OCR')->first();
            if(!$api_response) {
                $this->helper_controller->send_response('failed', 'No response found in dummy record', 422);
            }
    
            $response = $api_response->response;
            // echo $response;
            $result         = json_decode($response);
            $borrower_id    = $request->borrower_id;
            
            $status     = "failed";
            $api_status = "INVALID";
            $message    = "Please upload valid pancard";
            $code       = 422;
            
            $father_name = null;

            if(isset($result->status) && $result->status == "success" && isset($result->statusCode) && $result->statusCode == "200") {
                if($result->result[0]->details->pan_no->conf > 90 && $result->result[0]->details->pan_no->value != '') {
                    // echo "file is ok";
                    $match_with = $result->result[0]->details->pan_no->value;
                    // $verify_ocr_data = $this->ocr_details_match('Pan', $user_type, $match_with, $borrower_id);
                    $verify_ocr_data = true; // $this->ocr_details_match('Pan', $user_type, $match_with, $borrower_id);
                    // need to check this
                    $father_name = $result->result[0]->details->father->value ?? null;
                    
                    if($verify_ocr_data === true) {
                        $status     = "success";
                        $api_status = "Valid";
                        $message    = "File uploaded successfully";
                        $code       = 200;
                    } else {
                        Storage::delete($path);
                        $status     = "failed";
                        $api_status = "Valid";
                        $message    = "OCR data did not match with user data";
                        $code       = 422;
                    }
                } else {
                    Storage::delete($path);
                    $status     = "failed";
                    $api_status = "Valid";
                    $message    = "Please upload valid pancard";
                    $code       = 422;
                }
            } elseif(isset($result->status) && $result->status == 'failure' && isset($result->statusCode) && $result->statusCode == "501") {
                Storage::delete($path);
                $status     = "failed";
                $api_status = "INVALID";
                $message    = "Please upload valid pancard";
                $code       = 422;
            } else {
                Storage::delete($path);
                $status     = "failed";
                $api_status = "INVALID";
                $message    = "Please upload valid pancard";
                $code       = 422;
            }

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = $request->user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "PAN_CARD_OCR";
            $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
            $vendorServiceApi->response         = base64_encode(serialize($response));
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $input['user_type'];
            $vendorServiceApi->executive_id      = $executive_id;
            $vendorServiceApi->save();

            return $this->send_response($status, $message, $code, ['father_name' => $father_name]);
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
            $executive_id    = $request->user()->id;
            $errorArray = [];
            $errorCount = 0;
            $ip         = $this->get_user_ip();
            $client_ref_num = $this->generate_random_id();
            date_default_timezone_set('Asia/Kolkata');

            if($input == null) {
                return $this->send_response("failed", "Request body is empty", 422);
            } else {
                // $borrower_primary_key   = $this->get_borrower_id($request->user()->id);

                if(!isset($request->user_id) || empty($request->user_id)) {
                    $errorCount++;
                    $errorArray['user_id'] = "User id is required";
                    return $this->send_response("failed", "User id is required", 422);
                }

                if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                    $errorCount++;
                    $errorArray['borrower_id'] = "Borrower id is required";
                    return $this->send_response("failed", "Borrower id is required", 422);
                }
                $loan_details           = LoanBasicDetails::find($request->borrower_id);
                if(!$loan_details) {
                    return $this->send_response("failed", "Invalid request", 422);
                }
                if($loan_details->product_id == '4') {
                    if(!isset($input['aadhar_type']) || empty($input['aadhar_type'])) {
                        $input['aadhar_type'] = 'AADHAR_FRONT';
                    }
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


                if(!isset($input['aadhar_type']) || empty($input['aadhar_type'])) {
                    return $this->send_response("failed", "Aadhar type  is required", 422);
                } elseif(!in_array($input['aadhar_type'], ["AADHAR_FRONT", "AADHAR_BACK"])) {
                    return $this->send_response("failed", "Aadhar type can be only 'AADHAR_FRONT' or 'AADHAR_BACK'", 422);
                }

                $user_type = $input['user_type'];
                $aadhar_type = $input['aadhar_type'];
        
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

                // if($user_type == "BORROWER") {
                //     $borrower_details = BorrowerKyc::where('borrower_id', $request->borrower_id)->first();
                //     if(!$borrower_details) {
                //         return $this->helper_controller->send_response('failed', "Borrower not found", 422);
                //     }
                //     $aadhar_number = $borrower_details->aadhar;
                // }
                // if($user_type == "COAPPLICANT") {
                //     $coapplicant_details = CoapplicantKyc::where('borrower_id', $request->borrower_id)->first();
                //     if(!$coapplicant_details) {
                //         return $this->helper_controller->send_response('failed', "Coapplicabt details not found", 422);
                //     }
                //     $aadhar_number = $coapplicant_details->aadhar;
                // }
                // if($user_type == "GUARANTOR") {
                //     $gurantor_details = Guarantor::where('borrower_id', $request->borrower_id)->first();
                //     if(!$gurantor_details) {
                //         return $this->helper_controller->send_response('failed', "Borrower not found", 422);
                //     }
                //     $aadhar_number = $gurantor_details->aadhar;
                // }

                $aadhar_doc_type = ($aadhar_type == 'AADHAR_FRONT') ? 'AADHAR_CARD_OCR_FRONT' : 'AADHAR_CARD_OCR_BACK';
                if($aadhar_doc_type == 'AADHAR_CARD_OCR_FRONT') {

                    $api_response = ApiDemoResponses::where('api_flag', $aadhar_doc_type)->orderBy('id', 'asc')->first();
                } else {

                    $api_response = ApiDemoResponses::where('api_flag', $aadhar_doc_type)->where('doc_id', $input['user_type'])->orderBy('id', 'asc')->first();
                }
                if(!$api_response) {
                    $this->helper_controller->send_response('failed', 'No response found in dummy record', 422);
                }
        
                $response = $api_response->response;
                
                // echo $response;
                // die();
                $result = json_decode($response);
                
                $status         = "failed";
                $api_status     = ($result->statusCode == "200") ? 'VALID' : 'INVALID';
                $message        = "Please upload valid aadhar card";
                $code           = ($result->statusCode == "200") ? 200 : 422;
                $borrower_id    = $request->borrower_id;
                $result_data    = [];

                $vendorServiceApi                   = new VendorServiceApi;
                $vendorServiceApi->user_id          = $request->user_id;
                $vendorServiceApi->ip_address       = $ip;
                $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
                $vendorServiceApi->status           = $api_status;
                $vendorServiceApi->api_flag         = ($aadhar_type == 'AADHAR_FRONT') ? "AADHAR_CARD_OCR_FRONT" : "AADHAR_CARD_OCR_BACK";
                $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
                $vendorServiceApi->response         = base64_encode(serialize($response));
                $borrower_id                        = $request->borrower_id;
                $vendorServiceApi->borrower_id      = $borrower_id;
                $vendorServiceApi->applicant_type   = $input['user_type'];
                $vendorServiceApi->executive_id     = $executive_id;
                $vendorServiceApi->save();

                if(isset($result->status) && $result->status == "success" && isset($result->statusCode) && $result->statusCode == "200") {
                    $ocr_data = $result->result[0]->details ?? null;
                    $response_aadhar_type = $result->result[0]->type ?? null;

                    $is_front_valid = $aadhar_type == "AADHAR_FRONT" && isset($ocr_data->aadhaar->conf) && $ocr_data->aadhaar->conf >= 90 && !empty($ocr_data->aadhaar->value);
                    $is_back_valid  = $aadhar_type == "AADHAR_BACK" && isset($ocr_data->address->conf) && $ocr_data->address->conf >= 75;

                    if ($is_front_valid || $is_back_valid) {
                        if ($aadhar_type == "AADHAR_FRONT" && $response_aadhar_type != 'aadhaar_front_bottom') {
                            return $this->send_response('failed', "Please upload aadhar front bottom image", 422);
                        }
                        if ($aadhar_type == "AADHAR_BACK" && $response_aadhar_type != 'aadhaar_back') {
                            return $this->send_response('failed', "Please upload aadhar back bottom image", 422);
                        }

                        $match_with = $ocr_data->name->value ?? ''; // user name in aadhar name
                        $status     = "success";
                        $api_status = "Valid";
                        $message    = "File uploaded successfully";
                        $code       = 200;

                        $result_data = [
                            'status' => $result->status ?? '',
                            'statusCode' => $result->statusCode ?? '',
                            'type' => $response_aadhar_type ?? '',
                            'aadhaar' => $ocr_data->aadhaar ?? '',
                            'name' => $ocr_data->name->value ?? '',
                            'dob' => $ocr_data->dob->value ?? '',
                            'gender' => $ocr_data->gender->value ?? '',
                            'phone' => $ocr_data->phone->value ?? '',
                            'father' => $ocr_data->father->value ?? '',
                            'mother' => $ocr_data->mother->value ?? '',
                            'husband' => $ocr_data->husband->value ?? '',
                            'care_of' => $ocr_data->care_of->value ?? '',
                            'pin' => $ocr_data->pin->value ?? '',
                            'address' => $ocr_data->address ?? '',
                        ];
                    }else {
                        Storage::delete($path);
                        $status     = "failed";
                        $api_status = "Valid";
                        $message    = "Please upload valid aadhar card";
                        $code       = 422;
                    }
                } elseif(isset($result->status) && $result->status == 'failure' && isset($result->statusCode) && $result->statusCode == "501") {
                    Storage::delete($path);
                    $status     = "failed";
                    $api_status = "INVALID";
                    $message    = isset($result->error) ? $result->error : "Please upload valid aadhar card";
                    $code       = 422;
                } else {
                    Storage::delete($path);
                    $status     = "failed";
                    $api_status = "INVALID";
                    $message    = "Please upload valid aadhar card";
                    $code       = 422;
                }

                return $this->send_response($status, $message, $code, $result_data);
            }
        } catch (\Throwable $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    /**
     * Driving Licence Verification api
     * Created by: Mir Akib Javed
     * Date: 12-05-2024
     */
    public function verify_driving_licence(Request $request) {
        $input      = $request->all();
        $executive_id    = $request->user()->id;
        $errorArray = [];
        $errorCount = 0;
        $ip         = $this->get_user_ip();
        $client_ref_num = $this->generate_random_id(4);
        date_default_timezone_set('Asia/Kolkata');

        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        } else {

            if(!isset($request->user_id) || empty($request->user_id)) {
                $errorCount++;
                $errorArray['user_id'] = "User id is required";
                return $this->send_response("failed", "User id is required", 422);
            }

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }

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

        $api_response = ApiDemoResponses::where('doc_id', $input['dl_number'])->where('api_flag', 'DL_VALIDATION_API')->first();
        if(!$api_response) {
            $this->helper_controller->send_response('failed', 'No response found in dummy record', 422);
        }
        $response = $api_response->response;

        $result = json_decode($response);
        
        if(isset($result->http_response_code) && $result->http_response_code == 200) {
            $api_status = "VALID";
        } else {
            $api_status = "INVALID";
        }

        $vendorServiceApi               = new VendorServiceApi;
        $vendorServiceApi->user_id      = $request->user_id;
        $vendorServiceApi->ip_address   = $ip;
        $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
        $vendorServiceApi->status       = $api_status;
        $vendorServiceApi->api_flag     = "DL_VALIDATION_API";
        $vendorServiceApi->request      = base64_encode(serialize(json_encode($input)));
        $vendorServiceApi->response     = base64_encode(serialize($response));
        $borrower_id                    = $request->borrower_id;
        $vendorServiceApi->borrower_id  = $borrower_id;
        $vendorServiceApi->executive_id = $executive_id;
        $vendorServiceApi->save();

        if(isset($result->http_response_code) && $result->http_response_code == 200) {
            $api_status = "VALID";
            if($result->result_code == 101) {
                $is_valid = true;
                $message = "Diriving licence details fetched";
            } elseif($result->result_code == 102) {
                $is_valid = false;
                $message = isset($result->message) ? $result->message : "";
            } elseif($result->result_code == 103) {
                $is_valid = false;
                $message = isset($result->message) ? $result->message : "";
            } else {
                $is_valid = false;
                $message = "";
            }
            $result_response = [
                'status' => 'success',
                'is_valid' => $is_valid,
                'result_code' => isset($result->result_code) ? $result->result_code : '',
                'message' => $message,
                'data' => isset($result->result->details_of_driving_licence) ? $result->result->details_of_driving_licence : [],
            ];
        } else {
            $api_status = "INVALID";
            $result_response = [
                'status' => 'failed',
                'is_active' => false,
                'message' => "No results",
                'data' => [
                ],
            ];
        }
        return response()->json($result_response, 200);
    }

    /**
     * IOT activation api
     * Created by: Mir Akib Javed
     */
    public function iot_activition_check(Request $request) {

        $input      = $request->all();
        $executive_id    = $request->user()->id;
        $errorArray = [];
        $errorCount = 0;
        $ip         = $this->get_user_ip();
        $client_ref_num = $this->generate_random_id(4);
        date_default_timezone_set('Asia/Kolkata');
        
        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        }
        if(!isset($request->user_id) || empty($request->user_id)) {
            $errorCount++;
            $errorArray['user_id'] = "User id is required";
            return $this->send_response("failed", "User id is required", 422);
        }

        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }
        $borrower_id = $request->borrower_id;

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
            $api_response = ApiDemoResponses::where('doc_id', $vehile_id)->where('api_flag', 'IOT_VEHICLE_LIST_API')->first();
            if(!$api_response) {
                return $this->helper_controller->send_response('failed', 'No response found in dummy record', 422);
            }
    
            $response = $api_response->response;
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

            if(isset($result->data->vehicles[0]->vehicleUuid) && !empty($result->data->vehicles[0]->vehicleUuid)) {
                $vehicleUuid = $result->data->vehicles[0]->vehicleUuid;
                $dataReq['vehicleUuid'] = $vehile_id; //$vehicleUuid;
                $dataReq['borrower_id'] = $request->borrower_id;
                $dataReq['user_id']     = $request->user_id;
                $dataReq['executive_id']     = $executive_id;
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
                    return response()->json($result_response, 200);
                }
            } else {
                return $this->send_response('failed', "Vehicle does not exist", 422);
            }

            $vendorServiceApi               = new VendorServiceApi;
            $vendorServiceApi->user_id      = $request->user_id;
            $vendorServiceApi->ip_address   = $ip;
            $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
            $vendorServiceApi->status       = $api_status;
            $vendorServiceApi->api_flag     = "IOT_VEHICLE_LIST_API";
            $vendorServiceApi->request      = json_encode($input);
            $vendorServiceApi->response     = $response;
            
            $vendorServiceApi->borrower_id  = $borrower_id;
            $vendorServiceApi->executive_id  = $executive_id;
            $vendorServiceApi->save();

            if(isset($result->success) && $result->success === true) {
                $application_step = 28;
                $application_step = [
                    'step' => $application_step,
                    'vendor_service_api_id' => $vendorServiceApi->id,
                    'details' => "IOT Activation",
                    'status' => "COMPLETED",
                ];
    
                $this->updateApplicationStep($borrower_id, $application_step);

                $borrower_iot_details                   = new BorrowerIotDetails;
                $borrower_iot_details->user_id          = $request->user_id;
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
            } else {
                $result_response = [
                    'status' => 'failed',
                    'message' => isset($message) ? $message : "IOT not activated",
                ];
            }

            return response()->json($result_response, 200);
        } catch (Exception $e) {
            $result_response = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }        
    }

    private function get_vehicle_iot_details($dataReq) {
        $dataResponse = [];
        $vehicleUuid    = $dataReq['vehicleUuid'];
        $borrower_id    = $dataReq['borrower_id'];
        $user_id        = $dataReq['user_id'];
        $executive_id   = $dataReq['executive_id'];
        $ip             = $this->get_user_ip();
        try{
            $api_response = ApiDemoResponses::where('doc_id', $vehicleUuid)->where('api_flag', 'IOT_VEHICLE_DETAILS_API')->first();
            if(!$api_response) {
                // $this->helper_controller->send_response('failed', 'No response found in dummy record', 422);
                $dataResponse = [
                    'status' => 'failed'
                ];
                return $dataResponse;
            }

            $response = $api_response->response;
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
            $vendorServiceApi->executive_id = $executive_id;
            $vendorServiceApi->save();

            if(isset($result->success) && $result->success === true) {
                $dataResponse = [
                    'status' => 'success'
                ];
            } else {
                
                $dataResponse = [
                    'status' => 'failed'
                ];
            }

            return $dataResponse;

        } catch (Exception $e) {
            $dataResponse = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
        return $dataResponse;
    }

    /**
     * Video Kyc Generate URL api
     * Created by: Mir Akib Javed
     */

    public function video_kyc_generate_url(Request $request) {
        $input              = $request->all();
        $executive_id       = $request->user()->id;
        $errorArray         = [];
        $errorCount         = 0;
        $ip                 = $this->get_user_ip();
        $application_number = $this->generate_random_id();
        $currentStep        = 18;
        
        date_default_timezone_set('Asia/Kolkata');

        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        } else {
            if(!isset($request->user_id) || empty($request->user_id)) {
                $errorCount++;
                $errorArray['user_id'] = "User id is required";
                return $this->send_response("failed", "User id is required", 422);
            }

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
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
                // echo $response;
                // die();

                $result_code = 200;
                $result_response = [];
                if(isset($result_code) && $result_code == 200) {
                    $api_status = "VALID";
                    $message = "Video KYC url generated";
                    $result_response = [
                        'status' => 'success',
                        'result_code' => 200,
                        'message' => $message,
                        'data' => [
                            'application_number' => $application_number,
                            'url' => 'https://www.google.com/',
                        ],
                    ];

                    $borrower_id = $request->borrower_id;

                    $vkyc_request_details                       = new VkycApiReqDetails;
                    $vkyc_request_details->user_id              = $request->user_id;
                    $vkyc_request_details->borrower_id          = $borrower_id;
                    $vkyc_request_details->application_number   = $application_number;
                    $vkyc_request_details->mobile               = $input['mobile'];
                    $vkyc_request_details->request              = json_encode($input);
                    $vkyc_request_details->vkyc_url             = 'https://www.google.com/';
                    $vkyc_request_details->save();

                    try {
                        $mobile = '+91'. $input['mobile'];
                        $message = "Your Finayo VKYC application number is ". $application_number . " . Please click this link to start the video kyc process: https://www.google.com/";
                        // $this->twilio->sendSms($mobile, $message);

                        $application_step = [
                            'step' => $currentStep,
                            'vkyc_request_details_id' => $vkyc_request_details->id,
                            'details' => "VKYC Generate URL",
                            'status' => "COMPLETED",
                        ];
    
                        $this->updateApplicationStep($borrower_id, $application_step);
        
                        return response()->json([
                            'status' => 'success',
                            'result_code' => 200,
                            'message' => "Video KYC url generated",
                            'data' => [
                                'application_number' => $application_number,
                                'url' => 'https://www.google.com/',
                            ],
                        ], 200);
                    } catch (\Exception $e) {
                        return response()->json([
                            'status' => 'error',
                            'message' => $e->getMessage()
                        ], 422);
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

                $vendorServiceApi               = new VendorServiceApi;
                $vendorServiceApi->user_id      = $request->user_id;
                $vendorServiceApi->ip_address   = $ip;
                $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
                $vendorServiceApi->status       = $api_status;
                $vendorServiceApi->api_flag     = "VKYC_GENERATE_URL_API";
                $vendorServiceApi->request      = json_encode($input);
                $vendorServiceApi->response     = 'Dummy Response';
                $borrower_id                    = $request->borrower_id;
                $vendorServiceApi->borrower_id  = $borrower_id;
                $vendorServiceApi->executive_id = $executive_id;
                $vendorServiceApi->save();
                
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

    /**
     * VKYC get call status using unique ID
     */
    
    public function get_vkyc_status_by_application_number(Request $request) {
        $input          = $request->all();
        $executive_id   = $request->user()->id;
        $errorArray     = [];
        $errorCount     = 0;
        $ip             = $this->get_user_ip();
        
        date_default_timezone_set('Asia/Kolkata');

        if($input == null){
            return $this->send_response("failed", "Request body is empty", 422);
        } else {
            if(!isset($request->user_id) || empty($request->user_id)) {
                $errorCount++;
                $errorArray['user_id'] = "User id is required";
                return $this->send_response("failed", "User id is required", 422);
            }

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }

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
            
            try {
                
                $api_response = ApiDemoResponses::where('api_flag', 'VKYC_CALL_DETAILS_USING_UNIQUE_ID_API')->first();
                if(!$api_response) {
                    $this->helper_controller->send_response('failed', 'No response found in dummy record', 422);
                }
        
                $response = $api_response->response;
                
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
                    // $result->model->vkycStatus = "PENDING";
                    $result_response = [
                        'status' => 'success',
                        'result_code' => isset($result->code) ? $result->code : '',
                        'message' => $message,
                        'data' => $result->model
                    ];
                } else {
                    $api_status = "INVALID";
                    $result_response = [
                        'status' => 'failed',
                        'result_code' => isset($result->code) ? $result->code : '',
                        'message' => isset($result->message) ? $result->message : 'No records exist for the given id',
                    ];
                }

                $vendorServiceApi               = new VendorServiceApi;
                $vendorServiceApi->user_id      = $request->user_id;
                $vendorServiceApi->ip_address   = $ip;
                $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
                $vendorServiceApi->status       = $api_status;
                $vendorServiceApi->api_flag     = "VKYC_CALL_DETAILS_USING_UNIQUE_ID_API";
                $vendorServiceApi->request      = json_encode($input);
                $vendorServiceApi->response     = $response;
                $borrower_id                    = $request->borrower_id;
                $vendorServiceApi->borrower_id  = $borrower_id;
                $vendorServiceApi->executive_id = $executive_id;
                $vendorServiceApi->save();

                if(isset($result->model->vkycStatus) && $result->model->vkycStatus == "APPROVED") {
                    $currentStep = 19;
                    $application_step = [
                        'step' => $currentStep,
                        'vendor_service_api_id' => $vendorServiceApi->id,
                        'details' => "VKYC Status",
                        'status' => "COMPLETED",
                    ];

                    $this->updateApplicationStep($borrower_id, $application_step);
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

    /**
     * Vkyc get transaction details api
     * Created by: Mir Akib Javed
     */
    public function vkyc_transaction_details(Request $request) {
        $input      = $request->all();
        $executive_id    = $request->user()->id;
        $errorArray = [];
        $errorCount = 0;
        $ip         = $this->get_user_ip();
        
        date_default_timezone_set('Asia/Kolkata');

        if($input == null){
            return $this->send_response("failed", "Request body is empty", 422);
        } else {
            if(!isset($request->user_id) || empty($request->user_id)) {
                $errorCount++;
                $errorArray['user_id'] = "User id is required";
                return $this->send_response("failed", "User id is required", 422);
            }

            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }

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
                } else {
                    $api_status = "INVALID";
                    $result_response = [
                        'status' => 'failed',
                        'message' => "No results",
                        'data' => [
                        ],
                    ];
                }

                $vendorServiceApi               = new VendorServiceApi;
                $vendorServiceApi->user_id      = $request->user_id;
                $vendorServiceApi->ip_address   = $ip;
                $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
                $vendorServiceApi->status       = $api_status;
                $vendorServiceApi->api_flag     = "VKYC_TRANSACTION_DETAILS_API";
                $vendorServiceApi->request      = json_encode($input);
                $vendorServiceApi->response     = $response;
                $borrower_id                    = $request->borrower_id;
                $vendorServiceApi->borrower_id  = $borrower_id;
                $vendorServiceApi->executive_id = $executive_id;
                $vendorServiceApi->save();
                
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

    public function generate_esign(Request $request) {
        $input              = $request->all();
        $executive_id       = $request->user()->id;
        $errorArray         = [];
        $errorCount         = 0;
        $ip                 = $this->get_user_ip();
        date_default_timezone_set('Asia/Kolkata');
        $application_number = $this->generate_random_string(6);
        $unique_id = $application_number . date('dmY');
        

        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        }

        if(!isset($request->user_id) || empty($request->user_id)) {
            $errorCount++;
            $errorArray['user_id'] = "User id is required";
            return $this->send_response("failed", "User id is required", 422);
        }

        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
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
        $borrower_primary_key   = $request->borrower_id;
        $loan_details           = LoanBasicDetails::find($borrower_primary_key);
        if(!$loan_details) {
            return $this->send_response("failed", "Invalid request", 422);
        }

        if ($input['applicant_type'] === 'BORROWER' && $loan_details->is_agreement_reviewed === false) {
            return $this->send_response("failed", "Please wait while our executive verifies your application.", 422);
        }

        $borrower_id    = $loan_details->borrower_id;
        $product_id     = $loan_details->product_id;

        $borrower_details = BorrowerKyc::where('borrower_id', $borrower_primary_key)->latest()->first();
        if(!$borrower_details) {
            return $this->send_response('failed', "Invalid request, borrower not found", 422);
        }
        $gender             = $borrower_details->gender;
        $skip_co_applicant  = $borrower_details->skip_co_applicant;
        $skip_guarantor     = $borrower_details->skip_guarantor;
        
        // $templateId = "ESIG28228601";
        if($input['applicant_type'] == "BORROWER") {
            if ($product_id == 4) {
                if($skip_guarantor === true) {
                    // only borrower sign
                    $templateId = 'ESIG28292223';
                } else {
                    $templateId = 'ESIG28284955';
                }
            } else {
                if($gender == 'Male' && $skip_co_applicant == true) {
                    $templateId = 'ESIG28284955';
                } else {
                    $templateId = 'ESIG28247910';
                }
            }
            $currentStep = 21;
            $data = $this->helper_controller->prepare_data_for_esign_doc($borrower_primary_key);
            $pdf = Pdf::loadView('pdf.agreement-2', $data);
            // $pdf = Pdf::loadView('pdf.agreement-2', $data)->setPaper('a4', 'portrait');
            $pdfPath = "e-sign/{$borrower_id}/".$input['applicant_type']."/agreement.pdf";
            Storage::disk('public')->put($pdfPath, $pdf->output());
            $filePath = storage_path('app/public/' . $pdfPath);
        }
        
        if($input['applicant_type'] == "COAPPLICANT") {
            $templateId = 'ESIG28258198';
            $currentStep = 23;
            $pdfPath = "e-sign/{$borrower_id}/COAPPLICANT/pre_signed_agreement.pdf";
            // if (Storage::disk('public')->exists($pdfPath)) {
            //     $filePath = storage_path('app/public/' . $pdfPath);
            // } else {
            //     $errorCount++;
            //     $errorArray['esign_doc'] = "E-sign doc does not exist";
            //     return $this->send_response("failed", $errorArray['esign_doc'], 422);
            // }
        }
        
        if($input['applicant_type'] == "GUARANTOR") {
            $currentStep = 25;
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
            // if (Storage::disk('public')->exists($pdfPath)) {
            //     $filePath = storage_path('app/public/' . $pdfPath);
            // } else {
            //     $errorCount++;
            //     $errorArray['esign_doc'] = "E-sign doc does not exist";
            //     return $this->send_response("failed", $errorArray['esign_doc'], 422);
            // }
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
        $api_response = ApiDemoResponses::where('doc_id', $input['mobile'])->where('api_flag', 'GENERATE_ESIGN_FILE_UPLOAD_URL')->first();
        if(!$api_response) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Use proper uat data'
            ], 422);
        }

        // generate file upload url
        $get_esign_upload_url = $this->get_esign_upload_url($jsonRequestBody, $borrower_primary_key, $input['applicant_type'], $request->user_id, $executive_id);
        if(!isset($get_esign_upload_url) || $get_esign_upload_url['status'] !== true) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unable to generate upload url'
            ], 422);
        }

        $file_path  = ''; //$filePath;
        $doc_id     = $get_esign_upload_url['doc_id'];
        $upload_url = $get_esign_upload_url['upload_url'];

        // Upload file to generate e-sign url
        $upload_doc = $this->upload_file_to_prepare_esign_doc($file_path, $doc_id, $upload_url, $borrower_primary_key, $input['applicant_type'], $request->user_id, $executive_id);

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
                // $this->twilio->sendSms($mobile, $message);

                $application_step = [
                    'step' => $currentStep,
                    'esign_id' => 0, // $loan_basic_details->id,
                    'details' => $input['applicant_type']. " Esign Generate URL",
                    'status' => "COMPLETED",
                ];

                $this->updateApplicationStep($borrower_primary_key, $application_step);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Esign url generated',
                    'data' => $data
                ], 200);
            } catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ], 403);
            }
            
        } else {
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to generate Esign url',
            ], 422);
        }
    }

    /**
     * Get Esign File Upload Url API
     */
    private function get_esign_upload_url($jsonRequestBody, $borrower_primary_key, $applicant_type, $user_id, $executive_id) {
        // Generate Upload URL Request
        $ip = $this->get_user_ip();
        date_default_timezone_set('Asia/Kolkata');

        if($applicant_type == "BORROWER") {
            $borrower_details = BorrowerKyc::where('borrower_id', $borrower_primary_key)->latest()->first();
            if(!$borrower_details) {
                return $this->helper_controller->send_response('failed', "Borrower not found", 422);
            }
            $mobile_number = $borrower_details->mobile;
        }
        if($applicant_type == "COAPPLICANT") {
            $coapplicant_details = CoapplicantKyc::where('borrower_id', $borrower_primary_key)->latest()->first();
            if(!$coapplicant_details) {
                return $this->helper_controller->send_response('failed', "Coapplicabt details not found", 422);
            }
            $mobile_number = $coapplicant_details->mobile;
        }
        if($applicant_type == "GUARANTOR") {
            $gurantor_details = Guarantor::where('borrower_id', $borrower_primary_key)->latest()->first();
            if(!$gurantor_details) {
                return $this->helper_controller->send_response('failed', "Borrower not found", 422);
            }
            $mobile_number = $gurantor_details->mobile;
        }

        $api_response = ApiDemoResponses::where('doc_id', $mobile_number)->where('api_flag', 'GENERATE_ESIGN_FILE_UPLOAD_URL')->first();
        if(!$api_response) {
            $data = [
                'status' => false
            ];
            return $data;
        }

        $response = $api_response->response;
        

        // echo $response;
        // die();
        $result = json_decode($response);
        if(isset($result->code) && $result->code == 200) {
            $api_status         = 'VALID';
            $doc_id             = $result->model->docId;
            $file_upload_url    = $result->model->url;

            $data = [
                'status' => true,
                'doc_id' => $doc_id,
                'upload_url' => $file_upload_url
            ];

        } else {
            $api_status = 'INVALID';
            $data = [
                'status' => false
            ];
        }

        $vendorServiceApi                   = new VendorServiceApi;
        $vendorServiceApi->user_id          = $user_id;
        $vendorServiceApi->ip_address       = $ip;
        $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
        $vendorServiceApi->status           = $api_status;
        $vendorServiceApi->api_flag         = "GENERATE_ESIGN_FILE_UPLOAD_URL";
        $vendorServiceApi->request          = $jsonRequestBody;
        $vendorServiceApi->response         = $response;
        $borrower_id                        = $borrower_primary_key;
        $vendorServiceApi->borrower_id      = $borrower_id;
        $vendorServiceApi->applicant_type   = $applicant_type;
        $vendorServiceApi->executive_id     = $executive_id;
        $vendorServiceApi->save();

        return $data;
    }

    /**
     * Upload File to prepare esign document
     */
    private function upload_file_to_prepare_esign_doc($file_path, $doc_id, $upload_url, $borrower_primary_key, $applicant_type, $user_id, $executive_id) {
        $ip = $this->get_user_ip();
        $data_input = json_encode([
            'file_path' => $file_path,
            'doc_id' => $doc_id,
            'upload_url' => $upload_url
        ]);
        date_default_timezone_set('Asia/Kolkata');
        // Check if the response status code is 200
        $httpCode = 200;
        if($httpCode === 200) {
            $api_status         = 'VALID';

            $sign_url = 'https://sdk.dummy.ai/e-sign/templateesignprocess.html?docId='. $doc_id;

            $data = [
                'status' => true,
                'httpCode' => $httpCode,
                'docId' => $doc_id,
                'e_sign_url' => $sign_url
            ];
        } else {
            $api_status         = 'INVALID';
            $data = [
                'status' => false,
                'httpCode' => $httpCode,
                'docId' => $doc_id
            ];
        }

        $vendorServiceApi                   = new VendorServiceApi;
        $vendorServiceApi->user_id          = $user_id;
        $vendorServiceApi->ip_address       = $ip;
        $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
        $vendorServiceApi->status           = $api_status;
        $vendorServiceApi->api_flag         = "GENERATE_ESIGN_URL";
        $vendorServiceApi->request          = $data_input;
        $vendorServiceApi->response         = json_encode($data);
        $borrower_id                        = $borrower_primary_key;
        $vendorServiceApi->borrower_id      = $borrower_id;
        $vendorServiceApi->applicant_type   = $applicant_type;
        $vendorServiceApi->executive_id     = $executive_id;
        $vendorServiceApi->save();

        return $data;
    }

    public function get_credit_score_details(Request $request) {
        $input              = $request->all();
        $executive_id            = $request->user()->id;
        $errorArray         = [];
        $errorCount         = 0;
        $ip                 = $this->get_user_ip();

        date_default_timezone_set('Asia/Kolkata');
        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        }

        if(!isset($request->user_id) || empty($request->user_id)) {
            $errorCount++;
            $errorArray['user_id'] = "User id is required";
            return $this->send_response("failed", "User id is required", 422);
        }

        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
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
            if ($age < 18) {
                $errorCount++;
                $errorArray['dob'] = "Age must be within 18 to 55";
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

        $borrower_id = $request->borrower_id; 

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
        ]);
            // "consent" => [
            //     "consentFlag" => true,
            //     "consentTimestamp" => strtotime(date('Y-m-d')),
            //     "consentIpAddress" => $ip
            // ]

        $result_response = [];

        try{
            $api_response = ApiDemoResponses::where('doc_id', $input['pan'])->where('api_flag', 'CREDIT_SCORE_API_SIGNZY_EXPERIAN')->first();
            if(!$api_response) {
                $this->helper_controller->send_response('failed', 'No response found in dummy record', 422);
            }
            $response = $api_response->response;
            $result = json_decode($response);

            // echo $response;
            // die();
            $update_loan_details = LoanBasicDetails::find($borrower_id);
            
            if(isset($result->statusCode) && $result->statusCode == 200) {
                $api_status = "VALID";
            } elseif(isset($result->error) && isset($result->error->type) && $result->error->type == 'Not Found') {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi               = new VendorServiceApi;
            $vendorServiceApi->user_id      = $request->user_id;
            $vendorServiceApi->ip_address   = $ip;
            $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
            $vendorServiceApi->status       = $api_status;
            $vendorServiceApi->api_flag     = "CREDIT_SCORE_API_SIGNZY_EXPERIAN";
            $vendorServiceApi->request      = json_encode($input);
            $vendorServiceApi->response     = $response;
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $input['applicant_type'];
            $vendorServiceApi->executive_id      = $executive_id;
            $vendorServiceApi->doc_id       = $input['pan'];
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
                if($applicant_type == 'BORROWER') {
                    $message = "Right now you're not eligible for any loan, try again after few months";
                } elseif($applicant_type == 'COAPPLICANT') {
                    $message = "Unable to proceed, please change the co-applicant and try again";
                } elseif($applicant_type == 'GUARANTOR') {
                    $message = "Unable to proceed, please change the guarantor and try again";
                }
                $credit_score = $result->data->jsonExperianReport->SCORE->FCIREXScore;
                if($credit_score < 650) {
                    if($update_loan_details && $update_loan_details->manual_override !== true) {
                        $status = 'failed';
                        if($applicant_type == 'BORROWER') {
                            
                            if($update_loan_details) {
                                $update_loan_details->status = 'REJECTED';
                                $update_loan_details->save();
                            }
                        } 
                    }
                } elseif($is_willful_defaulter == true) {
                    $status = 'failed';
                } elseif(isset($dpd_data['dpd_in_3_month']) && $dpd_data['dpd_in_3_month'] > 1) {
                    $status = 'failed';
                } elseif(isset($dpd_data['dpd_in_1_year']) && $dpd_data['dpd_in_1_year'] > 0) {
                    $status = 'failed';
                } else {
                    $status = 'success';
                    $message = "Credit details fetched";
                }
                if($update_loan_details && $update_loan_details->manual_override === true) {
                    $status = 'success';
                    $message = "Credit details fetched";
                }
                
                $loan_application_summary_details = LoanApplicationSummary::where('borrower_id', $borrower_id)
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

                if($status == 'failed') {
                    $result_code = 422;
                } else {
                    $result_code = isset($result->statusCode) ? $result->statusCode : '';
                }
                $result_response = [
                    'status' => $status,
                    'result_code' => $result_code,
                    'message' => $message,
                    'data' => [
                        'BureauScore' => $credit_score,
                        'is_willful_defaulter' => $is_willful_defaulter,
                        'dpd_in_3_month' => isset($dpd_data['dpd_in_3_month']) ? $dpd_data['dpd_in_3_month'] : 0,
                        'dpd_in_1_year' => isset($dpd_data['dpd_in_1_year']) ? $dpd_data['dpd_in_1_year'] : 0,
                    ],
                ];

            } elseif(isset($result->error) && isset($result->error->type) && $result->error->type == 'Not Found') {
                //  && $applicant_type != 'GUARANTOR'
                $api_status = "VALID";
                $status = 'success';
                $message = "No credit history";

                $loan_application_summary_details = LoanApplicationSummary::where('borrower_id', $borrower_id)
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
            } else {
                $api_status = "INVALID";
                $result_response = [
                    'status' => 'failed',
                    'message' => isset($result->message) ? $result->message : "Credit history not found",
                ];
            }

            $result_code = $result_code ?? 200;

            return response()->json($result_response, $result_code);
            
        } catch (Exception $e) {
            $result_response = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            return response()->json($result_response, 422);
        }
    }

    public function calculate_dpds($account_details) {
        $dpd_in_3_month = 0;
        $dpd_in_1_year = 0;
        date_default_timezone_set('Asia/Kolkata');
        $currentYear = date('Y');
        $currentMonth = date('m');

        foreach ($account_details as $account_history) {
            $dpds = $account_history->CAIS_Account_History;
            foreach ($dpds as $entry) {
                $year = $entry->Year;
                $month = $entry->Month ?? 0;
                $dpd = $entry->Days_Past_Due;

                // Calculate the difference in months from the current month and year
                $monthsDifference = ($currentYear - $year) * 12 + ($currentMonth - $month);

                // Only consider the entries within the last 12 months
                if ($monthsDifference <= 12) {
                    if ($monthsDifference <= 3 && $dpd > 30) {
                        $dpd_in_3_month++;
                    }
                    if ($dpd > 90) {
                        $dpd_in_1_year++;
                    }
                }
            }
        }

        $data = [
            'dpd_in_3_month' => $dpd_in_3_month,
            'dpd_in_1_year' => $dpd_in_1_year,
        ];
        return $data;
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


    public function cheque_ocr(Request $request) {
        $input          = $request->all();
        $executive_id   = $request->user()->id;
        $errorArray     = [];
        $errorCount     = 0;
        $ip             = $this->get_user_ip();
        $client_ref_num = $this->generate_random_id();
        date_default_timezone_set('Asia/Kolkata');

        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        } else {
    
            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                $errorCount++;
                $errorArray['borrower_id'] = "Borrower id is required";
                return $this->send_response("failed", "Borrower id is required", 422);
            }

            $loan_details = LoanBasicDetails::find($request->borrower_id);
            if(!$loan_details) {
                return $this->send_response("failed", "Invalid borrower id", 422);
            }
            $user_id = $loan_details->user_id;

            if (!isset($request->cheque_image) || empty($request->cheque_image)) {
                return $this->send_response("failed", "Cheque is required", 422);
            }
    
            if (!$request->file('cheque_image')->isValid()) {
                return $this->send_response("failed", "Invalid cheque image", 422);
            }
    
            $allowed_types = array("image/jpeg", "image/png", "image/jpg");
    
            if (!in_array($request->file('cheque_image')->getMimeType(), $allowed_types)) {
                return $this->send_response("failed", "Only JPG, PNG, and JPEG files are allowed", 422);
            }
    
            $max_size = 5 * 1024 * 1024; // 5 MB
    
            if ($request->file('cheque_image')->getSize() > $max_size) {
                return $this->send_response("failed", "Cheque size must not exceed 5 MB", 422);
            }

            $user_type = 'BORROWER';
    
            // If validation passes, handle the file upload
            // Get the uploaded file
            $file = $request->file('cheque_image');

            // Generate a unique file name
            $fileName = $this->generate_random_string(20) . '.' . $file->getClientOriginalExtension();

            // Store the uploaded file with the new file name in storage/app/public directory
            $path = $file->storeAs('public/cheque_ocr/'.$user_type, $fileName);

            // Get the URL of the uploaded file
            $url = Storage::url($path);

            $api_response = ApiDemoResponses::where('api_flag', 'CHEQUE_OCR')->first();
            if(!$api_response) {
                $this->helper_controller->send_response('failed', 'No response found in dummy record', 422);
            }
            $response = $api_response->response;
            $result = json_decode($response, true);
            
            $status         = "failed";
            $api_status     = "INVALID";
            $message        = "Please upload valid cheque";
            $code           = 422;
            $user_id        = $user_id;
            $borrower_id    = $request->borrower_id;
            $result_response = [];
            if(isset($result['status']) && $result['status'] == "success" && isset($result['statusCode']) && $result['statusCode'] == "200") {
                $status     = "success";
                $api_status = "VALID";
                $message    = "Cheque is valid";
                $code       = 200;

                $result_response = [
                    'account_number' => $result['result'][0]['details']['account_number']['value'] ?? '',
                    'ifsc_code' => $result['result'][0]['details']['ifsc_code']['value'] ?? '',
                    'micr_code' => $result['result'][0]['details']['micr_code']['value'] ?? '',
                    'bank_name' => $result['result'][0]['details']['bank_name']['value'] ?? '',
                    'cheque_number' => $this->generate_random_number(6),
                ];
            } else {
                Storage::delete($path);
                $status     = "failed";
                $api_status = "INVALID";
                $message    = "Please upload valid cheque";
                $code       = 422;
            }

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = $user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "CHEQUE_OCR";
            $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
            $vendorServiceApi->response         = base64_encode(serialize($response));
            $borrower_id                        = $request->borrower_id;
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $user_type;
            $vendorServiceApi->executive_id     = $executive_id;
            $vendorServiceApi->save();

            return $this->send_response($status, $message, $code, $result_response);
        }

    }

    public function download_esign_doc(Request $request) {
        $input              = $request->all();
        $executive_id       = $request->user()->id;
        $errorArray         = [];
        $errorCount         = 0;
        $ip                 = $this->get_user_ip();

        date_default_timezone_set('Asia/Kolkata');
        $application_number = $this->generate_random_string(6);
        $unique_id          = $application_number . date('dmY');

        if($input == null) {
            return $this->send_response("failed", "Request body is empty", 422);
        }

        if(!isset($request->user_id) || empty($request->user_id)) {
            $errorCount++;
            $errorArray['user_id'] = "User id is required";
            return $this->send_response("failed", "User id is required", 422);
        }

        if (!isset($request->borrower_id) || empty($request->borrower_id)) {
            $errorCount++;
            $errorArray['borrower_id'] = "Borrower id is required";
            return $this->send_response("failed", "Borrower id is required", 422);
        }

        $borrower_primary_key   = $request->borrower_id;
        $loan_details           = LoanBasicDetails::find($borrower_primary_key);
        if(!$loan_details) {
            return $this->send_response("failed", "Loan details not found", 422);
        }

        $borrower_details = BorrowerKyc::where('borrower_id', $borrower_primary_key)->latest()->first();
        if(!$borrower_details) {
            return $this->send_response('failed', "Invalid request, borrower not found", 422);
        }

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
            $old_e_sign_url = $esign_api_response->e_sign_url;
        } else {
            $doc_id = $esign_api_response->docId ?? $input['doc_id'];
            $old_e_sign_url = $esign_api_response->e_sign_url ?? '';
        }
        
        // If resend_url is set, send the SMS
        if (isset($input['resend_url']) && !empty($input['resend_url'])) {
            $mobile = '+91' . $borrower_details->mobile;
            $message = "Your Finayo esign document id $doc_id. Please click this link to start the e-sign process: $old_e_sign_url";
            // $this->twilio->sendSms($mobile, $message);

            return response()->json([
                'status' => 'success',
                'message' => 'Esign url resent successfully',
                'data' => [
                    'doc_id' => $doc_id,
                    'e_sign_url' => $old_e_sign_url,
                ]
            ], 200);
        }

        $doc_id             = $doc_id; //$input['doc_id'];
        $applicant_type     = $input['applicant_type'];
        $jsonRequestBody    = json_encode([
            'docId' => $doc_id
        ]);
        
        $product_id = $loan_details->product_id;
        $borrower_id = $loan_details->borrower_id;

        $gender                = $borrower_details->gender;
        $skip_co_applicant     = $borrower_details->skip_co_applicant;
        $skip_guarantor        = $borrower_details->skip_guarantor;

        try{
            $api_response = ApiDemoResponses::where('doc_id', $doc_id)->where('api_flag', 'DOWNLOAD_ESIGN_DOCUMENT')->first();
            if(!$api_response) {
                $this->helper_controller->send_response('failed', 'No response found in dummy record', 422);
            }
            $response = $api_response->response;
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
            $vendorServiceApi->user_id          = $request->user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "DOWNLOAD_ESIGN_DOCUMENT";
            $vendorServiceApi->request          = json_encode($input);
            $vendorServiceApi->response         = $response;
            $vendorServiceApi->borrower_id      = $borrower_primary_key;
            $vendorServiceApi->applicant_type   = $applicant_type;
            $vendorServiceApi->executive_id     = $executive_id;
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

                    // $fileContents = file_get_contents($url);

                    // Generate a filename
                    // $filename = basename($url);

                    // Store the file in the storage (public directory in this example)
                    if($applicant_type == "BORROWER") {
                        $currentStep = 22;
                        if($gender != 'Male' && $skip_co_applicant == false) {
                            $path = "public/e-sign/{$borrower_id}/COAPPLICANT/pre_signed_agreement.pdf";
                            // Storage::put($path, $fileContents);
                        } elseif($product_id == '4' && $skip_guarantor === true) {
                            $path = "public/e-sign/{$borrower_id}/BORROWER/final_signed_agreement.pdf";
                            // Storage::put($path, $fileContents);

                        } else {
                            $path = "public/e-sign/{$borrower_id}/GUARANTOR/pre_signed_agreement.pdf";
                            // Storage::put($path, $fileContents);
                        }
                    } elseif($applicant_type == "COAPPLICANT") {
                        $currentStep = 24;
                        $path = "public/e-sign/{$borrower_id}/GUARANTOR/pre_signed_agreement.pdf";
                        // Storage::put($path, $fileContents);
                    } elseif($applicant_type == "GUARANTOR") {
                        $currentStep = 26;
                        $path = "public/e-sign/{$borrower_id}/BORROWER/final_signed_agreement.pdf";
                        // Storage::put($path, $fileContents);
                    }
                    
                    // $directory = dirname(storage_path("app/{$path}"));
                    // @chmod($directory, 0755);
                    // @chmod(storage_path("app/{$path}"), 0644);

                    $application_step = [
                        'step' => $currentStep,
                        'esign_id' => 0,
                        'details' => $applicant_type . " E-sign Status",
                        'status' => "COMPLETED",
                    ];
        
                    $this->updateApplicationStep($borrower_primary_key, $application_step);
                    
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
