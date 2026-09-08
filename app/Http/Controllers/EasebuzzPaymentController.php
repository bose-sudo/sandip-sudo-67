<?php
// UAT
namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\BorrowerAccountDetails;
use App\Models\BorrowerKyc;
use App\Models\BorrowerRepaymentSchedule;
use App\Models\BorrowerLoanScheme;
use App\Models\Dealer;
use App\Models\DisbursementDetails;
use App\Models\EmiTransactions;
use App\Models\EnachDebitRequest;
use App\Models\EnachDebitResponse;
use App\Models\EnachRequest;
use App\Models\EnachMandateFormData;
use App\Models\EnachScheduleLogs;
use App\Models\LenderBorrowerGroup;
use App\Models\LenderDirectors;
use App\Models\Lenders;
use App\Models\LoanBasicDetails;
use App\Models\MandateStatusUpdate;
use App\Models\NpciBankList;
use App\Models\PaymentGatewayResponse;
use App\Models\PresentmentStatusUpdate;
use App\Models\ProductModel;
use App\Models\SystemSetting;
use App\Models\VendorServiceApi;
use App\Traits\ApiResponse;
use App\Traits\CalculatesLoanSchedule;
use App\Traits\ResolvesBaseQuery;
use App\Services\BorrowerVisibilityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Services\TwilioService;
use App\Services\SmsService;
use Throwable;

class EasebuzzPaymentController extends Controller
{
    use ApiResponse;
    use CalculatesLoanSchedule;
    use ResolvesBaseQuery;
    protected $helper_controller;
    private $easebuzz_auth_key;
    private $wire_api_key;
    private $easebuzz_test_salt;
    private $easebuzz_test_key;
    private $easebuzz_enach_url_uat;
    private $easebuzz_enach_url_prod;
    private $sub_merchant_id;
    protected $twilio;
    private $auth_mode;
    protected SmsService $smsService;

    public function __construct(HelperController $helper_controller,
        TwilioService $twilio,
        SmsService $smsService,
        private readonly BorrowerVisibilityService $visibilityService
    )
    {
        $this->helper_controller = $helper_controller;
        $this->easebuzz_auth_key = '';
        $this->wire_api_key = '';

        $this->twilio = $twilio;
        
        // Production
        // $this->easebuzz_enach_url_prod = config('easebuzz.base_url');
        // $this->easebuzz_test_salt = config('easebuzz.merchant_salt');
        // $this->easebuzz_test_key = config('easebuzz.merchant_key');
        
        $this->sub_merchant_id = ''; //config('easebuzz.sub_merchant_akme'); // akme
        
        // Test
        $this->easebuzz_enach_url_prod = $this->easebuzz_enach_url_uat = config('easebuzz.base_url_uat');
        $this->easebuzz_test_key = config('easebuzz.merchant_key_uat');
        $this->easebuzz_test_salt = config('easebuzz.merchant_salt_uat');
        
        $this->smsService = $smsService;
        // $this->auth_mode = 'aadhaar';
        $this->auth_mode = 'debit_card';
    }

    public function encrypt_data($data) {
        return $this->encryptField($data, $this->easebuzz_test_key, $this->easebuzz_test_salt);
    }

    public function store_api_request_response(Request $request) {
        try {
            $validator = Validator::make($request->all(), [            
                'status' => 'required|in:VALID,INVALID',
                'api_flag' => 'required|string|regex:/^[A-Z_]+$/',
                'request_value'  => ['required', function ($attribute, $value, $fail) {
                    if (!is_array($value) && !is_string($value)) {
                        $fail("The {$attribute} field must be a valid JSON string or an array.");
                    }
                }],
                'response' => ['required', function ($attribute, $value, $fail) {
                    if (!is_array($value) && !is_string($value)) {
                        $fail("The {$attribute} field must be a valid JSON string or an array.");
                    }
                }],
                'borrower_id' => 'nullable|exists:loan_basic_details,id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed' , $validator->errors()->first(), 422);
            }

            $requestData = is_array($request->request_value) ? json_encode($request->request_value) : $request->request_value;
            $responseData = is_array($request->response) ? json_encode($request->response) : $request->response;

            $borrower_id = filled($request->borrower_id)
            ? $request->borrower_id
            : $this->helper_controller->get_borrower_id($request->user()->id);

            $gatewayResponse                = new PaymentGatewayResponse();
            $gatewayResponse->user_id       = auth()->id();
            $gatewayResponse->ip            = $this->helper_controller->get_user_ip();
            $gatewayResponse->date_time     = now();
            $gatewayResponse->status        = $request->status;
            $gatewayResponse->api_flag      = $request->api_flag;
            $gatewayResponse->borrower_id   = $borrower_id;
            $gatewayResponse->user_type     = 'BORROWER';
            $gatewayResponse->request       = base64_encode(serialize($requestData));
            $gatewayResponse->response      = base64_encode(serialize($responseData));
            $gatewayResponse->save(); 

            return $this->helper_controller->send_response('success', "API response stored successfully", 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    /**
     * Initiate Payment API for Easebuzz.
     */
    public function initiate_payment(Request $request)
    {
        try {
            return $this->helper_controller->send_response('failed', "Service not active", 422);

            $validator = Validator::make($request->all(), [
                'installment_no'  => 'required|integer',
                'installment_id'  => 'required|integer|exists:borrower_repayment_schedules,id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
            
            if (!auth()->check()) {
                return $this->helper_controller->send_response('failed', "Unauthenticated access, please login", 422);
            }

            // Start a database transaction for all DB changes.
            DB::beginTransaction();

            $user_id                = auth()->id();
            $borrower_primary_id    = $this->helper_controller->get_borrower_id($user_id);

            $borrower_details = BorrowerKyc::where('borrower_id', $borrower_primary_id)->first();
            if (!$borrower_details) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', "Invalid borrower", 422);
            }

            $loan_details = LoanBasicDetails::find($borrower_primary_id);
            if (!$loan_details) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', "Invalid borrower", 422);
            }

            $check_installment = BorrowerRepaymentSchedule::where('borrower_id', $borrower_primary_id)
                ->where('number_of_installment', $request->installment_no)
                ->where('id', $request->installment_id)
                ->first();
            if (!$check_installment) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            if ($check_installment->payment_status === "PAID") {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', "This installment is already paid", 422);
            }

            $fine_amount = $check_installment->fine_amount;
            $total_payment_amount = $check_installment->emi_amount + $fine_amount;

            // Create a new EMI transaction.
            $emi_txn                    = new EmiTransactions();
            $emi_txn->payment_date      = date('Y-m-d');
            $emi_txn->borrower_id       = $loan_details->id;
            $emi_txn->payment_status    = 'PENDING';
            $emi_txn->emi_amount        = $check_installment->emi_amount;
            $emi_txn->fine_amount       = $fine_amount;
            $emi_txn->total_payment     = $total_payment_amount;
            $emi_txn->user_id           = auth()->id();
            $emi_txn->ip                = $this->helper_controller->get_user_ip();
            $emi_txn->save();
            
            $txnnumber                  = str_pad($emi_txn->id, 3, '0', STR_PAD_LEFT);
            $transactionId              = "FIN-" . date('YmdHis') . '-' . $txnnumber;
            $emi_txn->transaction_id    = $transactionId;
            $emi_txn->save();

            $check_installment->transaction_id = $transactionId;
            $check_installment->save();
            // Commit the transaction now that the DB changes are done.
            DB::commit();
            
            // Prepare Easebuzz Payment Data
            $borrower_name = $borrower_details->name;
            $name_parts    = explode(' ', $borrower_name);
            $first_name    = $name_parts[0];

            $key           = $this->easebuzz_test_key;
            // Example transaction ID format: TEST-YYYYMMDDHHMMSS + random string
            // $transactionId = "FIN-" . date('YmdHis') . $this->helper_controller->generate_random_string(6);
            $amount        = number_format($total_payment_amount, 1, '.', ''); // For testing purposes
            $productInfo   = "EV VEHICLE";
            $firstName     = $first_name;
            $phone         = $borrower_details->mobile;
            $email         = $borrower_details->email;
            $success_url   = 'https://google.com';
            $failure_url   = 'https://google.com';
            $paymentMode   = 'NB,DC,CC,MW,UPI,OM,EMI,BT,BT';
            $request_flow  = 'SEAMLESS';
            $salt          = $this->easebuzz_test_salt;

            // UDF fields (here, only udf1 is used)
            $udf1 = $loan_details->borrower_id;
            $udf2 = $udf3 = $udf4 = $udf5 = $udf6 = $udf7 = $udf8 = $udf9 = $udf10 = '';

            // Build the hash string as per Easebuzz requirements
            $hashData = $key . "|" . $transactionId . "|" . $amount . "|" . $productInfo . "|" . $firstName . "|" . $email . "|" .
                        $udf1 . "|" . $udf2 . "|" . $udf3 . "|" . $udf4 . "|" . $udf5 . "|" . $udf6 . "|" . $udf7 . "|" . 
                        $udf8 . "|" . $udf9 . "|" . $udf10 . "|" . $salt;
            $hash = hash('sha512', $hashData);

            // Prepare POST data (URL-encoded)
            $postData = http_build_query([
                'key'             => $key,
                'txnid'           => $transactionId,
                'amount'          => $amount,
                'productinfo'     => $productInfo,
                'firstname'       => $firstName,
                'phone'           => $phone,
                'email'           => $email,
                'surl'            => $success_url,
                'furl'            => $failure_url,
                'hash'            => $hash,
                'udf1'            => $udf1,
                'udf2'            => $udf2,
                'udf3'            => $udf3,
                'udf4'            => $udf4,
                'udf5'            => $udf5,
                'udf6'            => $udf6,
                'udf7'            => $udf7,
                'address1'        => '',
                'address2'        => '',
                'city'            => '',
                'state'           => '',
                'country'         => '',
                'zipcode'         => '',
                'show_payment_mode'=> $paymentMode,
                'request_flow'    => $request_flow,
                'sub_merchant_id' => '',
                'payment_category'=> '',
                'account_no'      => '',
                'ifsc'            => ''
            ]);

            // Initiate cURL call to Easebuzz
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }
            curl_setopt_array($curl, [
                // CURLOPT_URL            => 'https://testpay.easebuzz.in/payment/initiateLink', // UAT
                CURLOPT_URL            => 'https://pay.easebuzz.in/payment/initiateLink', // Production
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => $postData,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded'
                ],
            ]);

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }

            $status     = 'failed';
            $api_status = "INVALID";
            if ($httpCode == 200) {
                $api_status = "VALID";
                $status     = 'success';
            }
            $result = json_decode($response, true);
            // Optionally add the hash into the result
            $result['hash'] = $hash;

            // Log API request/response
            $vendorServiceApi                   = new PaymentGatewayResponse();
            $vendorServiceApi->user_id          = $user_id ?? 0;
            $vendorServiceApi->ip               = $this->helper_controller->get_user_ip();
            $vendorServiceApi->date_time        = date("Y-m-d H:i:s");
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->borrower_id      = $borrower_primary_id;
            $vendorServiceApi->api_flag         = "EASEBUZZ_INITIATE_PAYMENT";
            $vendorServiceApi->request          = json_encode([$request->all(), ['prepared_data' => $postData]]);
            $vendorServiceApi->response         = $response;
            $vendorServiceApi->save();

            return $this->helper_controller->send_response($status, "API result fetched", $httpCode, $result);

        } catch (Exception $e) {
            // Roll back any pending transaction if not already rolled back.
            DB::rollBack();
            Log::error("Initiate Payment Error: " . $e->getMessage());
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: '. $e->getMessage(), 422);
        }
    }

    /**
     * Webhook to receive Easebuzz transaction responses.
     * This endpoint should be registered (e.g., in routes/api.php)
     * as a POST route: /easebuzz/webhook.
     */
    public function payment_webhook(Request $request)
    {
        try {
            $responseArray = $request->all();
            Log::info('Easebuzz webhook received', $responseArray);

            // Validate required fields.
            $validator = Validator::make($responseArray, [
                'hash'   => 'required|string',
                'status' => 'required|string',
                'txnid'  => 'required|string',
                'amount' => 'nullable',
            ]);
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            // Use your merchant salt key from the .env file
            $sKey = $this->easebuzz_test_salt;

            // Make sure amount is formatted dynamically
            $responseArray['amount'] = $responseArray['amount'] ?? 0;
            $responseArray['amount'] = number_format($responseArray['amount'], 1, '.', '');
            $computedHash = $this->_getReverseHashKey($responseArray, $sKey);

            if ($computedHash !== $responseArray['hash']) {
                Log::error('Webhook hash mismatch', ['computed' => $computedHash, 'received' => $responseArray['hash']]);
                return $this->helper_controller->send_response('failed', 'Hash key Mismatch', 401);
            }

            // Log the webhook response in PaymentGatewayResponse.
            DB::transaction(function () use ($responseArray) {
                $vendorServiceApi              = new PaymentGatewayResponse();
                $vendorServiceApi->user_id     = 0; // System initiated or unknown user
                $vendorServiceApi->ip          = $this->helper_controller->get_user_ip();
                $vendorServiceApi->date_time   = date("Y-m-d H:i:s");
                $vendorServiceApi->status      = 'VALID';
                $vendorServiceApi->api_flag    = "EASEBUZZ_WEBHOOK_RESPONSE";
                $vendorServiceApi->request     = json_encode($responseArray);
                $vendorServiceApi->response    = json_encode($responseArray);
                $vendorServiceApi->save();
            });

            // Process the transaction update in a DB transaction.
            DB::transaction(function () use ($responseArray) {
                // Find the transaction record.
                $emi_txn = EmiTransactions::where('transaction_id', $responseArray['txnid'])->first();
                if (!$emi_txn) {
                    Log::info('Invalid Transaction Id', ['txnid' => $responseArray['txnid']]);
                    return;
                }

                if($emi_txn === 'SUCCESS') {
                    return;
                }

                // Update the EMI transaction based on status.
                if ($responseArray['status'] === 'success') {
                    $emi_txn->payment_status     = "SUCCESS";
                } elseif ($responseArray['status'] === 'failure') {
                    $emi_txn->payment_status     = "FAILED";
                } else {
                    $emi_txn->payment_status     = "PENDING";
                }
                $emi_txn->payment_date       = date('Y-m-d', strtotime($responseArray['addedon']));
                $emi_txn->payment_updated_at = date('Y-m-d H:i:s', strtotime($responseArray['addedon']));
                $emi_txn->bank_ref_num       = $responseArray['bank_ref_num'] ?? null;
                $emi_txn->bankcode           = $responseArray['bankcode'] ?? null;
                $emi_txn->error              = $responseArray['error'] ?? null;
                $emi_txn->error_Message      = $responseArray['error_Message'] ?? null;
                $emi_txn->name_on_card       = $responseArray['name_on_card'] ?? null;
                $emi_txn->upi_va             = $responseArray['upi_va'] ?? null;
                $emi_txn->cardnum            = $responseArray['cardnum'] ?? null;
                $emi_txn->issuing_bank       = $responseArray['issuing_bank'] ?? null;
                $emi_txn->easepayid          = $responseArray['easepayid'] ?? null;
                $emi_txn->card_type          = $responseArray['card_type'] ?? null;
                $emi_txn->hash               = $responseArray['hash'] ?? null;
                $emi_txn->gateway_status     = $responseArray['status'] ?? null;
                $emi_txn->bank_name          = $responseArray['bank_name'] ?? null;
                $emi_txn->auth_code          = $responseArray['auth_code'] ?? null;
                $emi_txn->auth_ref_num       = $responseArray['auth_ref_num'] ?? null;
                $emi_txn->save();

                if ($responseArray['status'] === 'success') {
                    // Also update the installment schedule.
                    $emi_schedule = BorrowerRepaymentSchedule::where('transaction_id', $responseArray['txnid'])->first();
                    if ($emi_schedule) {
                        $emi_schedule->payment_status = 'PAID';
                        $emi_schedule->payment_date    = date('Y-m-d', strtotime($responseArray['addedon']));
                        $emi_schedule->fine_amount    = $emi_txn->fine_amount;
                        $emi_schedule->total_payment   = $responseArray['amount'];
                        $emi_schedule->receipt_no     = $responseArray['txnid'];
                        $emi_schedule->save();
                    }
                }
            });

            return $this->helper_controller->send_response('success', 'Webhook processed successfully', 200, $responseArray);
        } catch (Exception $e) {
            Log::error("Webhook Processing Error: " . $e->getMessage());
            return $this->helper_controller->send_response('failed', 'An unknown error occurred '. $e->getMessage(), 422);
        }
    }

    /**
     * Generate the reverse hash key for Easebuzz webhook verification.
     *
     * Reverse hash sequence (as per Easebuzz documentation):
     * salt|status|udf10|udf9|udf8|udf7|udf6|udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key
     *
     * @param array  $responseArray The response payload.
     * @param string $sKey          The merchant salt key.
     *
     * @return string The computed reverse hash.
     */
    private function _getReverseHashKey($responseArray, $sKey)
    {
        $reverseHashSequence = "udf10|udf9|udf8|udf7|udf6|udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key";
        $sequenceArray       = explode('|', $reverseHashSequence);

        // Build the reverse hash string starting with salt and response status.
        $reverseHash = $sKey . '|' . $responseArray['status'];

        // Append each field from the sequence (if available in response).
        foreach ($sequenceArray as $key) {
            $reverseHash .= '|' . (isset($responseArray[$key]) ? $responseArray[$key] : '');
        }

        return strtolower(hash('sha512', $reverseHash));
    }

    public function store_easebuzz_payment_response(Request $request) {
        try {
            $validator = Validator::make($request->all(), [            
                'status' => 'required|in:VALID,INVALID',
                'api_flag' => 'required|string|regex:/^[A-Z_]+$/',
                'request_value'  => ['required', function ($attribute, $value, $fail) {
                    if (!is_array($value) && !is_string($value)) {
                        $fail("The {$attribute} field must be a valid JSON string or an array.");
                    }
                }],
                'response' => ['required', function ($attribute, $value, $fail) {
                    if (!is_array($value) && !is_string($value)) {
                        $fail("The {$attribute} field must be a valid JSON string or an array.");
                    }
                }],
                'borrower_id' => 'nullable|exists:loan_basic_details,id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed' , $validator->errors()->first(), 422);
            }

            $requestData = is_array($request->request_value) ? json_encode($request->request_value) : $request->request_value;
            $responseData = is_array($request->response) ? json_encode($request->response) : $request->response;

            $borrower_id = filled($request->borrower_id)
            ? $request->borrower_id
            : $this->helper_controller->get_borrower_id($request->user()->id);

            $responseArray = json_decode($responseData, true);
            if(!isset($responseArray['payment_response'])) {
                return $this->helper_controller->send_response('failed' , "Invalid response", 422);
            }

            $responseArray = $responseArray['payment_response'];
            
            $sKey = $this->easebuzz_test_salt;
            $responseArray['amount'] = number_format($responseArray['amount'], 1, '.', '');
            $computedHash = $this->_getReverseHashKey($responseArray, $sKey);

            if ($computedHash !== $responseArray['hash']) {
                Log::error('Webhook hash mismatch', ['computed' => $computedHash, 'received' => $responseArray['hash']]);
                return $this->helper_controller->send_response('failed', 'Hash key Mismatch', 401);
            }

            $gatewayResponse                = new PaymentGatewayResponse();
            $gatewayResponse->user_id       = auth()->id();
            $gatewayResponse->ip            = $this->helper_controller->get_user_ip();
            $gatewayResponse->date_time     = now();
            $gatewayResponse->status        = $request->status;
            $gatewayResponse->api_flag      = $request->api_flag;
            $gatewayResponse->borrower_id   = $borrower_id;
            $gatewayResponse->user_type     = 'BORROWER';
            $gatewayResponse->request       = base64_encode(serialize($requestData));
            $gatewayResponse->response      = base64_encode(serialize($responseData));
            $gatewayResponse->save(); 
            
            // Process the transaction update in a DB transaction.
            DB::transaction(function () use ($responseArray) {
                // Find the transaction record.
                $emi_txn = EmiTransactions::where('transaction_id', $responseArray['txnid'])->first();
                if (!$emi_txn) {
                    Log::info('Invalid Transaction Id', ['txnid' => $responseArray['txnid']]);
                    return;
                }

                // Update the EMI transaction based on status.
                $payment_status = 'UNPAID';
                if ($responseArray['status'] === 'success') {
                    $payment_status = 'PAID';
                    $emi_txn->payment_status     = "SUCCESS";
                } elseif ($responseArray['status'] === 'failure') {
                    $emi_txn->payment_status     = "FAILED";
                } else {
                    $emi_txn->payment_status     = "PENDING";
                }
                $emi_txn->payment_date       = date('Y-m-d', strtotime($responseArray['addedon']));
                $emi_txn->payment_updated_at = date('Y-m-d H:i:s', strtotime($responseArray['addedon']));
                $emi_txn->bank_ref_num       = $responseArray['bank_ref_num'] ?? null;
                $emi_txn->bankcode           = $responseArray['bankcode'] ?? null;
                $emi_txn->error              = $responseArray['error'] ?? null;
                $emi_txn->error_Message      = $responseArray['error_Message'] ?? null;
                $emi_txn->name_on_card       = $responseArray['name_on_card'] ?? null;
                $emi_txn->upi_va             = $responseArray['upi_va'] ?? null;
                $emi_txn->cardnum            = $responseArray['cardnum'] ?? null;
                $emi_txn->issuing_bank       = $responseArray['issuing_bank'] ?? null;
                $emi_txn->easepayid          = $responseArray['easepayid'] ?? null;
                $emi_txn->card_type          = $responseArray['card_type'] ?? null;
                $emi_txn->hash               = $responseArray['hash'] ?? null;
                $emi_txn->gateway_status     = $responseArray['status'] ?? null;
                $emi_txn->bank_name          = $responseArray['bank_name'] ?? null;
                $emi_txn->auth_code          = $responseArray['auth_code'] ?? null;
                $emi_txn->auth_ref_num       = $responseArray['auth_ref_num'] ?? null;
                $emi_txn->save();

                if ($responseArray['status'] === 'success') {
                    // Also update the installment schedule.
                    $emi_schedule = BorrowerRepaymentSchedule::where('transaction_id', $responseArray['txnid'])->first();
                    if ($emi_schedule) {
                        $emi_schedule->payment_status = 'PAID';
                        $emi_schedule->payment_date    = date('Y-m-d', strtotime($responseArray['addedon']));
                        $emi_schedule->fine_amount    = $emi_txn->fine_amount;
                        $emi_schedule->total_payment   = $responseArray['amount'];
                        $emi_schedule->receipt_no     = $responseArray['txnid'];
                        $emi_schedule->save();
                    }
                }
            });

            return $this->helper_controller->send_response('success', "API response stored successfully", 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function get_emi_receipt(Request $requst, $receipt_no=null) {
        try {

            // return $this->helper_controller->send_response('failed' , "Update in process", 422);

            ini_set('memory_limit', '10M');
            ini_set('max_execution_time', 300);

            $user_id = auth()->id();

            $receipt_no = $receipt_no;
            $repayment_schedule = BorrowerRepaymentSchedule::where('receipt_no', $receipt_no)->first();
            if(!$repayment_schedule) {
                return $this->helper_controller->send_response('failed' , "Invalid borrower", 422);
            }
            $borrower_id    = $repayment_schedule->borrower_id; //$this->helper_controller->get_borrower_id($user_id);
            

            $loan_details = LoanBasicDetails::find($borrower_id);
            if(!$loan_details) {
                return $this->helper_controller->send_response('failed' , "Invalid borrower", 422);
            }

            $borrower_details = BorrowerKyc::select(['name', 'mobile'])->where('borrower_id', $borrower_id)->first();
            if(!$borrower_details) {
                return $this->helper_controller->send_response('failed' , "Invalid borrower", 422);
            }
            
            $disbursement_details = DisbursementDetails::select(['loan_id', 'lender_id'])->where('borrower_id', $borrower_id)->first();
            
            $lender_details = Lenders::find($disbursement_details->lender_id);
            
            $transaction_detail = EmiTransactions::where('transaction_id', $repayment_schedule->receipt_no)->first();
            if(!$transaction_detail) {
                return $this->helper_controller->send_response('failed' , "Transaction not found", 422);
            }

            // $pdf = Pdf::loadView('pdf.emi-receipt', $data);
            // $pdf = Pdf::loadView('pdf.agreement-2', $data)->setPaper('a4', 'portrait');
            $card_type = $transaction_detail->card_type ?? '';
            // $via = ($transaction_detail->upi_va != '' && $transaction_detail->upi_va != 'NA') 
            //         ? $transaction_detail->upi_va 
            //         : (($transaction_detail->cardnum != '' && $transaction_detail->cardnum != 'NA') 
            //             ? $transaction_detail->cardnum 
            //             : '-');
            $utr_number = ($transaction_detail->bank_ref_num != '' && $transaction_detail->bank_ref_num != 'NA') 
                    ? $transaction_detail->bank_ref_num : '-';

            // $payment_mode = $card_type;
            $payment_mode = !empty($card_type) ? $card_type : (!empty($transaction_detail->payment_mode) ? $transaction_detail->payment_mode : '');
            
            $loan_id = !empty($transaction_detail->loan_id) ? $transaction_detail->loan_id :
            (!empty($disbursement_details->loan_id) ? $disbursement_details->loan_id : '');
            $amountInWords = ucfirst($this->helper_controller->numberToWords($transaction_detail->total_payment) ?? '');
            $amountInWords = str_replace('-', ' ', $amountInWords);

            if($lender_details->name == 'FMEC International') {
                $lender_address = "Mercantile House, 15 K.G. Marg New Delhi – 110 001";
            } else {
                $lender_address = 'Akme Business Centre, 4-5 Subcity Center, Savina Circle, Udaipur 313002, Rajasthan';
            }
            $number_of_installment = (int) $repayment_schedule->number_of_installment;

            $data = [
                'lender_address' => $lender_address,
                'payment_date' => ($transaction_detail->payment_date) ? date('d/m/Y', strtotime($transaction_detail->payment_date)) : '',
                'receipt_no' => $repayment_schedule->receipt_no ?? '',
                'lender_name' => $lender_details->name ?? '',
                'borrower_name' => $borrower_details->name ?? '',
                'borrower_fin_id' => $loan_details->borrower_id ?? '',
                'borrower_mobile' => $borrower_details->mobile ?? '',
                'loan_account_number' => $loan_id,
                'loan_tenure' => $loan_details->loan_term ?? '',
                'emi_amount' => $repayment_schedule->emi_amount ?? '',
                'due_date' => ($repayment_schedule->due_date) ? date('d/m/Y', strtotime($repayment_schedule->due_date)) : '',
                'payment_received_date' => ($transaction_detail->payment_date) ? date('d/m/Y H:i:s', strtotime($transaction_detail->payment_date)) : '',
                'payment_mode' => $payment_mode,
                'utr_number' => $utr_number,
                'number_of_emi' => $this->addOrdinalSuffix($number_of_installment) ?? '',
                'total_amount_collected' => $transaction_detail->total_payment ?? '',
                'total_amount_in_words' => $amountInWords,
                'remarks' => "Collected in favour of ". $lender_details->name ?? '',
                'received_by' => $lender_details->name ?? '', // Lender name
                'signature' => '',
            ];

            // $pdfPath = "Emi-receipts/{$borrower_id}/".$receipt_no.".pdf";
            // $pdf = Pdf::loadView('pdf.emi-receipt', $data)->setPaper('a4', 'portrait');
            // return $pdf->stream();
            // return view('pdf.emi-receipt', $data);

            $pdf = Pdf::loadView('pdf.emi-receipt', $data)->setPaper('a4', 'portrait');

            $pdfPath = "Emi-receipts/{$loan_details->borrower_id}/{$receipt_no}.pdf";
            Storage::disk('public')->put($pdfPath, $pdf->output());

            $downloadUrl = asset('storage/' . $pdfPath);
            return $this->helper_controller->send_response('success', "Receipt generated successfully", 200, ['download_url' => $downloadUrl]);


        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function addOrdinalSuffix($number) {
        if (!is_numeric($number)) return $number;
    
        $suffixes = ['th', 'st', 'nd', 'rd'];
        $mod100 = $number % 100;
    
        return $number . (($mod100 >= 11 && $mod100 <= 13) ? 'th' : ($suffixes[$number % 10] ?? 'th'));
    }

    // public function fetch_account_details_for_enach(Request $request)
    // {
    //     try {
    //         $validator = Validator::make($request->all(), [
    //             'borrower_id' => 'nullable|integer|exists:loan_basic_details,id',
    //             'name'        => 'nullable|regex:/^[a-zA-Z\s]+$/',
    //             'mobile'      => 'nullable|digits:10',
    //             'email'       => 'nullable|email|max:255',
    //             'bank_name'   => 'nullable|string|max:255',
    //             'account_number' => 'nullable|string|max:50',
    //             'is_enach_enabled' => 'nullable|boolean',
    //             'page'        => 'nullable|integer|min:1',
    //             'number_of_records' => 'nullable|integer|min:1|max:100',
    //         ]);

    //         if ($validator->fails()) {
    //             return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
    //         }

    //         $query = BorrowerAccountDetails::with(['enachPermissionUpdatedBy:id,name'])
    //             ->join('loan_basic_details', 'borrower_account_details.borrower_id', '=', 'loan_basic_details.id')
    //             ->join('borrower_kycs', 'borrower_account_details.borrower_id', '=', 'borrower_kycs.borrower_id')
    //             ->select(
    //                 'borrower_account_details.*',
    //                 'borrower_kycs.name',
    //                 'borrower_kycs.mobile',
    //                 'borrower_kycs.email',
    //                 'loan_basic_details.borrower_id as borrower_fin_id'
    //             );
    //             // ->whereRaw(
    //             //     "loan_basic_details.loan_application_steps::jsonb @> '[{\"step\": 22, \"status\": \"COMPLETED\"}]'::jsonb"
    //             // );

    //         // $query->where('loan_basic_details.status', 'DISBURSED');
    //         // Add the OR condition:
    //         $query->where(function($q) {
    //             $q->where('loan_basic_details.status', 'DISBURSED')
    //               ->orWhere('borrower_account_details.enach_status', '!=', '');
    //         });

    //         if ($request->filled('borrower_id')) {
    //             $query->where('borrower_account_details.borrower_id', $request->borrower_id);
    //         }

    //         if ($request->filled('name')) {
    //             $query->whereRaw('LOWER(borrower_kycs.name) LIKE ?', ['%' . strtolower($request->name) . '%']);
    //         }

    //         if ($request->filled('mobile')) {
    //             $query->where('borrower_kycs.mobile', $request->mobile);
    //         }

    //         if ($request->filled('email')) {
    //             $query->where('borrower_kycs.email', 'like', '%' . $request->email . '%');
    //         }

    //         if ($request->filled('bank_name')) {
    //             $query->whereRaw('LOWER(borrower_account_details.bank_name) LIKE ?', ['%' . strtolower($request->bank_name) . '%']);
    //         }

    //         if ($request->filled('account_number')) {
    //             $query->where('borrower_account_details.account_number', 'like', '%' . $request->account_number . '%');
    //         }

    //         if ($request->filled('is_enach_enabled')) {
    //             $query->where('borrower_account_details.is_enach_enabled', $request->is_enach_enabled);
    //         }

    //         $query->orderBy('borrower_id', 'desc');
    //         $numberOfRecords = $request->input('number_of_records', 20);
    //         $data = $query->paginate($numberOfRecords);

    //         return $this->helper_controller->send_response('success', 'Borrower account details fetched successfully.', 200, $data);
    //     } catch (Exception $e) {
    //         return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 500);
    //     }
    // }
    public function fetch_account_details_for_enach(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id'        => 'nullable|integer|exists:loan_basic_details,id',
                'name'               => 'nullable|regex:/^[a-zA-Z\s]+$/',
                'mobile'             => 'nullable|digits:10',
                'email'              => 'nullable|email|max:255',
                'bank_name'          => 'nullable|string|max:255',
                'account_number'     => 'nullable|string|max:50',
                'is_enach_enabled'   => 'nullable|boolean',
                'page'               => 'nullable|integer|min:1',
                'number_of_records'  => 'nullable|integer|min:1|max:100',
                'Schedule'           => 'nullable|string|in:YES',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $query = BorrowerAccountDetails::with([
                    'enachPermissionUpdatedBy:id,name',
                    'enachSchedule:id,borrower_id,request_payload,status,scheduled_created_at'
                ])
                ->join('loan_basic_details', 'borrower_account_details.borrower_id', '=', 'loan_basic_details.id')
                ->join('borrower_kycs', 'borrower_account_details.borrower_id', '=', 'borrower_kycs.borrower_id')
                ->leftJoin('mandate_status_updates as msu', function ($join) {
                    $join->on('borrower_account_details.enach_transaction_id', '=', 'msu.transaction_id')
                        ->whereRaw('msu.id = (
                            SELECT max(id) FROM mandate_status_updates 
                            WHERE transaction_id = borrower_account_details.enach_transaction_id
                        )');
                })
                ->select(
                    'borrower_account_details.*',
                    'borrower_kycs.name',
                    'borrower_kycs.mobile',
                    'borrower_kycs.email',
                    'loan_basic_details.borrower_id as borrower_fin_id',
                    'msu.status as mandate_status',
                    'msu.umrn',
                    'msu.mandate_type',
                    'msu.start_date',
                    'msu.end_date',
                    'msu.amount',
                    'msu.bank_reference_number',
                    'msu.amount_rule',
                    'msu.response_code',
                    'msu.response_description',
                    'msu.mandate_id'
                );

            $query->where(function ($q) {
                $q->where('loan_basic_details.status', 'DISBURSED')
                  ->orWhere('borrower_account_details.enach_status', '!=', '');
            });

            if ($request->filled('Schedule') && $request->Schedule === 'YES') {
                $query->whereHas('enachSchedule');
            }

            // Apply filters if provided
            if ($request->filled('borrower_id')) {
                $query->where('borrower_account_details.borrower_id', $request->borrower_id);
            }

            if ($request->filled('name')) {
                $query->whereRaw('LOWER(borrower_kycs.name) LIKE ?', ['%' . strtolower($request->name) . '%']);
            }

            if ($request->filled('mobile')) {
                $query->where('borrower_kycs.mobile', $request->mobile);
            }

            if ($request->filled('email')) {
                $query->where('borrower_kycs.email', 'like', '%' . $request->email . '%');
            }

            if ($request->filled('bank_name')) {
                $query->whereRaw('LOWER(borrower_account_details.bank_name) LIKE ?', ['%' . strtolower($request->bank_name) . '%']);
            }

            if ($request->filled('account_number')) {
                $query->where('borrower_account_details.account_number', 'like', '%' . $request->account_number . '%');
            }

            if ($request->filled('is_enach_enabled')) {
                $query->where('borrower_account_details.is_enach_enabled', $request->is_enach_enabled);
            }

            $query->orderBy('borrower_account_details.borrower_id', 'desc');
            $numberOfRecords = $request->input('number_of_records', 20);

            $data = $query->paginate($numberOfRecords);

            return $this->helper_controller->send_response('success', 'Borrower account details fetched successfully.', 200, $data);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 500);
        }
    }

    public function create_pre_enach_mandate(Request $request)
    {
        try {
            // return $this->helper_controller->send_response('failed', "Unauthenticated access, please login", 422);
            if (!auth()->check()) {
                return $this->helper_controller->send_response('failed', "Unauthenticated access, please login", 422);
            }

            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
                'account_holder_name' => ['required', 'regex:/^[a-zA-Z\s]+$/', 'max:255'],
                'account_number' => 'required|integer',
                'ifsc_code' => ['required', 'regex:/^[a-zA-Z0-9]+$/', 'max:25'],
                'bank_name' => ['required', 'regex:/^[a-zA-Z\s]+$/', 'max:255'],
                'branch_code' => ['nullable', 'regex:/^[a-zA-Z0-9\s]+$/', 'max:255'],
                'account_type' => 'required|in:SAVINGS,CURRENT,savings,current,Savings,Current',
                'bank_code' => 'required|regex:/^[a-zA-Z0-9]+$/',
            ]);
    
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
    
            $merchantKey = $this->easebuzz_test_key;
            $salt = $this->easebuzz_test_salt;
            $sub_merchant_id = $this->sub_merchant_id;

            $borrower_id = $request->borrower_id;

            $borrower_details = BorrowerKyc::where('borrower_id', $borrower_id)->first();
            if (!$borrower_details) {
                // DB::rollBack();
                return $this->helper_controller->send_response('failed', "Invalid borrower", 422);
            }

            $repaymentSchedules = BorrowerRepaymentSchedule::where('borrower_id', $borrower_id)->get();

            if ($repaymentSchedules->isEmpty()) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            $emi_amount = $repaymentSchedules->first()->emi_amount;
            // $first_payment_date = $repaymentSchedules->first()->due_date;

            // Before
            // $first_payment_date = Carbon::parse($repaymentSchedules->first()->due_date)->startOfMonth()->format('Y-m-d');
            // $total_payment_amount = $repaymentSchedules->sum('emi_amount');

            // $final_due_date = optional($repaymentSchedules->sortByDesc('due_date')->first())->due_date;
            // $final_payment_date = $final_due_date ? $final_due_date : null;


            // After
            $first_payment_date = now()->addDay()->format('Y-m-d');
            $total_payment_amount = $repaymentSchedules->sum('emi_amount');

            $latest_due_date = optional($repaymentSchedules->sortByDesc('due_date')->first())->due_date;
            $final_payment_date = $latest_due_date ? Carbon::parse($latest_due_date)->addYear()->format('Y-m-d') : null;

            // Create a new EMI transaction.
            $enach_details                  = new EnachRequest();
            $enach_details->borrower_id     = $borrower_id;
            $enach_details->phone           = $borrower_details->mobile;
            $enach_details->email           = $borrower_details->email;
            $enach_details->e_nach_amount   = $total_payment_amount;
            $enach_details->status          = 'initiated';
            $enach_details->save();

            $phone = $borrower_details->mobile;
            $email = $borrower_details->email;

            $txnnumber                  = str_pad($enach_details->id, 3, '0', STR_PAD_LEFT);
            $transactionId              = "FIN-" . date('YmdHis') . '-' . $txnnumber . 'E';
            $enach_details->transaction_id = $transactionId;
            $enach_details->save();

            $accessKeyHashData = $merchantKey . "|" . $total_payment_amount . "|" . $transactionId . "|" . $salt;
            $accessKeyHash = hash('sha512', $accessKeyHashData);
            $accessKeyPostData = json_encode([
                "key" => $merchantKey,
                "transaction_id" => $transactionId,
                "success_url" => "https://insta.finayo.tech/api/enach/mandate/success",
                "failure_url" => "https://insta.finayo.tech/api/enach/mandate/failed",
                "submerchant_id" => $sub_merchant_id,
                "request_type" => "SEAMLESS",
                "amount" => $total_payment_amount,
                "email" => $email,
                "phone" => $phone,
                "start_date" => $first_payment_date,
                "end_date" => $final_payment_date,
                "frequency" => "monthly",
                "amount_rule" => "MAX",
                "upfront_presentment_amount" => 0,
                "payment_modes" => [
                    "EN"
                ],
                "udf1" => "",
                "udf2" => "",
                "udf3" => "",
                "udf4" => "",
                "udf5" => "",
                "udf6" => "",
                "udf7" => ""
            ], true);

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }
            curl_setopt_array($curl, array(
                CURLOPT_URL => $this->easebuzz_enach_url_prod . 'autocollect/v1/access-key/generate/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $accessKeyPostData,
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/json',
                    'Authorization:' . $accessKeyHash,
                    'Content-Type: application/json',
                    'X-EB-MERCHANT-KEY:' . $merchantKey,
                    'X-EB-SUB-MERCHANT-ID:'. $sub_merchant_id,
                    'X-EB-PAYMENT-MODE: EN'
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            // echo $response;

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }

            if ($httpCode != 200) {
                return $this->helper_controller->send_response('failed', "Failed", 422);
            }

            $result = json_decode($response, true);
            if ($result['success'] !== true) {
                return $this->helper_controller->send_response('failed', "Failed to intiate NACH", 422, $result);
            }
            $accessKey              = $result['access_key'];
            $account_number_text    = $request->account_number; //'282800002828';
            $account_number         = $this->encryptField($account_number_text, $merchantKey, $salt);
            $account_holder_name    = $this->encryptField($request->account_holder_name, $merchantKey, $salt);
            $account_type           = strtolower($request->account_type);
            $account_type           = $this->encryptField($account_type, $merchantKey, $salt);
            $bank_code              = $request->bank_code;
            $ifsc                   = strtoupper($request->ifsc);

            $hashData = $merchantKey . "|" . $account_number . "|" . $ifsc . "||" . $salt;
            $authorization = hash('sha512', $hashData);
            // Fields to encrypt

            // Before
            // $authMode = $this->auth_mode; // 'debit_card';
            // $authMode = 'aadhaar';

            // After
            $authMode = SystemSetting::where('key', 'enach.auth_mode')->value('value') ?? 'debit_card';

            $postData = http_build_query([
                'key'                   => $this->easebuzz_test_key,
                'access_key'            => $accessKey,
                'mandate_type'          => 'ENACH',
                'account_number'        => $account_number,
                'account_holder_name'   => $account_holder_name,
                'account_type'          => $account_type,
                'ifsc'                  => strtoupper($ifsc),
                'auth_mode'             => $authMode,
                'Authorization'         => $authorization,
                'bank_code'             => $bank_code,
            ]);

            $update_enach_request_details = EnachRequest::where('borrower_id', $borrower_id)->where('transaction_id', $transactionId)->first();
            if ($update_enach_request_details) {
                $update_enach_request_details->access_key           = $accessKey;
                $update_enach_request_details->mandate_type         = 'ENACH';
                $update_enach_request_details->account_number       = $request->account_number;
                $update_enach_request_details->account_holder_name  = $request->account_holder_name;
                $update_enach_request_details->account_type         = strtolower($request->account_type);
                $update_enach_request_details->ifsc                 = strtoupper($request->ifsc);
                $update_enach_request_details->auth_mode            = $authMode;
                $update_enach_request_details->Authorization        = $authorization;
                $update_enach_request_details->bank_code            = $request->bank_code;
                $update_enach_request_details->save();
            }

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $this->easebuzz_enach_url_prod . 'autocollect/v1/mandate/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded'
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $response;

            if ($httpCode != 200) {
                return $this->helper_controller->send_response('failed', 'Failed to intiate mandate', 422, $response);
            }

            $mandateData = new EnachMandateFormData();
            $mandateData->transaction_id = $transactionId;
            $mandateData->payload = base64_encode($response);
            $mandateData->status = 'initiated';
            $mandateData->save();

            return $this->helper_controller->send_response('success', 'Mandate HTML ready', 200, [
                'redirect_url' => url("/mandate/view/{$transactionId}")
            ]);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function create_enach_mandate(Request $request)
    {
        try {
            // return $this->helper_controller->send_response('failed', "Unauthenticated access", 422);

            if (!auth()->check()) {
                return $this->helper_controller->send_response('failed', "Unauthenticated access, please login", 422);
            }

            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
            ]);
    
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $device_type = null;
            if ($request->hasHeader('device-type') && !empty($request->header('device-type'))) {
                $device_type = $request->header('device-type');
            }
    
            $merchantKey = $this->easebuzz_test_key;
            $salt = $this->easebuzz_test_salt;
            $sub_merchant_id = $this->sub_merchant_id;

            $borrower_id = $request->borrower_id;

            $borrower_details = BorrowerKyc::where('borrower_id', $borrower_id)->first();
            if (!$borrower_details) {
                // DB::rollBack();
                return $this->helper_controller->send_response('failed', "Invalid borrower", 422);
            }

            $account_details = BorrowerAccountDetails::where('borrower_id', $borrower_id)->first();
            if (!$account_details) {
                return $this->helper_controller->send_response("failed", "Account details does not exist.", 422);
            }

            if($account_details->enach_status == 'initiated') {
                return $this->helper_controller->send_response("failed", "A mandate is already initiated.", 422);
            }
            if($account_details->enach_status == 'authorized') {
                return $this->helper_controller->send_response("failed", "Mandate already authorized.", 422);
            }
            
            $bank_code = $account_details->bank_code;

            $bank_npci_info = NpciBankList::where('bank_id', $bank_code)->first();

            if (!$bank_npci_info || $bank_npci_info->card_flag !== true) {
                return $this->helper_controller->send_response('failed', 'eNACH is not available for this bank.', 422);
            }

            // if($account_details->enach_status !=)
            
            $repaymentScheduleResult = $this->createRepaymentScheduleIfNotExists($borrower_id);

            if ($repaymentScheduleResult !== true) {
                return $repaymentScheduleResult; // it already contains the error response
            }

            $repaymentSchedules = BorrowerRepaymentSchedule::where('borrower_id', $borrower_id)->where('payment_status', 'UNPAID')->orderBy('id', 'asc')->get();

            if ($repaymentSchedules->isEmpty()) {
                return $this->helper_controller->send_response('failed', "Repayment schedule not prepared yet.", 422);
            }

            $emi_amount = $repaymentSchedules->first()->emi_amount;
            // $first_payment_date = $repaymentSchedules->first()->due_date;

            // Before
            // $first_payment_date = Carbon::parse($repaymentSchedules->first()->due_date)->startOfMonth()->format('Y-m-d');
            
            // After
            $first_payment_date = now()->addDay()->format('Y-m-d');
            $total_payment_amount = $repaymentSchedules->sum('emi_amount');

            $latest_due_date = optional($repaymentSchedules->sortByDesc('due_date')->first())->due_date;

            // Before
            // $final_due_date = $latest_due_date ? Carbon::parse($latest_due_date)->addMonths(6)->format('Y-m-d') : null;
            
            // After
            $final_due_date = $latest_due_date ? Carbon::parse($latest_due_date)->addYear('3')->format('Y-m-d') : null;
            $final_payment_date = $final_due_date ? $final_due_date : null;

            // Create a new EMI transaction.
            $enach_details                  = new EnachRequest();
            $enach_details->borrower_id     = $borrower_id;
            $enach_details->phone           = $borrower_details->mobile;
            $enach_details->email           = $borrower_details->email;
            $enach_details->e_nach_amount   = $total_payment_amount;
            $enach_details->status          = 'initiated';
            $enach_details->save();

            $phone = $borrower_details->mobile;
            $email = $borrower_details->email;

            $txnnumber                  = str_pad($enach_details->id, 3, '0', STR_PAD_LEFT);
            $transactionId              = "FIN-" . date('YmdHis') . '-' . $txnnumber . 'E';
            $enach_details->transaction_id = $transactionId;
            $enach_details->save();

            $account_details->enach_transaction_id = $transactionId;
            $account_details->save();

            $accessKeyHashData = $merchantKey . "|" . $total_payment_amount . "|" . $transactionId . "|" . $salt;
            $accessKeyHash = hash('sha512', $accessKeyHashData);
            $accessKeyPostData = json_encode([
                "key" => $merchantKey,
                "transaction_id" => $transactionId,
                "success_url" => "https://insta.finayo.tech/api/enach/mandate/success",
                "failure_url" => "https://insta.finayo.tech/api/enach/mandate/failed",
                "submerchant_id" => $sub_merchant_id,
                "request_type" => "SEAMLESS",
                "amount" => $total_payment_amount,
                "email" => $email,
                "phone" => $phone,
                "start_date" => $first_payment_date,
                "end_date" => $final_payment_date,
                "frequency" => "monthly",
                "amount_rule" => "MAX",
                "upfront_presentment_amount" => 0,
                "payment_modes" => [
                    "EN"
                ],
                "udf1" => "",
                "udf2" => "",
                "udf3" => "",
                "udf4" => "",
                "udf5" => "",
                "udf6" => "",
                "udf7" => ""
            ], true);

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }
            curl_setopt_array($curl, array(
                CURLOPT_URL => $this->easebuzz_enach_url_prod . 'autocollect/v1/access-key/generate/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $accessKeyPostData,
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/json',
                    'Authorization:' . $accessKeyHash,
                    'Content-Type: application/json',
                    'X-EB-MERCHANT-KEY:' . $merchantKey,
                    'X-EB-SUB-MERCHANT-ID:'. $sub_merchant_id,
                    'X-EB-PAYMENT-MODE: EN'
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            // echo $response;

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }

            if ($httpCode != 200) {
                return $this->helper_controller->send_response('failed', "Failed", 422);
            }

            $result = json_decode($response, true);
            if ($result['success'] !== true) {
                return $this->helper_controller->send_response('failed', "Failed to intiate NACH", 422, $result);
            }
            $accessKey              = $result['access_key'];
            $account_number_text    = $account_details->account_number;
            $account_number         = $this->encryptField($account_number_text, $merchantKey, $salt);
            $account_holder_name    = $this->encryptField($account_details->account_holder_name, $merchantKey, $salt);
            $account_type           = strtolower($account_details->account_type);
            $account_type           = $this->encryptField($account_type, $merchantKey, $salt);
            $bank_code              = $account_details->bank_code;
            $ifsc                   = strtoupper($account_details->ifsc);

            $hashData = $merchantKey . "|" . $account_number . "|" . $ifsc . "||" . $salt;
            $authorization = hash('sha512', $hashData);
            // Fields to encrypt

            // Before
            // $authMode = $this->auth_mode; // 'debit_card';
            // $authMode = 'aadhaar';

            // After
            $authMode = SystemSetting::where('key', 'enach.auth_mode')->value('value') ?? 'debit_card';
            
            $postData = http_build_query([
                'key'                   => $this->easebuzz_test_key,
                'access_key'            => $accessKey,
                'mandate_type'          => 'ENACH',
                'account_number'        => $account_number,
                'account_holder_name'   => $account_holder_name,
                'account_type'          => $account_type,
                'ifsc'                  => strtoupper($ifsc),
                'auth_mode'             => $authMode,
                'Authorization'         => $authorization,
                'bank_code'             => $bank_code,
            ]);

            $update_enach_request_details = EnachRequest::where('borrower_id', $borrower_id)->where('transaction_id', $transactionId)->first();
            if ($update_enach_request_details) {
                $update_enach_request_details->access_key           = $accessKey;
                $update_enach_request_details->mandate_type         = 'ENACH';
                $update_enach_request_details->account_number       = $account_details->account_number;
                $update_enach_request_details->account_holder_name  = $account_details->account_holder_name;
                $update_enach_request_details->account_type         = strtolower($account_details->account_type);
                $update_enach_request_details->ifsc                 = strtoupper($account_details->ifsc);
                $update_enach_request_details->auth_mode            = $authMode;
                $update_enach_request_details->Authorization        = $authorization;
                $update_enach_request_details->bank_code            = $account_details->bank_code;
                $update_enach_request_details->save();
            }

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $this->easebuzz_enach_url_prod . 'autocollect/v1/mandate/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded'
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $response;

            if ($httpCode != 200) {
                return $this->helper_controller->send_response('failed', 'Failed to intiate mandate', 422, $response);
            }

            $mandateData = new EnachMandateFormData();
            $mandateData->transaction_id = $transactionId;
            $mandateData->payload = base64_encode($response);
            $mandateData->status = 'initiated';
            $mandateData->save();

            $account_details = BorrowerAccountDetails::where('borrower_id', $borrower_id)->first();
            if ($account_details) {
                $account_details->enach_status = 'initiated';
                $account_details->save();
            }

            if (!$device_type) {
                $mandateUrl = url("/mandate/view/{$transactionId}");
                $sent_to = '+91' . $borrower_details->mobile;
                $message = "Click the link to register E-NACH mandate " .$mandateUrl;
                // $this->twilio->sendSms($sent_to, $message); // enable 
                $smsResponse = $this->smsService->sendLink($borrower_details->mobile, $message);
            }

            return $this->helper_controller->send_response('success', 'Mandate HTML ready', 200, [
                'redirect_url' => url("/mandate/view/{$transactionId}")
            ]);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }


    public function showMandate($transactionId)
    {
        try {
            // Find the mandate by transaction ID
            $mandate = EnachMandateFormData::where('transaction_id', $transactionId)
                        ->where('status', 'initiated')
                        ->first();
            
            if (!$mandate) {
                return $this->helper_controller->send_response('failed', 'Mandate not found.', 404);
            }
            
            // Decode the payload
            $decodedHtml = base64_decode($mandate->payload);
            
            // Return the HTML content directly in the response or render in a Blade view
            return response($decodedHtml)->header('Content-Type', 'text/html');
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function showMandateNew(Request $request)
    {
        try {
            $transactionId = $request->query('id');

            if (!$transactionId) {
                return $this->helper_controller->send_response(
                    'failed',
                    'Transaction ID is required.',
                    422
                );
            }

            // Find the mandate by transaction ID
            $mandate = EnachMandateFormData::where('transaction_id', $transactionId)
                ->where('status', 'initiated')
                ->first();

            if (!$mandate) {
                return $this->helper_controller->send_response(
                    'failed',
                    'Mandate not found.',
                    404
                );
            }

            // Decode the payload
            $decodedHtml = base64_decode($mandate->payload);

            // Return HTML directly
            return response($decodedHtml)
                ->header('Content-Type', 'text/html');
        } catch (\Throwable $e) {
            return $this->helper_controller->send_response(
                'failed',
                'An unknown error occurred.',
                500
            );
        }
    }

    public static function encryptField($plainText = "", $merchantKey = "", $merchantSalt = "")
    {
        // Generate key: SHA-256 hash of merchant_key, take first 32 characters as hex string
        $keyHex = substr(hash('sha256', $merchantKey), 0, 32);
        $key = $keyHex;

        // Generate IV: SHA-256 hash of merchant_salt, take first 16 characters as hex string
        $ivHex = substr(hash('sha256', $merchantSalt), 0, 16);
        $iv = $ivHex;

        // Apply PKCS7 padding
        $blockSize = 16;
        $padLength = $blockSize - (strlen($plainText) % $blockSize);
        $plainText .= str_repeat(chr($padLength), $padLength);

        // Encrypt using AES-256-CBC
        $encrypted = openssl_encrypt(
            $plainText,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv
        );

        // Return base64 encoded result
        return base64_encode($encrypted);
    }

    public function get_mandate_list(Request $request)
    {
        try {
            // === Get current month defaults ===
            $today = now()->toDateString(); // e.g., 2025-05-13
            $defaultStart = now()->startOfMonth()->toDateString(); // e.g., 2025-05-01

            // === Use provided input or fallback to defaults ===
            $created_at_start = $request->input('created_at_start', $defaultStart);
            $created_at_end = $request->input('created_at_end', $today);
            $created_at = $request->input('created_at'); // Optional

            // === Validation ===
            $validator = Validator::make([
                'created_at_start' => $created_at_start,
                'created_at_end' => $created_at_end,
                'created_at' => $created_at,
            ], [
                'created_at_start' => 'required|date',
                'created_at_end' => 'required|date|before_or_equal:today',
                'created_at' => 'nullable|date|before_or_equal:today',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            // === Config ===
            $merchant_key = $this->easebuzz_test_key;
            $merchant_salt = $this->easebuzz_test_salt; // replace with actual salt

            // === Auth String ===
            $auth_string = $merchant_key . '|' . $created_at_start . '|' . $created_at_end . '|' . ($created_at ?? '') . '|' . $merchant_salt;
            $authorization_hash = hash('sha512', $auth_string);

            // === Build URL ===
            $queryParams = [
                'key' => $merchant_key,
                'created_at_start' => $created_at_start,
                'created_at_end' => $created_at_end,
                'mandate_type' => 'ENACH',
            ];

            if (!empty($created_at)) {
                $queryParams['created_at'] = $created_at;
            }

            $url = $this->easebuzz_enach_url_prod . 'autocollect/v1/mandate/?' . http_build_query($queryParams);

            // === Make cURL Request ===
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => [
                    "X-EB-MERCHANT-KEY: $merchant_key",
                    "Authorization: $authorization_hash",
                ],
            ]);

            $response = curl_exec($curl);
            curl_close($curl);

            $responseData = json_decode($response, true);
            return $this->helper_controller->send_response('success', 'Mandate list fetched successfully.', 200, $responseData);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    public function retrieve_mandate(Request $request, $transaction_id)
    {
        try {
            // Validate the transaction ID directly
            $validator = Validator::make(['transaction_id' => $transaction_id], [
                'transaction_id' => ['required', 'regex:/^[A-Z0-9\-]+$/', 'max:100'],
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            // === Config ===
            $merchant_key   = $this->easebuzz_test_key;
            $merchant_salt  = $this->easebuzz_test_salt; // Replace with actual salt

            // === Generate Authorization hash ===
            $auth_string = $merchant_key . '|' . $transaction_id . '|' . $merchant_salt;
            $authorization_hash = hash('sha512', $auth_string);

            // === Prepare URL ===
            $url = $this->easebuzz_enach_url_prod . "autocollect/v1/mandate/{$transaction_id}/?key={$merchant_key}";

            // === CURL request ===
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => [
                    "X-EB-MERCHANT-KEY: {$merchant_key}",
                    "Authorization: {$authorization_hash}",
                ],
            ]);

            $response = curl_exec($curl);
            curl_close($curl);

            $responseData = json_decode($response, true);
            return $this->helper_controller->send_response('success', 'Mandate details retrieved.', 200, $responseData);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    public function initiate_debit_request(Request $request)
    {
        try {
            // Validate the transaction ID directly
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
                'transaction_id' => 'required|exists:enach_requests,transaction_id',
                'amount' => 'required|decimal:0,2',
                'presentment_date' => 'required|date',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $ip = $this->helper_controller->get_user_ip();

            // === Config ===
            $merchant_key   = $this->easebuzz_test_key;
            $merchant_salt  = $this->easebuzz_test_salt; // Replace with actual salt

            $enach_debit_request = new EnachDebitRequest();
            $enach_debit_request->borrower_id = $request->borrower_id;
            $enach_debit_request->transaction_id = $request->transaction_id;
            $enach_debit_request->amount = $request->amount;
            $enach_debit_request->presentment_date = $request->presentment_date;
            $enach_debit_request->modified_by = auth()->id();
            $enach_debit_request->ip = $ip;
            $enach_debit_request->save();

            $dbt_request_no             = str_pad($enach_debit_request->id, 3, '0', STR_PAD_LEFT);
            $merchant_request_number = "FIN-" . date('YmdHis') . '-' . $dbt_request_no.'EDR';

            $enach_debit_request->merchant_request_number = $merchant_request_number;
            $transaction_id = $request->transaction_id;
            $amount = $request->amount;
            // === Generate Authorization hash ===
            /**
             * SHA-512 hash of string in the format of
             * “<key>|<transaction_id>|<merchant_request_number>|<amount>|<salt>”
             */
            $auth_string = $merchant_key.'|'.$transaction_id.'|'.$merchant_request_number.'|'.$amount.'|'.$merchant_salt;
            $authorization_hash = hash('sha512', $auth_string);
            
            $enach_debit_request->authorization = $authorization_hash;
            $enach_debit_request->save();

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            $jsonRequestBody = json_encode([
                "key" => $merchant_key,
                "transaction_id" => $transaction_id,
                "amount" => $amount,
                "presentment_date" => $request->presentment_date,
                "merchant_request_number" => $merchant_request_number,
                "submerchant_id" => ""
            ]);

            curl_setopt_array($curl, array(
                CURLOPT_URL => $this->easebuzz_enach_url_prod . 'autocollect/v1/mandate/presentment/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonRequestBody,
                CURLOPT_HTTPHEADER => array(
                    'X-EB-MERCHANT-KEY: '. $merchant_key,
                    'Content-Type: application/json',
                    'Authorization: '. $authorization_hash
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $result = json_decode($response);
            $resultData = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }
            // echo $response;
            if($httpCode == 200) {
                if($result->success == true) {
                    $enach_debit_response = new EnachDebitResponse();
                    $enach_debit_response->response_id = $result->data->id ?? '';
                    $enach_debit_response->pg_transaction_id = $result->data->pg_transaction_id ?? '';
                    $enach_debit_response->amount = $result->data->amount ?? '';
                    $enach_debit_response->presentment_date = $result->data->presentment_date ?? null;
                    $enach_debit_response->status = $result->data->status ?? '';
                    $enach_debit_response->net_amount = $result->data->net_amount ?? '';
                    $enach_debit_response->transaction_reference_number = $result->data->transaction_reference_number ?? '';
                    $enach_debit_response->bank_reference_number = $result->data->bank_reference_number ?? '';
                    $enach_debit_response->status_at_bank = $result->data->status_at_bank ?? '';
                    $enach_debit_response->split_payments = ($result->data->split_payments) ? json_encode($result->data->split_payments) : '';
                    $enach_debit_response->merchant_request_number = $result->data->merchant_request_number ?? '';
                    $enach_debit_response->response_meta = ($result->data->response_meta) ? json_encode($result->data->response_meta) : '';
                    $enach_debit_response->response_created_at = ($result->data->created_at) ? date('Y-m-d H:i:s', strtotime($result->data->created_at)) : null;
                    $enach_debit_response->save();

                    return $this->helper_controller->send_response('success', " Debit request intiated", 422, $resultData);
                }
            } else {

                return $this->helper_controller->send_response('failed', "Failed to intiate debit request", 422, $resultData ?? '');
            }
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    public function easebuzz_enach_webhook(Request $request) {
        try {
            $event = $request->input('event');
            $data = $request->input('data');

            // return $request->getContent();

            if ($event === 'MANDATE_STATUS_UPDATE') {
                $storemandate = new MandateStatusUpdate();
                $storemandate->event = $event;
                $storemandate->raw_data = json_encode($request->all());
                $storemandate->status = $data['status'] ?? '';
                $storemandate->umrn = $data['umrn'] ?? null;
                $storemandate->customer_name = $data['customer_name'] ?? null;
                $storemandate->customer_account_number = $data['customer_account_number'] ?? null;
                $storemandate->customer_ifsc = $data['customer_ifsc'] ?? null;
                $storemandate->auth_mode = $data['auth_mode'] ?? null;
                $storemandate->frequency = $data['frequency'] ?? null;
                $storemandate->mandate_type = $data['mandate_type'] ?? null;
                $storemandate->start_date = $data['start_date'] ?? null;
                $storemandate->end_date = $data['end_date'] ?? null;
                $storemandate->amount = $data['amount'] ?? null;
                $storemandate->transaction_id = $data['transaction_id'] ?? null;
                $storemandate->bank_reference_number = $data['bank_reference_number'] ?? null;
                $storemandate->bank_code = $data['bank_code'] ?? null;
                $storemandate->customer_email = $data['customer_email'] ?? null;
                $storemandate->customer_phone = $data['customer_phone'] ?? null;
                $storemandate->amount_rule = $data['amount_rule'] ?? null;
                $storemandate->customer_account_type = $data['customer_account_type'] ?? null;
                $storemandate->is_revokable = $data['is_revokable'] ?? false;
                $storemandate->response_code = $data['response_meta']['code'] ?? null;
                $storemandate->response_description = $data['response_meta']['description'] ?? null;
                $storemandate->mandate_id = $data['id'] ?? null;
                $storemandate->save();

                if(isset($data['transaction_id'])) {
                    $enach_request_details = EnachRequest::where('transaction_id', $data['transaction_id'])->first();
                    if($enach_request_details) {
                        if ($enach_request_details->status !== 'authorized') {
                            $enach_request_details->status = $data['status'] ?? null;
                            $enach_request_details->update_date = $data['created_at'] ?? null;
                            if(isset($data['status']) && $data['status'] == 'authorized') {
                                $enach_request_details->is_enach_activated = true;
                                $enach_request_details->activation_date = $data['created_at'] ?? null;
                            } else {
                                $enach_request_details->is_enach_activated = false;
                            }
                            $enach_request_details->mandate_id = $data['id'] ?? null;
                            $enach_request_details->save();
                        }

                        $borrower_account_details = BorrowerAccountDetails::where('borrower_id', $enach_request_details->borrower_id)->where('enach_status', '<>', 'authorized')->where('enach_transaction_id', $data['transaction_id'])->first();
                        if($borrower_account_details) {
                            if(isset($data['status']) && $data['status'] == 'authorized') {
                                $borrower_account_details->is_enach_enabled = true;
                                $borrower_account_details->enach_enabled_on = $data['created_at'] ?? null;
                                $borrower_account_details->enach_start_date = $data['start_date'] ?? null;
                                $borrower_account_details->enach_end_date = $data['end_date'] ?? null;
                            } else {
                                $borrower_account_details->is_enach_enabled = false;
                            }
                            $borrower_account_details->enach_status = $data['status'] ?? null;
                            $borrower_account_details->mandate_id = $data['id'] ?? null;
                            $borrower_account_details->save();
                        }
                    }
                }
                
            } elseif ($event === 'PRESENTMENT_STATUS_UPDATE') {
                $storePresentmentStatusData = new PresentmentStatusUpdate();
                $storePresentmentStatusData->event = $event;
                $storePresentmentStatusData->raw_data = json_encode($request->all());
                $storePresentmentStatusData->status = $data['status'] ?? '';
                $storePresentmentStatusData->merchant_request_number = $data['merchant_request_number'] ?? null;
                $storePresentmentStatusData->transaction_reference_number = $data['transaction_reference_number'] ?? null;
                $storePresentmentStatusData->pg_transaction_id = $data['pg_transaction_id'] ?? null;
                $storePresentmentStatusData->status_at_bank = $data['status_at_bank'] ?? null;
                $storePresentmentStatusData->created_at_event = $data['created_at'] ?? null;
                $storePresentmentStatusData->presentment_date = $data['presentment_date'] ?? null;
                $storePresentmentStatusData->amount = $data['amount'] ?? null;
                $storePresentmentStatusData->split_payments = json_encode($data['split_payments'] ?? []);
                $storePresentmentStatusData->bank_reference_number = $data['bank_reference_number'] ?? null;
                $storePresentmentStatusData->net_amount = $data['net_amount'] ?? null;
                $storePresentmentStatusData->response_code = $data['response_meta']['code'] ?? null;
                $storePresentmentStatusData->response_description = $data['response_meta']['description'] ?? null;
                $storePresentmentStatusData->external_id = $data['id'] ?? null;
                $storePresentmentStatusData->save();

                $transactionId = $data['mandate']['transaction_id'] ?? null;
                $accountDetails = BorrowerAccountDetails::where('enach_transaction_id', $transactionId)->first();
                if ($accountDetails && $data['status'] == 'success') {
                    $presentmentDate = $data['presentment_date'];
                    $month = date('m', strtotime($presentmentDate));
                    $year = date('Y', strtotime($presentmentDate));

                    $emi = BorrowerRepaymentSchedule::where('borrower_id', $accountDetails->borrower_id)
                        ->where('payment_status', 'UNPAID')
                        ->whereMonth('due_date', $month)
                        ->whereYear('due_date', $year)
                        ->first();

                    if ($emi) {
                        $ref_no = $data['bank_reference_number'] ?? null;
                        $txn_ref = $data['transaction_reference_number'] ?? null;

                        // Store transaction
                        $emi_txn = new EmiTransactions();
                        $emi_txn->payment_date        = $presentmentDate;
                        $emi_txn->borrower_id         = $accountDetails->borrower_id;
                        $emi_txn->loan_id             = '';
                        $emi_txn->payment_status      = 'PAID';
                        $emi_txn->emi_amount          = $emi->emi_amount;
                        $emi_txn->fine_amount         = 0;
                        $emi_txn->total_payment       = $data['amount'] ?? 0;
                        $emi_txn->bank_ref_num        = $ref_no;
                        $emi_txn->payment_updated_at  = date('Y-m-d H:i:s');
                        $emi_txn->user_id             = auth()->id() ?? 0;
                        $emi_txn->ip                  = request()->ip();
                        $emi_txn->payment_mode        = 'ENACH';
                        $emi_txn->transaction_id      = $data['pg_transaction_id'] ?? null;
                        $emi_txn->save();

                        // Update repayment schedule
                        $emi->transaction_id     = $data['pg_transaction_id'] ?? null;
                        $emi->receipt_no         = $data['pg_transaction_id'] ?? null;
                        $emi->payment_status     = 'PAID';
                        $emi->payment_date       = $presentmentDate;
                        $emi->payment_mode       = 'ENACH';
                        $emi->reference          = $ref_no;
                        $emi->fine_amount        = 0;
                        $emi->total_payment      = $data['amount'] ?? 0;
                        $emi->save();
                    }
                }
            } else {
                Log::warning('Unknown webhook event: ' . $event);
            }

            // return response()->json(['message' => 'Webhook received'], 200);
            return $this->helper_controller->send_response('success', "Webhook received", 200);
        } catch (Exception $e) {
            // Log::error('Webhook error: ' . $e->getMessage());
            return $this->helper_controller->send_response('failed', "Something went wrong ".$e->getMessage(), 500);
        }
    }

    public function import_npci_bank_list(Request $request)
    {
        try {
            DB::beginTransaction();
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api.easebuzz.in/autocollect/v1/bank-codes/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
            ));

            $response = curl_exec($curl);
            curl_close($curl);

            $data = json_decode($response, true);

            if (!isset($data['success']) || !$data['success'] || !isset($data['bank_list']['data']['response'])) {
                return $this->helper_controller->send_response('failed', 'Invalid API response.', 422);
            }

            $banks = $data['bank_list']['data']['response'];
            // $userId = auth()->id();

            foreach ($banks as $bank) {
                if (!isset($bank['bank_id'], $bank['bank_name'])) {
                    continue; // skip if required fields are missing
                }

                $existing = NpciBankList::where('bank_name', $bank['bank_name'])->first();

                if ($existing) {
                    // $existing->bank_id       = $bank['bank_id'];
                    $existing->bank_name     = $bank['bank_name'];
                    $existing->netbank_flag  = $bank['netbank_flag'] ?? false;
                    $existing->card_flag     = $bank['card_flag'] ?? false;
                    $existing->adhar_flag    = $bank['adhar_flag'] ?? false;
                    $existing->save();
                } else {
                    $new = new NpciBankList();
                    $new->bank_id       = $bank['bank_id'];
                    $new->bank_name     = $bank['bank_name'];
                    $new->netbank_flag  = $bank['netbank_flag'] ?? false;
                    $new->card_flag     = $bank['card_flag'] ?? false;
                    $new->adhar_flag    = $bank['adhar_flag'] ?? false;
                    $new->save();
                }
            }

            DB::commit();
            return $this->helper_controller->send_response('success', 'NPCI bank list imported successfully.', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Something went wrong: " . $e->getMessage(), 500);
        }
    }

    public function update_borrower_bank_code(Request $request) {
        try {
            DB::beginTransaction();
                $accounts = BorrowerAccountDetails::all();
                foreach($accounts as $account) {
                    $bank_name = strtoupper($account->bank_name);

                    $npci_bank = NpciBankList::where('bank_name', $bank_name)->first();
                    if($npci_bank) {
                        $account->account_type = 'savings';
                        $account->bank_code = $npci_bank->bank_id;;
                        $account->save();
                    }
                }
    
                DB::commit();
            return $this->helper_controller->send_response('success', 'Accounts updated successfully.', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Something went wrong: " . $e->getMessage(), 500);
        }
    }   

    // public function get_borrower_account_enach_details (Request $request) {
    //     try {
    //         if (!auth()->check()) {
    //             return $this->helper_controller->send_response('failed', "Unauthenticated access, please login", 422);
    //         }

    //         $validator = Validator::make($request->all(), [
    //             'borrower_id' => 'required|exists:loan_basic_details,id',
    //         ]);
         
    //         if ($validator->fails()) {
    //             return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
    //         }

    //         DB::beginTransaction();
    //         $loan_details = LoanBasicDetails::where('id', $request->borrower_id)->first();
    //         $count = 0;
    //         $borrower_id = $request->borrower_id;
            
    //         if(!$loan_details) {
    //             return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
    //         }

    //         $lender_id = null;
    //         if (!empty($loan_details->lender_id)) {
    //             $lender_id = $loan_details->lender_id;
    //         }

    //         if(!$lender_id) {
    //             $lenderBorrowerGroup = LenderBorrowerGroup::where('borrower_id', $borrower_id)->first();
    //             $lender_id = $lenderBorrowerGroup->lender_id;
    //         }

    //         if(!$lender_id) {
    //             return $this->helper_controller->send_response('failed', "Lender not assigned", 422);
    //         }

    //         if($loan_details->model_id != '') {
    //             $productModel = ProductModel::where('model_unq', $loan_details->model_id)->first();
    //         } else {
    //             $dealer = Dealer::where('dealer_unq',  $loan_details->dealer_id)->first();
    //             if($dealer) {

    //                 $productModel = ProductModel::where('dealer_id', $dealer->id)->where('name', $loan_details->model)->first();
    //             }
    //         }
    //         if($productModel) {
    //             $roi = $productModel->interest;
    //             $loan_amount = $loan_details->loan_amount;
    //             $interest = $roi;
    //             $tenure = $loan_details->loan_term;
    //             if($tenure > 0 && $tenure <= 15) {
    //                 $insurance_2nd_year = 0;
    //             } elseif($tenure > 15 && $tenure <= 24) {
    //                 $insurance_2nd_year = $productModel->insurance_2nd_year;
    //             } else {
    //                 $insurance_2nd_year = 0;
    //             }

    //             $net_loan_amount = $loan_amount + $productModel->iot + $insurance_2nd_year;


    //             $emiCalculation = $this->helper_controller->generateReducingInterestSchedule($net_loan_amount, $interest, $tenure);

    //             if (!isset($emiCalculation['schedule']) || empty($emiCalculation['schedule'])) {
    //                 return $this->helper_controller->send_response('failed', 'EMI schedule generation failed.', 422);
    //             }
                
    //             $emi_chart_exist = BorrowerRepaymentSchedule::where('borrower_id', $loan_details->id)->first();
    //             if(!$emi_chart_exist){
    //                 $api_details = VendorServiceApi::where('borrower_id', $loan_details->id)
    //                                     ->where('applicant_type', 'BORROWER')
    //                                     ->where('api_flag', 'DOWNLOAD_ESIGN_DOCUMENT')
    //                                     ->where('status', 'VALID')
    //                                     ->get();
    //                 $signed_date = null;
    //                 foreach ($api_details as $apiInfo) {
    //                     $api_response = json_decode($apiInfo->response);
    //                     if (isset($api_response->model->signers[0]->state) && $api_response->model->signers[0]->state == 'signed') {
    //                         $signed_date = Carbon::createFromFormat('M j, Y, g:i:s A', $api_response->model->signers[0]->signedOn);
    //                     }
    //                 }
    //                 if(!$signed_date) {
    //                     return $this->helper_controller->send_response('failed', 'Unable to proceed, Loan aggrement not signed.', 422);
    //                 }

    //                 // ****************************** Important ************************************//
    //                 // ****************************** Important ************************************//

    //                 // $signed_date = Carbon::today(); // remove this line in live

    //                 // ****************************** Important ************************************//
    //                 // ****************************** Important ************************************//
                    
    //                 if($signed_date) {
    //                     $tenure_count = 0;
    //                     if ($signed_date->day > 15) {
    //                         $start_date = $signed_date->copy()->addMonths(2)->day(5);
    //                     } else {
    //                         $start_date = $signed_date->copy()->addMonth()->day(5);
    //                     }
    //                     foreach ($emiCalculation['schedule'] as $emi) {
    //                         $tenure_count++;
    //                         $schedule = new BorrowerRepaymentSchedule();
    //                         $schedule->borrower_id = $loan_details->id;
    //                         $schedule->payment_status = 'UNPAID';
    //                         $schedule->emi_amount = $emi['total_payment'];
    //                         $schedule->number_of_installment = $tenure_count;
    //                         $schedule->principal_amount = $emi['principal'];
    //                         $schedule->interest_amount = $emi['interest'];
    //                         $schedule->fine_amount = 0;
    //                         $schedule->due_date = $start_date->copy()->addMonths($tenure_count - 1);
    //                         $schedule->user_id = auth()->id();
    //                         $schedule->user_type = "Borrower";
    //                         $schedule->ip = $this->helper_controller->get_user_ip();
    //                         $schedule->save();
    //                     }
    //                 }
    //             } 
    //         }
            
    //         $account_details = BorrowerAccountDetails::where('borrower_id', $loan_details->id)->first();
    //         if(!$account_details) {
    //             return $this->helper_controller->send_response('failed', "No borrower details found", 422);
    //         }
    //         $can_skip = 0;
    //         $reason = [];
            
    //         $ifsc_code = strtoupper($account_details->ifsc);
    //         if(empty($account_details->bank_code)) {
    //             $response = $this->helper_controller->get_ifsc_information($ifsc_code);
    //             $response = json_encode($response);
    //             $response = json_decode($response, true);
    //             // return $response;
    //             $ifsc_details = $response['original'];

    //             if ($ifsc_details && $ifsc_details['status'] === 'success') {
    //                 $bank_code = $ifsc_details['data']['bankcode'];
    //                 $account_details->account_type = empty($account_details->account_type) ? 'savings' : $account_details->account_type;
    //                 $account_details->ifsc = $ifsc_code;
    //                 $account_details->bank_code = $bank_code ?? null;
    //                 $account_details->save();
    //             }
    //         } else {
    //             $bank_code = $account_details->bank_code;
    //         }

    //         $check_nach_availability = NpciBankList::where('bank_id', $bank_code)->first();
    //         if(!$check_nach_availability) {
    //             $can_skip++;
    //             array_push($reason, "Bank not listed in NPCI");
    //         }
    //         if($check_nach_availability->card_flag == false) {
    //             $can_skip++;
    //             array_push($reason, "E-NACH using debit card not available");
    //         }

    //         if ($account_details->enach_status !== null) {
    //             $can_skip++;
    //             array_push($reason, "E-NACH status is ". $account_details->enach_status);   
    //         }

    //         $can_skip_step = false;
    //         if($can_skip > 0) {
    //             $can_skip_step = true;
    //         }
    //         $can_skip_step = true;
    //         $result = [
    //             'borrower_id' => $loan_details->id,
    //             'borrower_fin_id' => $loan_details->borrower_id,
    //             'emi_debit_consent' => $account_details->emi_debit_consent,
    //             'enach_status' => $account_details->enach_status,
    //             'account_holder_name' => $account_details->account_holder_name,
    //             'account_number' => $account_details->account_number,
    //             'ifsc' => $ifsc_code,
    //             'bank_name' => $account_details->bank_name,
    //             'branch_code' => $account_details->branch_code,
    //             'is_enach_enabled' => $account_details->is_enach_enabled,
    //             'account_type' => $account_details->account_type,
    //             'bank_code' => $bank_code,
    //             'can_skip_step' => $can_skip_step
    //         ];

    //         DB::commit();
    //         return $this->helper_controller->send_response('success', "Borrower e-nach details fetched", 200, $result);
    //     } catch (Exception $e) {
    //         return $this->helper_controller->send_response('failed', "Something went wrong: " . $e->getMessage(), 500);
    //     }
    // }

    public function get_borrower_account_enach_details (Request $request) {
        try {
            if (!auth()->check()) {
                return $this->helper_controller->send_response('failed', "Unauthenticated access, please login", 422);
            }

            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
            ]);
         
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();
            $loan_details = LoanBasicDetails::where('id', $request->borrower_id)->first();
            $count = 0;
            $borrower_id = $request->borrower_id;
            
            if(!$loan_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }

            $account_details = BorrowerAccountDetails::where('borrower_id', $loan_details->id)->first();
            if(!$account_details) {
                return $this->helper_controller->send_response('failed', "No borrower account details found", 422);
            }

            if (
                !empty($account_details->enach_transaction_id) && !empty($account_details->enach_status) &&
                strtolower($account_details->enach_status) === 'initiate'
            ) {
                $mandate_result = $this->helper_controller->retrieveMandate($account_details->enach_transaction_id);

                if ($mandate_result['status'] === 'success' && isset($mandate_result['data']['status'])) {
                    $account_details->enach_status = $mandate_result['data']['status'];
                    $account_details->save();

                    $result = [
                        'borrower_id' => $loan_details->id,
                        'borrower_fin_id' => $loan_details->borrower_id,
                        'emi_debit_consent' => $account_details->emi_debit_consent,
                        'enach_status' => $account_details->enach_status,
                        'account_holder_name' => $account_details->account_holder_name,
                        'account_number' => $account_details->account_number,
                        'ifsc' => strtoupper($account_details->ifsc),
                        'bank_name' => $account_details->bank_name,
                        'branch_code' => $account_details->branch_code,
                        'is_enach_enabled' => $account_details->is_enach_enabled,
                        'account_type' => $account_details->account_type,
                        'bank_code' => $account_details->bank_code,
                        'can_skip_step' => false
                    ];
                    DB::commit();
                    return $this->helper_controller->send_response('success', "Borrower e-nach details fetched", 200, $result);
                }
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
            } else {
                if($check_nach_availability->card_flag == false) {
                    $can_skip++;
                    array_push($reason, "E-NACH using debit card not available");
                }

                if ($account_details->enach_status !== null) {
                    $can_skip++;
                    array_push($reason, "E-NACH status is ". $account_details->enach_status);   
                }
            }

            $can_skip_step = false;
            if($can_skip > 0) {
                $can_skip_step = true;
            }
            // $can_skip_step = true;

            // $lender_id = null;
            // if (!empty($loan_details->lender_id)) {
            //     $lender_id = $loan_details->lender_id;
            // }

            // if(!$lender_id) {
            //     $lenderBorrowerGroup = LenderBorrowerGroup::where('borrower_id', $borrower_id)->first();
            //     $lender_id = $lenderBorrowerGroup->lender_id;
            // }

            // if(!$lender_id) {
            //     return $this->helper_controller->send_response('failed', "Lender not assigned", 422);
            // }

            $emi_chart_exist = BorrowerRepaymentSchedule::where('borrower_id', $loan_details->id)->first();
            if(!$emi_chart_exist){
                $productModel = BorrowerLoanScheme::where('borrower_id', $loan_details->id)->first();
                if(!$productModel) {
                    if($loan_details->model_id != '') {
                        $productModel = ProductModel::where('model_unq', $loan_details->model_id)->first();
                    } else {
                        $dealer = Dealer::where('dealer_unq',  $loan_details->dealer_id)->first();
                        if($dealer) {

                            $productModel = ProductModel::where('dealer_id', $dealer->id)->where('name', $loan_details->model)->first();
                        }
                    }
                }
                if($productModel) {
                    $roi = $productModel->interest;
                    $loan_amount = $loan_details->loan_amount;
                    $interest = $roi;
                    $tenure = $loan_details->loan_term;
                    if($tenure > 0 && $tenure <= 15) {
                        $insurance_2nd_year = 0;
                    } elseif($tenure > 15 && $tenure <= 24) {
                        $insurance_2nd_year = $productModel->insurance_2nd_year;
                    } else {
                        $insurance_2nd_year = 0;
                    }

                    $net_loan_amount = $loan_amount + $productModel->iot + $insurance_2nd_year;


                    $emiCalculation = $this->helper_controller->generateReducingInterestSchedule($net_loan_amount, $interest, $tenure);

                    if (!isset($emiCalculation['schedule']) || empty($emiCalculation['schedule'])) {
                        return $this->helper_controller->send_response('failed', 'EMI schedule generation failed.', 422);
                    }
            
                    // $api_details = VendorServiceApi::where('borrower_id', $loan_details->id)
                    //                     ->where('applicant_type', 'BORROWER')
                    //                     ->where('api_flag', 'DOWNLOAD_ESIGN_DOCUMENT')
                    //                     ->where('status', 'VALID')
                    //                     ->get();
                    // $signed_date = null;
                    // foreach ($api_details as $apiInfo) {
                    //     $api_response = json_decode($apiInfo->response);
                    //     if (isset($api_response->model->signers[0]->state) && $api_response->model->signers[0]->state == 'signed') {
                    //         $signed_date = Carbon::createFromFormat('M j, Y, g:i:s A', $api_response->model->signers[0]->signedOn);
                    //     }
                    // }
                    // if(!$signed_date) {
                    //     return $this->helper_controller->send_response('failed', 'Unable to proceed, Loan aggrement not signed.', 422);
                    // }

                    // ****************************** Important ************************************//
                    // ****************************** Important ************************************//

                    $signed_date = Carbon::today(); // remove this line in live

                    // ****************************** Important ************************************//
                    // ****************************** Important ************************************//
                    
                    if($signed_date) {
                        $tenure_count = 0;
                        // if ($signed_date->day > 15) {
                        //     $start_date = $signed_date->copy()->addMonths(2)->day(5);
                        // } else {
                        //     $start_date = $signed_date->copy()->addMonth()->day(5);
                        // }

                        $start_date = $signed_date->day > 15
                            ? $signed_date->copy()->addMonthsNoOverflow(2)->day(5)
                            : $signed_date->copy()->addMonthNoOverflow()->day(5);

                        // After (Green Raha only, for now will do manually)
                        // $start_date = $signed_date->day > 15
                        //     ? $signed_date->copy()->addMonthNoOverflow()->day(15)
                        //     : $signed_date->copy()->addMonthNoOverflow()->day(5);

                        foreach ($emiCalculation['schedule'] as $emi) {
                            $tenure_count++;
                            $schedule = new BorrowerRepaymentSchedule();
                            $schedule->borrower_id = $loan_details->id;
                            $schedule->payment_status = 'UNPAID';
                            $schedule->emi_amount = $emi['total_payment'];
                            $schedule->number_of_installment = $tenure_count;
                            $schedule->principal_amount = $emi['principal'];
                            $schedule->interest_amount = $emi['interest'];
                            $schedule->fine_amount = 0;
                            $schedule->due_date = $start_date->copy()->addMonths($tenure_count - 1);
                            $schedule->user_id = auth()->id();
                            $schedule->user_type = "Borrower";
                            $schedule->ip = $this->helper_controller->get_user_ip();
                            $schedule->save();
                        }
                    }
                } 
            }
            
            $result = [
                'borrower_id' => $loan_details->id,
                'borrower_fin_id' => $loan_details->borrower_id,
                'emi_debit_consent' => $account_details->emi_debit_consent,
                'enach_status' => $account_details->enach_status,
                'account_holder_name' => $account_details->account_holder_name,
                'account_number' => $account_details->account_number,
                'ifsc' => $ifsc_code,
                'bank_name' => $account_details->bank_name,
                'branch_code' => $account_details->branch_code,
                'is_enach_enabled' => $account_details->is_enach_enabled,
                'account_type' => $account_details->account_type,
                'bank_code' => $bank_code,
                'can_skip_step' => $can_skip_step
            ];

            DB::commit();
            return $this->helper_controller->send_response('success', "Borrower e-nach details fetched", 200, $result);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "Something went wrong: " . $e->getMessage(), 500);
        }
    }

    public function createRepaymentScheduleIfNotExists($borrower_id)
    {
        $loan_details = LoanBasicDetails::where('id', $borrower_id)->first();

        if (!$loan_details) {
            return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
        }

        $emi_chart_exist = BorrowerRepaymentSchedule::where('borrower_id', $loan_details->id)->first();
        if ($emi_chart_exist) {
            return true; // Schedule already exists
        }

        // Get product model
        $productModel = BorrowerLoanScheme::where('borrower_id', $loan_details->id)->first();
        if(!$productModel) {
            if($loan_details->model_id != '') {
                $productModel = ProductModel::where('model_unq', $loan_details->model_id)->first();
            } else {
                $dealer = Dealer::where('dealer_unq',  $loan_details->dealer_id)->first();
                if($dealer) {

                    $productModel = ProductModel::where('dealer_id', $dealer->id)->where('name', $loan_details->model)->first();
                }
            }
        }

        if (empty($productModel)) {
            return $this->helper_controller->send_response('failed', "Product model not found", 422);
        }

        // Loan details
        $roi = $productModel->interest;
        $loan_amount = $loan_details->loan_amount;
        $interest = $roi;
        $tenure = $loan_details->loan_term;

        // Insurance logic
        if ($tenure > 0 && $tenure <= 15) {
            $insurance_2nd_year = 0;
        } elseif ($tenure > 15 && $tenure <= 24) {
            $insurance_2nd_year = $productModel->insurance_2nd_year;
        } else {
            $insurance_2nd_year = 0;
        }

        $net_loan_amount = $loan_amount + $productModel->iot + $insurance_2nd_year;

        // Generate EMI schedule
        $emiCalculation = $this->helper_controller->generateReducingInterestSchedule($net_loan_amount, $interest, $tenure);

        if (!isset($emiCalculation['schedule']) || empty($emiCalculation['schedule'])) {
            return $this->helper_controller->send_response('failed', 'EMI schedule generation failed.', 422);
        }

        // Get signed date
        $api_details = VendorServiceApi::where('borrower_id', $loan_details->id)
            ->where('applicant_type', 'BORROWER')
            ->where('api_flag', 'DOWNLOAD_ESIGN_DOCUMENT')
            ->where('status', 'VALID')
            ->get();

        $signed_date = null;
        foreach ($api_details as $apiInfo) {
            $api_response = json_decode($apiInfo->response);
            if (isset($api_response->model->signers[0]->state) && $api_response->model->signers[0]->state == 'signed') {
                $signed_date = Carbon::createFromFormat('M j, Y, g:i:s A', $api_response->model->signers[0]->signedOn);
            }
        }

        $disbursement_details = DisbursementDetails::where('borrower_id', $loan_details->id)->first();
        if($disbursement_details) { 
            $signed_date = Carbon::createFromFormat('Y-m-d', $disbursement_details->disbursement_date);
        }
        if (!$signed_date) {
            return $this->helper_controller->send_response('failed', 'Unable to proceed, Loan not disbursed.', 422);
        }

        // **************** REMOVE IN PRODUCTION ****************
        // $signed_date = Carbon::today(); // ONLY FOR LOCAL/TEST
        // ******************************************************

        // Schedule generation
        $tenure_count = 0;
        // Before
        $start_date = $signed_date->day > 15
            ? $signed_date->copy()->addMonthsNoOverflow(2)->day(5)
            : $signed_date->copy()->addMonthNoOverflow()->day(5);

        // After (Green raha only)
        // $start_date = $signed_date->day > 15
        //     ? $signed_date->copy()->addMonthNoOverflow()->day(15)
        //     : $signed_date->copy()->addMonthNoOverflow()->day(5);

        foreach ($emiCalculation['schedule'] as $emi) {
            $tenure_count++;
            $schedule = new BorrowerRepaymentSchedule();
            $schedule->borrower_id = $loan_details->id;
            $schedule->payment_status = 'UNPAID';
            $schedule->emi_amount = $emi['total_payment'];
            $schedule->number_of_installment = $tenure_count;
            $schedule->principal_amount = $emi['principal'];
            $schedule->interest_amount = $emi['interest'];
            $schedule->fine_amount = 0;
            $schedule->due_date = $start_date->copy()->addMonths($tenure_count - 1);
            $schedule->user_id = auth()->id();
            $schedule->user_type = "Borrower";
            $schedule->ip = $this->helper_controller->get_user_ip();
            $schedule->save();
        }

        return true;
    }

    public function create_enach_debit_schedule(Request $request)
    {
        try {
            if (!auth()->check()) {
                return $this->helper_controller->send_response('failed', "Unauthenticated access, please login", 422);
            }
            
            return $this->helper_controller->send_response('failed', 'API not in use.', 422);

            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }


            // Loan must be disbursed
            $loan_details = LoanBasicDetails::where('id', $request->borrower_id)->where('status', 'DISBURSED')->first();
            if (!$loan_details) {
                return $this->helper_controller->send_response('failed', "The loan is not disbursed yet", 422);
            }

            $account_details = BorrowerAccountDetails::where('borrower_id', $loan_details->id)->first();
            if (!$account_details) {
                return $this->helper_controller->send_response('failed', "Account details does not exist.", 422);
            }
            if ($account_details->enach_status != 'authorized') {
                return $this->helper_controller->send_response('failed', "Enach mandate not authorized yet.", 422);
            }
            $borrower_id = $request->borrower_id;

            // validate and correct emi date if needed.
            // added on 31-07-2025
            // start
            $disbursement_details = DisbursementDetails::where('borrower_id', $loan_details->id)->first();
            if(!$disbursement_details) { 
                return $this->helper_controller->send_response('failed', 'Unable to proceed, Loan not disbursed.', 422);
            }
            $disbursement_date = $disbursement_details->disbursement_date;

            $validateAndCorrectEmiDueDates = $this->helper_controller->validateAndCorrectEmiDueDates($loan_details->id, $disbursement_date);

            if ($validateAndCorrectEmiDueDates['status'] !== 'success') {
                return $this->helper_controller->send_response('failed', $validateAndCorrectEmiDueDates['message'] ?? 'Failed to create schedule', 422);
            }
            // end

            $repaymentSchedules = BorrowerRepaymentSchedule::where('borrower_id', $borrower_id)
                ->where('payment_status', 'UNPAID')
                ->orderBy('id', 'asc')
                ->get();

            if ($repaymentSchedules->isEmpty()) {
                return $this->helper_controller->send_response('failed', "Repayment schedule not prepared", 422);
            }

            // Check if a successful schedule already exists for this borrower
            $existingSchedule = EnachScheduleLogs::where('borrower_id', $borrower_id)
                ->where('status', 'success')
                ->first();

            if ($existingSchedule) {
                return $this->helper_controller->send_response('failed', 'Schedule already exists for this borrower.', 422);
            }

            /**
             * To create an eNACH schedule correctly, the debit start date must be set 
             * **one day before** the actual due date. This ensures that the debit is 
             * processed on the intended due date.
             *
             * How the process works:
             * 1. The presentment (debit) request is sent to the service provider on the specified `start_date`.
             * 2. The service provider forwards the request to the bank on the same day.
             * 3. The bank processes the debit on the following day.
             *
             * Example:
             * - If the actual due date is the 6th of the month, the start date should be set to the 5th.
             * - The service provider will send the request on the 5th.
             * - The user is notified that the debit is due on the 6th.
             * - The bank executes the debit on the 6th.
             *
             * Therefore, to ensure the debit happens on the actual due date, the start date must be:
             * Carbon::parse($repaymentSchedules->first()->due_date)->subDay()->format('Y-m-d');
             *
             * Actual due date: $repaymentSchedules->first()->due_date
             * Defined start date in schedule (1 day before actual due date): 
             * Carbon::parse($repaymentSchedules->first()->due_date)->subDay()->format('Y-m-d');
             * 
             * // updated on 27-12-2025
             * from now on the debit schedule will be on the same date as due date, that means the presentment will
             * happen on 5th, not 4th.
             */

            $mandate_start_date = Carbon::parse($account_details->enach_start_date)->format('Y-m-d');
            $first_due_date     = Carbon::parse($repaymentSchedules->first()->due_date)->format('Y-m-d');
            // $first_payment_date = Carbon::parse($first_due_date)->subDay()->format('Y-m-d');
            $first_payment_date = Carbon::parse($first_due_date)->format('Y-m-d');

            // Convert to Carbon temporarily for checking
            $first_payment_carbon = Carbon::parse($first_payment_date);

            $todayCarbon = Carbon::now()->day;

            if ($first_payment_carbon->month === Carbon::now()->month && in_array($todayCarbon, [4, 5])) {
                return $this->helper_controller->send_response(
                    'failed',
                    'Can not create schedule on the same day of payment',
                    422
                );
            }

            $lastSchedule = $repaymentSchedules->last();
            $regular_emi_amount = (int) $repaymentSchedules->first()->emi_amount;
            $last_emi_amount = (int) $lastSchedule->emi_amount;
            $number_of_emis = $repaymentSchedules->count();

            $mandate_id = $account_details->mandate_id;
            // Step 1: Create initial log record
            $log = EnachScheduleLogs::create([
                'borrower_id' => $borrower_id,
                'status' => 'initiated',
                'scheduled_created_at' => date('Y-m-d H:i:s'),
            ]);

            // Step 2: Generate unique merchant_request_number using log id
            $schedule_request_no = $log->id;
            $merchant_request_number = 'FINACH' . date('YmdHis') . $schedule_request_no . 'S';

            /**
             * Handle special case where the last EMI amount is different from the rest.
             *
             * 🔍 Why this is needed:
             * - EMIs are typically rounded for simplicity (e.g., ₹5,000 per month).
             * - However, due to rounding, the **last EMI** may have a slightly **different amount**
             *   to adjust for the total principal + interest correctly.
             *
             * 🧠 Logic:
             * - If all EMIs are the same → create **one** schedule with full count.
             * - If the last EMI amount is **not equal** to the rest:
             *   1. Create a schedule for all **regular EMIs** (e.g., ₹5,000 for 11 months).
             *   2. Create a separate schedule for the **last EMI** (e.g., ₹4,850 for month 12).
             *
             * 📦 Final output:
             * - `schedules[0]` → bulk of EMIs with uniform amount
             * - `schedules[1]` → last EMI with adjusted amount and specific start date
             *
             * ⚠️ Note:
             * - Each schedule block must have its own `start_date`, `debit_amount`, `number_of_debits`, etc.
             * - The start date for the final EMI is also offset by 1 day before its actual due date,
             *   just like the others.
             *
             * Example:
             * - 12 EMIs: First 11 of ₹5,000, last one of ₹4,850.
             * - schedules[0]: ₹5,000 × 11
             * - schedules[1]: ₹4,850 × 1 (starting 1 day before last due date)
             */

            $schedules = [];
            // Condition: If mandate starts ON first due date, cannot debit a day before for first EMI
            if ($mandate_start_date == $first_due_date) {
                $schedules[] = [
                    "id" => "1",
                    "start_date" => $first_due_date, // Debit on actual due date
                    "frequency" => "monthly",
                    "debit_amount" => $regular_emi_amount,
                    "number_of_debits" => 1,
                    "mandate_id" => $mandate_id,
                ];

                // Handle middle EMIs
                if ($number_of_emis > 2) {
                    // $middle_due_date = Carbon::parse($repaymentSchedules[1]->due_date)->subDay()->format('Y-m-d');
                    $middle_due_date = Carbon::parse($repaymentSchedules[1]->due_date)->format('Y-m-d');

                    $schedules[] = [
                        "id" => "2",
                        "start_date" => $middle_due_date,
                        "frequency" => "monthly",
                        "debit_amount" => $regular_emi_amount,
                        "number_of_debits" => $number_of_emis - 2,
                        "mandate_id" => $mandate_id,
                    ];
                }

                // Last EMI
                // $final_due_date = Carbon::parse($lastSchedule->due_date)->subDay()->format('Y-m-d');
                $final_due_date = Carbon::parse($lastSchedule->due_date)->format('Y-m-d');

                $schedules[] = [
                    "id" => "3",
                    "start_date" => $final_due_date,
                    "frequency" => "monthly",
                    "debit_amount" => $last_emi_amount,
                    "number_of_debits" => 1,
                    "mandate_id" => $mandate_id,
                ];

            } else {
                if ($number_of_emis > 1 && $last_emi_amount != $regular_emi_amount) {
                    $schedules[] = [
                        "id" => "1",
                        "start_date" => $first_payment_date,
                        "frequency" => "monthly",
                        "debit_amount" => $regular_emi_amount,
                        "number_of_debits" => $number_of_emis - 1,
                        "mandate_id" => $mandate_id,
                    ];

                    // $final_due_date = Carbon::parse($lastSchedule->due_date)->subDay()->format('Y-m-d');
                    $final_due_date = Carbon::parse($lastSchedule->due_date)->format('Y-m-d');

                    $schedules[] = [
                        "id" => "2",
                        "start_date" => $final_due_date,
                        "frequency" => "monthly",
                        "debit_amount" => $last_emi_amount,
                        "number_of_debits" => 1,
                        "mandate_id" => $mandate_id,
                    ];
                } else {
                    $schedules[] = [
                        "id" => "1",
                        "start_date" => $first_payment_date,
                        "frequency" => "monthly",
                        "debit_amount" => $regular_emi_amount,
                        "number_of_debits" => $number_of_emis,
                        "mandate_id" => $mandate_id,
                    ];
                }
            }

            // Step 3: Auth and headers
            $merchantKey    = $this->easebuzz_test_key;
            $salt           = $this->easebuzz_test_salt;

            $payload = [
                "key" => $merchantKey,
                "merchant_request_number" => $merchant_request_number,
                "schedules" => $schedules,
            ];

            // Step 5: Update log with request payload and merchant_request_number
            $log->update([
                'merchant_request_number' => $merchant_request_number,
                'request_payload' => json_encode($payload),
            ]);

            /**
             * SHA-512 hash of string in the format of
             * “<key>|<merchant_request_number>|<salt>”
             */
            $auth_string        = $merchantKey . '|' . $merchant_request_number . '|' . $salt;
            $authorization_hash = hash('sha512', $auth_string);
            $sub_merchant_id    = $this->sub_merchant_id;
            // Step 5: cURL Request
            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL => $this->easebuzz_enach_url_prod . 'autocollect/v1/scheduler/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $authorization_hash,
                    'X-EB-MERCHANT-KEY: ' . $merchantKey,
                    'X-EB-SUB-MERCHANT-ID: ' . $sub_merchant_id,
                    'Content-Type: application/json'
                ],
            ]);

            $response = curl_exec($curl);

            if (curl_errno($curl)) {
                $error_msg = curl_error($curl);
                curl_close($curl);

                // Update log with failure
                $log->update([
                    'response_payload' => json_encode(['error' => $error_msg]),
                    'status' => 'failed',
                ]);

                return $this->helper_controller->send_response('failed', "Curl error: $error_msg", 422);
            }

            curl_close($curl);

            $decodedResponse = json_decode($response, true);

            // Extract schedule ids from response (if any)
            $accepted = $decodedResponse['data']['accepted_schedules'][0] ?? null;
            $scheduleId = $accepted['id'] ?? null;
            $schedulerId = $accepted['scheduler_id'] ?? null;

            // Step 8: Update log with response payload and status
            $log->update([
                'response_payload' => json_encode($decodedResponse),
                'schedule_id' => $scheduleId,
                'scheduler_id' => $schedulerId,
                'status' => $scheduleId ? 'success' : 'failed',
            ]);

            return $this->helper_controller->send_response('success', 'Schedule created successfully', 200, $decodedResponse);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "Something went wrong: " . $e->getMessage(), 422);
        }
    }

    // public function get_monthly_payment_report(Request $request)
    // {
    //     try {

    //         $user = auth()->user();
    //         $lender_id = false;
    //         $borrower_ids = null;
    //         if ($user->user_type === 34) {
    //             // Lender Executive
    //             $lenderExecutive = LenderDirectors::where('auth_id', $user->id)->first();
    //             if ($lenderExecutive) {
    //                 $lender_id = $lenderExecutive->lender_id;
    //             }
    //         } elseif ($user->user_type === 29) {
    //             // Lender
    //             $lender = Lenders::where('lender_auth_id', $user->id)->first();
    //             if ($lender) {
    //                 $lender_id = $lender->id;
    //             }
    //         }

    //         if ($lender_id) {
    //             $lenderBorrowerGroups = LenderBorrowerGroup::where('lender_id', $lender_id)->pluck('borrower_id');
    //             $otherBorrowers = LoanBasicDetails::where('lender_id', $lender_id)
    //                 ->whereNotIn('id', $lenderBorrowerGroups)  // avoid duplicates at DB level
    //                 ->pluck('id');

    //             $borrower_ids = $lenderBorrowerGroups->merge($otherBorrowers)->unique()->values()->toArray();
    //         }

    //         $validator = Validator::make($request->all(), [
    //             'start_date'   => ['nullable', 'date'],
    //             'end_date'     => ['nullable', 'date', 'after_or_equal:start_date'],
    //             'month'        => ['nullable', 'date_format:F'], // e.g., "June"
    //             'borrower_id'  => ['nullable', 'regex:/^[a-zA-Z0-9\-]+$/'],
    //             'number_of_records'     => ['nullable', 'integer', 'min:1', 'max:100'],
    //             'page'         => ['nullable', 'integer', 'min:1'],
    //             'payment_status' => ['nullable', 'string', 'in:PAID,UNPAID']
    //         ]);

    //         if ($validator->fails()) {
    //             return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
    //         }

    //         // Base query setup with joins
    //         $baseQuery = BorrowerRepaymentSchedule::query()
    //             ->join('loan_basic_details', 'borrower_repayment_schedules.borrower_id', '=', 'loan_basic_details.id')
    //             ->leftJoin('borrower_kycs', 'borrower_kycs.borrower_id', '=', 'loan_basic_details.id')
    //             ->leftJoin('presentment_status_updates as psu', 'borrower_repayment_schedules.merchant_request_number', '=', 'psu.merchant_request_number')
    //             ->leftJoin('disbursement_details', function ($join) {
    //                 $join->on('disbursement_details.borrower_id', '=', 'loan_basic_details.id');
    //             })
    //             ->leftJoin('lenders', DB::raw('CAST(lenders.id AS TEXT)'), '=', 'disbursement_details.lender_id')
    //             ->where('loan_basic_details.status', 'DISBURSED');

    //         if ($borrower_ids !== null) {
    //             $baseQuery->whereIn('loan_basic_details.id', $borrower_ids);
    //         }

    //         // Filter logic as reusable closure
    //         $applyFilters = function ($query) use ($request) {
    //             if ($request->filled('borrower_id')) {
    //                 $input = $request->borrower_id;
    //                 if (is_numeric($input)) {
    //                     $query->where('loan_basic_details.id', $input);
    //                 } else {
    //                     $query->whereRaw("LOWER(loan_basic_details.borrower_id) LIKE ?", ['%' . strtolower($input) . '%']);
    //                 }
    //             } else {
    //                 if ($request->filled('start_date') && $request->filled('end_date')) {
    //                 $query->whereDate('due_date', '>=', $request->start_date)
    //                       ->whereDate('due_date', '<=', $request->end_date);
    //                 } else {
    //                     $year = $request->input('year', date('Y'));
    //                     $month = $request->input('month', date('F')) ?: date('F');
    //                     $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
    //                     $query->whereMonth('due_date', $monthNumber)->whereYear('due_date', $year);
    //                 }
    //             }

    //             if ($request->filled('payment_status')) {
    //                 $payment_status = $request->payment_status;
    //                 $query->where("borrower_repayment_schedules.payment_status", $payment_status);
    //             }
    //         };

    //         // Apply filters to both paginated and aggregate queries
    //         $applyFilters($baseQuery);

    //         // Paginated query for display
    //         $dataQuery = (clone $baseQuery)->select([
    //             'borrower_repayment_schedules.*',
    //             'loan_basic_details.borrower_id as borrower_fin_id',
    //             'borrower_kycs.name',
    //             'borrower_kycs.mobile',
    //             'lenders.name as lender_name',
    //             'psu.status_at_bank',
    //             'psu.response_description as presentment_remarks'
    //         ]);

    //         $perPage = $request->input('number_of_records', 15);
    //         $page = $request->input('page', 1);

    //         $paginated = $dataQuery->orderBy('borrower_repayment_schedules.id', 'asc')
    //             ->paginate($perPage, ['*'], 'page', $page);

    //         // Aggregate SQL summary
    //         $aggregateQuery = (clone $baseQuery)->selectRaw("
    //             SUM(emi_amount) as total_amount,
    //             SUM(CASE WHEN payment_status = 'PAID' THEN total_payment ELSE 0 END) as total_paid,
    //             SUM(CASE WHEN payment_status = 'UNPAID' THEN emi_amount ELSE 0 END) as total_unpaid,
    //             COUNT(CASE WHEN payment_status = 'PAID' THEN 1 ELSE NULL END) as paid_count,
    //             COUNT(CASE WHEN payment_status = 'UNPAID' THEN 1 ELSE NULL END) as unpaid_count,
    //             SUM(principal_amount) as total_principal,
    //             SUM(interest_amount) as total_interest
    //         ")->first();

    //         $resultData = [
    //             'payment_summary' => [
    //                 'total_amount'    => $aggregateQuery->total_amount,
    //                 'total_paid'      => $aggregateQuery->total_paid,
    //                 'total_unpaid'    => $aggregateQuery->total_unpaid,
    //                 'paid_count'      => $aggregateQuery->paid_count,
    //                 'unpaid_count'    => $aggregateQuery->unpaid_count,
    //                 'total_principal' => $aggregateQuery->total_principal,
    //                 'total_interest'  => $aggregateQuery->total_interest,
    //             ],
    //             'data' => $paginated
    //         ];

    //         return $this->helper_controller->send_response('success', 'Monthly Payment Report Fetched', 200, $resultData);
    //     } catch (Throwable $e) {
    //         return $this->helper_controller->send_response('failed', "Something went wrong: " . $e->getMessage(), 422);
    //     }
    // }

    
    // ─── In your controller class header ──────────────────────────────────────────
    // use App\Traits\ResolvesBaseQuery;
    //
    // class YourController extends Controller
    // {
    //     use ResolvesBaseQuery;
    //
    //     public function __construct(
    //         private readonly HelperController         $helper_controller,
    //         private readonly BorrowerVisibilityService $visibilityService,
    //     ) {}
    // ─────────────────────────────────────────────────────────────────────────────
     
     
    public function get_monthly_payment_report(Request $request)
    {
        try {
     
            $validator = Validator::make($request->all(), [
                'start_date'        => ['nullable', 'date'],
                'end_date'          => ['nullable', 'date', 'after_or_equal:start_date'],
                'month'             => ['nullable', 'date_format:F'],
                'borrower_id'       => ['nullable', 'regex:/^[a-zA-Z0-9\-]+$/'],
                'number_of_records' => ['nullable', 'integer', 'min:1', 'max:100'],
                'page'              => ['nullable', 'integer', 'min:1'],
                'payment_status'    => ['nullable', 'string', 'in:PAID,UNPAID'],
                'rsm_id'            => ['nullable', 'integer']
            ]);
     
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
     
            // ── Resolve scope via trait (handles lender, LSP, dealer, OEM, admin) ─
            [$scopedLoanQuery, , $empty] = $this->resolveBaseQuery(auth()->id(), $this->visibilityService);
     
            if ($empty) {
                return $this->helper_controller->send_response('success', 'Monthly Payment Report Fetched', 200, [
                    'payment_summary' => [
                        'total_amount'    => 0,
                        'total_paid'      => 0,
                        'total_unpaid'    => 0,
                        'paid_count'      => 0,
                        'unpaid_count'    => 0,
                        'total_principal' => 0,
                        'total_interest'  => 0,
                    ],
                    'data' => [],
                ]);
            }
     
            // Collect scoped borrower IDs from the resolved loan query
            $borrowerIds = $scopedLoanQuery->pluck('id')->toArray();
     
            $baseQuery = BorrowerRepaymentSchedule::query()
                ->join('loan_basic_details', 'borrower_repayment_schedules.borrower_id', '=', 'loan_basic_details.id')
                ->leftJoin('borrower_account_details', 'borrower_repayment_schedules.borrower_id', '=', 'borrower_account_details.borrower_id')
                ->leftJoinSub(
                    DB::table('mandate_status_updates as msu')
                        ->select('msu.*')
                        ->where('msu.status', 'authorized')
                        ->whereIn('msu.id', function ($q) {
                            $q->selectRaw('MAX(id)')
                              ->from('mandate_status_updates')
                              ->where('status', 'authorized')
                              ->groupBy('transaction_id');
                        }),
                    'mandate_status_updates',
                    function ($join) {
                        $join->on(
                            'borrower_account_details.enach_transaction_id',
                            '=',
                            'mandate_status_updates.transaction_id'
                        );
                    }
                )
                ->leftJoin('borrower_kycs', 'borrower_kycs.borrower_id', '=', 'loan_basic_details.id')
                ->leftJoin('dealers', 'loan_basic_details.dealer_id', '=', 'dealers.dealer_unq')
                ->leftJoin('dealer_user_map', 'dealers.dealer_unq', '=', 'dealer_user_map.dealer_unq')
                ->leftJoin('admin_users', 'dealer_user_map.user_id', '=', 'admin_users.id')
                ->leftJoin('presentment_status_updates as psu', 'borrower_repayment_schedules.merchant_request_number', '=', 'psu.merchant_request_number')
                ->leftJoin('disbursement_details', function ($join) {
                    $join->on('disbursement_details.borrower_id', '=', 'loan_basic_details.id');
                })
                ->leftJoin('lenders', DB::raw('CAST(lenders.id AS TEXT)'), '=', 'disbursement_details.lender_id')
                ->where('loan_basic_details.status', 'DISBURSED')
                ->whereIn('loan_basic_details.id', $borrowerIds);
     
            // ── Filters ───────────────────────────────────────────────────────────
            $applyFilters = function ($query) use ($request) {
                if ($request->filled('borrower_id')) {
                    $input = $request->borrower_id;
                    if (is_numeric($input)) {
                        $query->where('loan_basic_details.id', $input);
                    } else {
                        $query->whereRaw("LOWER(loan_basic_details.borrower_id) LIKE ?", ['%' . strtolower($input) . '%']);
                    }
                } else {
                    if ($request->filled('start_date') && $request->filled('end_date')) {
                        $query->whereDate('due_date', '>=', $request->start_date)
                              ->whereDate('due_date', '<=', $request->end_date);
                    } else {
                        $year        = $request->input('year', date('Y'));
                        $month       = $request->input('month', date('F')) ?: date('F');
                        $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
                        $query->whereMonth('due_date', $monthNumber)->whereYear('due_date', $year);
                    }
                }
     
                if ($request->filled('payment_status')) {
                    $query->where('borrower_repayment_schedules.payment_status', $request->payment_status);
                }
                if ($request->filled('lender_id')) {
                    $query->where('disbursement_details.lender_id', $request->lender_id);
                }

                if ($request->filled('dealer_id')) {
                    $query->where('dealers.id', $request->dealer_id);
                }

                if ($request->filled('rsm_id')) {
                    $rsm = AdminUser::where('id', $request->rsm_id)
                        ->where('user_type', AdminUser::RSM_USER)
                        ->where('is_deleted', false)
                        ->first();
                    if ($rsm) {
                        $rsmDealerUnqs = $this->visibilityService->resolveVisibleDealerUnqs($rsm->id);
                        if ($rsmDealerUnqs->isNotEmpty()) {
                            $query->whereIn('loan_basic_details.dealer_id', $rsmDealerUnqs);
                        }
                    }
                }
            };
     
            $applyFilters($baseQuery);
     
            // ── Paginated data query ───────────────────────────────────────────────
            $dataQuery = (clone $baseQuery)->select([
                'borrower_repayment_schedules.*',
                'loan_basic_details.borrower_id as borrower_fin_id',
                'loan_basic_details.loan_term as loan_tenure',
                'borrower_kycs.name',
                'borrower_kycs.mobile',
                'lenders.name as lender_name',
                'psu.status_at_bank as presentment_status',         // ← updated column
                'dealers.dealer_unq as dealer_id',
                'dealers.name as dealer_name',
                'admin_users.name as rsm_name',
                'mandate_status_updates.umrn as umrn_number',
                // 'psu.response_description as presentment_remarks',  // ← will be overridden below for successes
                DB::raw("
                    COALESCE(
                        NULLIF(psu.response_description, ''),
                        borrower_repayment_schedules.remarks
                    ) as presentment_remarks
                ")
            ]);
     
            $perPage = $request->input('number_of_records', 15);
            $page    = $request->input('page', 1);
     
            $paginated = $dataQuery
                ->orderBy('borrower_repayment_schedules.id', 'asc')
                ->paginate($perPage, ['*'], 'page', $page);
     
            // Override presentment_remarks: "success" → fixed label, else keep DB value
            $paginated->getCollection()->transform(function ($row) {
                if (strtolower($row->presentment_status ?? '') === 'success') {
                    $row->presentment_remarks = 'Successful Transaction';
                }
                return $row;
            });
     
            // ── Aggregate summary ─────────────────────────────────────────────────
            // $aggregateQuery = (clone $baseQuery)->selectRaw("
            //     SUM(emi_amount) as total_amount,
            //     SUM(CASE WHEN payment_status = 'PAID' THEN total_payment ELSE 0 END) as total_paid,
            //     SUM(CASE WHEN payment_status = 'UNPAID' THEN emi_amount ELSE 0 END) as total_unpaid,
            //     COUNT(CASE WHEN payment_status = 'PAID' THEN 1 ELSE NULL END) as paid_count,
            //     COUNT(CASE WHEN payment_status = 'UNPAID' THEN 1 ELSE NULL END) as unpaid_count,
            //     SUM(principal_amount) as total_principal,
            //     SUM(interest_amount) as total_interest
            // ")->first();
     
            $resultData = [
                // 'payment_summary' => [
                //     'total_amount'    => $aggregateQuery->total_amount,
                //     'total_paid'      => $aggregateQuery->total_paid,
                //     'total_unpaid'    => $aggregateQuery->total_unpaid,
                //     'paid_count'      => $aggregateQuery->paid_count,
                //     'unpaid_count'    => $aggregateQuery->unpaid_count,
                //     'total_principal' => $aggregateQuery->total_principal,
                //     'total_interest'  => $aggregateQuery->total_interest,
                // ],
                'data' => $paginated,
            ];
     
            return $this->helper_controller->send_response('success', 'Monthly Payment Report Fetched', 200, $resultData);
     
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', "Something went wrong: " . $e->getMessage(), 422);
        }
    }
}
