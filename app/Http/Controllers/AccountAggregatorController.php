<?php

namespace App\Http\Controllers;

use App\Helpers\AesEncryptionHelper;
use App\Models\AaLenderCode;
use App\Models\AaRequestDetails;
use App\Models\AaWebhookResponse;
use App\Models\Dealer;
use App\Models\LenderDealerGroup;
use App\Models\LoanBasicDetails;
use App\Models\LenderBorrowerGroup;
use App\Models\VendorServiceApi;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AccountAggregatorController extends Controller
{
    protected $helper_controller;
    private $ignosis_api_key;
    protected $webhook_secret;
    protected $api_env;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
        $this->ignosis_api_key = "58e11f3b4b41663855c557d4e6";
        $this->webhook_secret = "24be325beb78d09d8ad94cf7d1576fa3e959cfea99ce493288f3327de308ebca";
        $this->api_env = 'UAT'; // [UAT, PROD]
    }

    public function get_aa_api_key($lender_id, $env) {
        $lender_key = AaLenderCode::where('lender_id', 4)->where('env', $env)->first();
        if(!$lender_key) {
            return [
                'status' => false
            ];
        }
        
        $helper     = new AesEncryptionHelper();
        $api_key    = $helper->decrypt($lender_key->key);
        $api_url    = $lender_key->url;
        // $aa_api_data['key'] = '58e11f3b4b41663855c557d4e6';
        // $aa_api_data['url'] = 'https://fiupreprod.ignosis.ai/fiu/api/pirimid/';

        return [
            'status' => true,
            'key' => '58e11f3b4b41663855c557d4e6', //$api_key,
            'url' => 'https://fiupreprod.ignosis.ai/fiu/api/pirimid/', //$api_url
        ];
    }

    public function get_fips(Request $request) {
        try {
            $helper     = new AesEncryptionHelper();
            $api_key    = $helper->encrypt("58e11f3b4b41663855c557d4e6");

            $validator = Validator::make($request->all(), [
                'borrower_id' => ['required', 'exists:loan_basic_details,id'], // Ensures exactly 10 digits
            ]);
            
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);;
            }

            $loan_details           = LoanBasicDetails::find($request->borrower_id);
            if(!$loan_details) {
                return $this->send_response("failed", "Loan details not found", 422);
            }

            $lender_id = null;
            if (!empty($loan_details->lender_id)) {
                $lender_id = $loan_details->lender_id;
            }

            if(!$lender_id) {
                $lenderBorrowerGroup = LenderBorrowerGroup::where('borrower_id', $borrower_id)->first();
                $lender_id = $lenderBorrowerGroup->lender_id;
            }

            // Step 3: Try from LenderDealerGroup via LoanBasicDetail -> dealer_id -> dealer_unq
            if (!$lender_id && !empty($loan_details->dealer_id)) {
                $dealer = Dealer::where('dealer_unq', $loan_details->dealer_id)->first();
                if ($dealer) {
                    $lenderDealerGroup = LenderDealerGroup::where('dealer_id', $dealer->id)->where('status', '=', 'active')->first();
                    if ($lenderDealerGroup) {
                        $lender_id = $lenderDealerGroup->lender_id;
                    }
                }
            }

            if(!$lender_id) {
                return $this->helper_controller->send_response('failed', "Lender not assigned", 422);
            }

            $aa_api_data = $this->get_aa_api_key(4, 'UAT');
            if($aa_api_data['status'] == false) {
                return $this->helper_controller->send_response('failed', "Invalid request, no credentials found", 422);
            }
            // return $aa_api_data['url'];
            
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => $aa_api_data['url'] . 'web/fip',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/json',
                    'API_KEY: '. $aa_api_data['key'],
                    // 'Cookie: AWSALB=uPWcBj4KPwZejxzRRxIfLInbIxtEutADQic3aCuspKtBBQ7XwPHhLVPiztiVmHzY9qGDIzpjqMMcm22Mdy2BXwrO5xoUbgDYGnFI3kXYaNkcJjdvXfUD+IcCpCt8; AWSALBCORS=uPWcBj4KPwZejxzRRxIfLInbIxtEutADQic3aCuspKtBBQ7XwPHhLVPiztiVmHzY9qGDIzpjqMMcm22Mdy2BXwrO5xoUbgDYGnFI3kXYaNkcJjdvXfUD+IcCpCt8'
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
            $result = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }

            if($httpCode === 401 && isset($result['errorCode']) && $result['errorCode']) {
                return $this->helper_controller->send_response('failed', "Invalid api key", 422, $result);
            }

            if($httpCode !== 200) {
                $responseCode = !empty($result['errorCode']) ? $result['errorCode'] : 422;
                $msg = $result['errorMsg'] ?? "Failed to fetch FIP list";

                $responseCode = ($responseCode === 401) ? 422 : 200;
                // return $this->helper_controller->send_response('success', $msg, $responseCode, $filteredData);
                return $this->helper_controller->send_response('failed', $msg, $responseCode);
            }


            if (isset($result['fip']) && is_array($result['fip'])) {
                $result['fip'] = array_values(array_filter($result['fip'], function ($item) {
                    return $item['enabled'] !== false;
                }));
            }

            // return count($result['fip']); // total 143 enabled=
            if( count($result['fip']) == 0) {
                return $this->helper_controller->send_response('failed', "No banks are enabled for this lender", 422);
            }

            // If you need reindexing
            // $filteredData = array_values($response);

            $result['aa_skip_details'] = [
                'is_aa_skippable' => $loan_details->is_aa_skippable,
                'aa_skip_enabled_by' => $loan_details->aa_skip_enabled_by,
                'aa_skip_enabled_at' => $loan_details->aa_skip_enabled_at,
                'aa_skip_reason' => $loan_details->aa_skip_reason,
            ];

            return $this->helper_controller->send_response('success', "FIP list fetched", 200, $result);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function initiate_request_b2c(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'mobile' => ['required', 'digits:10'], // Ensures exactly 10 digits
                'pan_no' => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'], // PAN validation
                'fip_id' => ['required', 'regex:/^[a-zA-Z0-9\-_]+$/'], // Allows letters, numbers, hyphens, underscores
                'applicant_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR',
            ]);
            
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);;
            }
            $input   = $request->all();
            $user_id = $request->user()->id;

            $borrower_primary_key   = $this->helper_controller->get_borrower_id($request->user()->id);

            $loan_details           = LoanBasicDetails::find($borrower_primary_key);
            if(!$loan_details) {
                return $this->send_response("failed", "Loan details not found", 422);
            }

            $ip         = $this->helper_controller->get_user_ip();
            // $uniqueId   = $this->helper_controller->generate_random_id();
            $trackingId = $loan_details->borrower_id . '-' . date('Y-m-d-H-i-s');

            $lender_id = null;
            if (!empty($loan_details->lender_id)) {
                $lender_id = $loan_details->lender_id;
            }

            if(!$lender_id) {
                $lenderBorrowerGroup = LenderBorrowerGroup::where('borrower_id', $borrower_id)->first();
                $lender_id = $lenderBorrowerGroup->lender_id;
            }

            // Step 3: Try from LenderDealerGroup via LoanBasicDetail -> dealer_id -> dealer_unq
            if (!$lender_id && !empty($loan_details->dealer_id)) {
                $dealer = Dealer::where('dealer_unq', $loan_details->dealer_id)->first();
                if ($dealer) {
                    $lenderDealerGroup = LenderDealerGroup::where('dealer_id', $dealer->id)->where('status', '=', 'active')->first();
                    if ($lenderDealerGroup) {
                        $lender_id = $lenderDealerGroup->lender_id;
                    }
                }
            }

            if(!$lender_id) {
                return $this->helper_controller->send_response('failed', "Lender not assigned", 422);
            }

            $aa_api_data = $this->get_aa_api_key(4, 'UAT');
            if($aa_api_data['status'] == false) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }
            
            $data = [
                "phoneNumber" => '9000000000', // $request->mobile,
                "templateTypes" => [
                    "UNDERWRITING",
                    "MONITORING"
                ],
                "trackingId" => $trackingId,
                "fipIds" => [
                    "FIP-ID", // $request->fip_id,
                ],
                "secondaryIdentifiers" => [
                    [
                        "type" => "PAN",
                        "value" => $request->pan_no,
                    ]
                ],
                "redirectionUrl" => "https://google.com"
            ];
            
            $jsonData = json_encode($data, JSON_PRETTY_PRINT);
            // echo $jsonData;

            $aa_request_details                         = new AaRequestDetails();
            $aa_request_details->borrower_id            = $borrower_primary_key;
            $aa_request_details->mobile                 = $request->mobile;
            $aa_request_details->pan_no                 = $request->pan_no;
            $aa_request_details->fip_ids                = $request->fip_id;
            $aa_request_details->tracking_id            = $trackingId;
            $aa_request_details->applicant_type         = $request->applicant_type;
            $aa_request_details->action_performed_by    = auth()->id();
            $aa_request_details->action_user_type       = "USER";
            $aa_request_details->input_data             = json_encode($data);
            $aa_request_details->ip_address             = $this->helper_controller->get_user_ip();
            $aa_request_details->save();

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => $aa_api_data['url'] . 'web/multi-consent/initiate/phone-number',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonData,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'API_KEY: '. $aa_api_data['key'],
                    // 'Cookie: AWSALB=M9AQ5QWMkYkN6jFme6URqGrCG8a6KPW/dhMJ5On46DXA/Shkjk17iVbQztIaT1pwGg2KjcgkXANzQFYF23Wj+9/rf3Ou49baleNdDQ4McmunT5A/b0KUbqXg6xJr; AWSALBCORS=M9AQ5QWMkYkN6jFme6URqGrCG8a6KPW/dhMJ5On46DXA/Shkjk17iVbQztIaT1pwGg2KjcgkXANzQFYF23Wj+9/rf3Ou49baleNdDQ4McmunT5A/b0KUbqXg6xJr'
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }

            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            // echo $response;

            $result = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }

            $resultData = [];
            if($httpCode === 200) {
                $status = 'success';
                $api_status = "VALID";
                $responseMessage = "Request initiated successfully";
                $resultData = [
                    'redirectionUrl' => $result['redirectionUrl'] ?? '',
                ];
            } else {
                $status = 'failed';
                $api_status = "INVALID";
                $responseMessage = $result['errorMsg'] ?? "Failed to initiate request";
            }

            $vendorServiceApi                   = new VendorServiceApi();
            $vendorServiceApi->user_id          = $user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "ACCOUNT_AGGREGATOR_INITIATE_REQUEST";
            $vendorServiceApi->request          = json_encode($input);
            $vendorServiceApi->response         = $response;
            $vendorServiceApi->borrower_id      = $borrower_primary_key;
            $vendorServiceApi->applicant_type   = $input['applicant_type'];
            $vendorServiceApi->save();

            return $this->helper_controller->send_response($status, $responseMessage, $httpCode, $resultData);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }
    
    public function initiate_request_admin(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => ['required', 'exists:users,id'], // Ensures exactly 10 digits
                'borrower_id' => ['required', 'exists:loan_basic_details,id'], // Ensures exactly 10 digits
                'mobile' => ['required', 'digits:10'], // Ensures exactly 10 digits
                'pan_no' => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'], // PAN validation
                'fip_id' => ['required', 'regex:/^[a-zA-Z0-9\-_]+$/'], // Allows letters, numbers, hyphens, underscores
                'applicant_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR',
            ]);
            
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);;
            }
            $input         = $request->all();
            $admin_user_id = $request->user()->id;

            $borrower_primary_key   = $request->borrower_id;
            $loan_details           = LoanBasicDetails::find($borrower_primary_key);
            
            $ip         = $this->helper_controller->get_user_ip();
            // $uniqueId   = $this->helper_controller->generate_random_id();
            $trackingId = $loan_details->borrower_id . '-' . date('Y-m-d-H-i-s');

            $lender_id = null;
            if (!empty($loan_details->lender_id)) {
                $lender_id = $loan_details->lender_id;
            }

            if(!$lender_id) {
                $lenderBorrowerGroup = LenderBorrowerGroup::where('borrower_id', $borrower_id)->first();
                $lender_id = $lenderBorrowerGroup->lender_id;
            }

            // Step 3: Try from LenderDealerGroup via LoanBasicDetail -> dealer_id -> dealer_unq
            if (!$lender_id && !empty($loan_details->dealer_id)) {
                $dealer = Dealer::where('dealer_unq', $loan_details->dealer_id)->first();
                if ($dealer) {
                    $lenderDealerGroup = LenderDealerGroup::where('dealer_id', $dealer->id)->where('status', '=', 'active')->first();
                    if ($lenderDealerGroup) {
                        $lender_id = $lenderDealerGroup->lender_id;
                    }
                }
            }

            if(!$lender_id) {
                return $this->helper_controller->send_response('failed', "Lender not assigned", 422);
            }

            $aa_api_data = $this->get_aa_api_key(4, 'UAT');
            if($aa_api_data['status'] == false) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }
            
            $data = [
                "phoneNumber" => 9000000000, //$request->mobile,
                "templateTypes" => [
                    "UNDERWRITING",
                    "MONITORING"
                ],
                "trackingId" => $trackingId,
                "fipIds" => [
                    "FIP-ID", // $request->fip_id,
                ],
                "secondaryIdentifiers" => [
                    [
                        "type" => "PAN",
                        "value" => $request->pan_no,
                    ]
                ],
                "redirectionUrl" => "https://google.com"
            ];
            
            $jsonData = json_encode($data, JSON_PRETTY_PRINT);

            $aa_request_details                         = new AaRequestDetails();
            $aa_request_details->borrower_id            = $borrower_primary_key;
            $aa_request_details->mobile                 = $request->mobile;
            $aa_request_details->pan_no                 = $request->pan_no;
            $aa_request_details->fip_ids                = $request->fip_id;
            $aa_request_details->tracking_id            = $trackingId;
            $aa_request_details->applicant_type         = $request->applicant_type;
            $aa_request_details->action_performed_by    = auth()->id();
            $aa_request_details->action_user_type       = "ADMIN USER";
            $aa_request_details->input_data             = json_encode($data);
            $aa_request_details->ip_address             = $this->helper_controller->get_user_ip();
            $aa_request_details->save();
            
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => $aa_api_data['url'] . 'web/multi-consent/initiate/phone-number',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonData,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'API_KEY: '. $aa_api_data['key'],
                    // 'Cookie: AWSALB=M9AQ5QWMkYkN6jFme6URqGrCG8a6KPW/dhMJ5On46DXA/Shkjk17iVbQztIaT1pwGg2KjcgkXANzQFYF23Wj+9/rf3Ou49baleNdDQ4McmunT5A/b0KUbqXg6xJr; AWSALBCORS=M9AQ5QWMkYkN6jFme6URqGrCG8a6KPW/dhMJ5On46DXA/Shkjk17iVbQztIaT1pwGg2KjcgkXANzQFYF23Wj+9/rf3Ou49baleNdDQ4McmunT5A/b0KUbqXg6xJr'
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }

            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            $result = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }

            $resultData = [];
            if($httpCode === 200) {
                $status = 'success';
                $api_status = "VALID";
                $responseMessage = "Request initiated successfully";
                $resultData = [
                    'redirectionUrl' => $result['redirectionUrl'] ?? '',
                ];
            } else {
                $status = 'failed';
                $api_status = "INVALID";
                $responseMessage = $result['errorMsg'] ?? "Failed to initiate request";
            }

            $vendorServiceApi                   = new VendorServiceApi();
            $vendorServiceApi->user_id          = $request->user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "ACCOUNT_AGGREGATOR_INITIATE_REQUEST";
            $vendorServiceApi->request          = json_encode($input);
            $vendorServiceApi->response         = $response;
            $borrower_id                        = $request->borrower_id;
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $input['applicant_type'];
            $vendorServiceApi->admin_user_id    = $admin_user_id;
            $vendorServiceApi->save();

            return $this->helper_controller->send_response($status, $responseMessage, $httpCode, $resultData);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function initiate_request_b2b(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => ['required', 'exists:users,id'], // Ensures exactly 10 digits
                'borrower_id' => ['required', 'exists:loan_basic_details,id'], // Ensures exactly 10 digits
                'mobile' => ['required', 'digits:10'], // Ensures exactly 10 digits
                'pan_no' => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'], // PAN validation
                'fip_id' => ['required', 'regex:/^[a-zA-Z0-9\-_]+$/'], // Allows letters, numbers, hyphens, underscores
                'applicant_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR',
            ]);
            
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);;
            }
            $input         = $request->all();
            $executive_id = $request->user()->id;

            $borrower_primary_key   = $request->borrower_id;
            $loan_details           = LoanBasicDetails::find($borrower_primary_key);
            
            $ip         = $this->helper_controller->get_user_ip();
            // $uniqueId   = $this->helper_controller->generate_random_id();
            $trackingId = $loan_details->borrower_id . '-' . date('Y-m-d-H-i-s');

            $lender_id = null;
            if (!empty($loan_details->lender_id)) {
                $lender_id = $loan_details->lender_id;
            }

            if(!$lender_id) {
                $lenderBorrowerGroup = LenderBorrowerGroup::where('borrower_id', $borrower_id)->first();
                $lender_id = $lenderBorrowerGroup->lender_id;
            }

            // Step 3: Try from LenderDealerGroup via LoanBasicDetail -> dealer_id -> dealer_unq
            if (!$lender_id && !empty($loan_details->dealer_id)) {
                $dealer = Dealer::where('dealer_unq', $loan_details->dealer_id)->first();
                if ($dealer) {
                    $lenderDealerGroup = LenderDealerGroup::where('dealer_id', $dealer->id)->where('status', '=', 'active')->first();
                    if ($lenderDealerGroup) {
                        $lender_id = $lenderDealerGroup->lender_id;
                    }
                }
            }

            if(!$lender_id) {
                return $this->helper_controller->send_response('failed', "Lender not assigned", 422);
            }
            
            $aa_api_data = $this->get_aa_api_key(4, 'UAT');
            if($aa_api_data['status'] == false) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }
            
            
            $data = [
                "phoneNumber" => '1234567890', // $request->mobile,
                "templateTypes" => [
                    "UNDERWRITING",
                    "MONITORING"
                ],
                "trackingId" => $trackingId,
                "fipIds" => [
                    "FIP-ID", // $request->fip_id,
                ],
                "secondaryIdentifiers" => [
                    [
                        "type" => "PAN",
                        "value" => $request->pan_no,
                    ]
                ],
                "redirectionUrl" => "https://google.com"
            ];
            
            $jsonData = json_encode($data, JSON_PRETTY_PRINT);

            $aa_request_details                         = new AaRequestDetails();
            $aa_request_details->borrower_id            = $borrower_primary_key;
            $aa_request_details->mobile                 = $request->mobile;
            $aa_request_details->pan_no                 = $request->pan_no;
            $aa_request_details->fip_ids                = $request->fip_id;
            $aa_request_details->tracking_id            = $trackingId;
            $aa_request_details->applicant_type         = $request->applicant_type;
            $aa_request_details->action_performed_by    = auth()->id();
            $aa_request_details->action_user_type       = "B2B EXECUTIVE";
            $aa_request_details->input_data             = json_encode($data);
            $aa_request_details->ip_address             = $this->helper_controller->get_user_ip();
            $aa_request_details->save();
            
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => $aa_api_data['url'] . 'web/multi-consent/initiate/phone-number',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonData,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'API_KEY: '. $aa_api_data['key'],
                    // 'Cookie: AWSALB=M9AQ5QWMkYkN6jFme6URqGrCG8a6KPW/dhMJ5On46DXA/Shkjk17iVbQztIaT1pwGg2KjcgkXANzQFYF23Wj+9/rf3Ou49baleNdDQ4McmunT5A/b0KUbqXg6xJr; AWSALBCORS=M9AQ5QWMkYkN6jFme6URqGrCG8a6KPW/dhMJ5On46DXA/Shkjk17iVbQztIaT1pwGg2KjcgkXANzQFYF23Wj+9/rf3Ou49baleNdDQ4McmunT5A/b0KUbqXg6xJr'
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }

            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            $result = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }

            $resultData = [];
            if($httpCode === 200) {
                $status = 'success';
                $api_status = "VALID";
                $responseMessage = "Request initiated successfully";
                $resultData = [
                    'redirectionUrl' => $result['redirectionUrl'] ?? '',
                ];
            } else {
                $status = 'failed';
                $api_status = "INVALID";
                $responseMessage = $result['errorMsg'] ?? "Failed to initiate request";
            }

            $vendorServiceApi                   = new VendorServiceApi();
            $vendorServiceApi->user_id          = $request->user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = "ACCOUNT_AGGREGATOR_INITIATE_REQUEST";
            $vendorServiceApi->request          = json_encode($input);
            $vendorServiceApi->response         = $response;
            $borrower_id                        = $request->borrower_id;
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $input['applicant_type'];
            $vendorServiceApi->executive_id     = $executive_id;
            $vendorServiceApi->save();

            return $this->helper_controller->send_response($status, $responseMessage, $httpCode, $resultData);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function store_account_aggregator_webhook_response(Request $request)
    {
        // Validate API Key
        $apiKey = $request->header('ApiKeyAuth');
        if (!$apiKey || $apiKey !== $this->webhook_secret) {
            Log::warning('Unauthorized webhook access attempt.', [
                'ip' => $this->helper_controller->get_user_ip(),
                'provided_key' => $apiKey,
            ]);
            return $this->helper_controller->send_response('failed', "Unauthorized", 401);
        }

        // Log the webhook call
        Log::info('AA Webhook Received at ' . now());

        // Input validation
        $validator = Validator::make($request->all(), [
            'notificationType' => 'required|string',
            'status' => 'required|string',
            'trackingId' => 'required|string',
            'referenceId' => 'required|string',
            'accounts' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        // Store data
        $aa_webhook_response                    = new AaWebhookResponse();
        $aa_webhook_response->notification_type = $request->input('notificationType');
        $aa_webhook_response->status            = $request->input('status');
        $aa_webhook_response->tracking_id       = $request->input('trackingId');
        $aa_webhook_response->reference_id      = $request->input('referenceId');
        $aa_webhook_response->response_data     = json_encode($request->all());
        $aa_webhook_response->ip_address        = $this->helper_controller->get_user_ip();
        $aa_webhook_response->save();

        $AaRequestDetails = AaRequestDetails::where('tracking_id', $request->input('trackingId'))->first();
        if($AaRequestDetails) {
            $AaRequestDetails->tracking_id  = $request->input('trackingId');
            $AaRequestDetails->reference_id = $request->input('referenceId');
            $AaRequestDetails->aa_status    = $request->input('status');
            $AaRequestDetails->save();
        }

        return $this->helper_controller->send_response('success', 'Webhook received and stored successfully', 200);
    }

    public function download_account_aggregator_excel_report(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                // 'user_id' => ['required', 'integer', 'exists:users,id'],
                'borrower_id' => ['required', 'integer', 'exists:loan_basic_details,id'],
                'applicant_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);;
            }

            $input         = $request->all();
            $admin_user_id = $request->user()->id;

            $borrower_primary_key   = $request->borrower_id;

            $loan_details           = LoanBasicDetails::find($borrower_primary_key);
            if(!$loan_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower", 422);
            }

            $lender_id = null;
            if (!empty($loan_details->lender_id)) {
                $lender_id = $loan_details->lender_id;
            }

            if(!$lender_id) {
                $lenderBorrowerGroup = LenderBorrowerGroup::where('borrower_id', $borrower_id)->first();
                $lender_id = $lenderBorrowerGroup->lender_id;
            }

            // Step 3: Try from LenderDealerGroup via LoanBasicDetail -> dealer_id -> dealer_unq
            if (!$lender_id && !empty($loan_details->dealer_id)) {
                $dealer = Dealer::where('dealer_unq', $loan_details->dealer_id)->first();
                if ($dealer) {
                    $lenderDealerGroup = LenderDealerGroup::where('dealer_id', $dealer->id)->where('status', '=', 'active')->first();
                    if ($lenderDealerGroup) {
                        $lender_id = $lenderDealerGroup->lender_id;
                    }
                }
            }

            if(!$lender_id) {
                return $this->helper_controller->send_response('failed', "Lender not assigned", 422);
            }

            $aa_api_data = $this->get_aa_api_key(4, 'UAT');
            if($aa_api_data['status'] == false) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            $ip         = $this->helper_controller->get_user_ip();

            $aa_request_details = AaRequestDetails::where('borrower_id', $request->borrower_id)
                                ->where('applicant_type', $request->applicant_type)
                                ->latest()
                                ->first();
            if(!$aa_request_details) {
                return $this->helper_controller->send_response('failed', "Account aggregator authorization not initiated yet", 422);
            }
            
            if($aa_request_details->aa_status != 'COMPLETED') {
                return $this->helper_controller->send_response('failed', "Account aggregator authorization not completed yet", 422);
            }
            $filename = "{$request->applicant_type}-AA-Report-{$loan_details->borrower_id}.xlsx";
            $relativePath = "AA_Report/{$loan_details->borrower_id}/{$request->applicant_type}/{$filename}";
            $storagePath = "public/{$relativePath}";

            if (Storage::exists($storagePath)) {
                // File already exists, return the public URL
                $publicUrl = Storage::url($relativePath);

                return $this->helper_controller->send_response('success', 'File already exists', 200, [
                    'download_link' => asset($publicUrl)
                ]);
            }

            // $postData = [
            //     'trackingId' => $aa_request_details->tracking_id,
            //     'referenceId' => $aa_request_details->reference_id
            // ];

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => $aa_api_data['url'] . 'web/fetch/analytics/excel?trackingId='.$aa_request_details->tracking_id.'&referenceId='. $aa_request_details->reference_id,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                // CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER => array(
                    'API_KEY: '. $aa_api_data['key']
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }

            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($httpCode === 200) {
                $fileContents = $response;
                $filename = "{$request->applicant_type}-AA-Report-{$loan_details->borrower_id}" . '.xlsx';
                $relativePath = "AA_Report/{$loan_details->borrower_id}/{$request->applicant_type}/{$filename}";
                $storagePath = "public/{$relativePath}";

                $oldUmask = umask(0); // This allows 0755 / 0644 to work
                Storage::put($storagePath, $fileContents);
                umask($oldUmask); // Restore original umask

                $absolutePath = storage_path("app/public/{$relativePath}");
                $directory = dirname($absolutePath);
                // Change directory and file permissions explicitly
                if (is_dir($directory)) {
                    @chmod($directory, 0755);
                }
                if (file_exists($absolutePath)) {
                    @chmod($absolutePath, 0644);
                }

                // Generate a public URL if symbolic link is set with `php artisan storage:link`
                $publicUrl = Storage::url("AA_Report/{$loan_details->borrower_id}/{$request->applicant_type}/{$filename}");

                $vendorServiceApi                   = new VendorServiceApi();
                $vendorServiceApi->user_id          = $loan_details->user_id;
                $vendorServiceApi->ip_address       = $ip;
                $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
                $vendorServiceApi->status           = "VALID";
                $vendorServiceApi->api_flag         = "ACCOUNT_AGGREGATOR_DOWNLOAD_EXCEL_REPORT";
                $vendorServiceApi->request          = json_encode($input);
                $vendorServiceApi->response         = $publicUrl;
                $borrower_id                        = $request->borrower_id;
                $vendorServiceApi->borrower_id      = $borrower_id;
                $vendorServiceApi->applicant_type   = $input['applicant_type'];
                $vendorServiceApi->admin_user_id    = $admin_user_id;
                $vendorServiceApi->save();

                return $this->helper_controller->send_response('success', 'Excel file stored successfully', 200, [
                    'download_link' => asset($publicUrl)
                ]);
            } else {
                $vendorServiceApi                   = new VendorServiceApi();
                $vendorServiceApi->user_id          = $loan_details->user_id;
                $vendorServiceApi->ip_address       = $ip;
                $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
                $vendorServiceApi->status           = "VALID";
                $vendorServiceApi->api_flag         = "ACCOUNT_AGGREGATOR_DOWNLOAD_EXCEL_REPORT";
                $vendorServiceApi->request          = json_encode($input);
                $vendorServiceApi->response         = $response;
                $borrower_id                        = $request->borrower_id;
                $vendorServiceApi->borrower_id      = $borrower_id;
                $vendorServiceApi->applicant_type   = $input['applicant_type'];
                $vendorServiceApi->admin_user_id    = $admin_user_id;
                $vendorServiceApi->save();
                return $this->helper_controller->send_response('failed', 'Failed to get aa excel report', 422);
            }
            
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    public function download_account_aggregator_pdf_report(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                // 'user_id' => ['required', 'integer', 'exists:users,id'],
                'borrower_id' => ['required', 'integer', 'exists:loan_basic_details,id'],
                'applicant_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);;
            }

            $input         = $request->all();
            $admin_user_id = $request->user()->id;

            $borrower_primary_key   = $request->borrower_id;
            $loan_details           = LoanBasicDetails::find($borrower_primary_key);

            $aa_api_data = $this->get_aa_api_key(4, 'UAT');
            if($aa_api_data['status'] == false) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            $ip         = $this->helper_controller->get_user_ip();

            $aa_request_details = AaRequestDetails::where('borrower_id', $request->borrower_id)
                                ->where('applicant_type', $request->applicant_type)
                                ->latest()
                                ->first();
            if(!$aa_request_details) {
                return $this->helper_controller->send_response('failed', "Account aggregator authorization not initiated yet", 422);
            }
            
            if($aa_request_details->aa_status != 'COMPLETED') {
                return $this->helper_controller->send_response('failed', "Account aggregator authorization not completed yet", 422);
            }

            $filename = "{$request->applicant_type}-AA-Report-{$loan_details->borrower_id}.pdf";
            $relativePath = "AA_Report/{$loan_details->borrower_id}/{$request->applicant_type}/{$filename}";
            $storagePath = "public/{$relativePath}";

            if (Storage::exists($storagePath)) {
                // File already exists, return the public URL
                $publicUrl = Storage::url($relativePath);

                return $this->helper_controller->send_response('success', 'File already exists', 200, [
                    'download_link' => asset($publicUrl)
                ]);
            }

            // $postData = [
            //     'trackingId' => $aa_request_details->tracking_id,
            //     'referenceId' => $aa_request_details->reference_id
            // ];

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => $aa_api_data['url'] . 'web/fetch/analytics/pdf?trackingId='.$aa_request_details->tracking_id.'&referenceId='. $aa_request_details->reference_id,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                // CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER => array(
                    'API_KEY: '. $aa_api_data['key']
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }

            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($httpCode === 200) {
                $fileContents = $response;
                $filename = "{$request->applicant_type}-AA-Report-{$loan_details->borrower_id}" . '.pdf';
                $relativePath = "AA_Report/{$loan_details->borrower_id}/{$request->applicant_type}/{$filename}";
                $storagePath = "public/{$relativePath}";

                $oldUmask = umask(0); // This allows 0755 / 0644 to work
                Storage::put($storagePath, $fileContents);
                umask($oldUmask); // Restore original umask

                $absolutePath = storage_path("app/public/{$relativePath}");
                $directory = dirname($absolutePath);
                // Change directory and file permissions explicitly
                if (is_dir($directory)) {
                    @chmod($directory, 0755);
                }
                if (file_exists($absolutePath)) {
                    @chmod($absolutePath, 0644);
                }

                // Generate a public URL if symbolic link is set with `php artisan storage:link`
                $publicUrl = Storage::url("AA_Report/{$loan_details->borrower_id}/{$request->applicant_type}/{$filename}");

                $vendorServiceApi                   = new VendorServiceApi();
                $vendorServiceApi->user_id          = $loan_details->user_id;
                $vendorServiceApi->ip_address       = $ip;
                $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
                $vendorServiceApi->status           = "VALID";
                $vendorServiceApi->api_flag         = "ACCOUNT_AGGREGATOR_DOWNLOAD_PDF_REPORT";
                $vendorServiceApi->request          = json_encode($input);
                $vendorServiceApi->response         = $publicUrl;
                $borrower_id                        = $request->borrower_id;
                $vendorServiceApi->borrower_id      = $borrower_id;
                $vendorServiceApi->applicant_type   = $input['applicant_type'];
                $vendorServiceApi->admin_user_id    = $admin_user_id;
                $vendorServiceApi->save();

                return $this->helper_controller->send_response('success', 'Excel file stored successfully', 200, [
                    'download_link' => asset($publicUrl)
                ]);
            } else {
                $vendorServiceApi                   = new VendorServiceApi();
                $vendorServiceApi->user_id          = $loan_details->user_id;
                $vendorServiceApi->ip_address       = $ip;
                $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
                $vendorServiceApi->status           = "VALID";
                $vendorServiceApi->api_flag         = "ACCOUNT_AGGREGATOR_DOWNLOAD_PDF_REPORT";
                $vendorServiceApi->request          = json_encode($input);
                $vendorServiceApi->response         = $response;
                $borrower_id                        = $request->borrower_id;
                $vendorServiceApi->borrower_id      = $borrower_id;
                $vendorServiceApi->applicant_type   = $input['applicant_type'];
                $vendorServiceApi->admin_user_id    = $admin_user_id;
                $vendorServiceApi->save();

                return $this->helper_controller->send_response('failed', 'Failed to get aa excel report', 422);
            }
            
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    // public function get_borrowers_aa_records(Request $request) {
    //     try {
    //         $borrowers = LoanBasicDetails::select([
    //             'id', 'user_id', 'borrower_id', 'status as loan_status', 'lender_id',
    //             'is_aa_skippable', 'aa_skip_enabled_by', 'aa_skip_enabled_at', 'aa_skip_reason',
    //         ])
    //         ->with([
    //             'latestBorrowerKyc:id,borrower_id,name,mobile,pan',
    //             'latestCoapplicantKyc:id,borrower_id,name,mobile,pan',
    //             'latestGuarantor:id,borrower_id,name,mobile,pan',

    //             // AA‑request statuses added here
    //             'borrowerAa:id,borrower_id,aa_status',
    //             'coapplicantAa:id,borrower_id,aa_status',
    //             'guarantorAa:id,borrower_id,aa_status',
    //         ])
    //         ->where('status', '<>', 'REJECTED')
    //         ->whereRaw(
    //             "loan_application_steps::jsonb @> '[{\"step\": 1, \"status\": \"COMPLETED\"}]'::jsonb"
    //         )
    //         ->orderBy('id', 'asc')
    //         ->get()// ↓ flatten the three AA relations into simple attributes
    //         ->map(function ($loan) {
    //             $loan->borrower_aa_status    = $loan->borrowerAa->aa_status;
    //             $loan->coapplicant_aa_status = $loan->coapplicantAa->aa_status;
    //             $loan->guarantor_aa_status   = $loan->guarantorAa->aa_status;
    //             $loan->aa_skip_details       = [
    //                 'is_aa_skippable' => $loan->is_aa_skippable,
    //                 'aa_skip_enabled_by' => $loan->aa_skip_enabled_by,
    //                 'aa_skip_enabled_at' => $loan->aa_skip_enabled_at,
    //                 'aa_skip_reason' => $loan->aa_skip_reason,
    //             ];

    //             // we no longer need the relation objects in the JSON response
    //             unset($loan->borrowerAa, $loan->coapplicantAa, $loan->guarantorAa, $loan->latestBankDetails);
    //             return $loan;
    //         });;
            
    //         return $borrowers;
    //     } catch (Throwable $e) {
    //         return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
    //     }
    // }
    public function get_borrowers_aa_records(Request $request)
    {
        try {
            // Validate incoming request
            $validator = Validator::make($request->all(), [
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'borrower_id' => 'sometimes|string|max:50',
                'borrower_name' => 'sometimes|string|max:100',
                'mobile' => 'sometimes|string|regex:/^[0-9]{6,15}$/',
                'pan' => 'sometimes|string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/i',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors(), 422);
            }

            $query = LoanBasicDetails::select([
                    'id', 'user_id', 'borrower_id', 'status as loan_status', 'lender_id',
                    'is_aa_skippable', 'aa_skip_enabled_by', 'aa_skip_enabled_at', 'aa_skip_reason',
                ])
                ->with([
                    'latestBorrowerKyc:id,borrower_id,name,mobile,pan',
                    'latestCoapplicantKyc:id,borrower_id,name,mobile,pan',
                    'latestGuarantor:id,borrower_id,name,mobile,pan',
                    'borrowerAa:id,borrower_id,aa_status',
                    'coapplicantAa:id,borrower_id,aa_status',
                    'guarantorAa:id,borrower_id,aa_status',
                ])
                ->where('status', '<>', 'REJECTED')
                ->whereRaw(
                    "loan_application_steps::jsonb @> '[{\"step\": 1, \"status\": \"COMPLETED\"}]'::jsonb"
                );

            // borrower_id filter
            if ($request->filled('borrower_id')) {
                $bid = $request->borrower_id;
                if (is_numeric($bid)) {
                    $query->where('id', $bid);
                } else {
                    $query->where('borrower_id', $bid);
                }
            }

            // borrower_name filter
            if ($request->filled('borrower_name')) {
                $name = strtolower($request->borrower_name);
                $query->whereHas('latestBorrowerKyc', function ($q) use ($name) {
                    $q->whereRaw('LOWER(name) LIKE ?', ["%$name%"]);
                });
            }

            // mobile filter (borrower only)
            if ($request->filled('mobile')) {
                $mobile = strtolower($request->mobile);
                $query->whereHas('latestBorrowerKyc', function ($q) use ($mobile) {
                    $q->whereRaw('LOWER(mobile) LIKE ?', ["%$mobile%"]);
                });
            }

            // pan filter (borrower only)
            if ($request->filled('pan')) {
                $pan = strtolower($request->pan);
                $query->whereHas('latestBorrowerKyc', function ($q) use ($pan) {
                    $q->whereRaw('LOWER(pan) LIKE ?', ["%$pan%"]);
                });
            }


            // Pagination
            $perPage = $request->get('per_page', 10);
            $page = $request->get('page', 1);

            $paginator = $query->orderBy('id', 'desc')->paginate($perPage, ['*'], 'page', $page);

            // Transform records
            $paginator->getCollection()->transform(function ($loan) {
                $loan->borrower_aa_status    = $loan->borrowerAa->aa_status ?? 'PENDING';
                $loan->coapplicant_aa_status = $loan->coapplicantAa->aa_status ?? 'PENDING';
                $loan->guarantor_aa_status   = $loan->guarantorAa->aa_status ?? 'PENDING';
                $loan->aa_skip_details       = [
                    'is_aa_skippable' => $loan->is_aa_skippable,
                    'aa_skip_enabled_by' => $loan->aa_skip_enabled_by,
                    'aa_skip_enabled_at' => $loan->aa_skip_enabled_at,
                    'aa_skip_reason' => $loan->aa_skip_reason,
                ];

                unset($loan->borrowerAa, $loan->coapplicantAa, $loan->guarantorAa, $loan->latestBankDetails);
                return $loan;
            });

            return $this->helper_controller->send_response('success', 'Account aggregator records fetched', 200, $paginator);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 500);
        }
    }

    public function check_aa_status(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => ['required', 'exists:loan_basic_details,id'], // Ensures exactly 10 digits
                'applicant_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);;
            }

            
            $aa_request_details = AaRequestDetails::where('borrower_id', $request->borrower_id)
                ->where('applicant_type', $request->applicant_type)
                ->latest()
                ->first();

            if(!$aa_request_details) {
                return $this->helper_controller->send_response('failed', "Request not initiated yet", 422);
            }

            $result_data = [
                'mobile' => $aa_request_details->mobile,
                'pan_no' => $aa_request_details->pan_no,
                'tracking_id' => $aa_request_details->tracking_id,
                'aa_status' => 'COMPLETED', //$aa_request_details->aa_status,
            ];

            return $this->helper_controller->send_response('success', "AA status fetched", 200, $result_data);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    public function check_aa_status_b2c(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => ['required', 'exists:loan_basic_details,id'], // Ensures exactly 10 digits
                'applicant_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR',
            ]);

            if ($validator->fails()) {
                $result_data = [
                    'aa_status' => 'NOT INITIATED',
                ];

                return $this->helper_controller->send_response('success', "AA status fetched", 200, $result_data);
            }

            
            $aa_request_details = AaRequestDetails::where('borrower_id', $request->borrower_id)
                ->where('applicant_type', $request->applicant_type)
                ->latest()
                ->first();

            if(!$aa_request_details) {
                return $this->helper_controller->send_response('failed', "Request not initiated yet", 422);
            }

            $result_data = [
                'mobile' => $aa_request_details->mobile,
                'pan_no' => $aa_request_details->pan_no,
                'tracking_id' => $aa_request_details->tracking_id,
                'aa_status' => $aa_request_details->aa_status,
            ];

            return $this->helper_controller->send_response('success', "AA status fetched", 200, $result_data);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    
}
