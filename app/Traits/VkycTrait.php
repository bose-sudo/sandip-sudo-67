<?php

// =============================================================================
// FILE: app/Traits/VkycTrait.php
//
// HOW TO USE — in each controller:
//
// B2CController   → use VkycTrait;
//   public function video_kyc_generate_url(Request $request) {
//       return $this->unified_vkyc_generate_url($request, 'b2c');
//   }
//   public function get_vkyc_status_by_application_number(Request $request) {
//       return $this->unified_vkyc_status($request, 'b2c');
//   }
//   public function vkyc_transaction_details(Request $request) {
//       return $this->unified_vkyc_transaction_details($request, 'b2c');
//   }
//
// B2BController   → use VkycTrait;
//   public function video_kyc_generate_url(Request $request) {
//       return $this->unified_vkyc_generate_url($request, 'b2b');
//   }
//   public function get_vkyc_status_by_application_number(Request $request) {
//       return $this->unified_vkyc_status($request, 'b2b');
//   }
//   public function vkyc_transaction_details(Request $request) {
//       return $this->unified_vkyc_transaction_details($request, 'b2b');
//   }
//
// AdminController → use VkycTrait;
//   public function video_kyc_generate_url(Request $request) {
//       return $this->unified_vkyc_generate_url($request, 'admin');
//   }
//   public function get_vkyc_status_by_application_number(Request $request) {
//       return $this->unified_vkyc_status($request, 'admin');
//   }
//   public function vkyc_transaction_details(Request $request) {
//       return $this->unified_vkyc_transaction_details($request, 'admin');
//   }
//
// =============================================================================
//
// CALLER DIFFERENCES (all three methods):
//   b2c   → user_id from auth token, borrower_id from get_borrower_id()
//           no actor field stored in VendorServiceApi
//           updateApplicationStep($user_id, $borrower_id, $step)
//   b2b   → user_id + borrower_id from request body (required)
//           actor field: executive_id = logged-in user
//           updateApplicationStep($borrower_id, $step)
//   admin → user_id + borrower_id from request body (required)
//           actor field: admin_user_id = logged-in user
//           updateApplicationStep($borrower_id, $step)
//
// API ENDPOINTS (v2):
//   Generate URL:      POST .../vkyc/v2/integration/leads/
//   Get Status:        POST .../vkyc/v2/integration/sessions/unique-id/
//   Session Details:   POST .../vkyc/v2/integration/sessions/details/
//   Demo base:  https://svcdemo.digitap.work
//   Prod base:  https://svc.digitap.ai
//
// =============================================================================

namespace App\Traits;

use App\Models\VkycApiReqDetails;
use App\Models\VendorServiceApi;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

trait VkycTrait
{
    // =========================================================================
    // PUBLIC METHOD 1: Generate VKYC URL
    // =========================================================================
    public function unified_vkyc_generate_url(Request $request, string $caller): JsonResponse
    {
        date_default_timezone_set('Asia/Kolkata');

        $input              = $request->all();
        $ip                 = $this->get_user_ip();
        $application_number = $this->generate_random_id();
        $currentStep        = 18;

        // =====================================================================
        // 1. RESOLVE IDs + ACTOR FIELD
        // =====================================================================
        [$user_id, $borrower_id, $actor_id, $actor_field] = $this->resolve_caller_ids($request, $caller);
        if ($user_id instanceof JsonResponse) return $user_id;

        // =====================================================================
        // 2. VALIDATE INPUT
        // =====================================================================
        $validator = Validator::make($input, [
            'mobile' => ['required', 'regex:/^(?:\+91|91)?[6-9]\d{9}$/'],
            'name'   => ['required', 'string'],
        ], [
            'mobile.required' => 'Mobile number is required',
            'mobile.regex'    => 'Invalid mobile number format',
            'name.required'   => 'Name is required',
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        // =====================================================================
        // 3. PARSE NAME → first / last
        //    Single-word name: first = last = same word
        //    Multi-word name:  first = first word, last = last word
        // =====================================================================
        $name_parts = explode(' ', trim($input['name']));
        $first_name = $name_parts[0];
        $last_name  = $name_parts[count($name_parts) - 1];

        // =====================================================================
        // 4. CALL V2 API
        //    Demo:  https://svcdemo.digitap.work/vkyc/v2/integration/leads/
        //    Prod:  https://svc.digitap.ai/vkyc/v2/integration/leads/
        // =====================================================================
        try {
            $httpResponse = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->prod_authorization_key,
                'Content-Type'  => 'application/json',
            ])
                ->timeout(30)
                ->post('https://svc.digitap.ai/vkyc/v2/integration/leads/', [
                    'firstName'        => $first_name,
                    'lastName'         => $last_name,
                    'uniqueId'         => $application_number,
                    'mobile'           => $input['mobile'],
                    'email'            => $input['email'] ?? '',
                    'isKyc'            => false,
                    'sendEmail'        => false,
                    'sendSms'          => true,
                    'randomAssignment' => true,
                    'redirectionUrl'   => 'https://finayo.tech',
                ]);

            if ($httpResponse->failed() && $httpResponse->status() !== 422) {
                throw new Exception('API request failed with HTTP ' . $httpResponse->status());
            }

            $result   = $httpResponse->object();
            $response = $httpResponse->body();

            // =====================================================================
            // 5. INTERPRET RESPONSE
            //    V2 flat response: { "url": "...", "vkycCompleted": false, "uniqueIdExist": false }
            //    Success = HTTP 200 + url present and non-empty
            // =====================================================================
            $api_success = $httpResponse->successful() && !empty($result->url);
            $api_status  = $api_success ? 'VALID' : 'INVALID';

            if ($api_success) {
                $vkyc_url = $result->url;

                // --- Persist VKYC request record ---
                $vkyc_request_details                     = new VkycApiReqDetails;
                $vkyc_request_details->user_id            = $user_id;
                $vkyc_request_details->borrower_id        = $borrower_id;
                $vkyc_request_details->application_number = $application_number;
                $vkyc_request_details->mobile             = $input['mobile'];
                $vkyc_request_details->request            = json_encode($input);
                $vkyc_request_details->vkyc_url           = $vkyc_url;
                $vkyc_request_details->save();

                // --- Send SMS ---
                $kycUrl = str_replace(' ', '%20', html_entity_decode($vkyc_url));

                $this->smsService->sendLink(
                    $input['mobile'],
                    'Your Finayo VKYC application number is ' . $application_number
                        . ' Please click this link to start the video kyc process: ' . $kycUrl
                );

                // --- Update application step ---
                $application_step = [
                    'step'                    => $currentStep,
                    'vkyc_request_details_id' => $vkyc_request_details->id,
                    'details'                 => 'VKYC Generate URL',
                    'status'                  => 'COMPLETED',
                ];

                $this->update_step($caller, $user_id, $borrower_id, $application_step);

                // --- Log API call ---
                $this->log_vendor_api(
                    api_flag: 'VKYC_GENERATE_URL_API',
                    user_id: $user_id,
                    ip: $ip,
                    api_status: $api_status,
                    input: $input,
                    response: $response,
                    borrower_id: $borrower_id,
                    actor_field: $actor_field,
                    actor_id: $actor_id,
                );

                return response()->json([
                    'status'      => 'success',
                    'result_code' => 200,
                    'message'     => 'Video KYC url generated',
                    'data'        => [
                        'application_number' => $application_number,
                        'url'                => $vkyc_url,
                    ],
                ], 200);
            }

            // --- Failure path ---
            $this->log_vendor_api(
                api_flag: 'VKYC_GENERATE_URL_API',
                user_id: $user_id,
                ip: $ip,
                api_status: $api_status,
                input: $input,
                response: $response,
                borrower_id: $borrower_id,
                actor_field: $actor_field,
                actor_id: $actor_id,
            );

            return response()->json([
                'status'      => 'failed',
                'result_code' => $httpResponse->status() ?? 422,
                'message'     => $result->message ?? 'No results',
                'data'        => [],
            ], 422);

        } catch (Exception $e) {
            return response()->json([
                'status'  => 'failed',
                'message' => $e->getMessage(),
                'data'    => [],
            ], 422);
        }
    }

    // =========================================================================
    // PUBLIC METHOD 2: Get VKYC Status by Application Number (unique ID)
    // =========================================================================
    public function unified_vkyc_status(Request $request, string $caller): JsonResponse
    {
        date_default_timezone_set('Asia/Kolkata');

        $input = $request->all();
        $ip    = $this->get_user_ip();

        // =====================================================================
        // 1. RESOLVE IDs + ACTOR FIELD
        // =====================================================================
        [$user_id, $borrower_id, $actor_id, $actor_field] = $this->resolve_caller_ids($request, $caller);
        if ($user_id instanceof JsonResponse) return $user_id;

        // =====================================================================
        // 2. VALIDATE INPUT
        // =====================================================================
        $validator = Validator::make($input, [
            'application_number' => ['required', 'string'],
        ], [
            'application_number.required' => 'Application number is required',
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        // =====================================================================
        // 3. CALL V2 STATUS API
        //    Request:  { "uniqueIds": ["FINV2030420260318P"] }
        //
        //    Response — dynamic key is the session UUID:
        //    {
        //        "bc310513-f1c5-4fa8-8923-28ec905875b0": {
        //            "callStatus":    "ACCEPTED",
        //            "vkycStatus":    "APPROVED",
        //            "state":         "UPLOAD",
        //            "status":        "UPLOAD_SUCCESSFUL",
        //            "callInitiated": true
        //        }
        //    }
        //
        //    Demo:  https://svcdemo.digitap.work/vkyc/v2/integration/sessions/unique-id/
        //    Prod:  https://svc.digitap.ai/vkyc/v2/integration/sessions/unique-id/
        // =====================================================================
        try {
            $httpResponse = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->prod_authorization_key,
                'Content-Type'  => 'application/json',
            ])
                ->timeout(30)
                ->post('https://svc.digitap.ai/vkyc/v2/integration/sessions/unique-id/', [
                    'uniqueIds' => [$input['application_number']],
                ]);

            if ($httpResponse->failed()) {
                $errorBody = $httpResponse->json();
                $message   = $errorBody['errors'] ?? $errorBody['message'] ?? ('API request failed with HTTP ' . $httpResponse->status());
                throw new Exception($message);
            }

            $response    = $httpResponse->body();
            $resultArray = $httpResponse->json();

            // =====================================================================
            // 4. EXTRACT SESSION DATA
            //    Response is { "<sessionUuid>": { ...fields } }
            //    We take the first value since we sent one uniqueId.
            // =====================================================================
            $session_id   = !empty($resultArray) ? array_key_first($resultArray) : null;
            $session_data = $session_id ? (object) $resultArray[$session_id] : null;

            $api_success = $session_data !== null && $httpResponse->successful();
            $api_status  = $api_success ? 'VALID' : 'INVALID';

            // =====================================================================
            // 5. FETCH STORED VKYC URL from vkyc_api_req_details
            //    Resurfaces the KYC link so the frontend can show it again
            //    if the user hasn't completed KYC yet.
            // =====================================================================
            $vkyc_url    = '';
            $vkyc_record = DB::table('vkyc_api_req_details')
                ->where('borrower_id', $borrower_id)
                ->latest()
                ->first();

            if ($vkyc_record) {
                $vkyc_url = $vkyc_record->vkyc_url;
            }

            // =====================================================================
            // 6. LOG API CALL — return the model so we can reference its ID below
            // =====================================================================
            $vendorServiceApi = $this->log_vendor_api(
                api_flag: 'VKYC_CALL_DETAILS_USING_UNIQUE_ID_API',
                user_id: $user_id,
                ip: $ip,
                api_status: $api_status,
                input: $input,
                response: $response,
                borrower_id: $borrower_id,
                actor_field: $actor_field,
                actor_id: $actor_id,
                return_model: true,
            );

            // =====================================================================
            // 7. BUILD RESPONSE + CONDITIONAL STEP UPDATE
            //
            //    Response shape mirrors old V1 exactly:
            //    {
            //        "status": "success",
            //        "result_code": 200,
            //        "message": "Video KYC details fetched",
            //        "data": {
            //            "transactionId": "...",
            //            "callStatus": "...",
            //            "vkycStatus": "...",
            //            "state": "...",
            //            "status": "...",
            //            "callInitiated": true,
            //            "uniqueId": "...",
            //            "url": "..."   ← vkyc link resurface (was only in admin before)
            //        }
            //    }
            // =====================================================================
            if ($api_success) {

                if (($session_data->vkycStatus ?? '') === 'APPROVED') {
                    $application_step = [
                        'step'                  => 19,
                        'vendor_service_api_id' => $vendorServiceApi->id,
                        'details'               => 'VKYC Status',
                        'status'                => 'COMPLETED',
                    ];

                    $this->update_step($caller, $user_id, $borrower_id, $application_step);

                    // ---------------------------------------------------------------
                    // Auto-fetch full transaction details on APPROVED so that the PDF
                    // is downloaded and all session data is logged without requiring
                    // a separate frontend call.
                    // session_id here is the UUID key from the V2 status response.
                    // ---------------------------------------------------------------
                    try {
                        $this->fetch_and_store_transaction_details(
                            session_id:  $session_id,
                            borrower_id: $borrower_id,
                            user_id:     $user_id,
                            ip:          $ip,
                            actor_field: $actor_field,
                            actor_id:    $actor_id,
                        );
                    } catch (Exception $e) {
                        // Best-effort — don't fail the status response if details fetch fails
                        Log::warning('VKYC auto-fetch transaction details failed for session ' . $session_id . ': ' . $e->getMessage());
                    }
                }

                return response()->json([
                    'status'      => 'success',
                    'result_code' => 200,
                    'message'     => 'Video KYC details fetched',
                    'data'        => [
                        'transactionId'  => $session_id,
                        'callStatus'     => $session_data->callStatus    ?? '',
                        'vkycStatus'     => $session_data->vkycStatus    ?? '',
                        'state'          => $session_data->state         ?? '',
                        'status'         => $session_data->status        ?? '',
                        'callInitiated'  => $session_data->callInitiated ?? false,
                        'uniqueId'       => $input['application_number'],
                        'url'            => $vkyc_url,
                    ],
                ], 200);
            }

            return response()->json([
                'status'      => 'failed',
                'result_code' => '',
                'message'     => 'No records exist for the given application number',
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status'  => 'failed',
                'message' => $e->getMessage(),
                'data'    => [],
            ], 422);
        }
    }

    // =========================================================================
    // PUBLIC METHOD 3: Get VKYC Transaction Details by Session ID
    //
    // V2 endpoint:
    //   Demo:  https://svcdemo.digitap.work/vkyc/v2/integration/sessions/details/
    //   Prod:  https://svc.digitap.ai/vkyc/v2/integration/sessions/details/
    //
    // Request:  { "sessionIds": ["bc310513-f1c5-4fa8-8923-28ec905875b0"] }
    //
    // Response shape mirrors old V1 exactly:
    // {
    //   "status": "success",
    //   "result_code": 200,
    //   "message": "Video KYC details fetched",
    //   "data": {
    //     "transactionDetailInformation": {
    //       "<sessionId>": {
    //         "agentTransactionInformation": { ... },
    //         "subscribedServiceInformation": { ... },
    //         "additionalImages": { ... }
    //       }
    //     }
    //   }
    // }
    //
    // After a successful details fetch, if vkycPdfUrl is present the PDF is
    // downloaded and saved to:
    //   storage/app/public/Borrower/{borrower_id}/VKYC_Report/vkyc_report_{borrower_id}.pdf
    // =========================================================================
    public function unified_vkyc_transaction_details(Request $request, string $caller): JsonResponse
    {
        date_default_timezone_set('Asia/Kolkata');

        $input = $request->all();
        $ip    = $this->get_user_ip();

        // =====================================================================
        // 1. RESOLVE IDs + ACTOR FIELD
        // =====================================================================
        [$user_id, $borrower_id, $actor_id, $actor_field] = $this->resolve_caller_ids($request, $caller);
        if ($user_id instanceof JsonResponse) return $user_id;

        // =====================================================================
        // 2. VALIDATE INPUT
        //    V2 uses session_id (UUID returned by the status API).
        //    Old V1 used transaction_id — renamed to session_id to match V2.
        // =====================================================================
        $validator = Validator::make($input, [
            'session_id' => ['required', 'string'],
        ], [
            'session_id.required' => 'Session id is required',
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        // =====================================================================
        // 3. CALL V2 SESSION DETAILS API
        // =====================================================================
        try {
            $httpResponse = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->prod_authorization_key,
                'Content-Type'  => 'application/json',
            ])
                ->timeout(30)
                ->post('https://svc.digitap.ai/vkyc/v2/integration/sessions/details/', [
                    'sessionIds' => [$input['session_id']],
                ]);

            if ($httpResponse->failed()) {
                throw new Exception('API request failed with HTTP ' . $httpResponse->status());
            }

            $response    = $httpResponse->body();
            $resultArray = $httpResponse->json();

            // =====================================================================
            // 4. EXTRACT SESSION DATA
            //    Response: { "<sessionUuid>": { ...richDetails } }
            //    We take the first value since we sent one session ID.
            // =====================================================================
            $session_id   = !empty($resultArray) ? array_key_first($resultArray) : null;
            $session_data = $session_id ? (object) $resultArray[$session_id] : null;

            $api_success = $session_data !== null && $httpResponse->successful();
            $api_status  = $api_success ? 'VALID' : 'INVALID';

            // =====================================================================
            // 5. LOG API CALL
            // =====================================================================
            $this->log_vendor_api(
                api_flag: 'VKYC_TRANSACTION_DETAILS_API',
                user_id: $user_id,
                ip: $ip,
                api_status: $api_status,
                input: $input,
                response: $response,
                borrower_id: $borrower_id,
                actor_field: $actor_field,
                actor_id: $actor_id,
            );

            // =====================================================================
            // 6. BUILD RESPONSE
            //
            //    agentTransactionInformation is mapped field-by-field from V2 to
            //    match the old V1 key names exactly so the frontend needs no changes.
            //
            //    Fields that existed in V1 but are absent in V2 are stubbed as ''
            //    so the frontend never gets undefined keys.
            //
            //    New V2-only fields (vkycDate, qaComment, vkycPdfUrl) are added
            //    inside agentTransactionInformation — the frontend can adopt them
            //    incrementally without breaking existing reads.
            //
            //    subscribedServiceInformation sub-objects are passed through as-is;
            //    the frontend reads them as nested objects and field-name differences
            //    (e.g. faceMatchResult vs is_same_face) are a V2 change the frontend
            //    must handle when it reads those sub-fields.
            // =====================================================================
            if ($api_success) {

                // Split single name field → userFname / userLname to match V1 shape
                $name_parts = explode(' ', trim($session_data->name ?? ''), 2);
                $user_fname = $name_parts[0] ?? '';
                $user_lname = $name_parts[1] ?? '';

                $agentInfo = [
                    // Core identifiers
                    'transactionId'         => $session_id,
                    'uniqueId'              => $session_data->uniqueId          ?? '',
                    'userPartnerId'         => $session_data->uniqueId          ?? '',

                    // Agent fields — V2 does not return agent info in details endpoint
                    'agentFname'            => '',
                    'agentLname'            => '',
                    'agentEmailId'          => '',
                    'agentPhone'            => '',

                    // User identity
                    'userFname'             => $user_fname,
                    'userLname'             => $user_lname,
                    'userEmailId'           => $session_data->email             ?? '',
                    'userPhone'             => $session_data->mobileNo          ?? '',
                    'subClientId'           => '',

                    // Call / status
                    'agentCallStatus'       => $session_data->callStatus        ?? '',  // V1 key name
                    'agentVkycStatus'       => $session_data->agentVkycStatus   ?? '',
                    'agentComments'         => $session_data->agentComment      ?? '',  // V2: no trailing 's'
                    'qaComment'             => $session_data->qaComment         ?? '',
                    'disposition'           => '',   // not in V2
                    'callType'              => '',   // not in V2
                    'purgeStatus'           => '',   // not in V2
                    'agentStatusUpdateTime' => '',   // not in V2

                    // Location
                    'userLongitude'         => $session_data->longitude         ?? '',
                    'userLatitude'          => $session_data->latitude          ?? '',
                    'detectedAddress'       => $session_data->locationInfo->address ?? '',
                    'bandwidth'             => '',   // not in V2

                    // Media URLs
                    'userSelfieurl'         => $session_data->userSelfieUrl     ?? '',
                    'userPanUrl'            => $session_data->userPanUrl        ?? '',
                    'agentVideoUrl'         => $session_data->agentVideoUrl     ?? '',
                    'userVideoUrl'          => '',   // not in V2
                    'userBusinessPanUrl'    => '',   // not in V2
                    'userAadharFrontUrl'    => '',   // not in V2
                    'userAadharBackUrl'     => '',   // not in V2
                    'userAddressProofUrl'   => '',   // not in V2
                    'userOtherProofUrl'     => '',   // not in V2
                    'vkycPdfUrl'            => $session_data->vkycPdfUrl        ?? '',

                    // FM results — V2 moves these into subscribedServiceInformation sub-objects
                    'fmResult'              => '',   // not in V2 root
                    'flResult'              => '',   // not in V2 root

                    // V2-only additions
                    'vkycDate'              => $session_data->vkycDate          ?? '',
                    'duration'              => $session_data->duration          ?? '',
                ];

                // Build subscribedServiceInformation from V2 sub-objects.
                // Keys match old V1 names so frontend reads the same map keys.
                $subscribedServices = [];
                if (!empty($session_data->fmPanSelfie)) {
                    $subscribedServices['FM_PAN_SELFIE'] = $session_data->fmPanSelfie;
                }
                if (!empty($session_data->fmLiveliness)) {
                    $subscribedServices['FACE_LIVENESS'] = $session_data->fmLiveliness;
                }
                if (!empty($session_data->panValidation)) {
                    $subscribedServices['PAN_VALIDATION'] = $session_data->panValidation;
                }
                if (!empty($session_data->panOcr)) {
                    $subscribedServices['PAN_OCR'] = $session_data->panOcr;
                }
                if (!empty($session_data->locationInfo)) {
                    $subscribedServices['LOCATION_INFO'] = $session_data->locationInfo;
                }

                // =====================================================================
                // 7. DOWNLOAD VKYC PDF REPORT
                //    V1 used additionalImages->VKYC_IMAGE for the PDF link.
                //    V2 exposes the same PDF at vkycPdfUrl (root level field).
                //    Saved to:
                //      storage/app/public/Borrower/{borrower_id}/VKYC_Report/
                //          vkyc_report_{borrower_id}.pdf
                // =====================================================================
                $vkyc_pdf_url = $session_data->vkycPdfUrl ?? null;

                if ($vkyc_pdf_url) {
                    try {
                        $fileName      = 'vkyc_report_FIN-' . $borrower_id . '.pdf';
                        $directoryPath = storage_path('app/public/Borrower/FIN-' . $borrower_id . '/VKYC_Report');

                        if (!File::exists($directoryPath)) {
                            File::makeDirectory($directoryPath, 0755, true);
                        }

                        $filePath = $directoryPath . '/' . $fileName;
                        file_put_contents($filePath, file_get_contents($vkyc_pdf_url));
                    } catch (Exception $e) {
                        // Log but don't fail the whole request — PDF download is best-effort
                        Log::warning('VKYC PDF download failed for borrower ' . $borrower_id . ': ' . $e->getMessage());
                    }
                }

                // =====================================================================
                // 8. RETURN RESPONSE
                //    Shape mirrors old V1 exactly:
                //    data.transactionDetailInformation.<sessionId>.agentTransactionInformation
                //    data.transactionDetailInformation.<sessionId>.subscribedServiceInformation
                //    data.transactionDetailInformation.<sessionId>.additionalImages
                // =====================================================================
                return response()->json([
                    'status'      => 'success',
                    'result_code' => 200,
                    'message'     => 'Video KYC details fetched',
                    'data'        => [
                        'transactionDetailInformation' => [
                            $session_id => [
                                'agentTransactionInformation'  => $agentInfo,
                                'subscribedServiceInformation' => $subscribedServices,
                                'additionalImages'             => (array) ($session_data->additionalImages ?? new \stdClass),
                            ],
                        ],
                    ],
                ], 200);
            }

            return response()->json([
                'status'  => 'failed',
                'message' => 'No results',
                'data'    => [],
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status'  => 'failed',
                'message' => $e->getMessage(),
                'data'    => [],
            ], 422);
        }
    }

    // =========================================================================
    // PRIVATE HELPER: resolve user_id, borrower_id, actor_id, actor_field
    //
    // Returns: [$user_id, $borrower_id, $actor_id, $actor_field]
    //
    // On validation failure the first element is a JsonResponse — callers check:
    //   if ($user_id instanceof JsonResponse) return $user_id;
    // =========================================================================
    private function resolve_caller_ids(Request $request, string $caller): array
    {
        $actor_id    = $request->user()->id;
        $actor_field = match ($caller) {
            'admin' => 'admin_user_id',
            'b2b'   => 'executive_id',
            default => null,
        };

        if (in_array($caller, ['admin', 'b2b'])) {
            if (empty($request->user_id)) {
                return [$this->send_response('failed', 'User id is required', 422), null, null, null];
            }
            if (empty($request->borrower_id)) {
                return [$this->send_response('failed', 'Borrower id is required', 422), null, null, null];
            }
            return [$request->user_id, $request->borrower_id, $actor_id, $actor_field];
        }

        // b2c — identity from the authenticated token
        $user_id     = $request->user()->id;
        $borrower_id = $this->get_borrower_id($user_id);
        return [$user_id, $borrower_id, $actor_id, $actor_field];
    }

    // =========================================================================
    // PRIVATE HELPER: updateApplicationStep abstraction
    //
    // b2c signature: updateApplicationStep($user_id, $borrower_id, $step)
    // b2b / admin:   updateApplicationStep($borrower_id, $step)
    // =========================================================================
    private function update_step(string $caller, $user_id, $borrower_id, array $step): void
    {
        if ($caller === 'b2c') {
            $this->updateApplicationStep($user_id, $borrower_id, $step);
        } else {
            $this->updateApplicationStep($borrower_id, $step);
        }
    }

    // =========================================================================
    // PRIVATE HELPER: write one row to vendor_service_apis
    //
    // $return_model = true  → returns the saved Eloquent model
    //                         (needed when the caller references ->id for step log)
    // $return_model = false → returns null
    // =========================================================================
    private function log_vendor_api(
        string  $api_flag,
        $user_id,
        string  $ip,
        string  $api_status,
        array   $input,
        string  $response,
        $borrower_id,
        ?string $actor_field,
        $actor_id,
        bool    $return_model = false,
    ): VendorServiceApi|null {
        $log              = new VendorServiceApi;
        $log->user_id     = $user_id;
        $log->ip_address  = $ip;
        $log->date_time   = date('Y-m-d H:i:s');
        $log->status      = $api_status;
        $log->api_flag    = $api_flag;
        $log->request     = json_encode($input);
        $log->response    = $response;
        $log->borrower_id = $borrower_id;

        if ($actor_field) {
            $log->{$actor_field} = $actor_id;
        }

        $log->save();

        return $return_model ? $log : null;
    }

    // =========================================================================
    // PRIVATE HELPER: fetch and store transaction details internally
    //
    // Called automatically from unified_vkyc_status when vkycStatus = APPROVED.
    // Downloads the PDF and logs the API call exactly as the public method does,
    // but does not return a response — fire and forget from the status call.
    // =========================================================================
    private function fetch_and_store_transaction_details(
        string  $session_id,
        $borrower_id,
        $user_id,
        string  $ip,
        ?string $actor_field,
        $actor_id,
    ): void {
        $httpResponse = Http::withHeaders([
            'Authorization' => 'Basic ' . $this->prod_authorization_key,
            'Content-Type'  => 'application/json',
        ])
            ->timeout(30)
            ->post('https://svc.digitap.ai/vkyc/v2/integration/sessions/details/', [
                'sessionIds' => [$session_id],
            ]);

        if ($httpResponse->failed()) {
            $errorBody = $httpResponse->json();
            $message   = $errorBody['errors'] ?? $errorBody['message'] ?? ('API request failed with HTTP ' . $httpResponse->status());
            throw new Exception($message);
        }

        $response    = $httpResponse->body();
        $resultArray = $httpResponse->json();

        $returned_session_id = !empty($resultArray) ? array_key_first($resultArray) : null;
        $session_data        = $returned_session_id ? (object) $resultArray[$returned_session_id] : null;

        $api_status = $session_data !== null ? 'VALID' : 'INVALID';

        // Log the API call
        $this->log_vendor_api(
            api_flag:     'VKYC_TRANSACTION_DETAILS_API',
            user_id:      $user_id,
            ip:           $ip,
            api_status:   $api_status,
            input:        ['session_id' => $session_id, 'auto_triggered' => true],
            response:     $response,
            borrower_id:  $borrower_id,
            actor_field:  $actor_field,
            actor_id:     $actor_id,
        );

        if (!$session_data) {
            return;
        }

        // Download PDF
        $vkyc_pdf_url = $session_data->vkycPdfUrl ?? null;

        if ($vkyc_pdf_url) {
            $fileName      = 'vkyc_report_FIN-' . $borrower_id . '.pdf';
            $directoryPath = storage_path('app/public/Borrower/FIN-' . $borrower_id . '/VKYC_Report');

            if (!File::exists($directoryPath)) {
                File::makeDirectory($directoryPath, 0755, true);
            }

            $filePath    = $directoryPath . '/' . $fileName;
            $pdfResponse = Http::timeout(60)
                ->withoutVerifying()
                ->withOptions(['allow_redirects' => true])
                ->get($vkyc_pdf_url);

            if ($pdfResponse->successful()) {
                File::put($filePath, $pdfResponse->body());
            } else {
                Log::warning('VKYC auto PDF download failed for borrower ' . $borrower_id . ' — HTTP ' . $pdfResponse->status());
            }
        }
    }

    // =========================================================================
    // PUBLIC METHOD 4: Check VKYC Result
    //
    // Controller wiring:
    //   public function check_vkyc_result(Request $request) {
    //       return $this->unified_check_vkyc_result($request, 'b2c');   // or 'b2b' / 'admin'
    //   }
    //
    // Caller differences:
    //   b2c   → user_id + borrower_id from auth token / get_borrower_id()
    //           updateApplicationStep($user_id, $borrower_id, $step)
    //   b2b   → borrower_id from request body (required)
    //           updateApplicationStep($borrower_id, $step)
    //   admin → borrower_id from request body (required)
    //           updateApplicationStep($borrower_id, $step)
    // =========================================================================
    public function unified_check_vkyc_result(Request $request, string $caller): JsonResponse
    {
        date_default_timezone_set('Asia/Kolkata');

        // =====================================================================
        // 1. RESOLVE IDs
        // =====================================================================
        [$user_id, $borrower_id, $actor_id, $actor_field] = $this->resolve_caller_ids($request, $caller);
        if ($user_id instanceof JsonResponse) return $user_id;

        // =====================================================================
        // 2. FETCH LATEST VKYC REQUEST RECORD FOR THIS BORROWER
        // =====================================================================
        $vkyc_result = DB::table('vkyc_api_req_details')
            ->where('borrower_id', $borrower_id)
            ->latest()
            ->first();

        if (!$vkyc_result) {
            return $this->send_response('failed', 'No results found', 404);
        }

        $userData           = json_decode($vkyc_result->request);
        $application_number = $vkyc_result->application_number;

        // =====================================================================
        // 3. BASE DATA — always returned regardless of status
        //    transaction_id and vkyc_status come from the new columns on
        //    vkyc_api_req_details, kept in sync by the webhook handler.
        // =====================================================================
        $data = [
            'application_number'  => $application_number,
            'url'                 => $vkyc_result->vkyc_url       ?? '',
            'status'              => $vkyc_result->vkyc_status    ?? '',
            'transaction_id'      => $vkyc_result->transaction_id ?? '',
            'mobile'              => $userData->mobile            ?? '',
            'name'                => $userData->name              ?? '',
            'state'               => '',
            'comments'            => '',
        ];

        // =====================================================================
        // 4. ENRICH FROM LATEST WEBHOOK LOG
        //    state and comments live in vkyc_webhook_logs, not on the req record
        // =====================================================================
        $webhook = DB::table('vkyc_webhook_logs')
            ->where('unique_id', $application_number)
            ->latest('received_at')
            ->first();

        if ($webhook) {
            $data['state']    = $webhook->state    ?? '';
            $data['comments'] = $webhook->comments ?? '';
        }

        // =====================================================================
        // 5. APPROVED CHECK
        //    Primary:  vkyc_api_req_details.vkyc_status (set by webhook handler)
        //    Fallback: any APPROVED row in vkyc_webhook_logs for this uniqueId
        // =====================================================================
        $is_approved = ($vkyc_result->vkyc_status === 'APPROVED')
            || DB::table('vkyc_webhook_logs')
                ->where('unique_id', $application_number)
                ->where('status', 'APPROVED')
                ->exists();

        if ($is_approved) {
            $data['status'] = 'APPROVED';

            $application_step = [
                'step'           => 19,
                'vkyc_result_id' => $vkyc_result->id,
                'details'        => 'VKYC Status',
                'status'         => 'COMPLETED',
            ];

            $this->update_step($caller, $user_id, $borrower_id, $application_step);

            return $this->send_response('success', 'VKYC result fetched', 200, $data);
        }

        // =====================================================================
        // 6. NON-APPROVED FALLBACK
        //    Read the latest VKYC_CALL_DETAILS_USING_UNIQUE_ID_API log from
        //    vendor_service_apis — V2 response is flat { "<uuid>": {...} }
        // =====================================================================
        $vkycApiLog = VendorServiceApi::where('borrower_id', $borrower_id)
            ->where('api_flag', 'VKYC_CALL_DETAILS_USING_UNIQUE_ID_API')
            ->where('status', 'VALID')
            ->latest()
            ->first();

        if ($vkycApiLog) {
            $apiResponse = json_decode($vkycApiLog->response, true);
            $sessionData = !empty($apiResponse) ? (object) reset($apiResponse) : null;

            if ($sessionData) {
                $data['status']         = $sessionData->vkycStatus ?? '';
                $data['state']          = $sessionData->state      ?? $data['state'];
                $data['transaction_id'] = array_key_first($apiResponse) ?? $data['transaction_id'];

                if (($sessionData->vkycStatus ?? '') === 'APPROVED') {
                    $data['status'] = 'APPROVED';

                    $application_step = [
                        'step'           => 19,
                        'vkyc_result_id' => $vkyc_result->id,
                        'details'        => 'VKYC Status',
                        'status'         => 'COMPLETED',
                    ];

                    $this->update_step($caller, $user_id, $borrower_id, $application_step);

                    return $this->send_response('success', 'VKYC result fetched', 200, $data);
                }
            }
        }

        // Pending / incomplete — return whatever we have
        return $this->send_response('success', 'VKYC result fetched', 200, $data);
    }
}