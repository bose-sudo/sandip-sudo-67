<?php

// =============================================================================
// FILE: app/Traits/PanVerificationTrait.php
//
// HOW TO USE — in each controller:
//
// B2CController   → use PanVerificationTrait;
//   public function pan_details_verification(Request $request) {
//       return $this->unified_pan_verification($request, 'b2c');
//   }
//
// AdminController → use PanVerificationTrait;
//   public function pan_details_verification(Request $request) {
//       return $this->unified_pan_verification($request, 'admin');
//   }
//
// B2BController   → use PanVerificationTrait;
//   public function pan_details_verification(Request $request) {
//       return $this->unified_pan_verification($request, 'b2b');
//   }
// =============================================================================

namespace App\Traits;

use App\Models\LoanBasicDetails;
use App\Models\VendorServiceApi;
use DateTime;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

trait PanVerificationTrait
{
    // =========================================================================
    // PUBLIC ENTRY POINT
    // Caller: 'b2c' | 'admin' | 'b2b'
    //
    // Differences per caller:
    //   b2c   → borrower_id from get_borrower_id(), actor field: none
    //           aadhar DOB check: commented out in original (skipped)
    //   admin → borrower_id from $request->borrower_id, actor field: admin_user_id
    //           aadhar DOB check: active
    //   b2b   → borrower_id from $request->borrower_id, actor field: executive_id
    //           aadhar DOB check: active
    // =========================================================================
    public function unified_pan_verification(Request $request, string $caller): JsonResponse
    {
        date_default_timezone_set('Asia/Kolkata');

        $input          = $request->all();
        $ip             = $this->get_user_ip();
        $client_ref_num = $this->generate_random_id();

        // =====================================================================
        // 1. CALLER-SPECIFIC: resolve IDs + actor field
        // =====================================================================
        $is_delegated = in_array($caller, ['admin', 'b2b']);

        if ($is_delegated) {
            if (empty($request->user_id)) {
                return $this->send_response('failed', 'User id is required', 422);
            }
            if (empty($request->borrower_id) || !is_numeric($request->borrower_id)) {
                return $this->send_response('failed', 'Borrower id is required', 422);
            }
            $user_id     = $request->user_id;
            $borrower_id = (int) $request->borrower_id;
        } else {
            // b2c
            $user_id     = $request->user()->id;
            $borrower_id = $this->get_borrower_id($user_id);
            if (!is_numeric($borrower_id)) {
                return $this->send_response('failed', 'Unknown error occurred, please try again', 422);
            }
            $borrower_id = (int) $borrower_id;
        }

        $actor_id    = $request->user()->id;  // the logged-in person making the call
        $actor_field = match($caller) {
            'admin' => 'admin_user_id',
            'b2b'   => 'executive_id',
            default => null,
        };

        // =====================================================================
        // 2. VALIDATE — Validator::make, first error only
        // =====================================================================
        $validator = Validator::make($input, [
            'pan'            => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'],
            'name'           => ['required', 'string'],
            'applicant_type' => ['required', 'in:BORROWER,COAPPLICANT,GUARANTOR'],
        ], [
            'pan.required'            => 'Pan number is required',
            'pan.regex'               => 'Invalid Pan number format',
            'name.required'           => 'Name is required',
            'applicant_type.required' => 'Applicant type is required',
            'applicant_type.in'       => 'Invalid applicant type',
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $pan            = $input['pan'];
        $applicant_type = $input['applicant_type'];

        // =====================================================================
        // 3. DUPLICATE APPLICATION CHECK (BORROWER only)
        //    If an active/disbursed application already exists with this PAN,
        //    block the request.
        // =====================================================================
        if ($applicant_type === 'BORROWER') {
            $is_application_exist = DB::table('borrower_kycs as bk')
                ->join('loan_basic_details as lbd', 'bk.borrower_id', '=', 'lbd.id')
                ->where('bk.pan', $pan)
                ->whereIn('lbd.status', ['NEW', 'ACTIVE', 'DISBURSED'])
                ->first();

            if ($is_application_exist) {
                return $this->send_response(
                    'failed',
                    'An application already exists with this PAN. You can apply again only after the account is closed.',
                    422
                );
            }
        }

        // =====================================================================
        // 4. FETCH AADHAR DOB (for DOB cross-check later)
        //    b2c: aadhar check was commented out in original — skipped here too
        //    admin + b2b: fetch if available (not mandatory — soft check)
        // =====================================================================
        $aadharDob = null;

        // if ($is_delegated) {
        // }
        $aadhar_record = VendorServiceApi::where('borrower_id', $borrower_id)
            ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
            ->where('applicant_type', $applicant_type)
            ->where('status', 'VALID')
            ->latest()
            ->first();

        if ($aadhar_record) {
            $aadharData = json_decode(unserialize(base64_decode($aadhar_record->response)));
            if (!empty($aadharData->model->dob)) {
                $aadharDob = date('Y-m-d', strtotime($aadharData->model->dob));
            }
        }

        // =====================================================================
        // 5. CACHE CHECK — reuse existing VALID record for this PAN
        //    Uses: single index on api_flag + composite index (doc_id, date_time)
        //
        //    Uncomment the date_time line below to add a 45-day expiry window
        //    if cost reduction is needed in the future.
        // =====================================================================
        $cachedRecord = VendorServiceApi::where('api_flag', 'PAN_DETAILS_VALIDATION')
            ->where('doc_id', $pan)                 // composite index: doc_id
            ->where('applicant_type', $applicant_type)
            ->where('borrower_id', $borrower_id)
            ->where('status', 'VALID')
            // ->where('date_time', '>=', now()->subDays(45)->format('Y-m-d H:i:s'))  // uncomment for 45-day window
            ->latest('date_time')
            ->first();

        if ($cachedRecord) {
            $cachedResult = json_decode(unserialize(base64_decode($cachedRecord->response)));

            return $this->process_pan_result(
                $cachedResult,
                $applicant_type,
                $borrower_id,
                $aadharDob,
                $cachedRecord,
                true    // is_cached
            );
        }

        // =====================================================================
        // 6. CALL EXTERNAL API
        // =====================================================================
        try {
            $jsonRequestBody = json_encode([
                'client_ref_num' => $client_ref_num,
                'pan'            => $pan,
                'name'           => $input['name'],
                'father'         => 'yes',
            ]);

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, [
                CURLOPT_URL            => "https://svc.digitap.ai/validation/kyc/v1/pan_details",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 30,       // hard cap — never 0 (infinite)
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => $jsonRequestBody,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Basic ' . $this->prod_authorization_key,
                    'Content-Type: application/json',
                ],
            ]);

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }
            curl_close($curl);

            $result = json_decode($response);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }

            // =================================================================
            // 7. PERSIST API LOG — always, regardless of result
            // =================================================================
            $api_status = (isset($result->http_response_code) && $result->http_response_code == 200)
                ? 'VALID'
                : 'INVALID';

            $vendorServiceApi                   = new VendorServiceApi;
            $vendorServiceApi->user_id          = $user_id;
            $vendorServiceApi->ip_address       = $ip;
            $vendorServiceApi->date_time        = date('Y-m-d H:i:s');
            $vendorServiceApi->status           = $api_status;
            $vendorServiceApi->api_flag         = 'PAN_DETAILS_VALIDATION';
            $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
            $vendorServiceApi->response         = base64_encode(serialize($response));
            $vendorServiceApi->borrower_id      = $borrower_id;
            $vendorServiceApi->applicant_type   = $applicant_type;
            $vendorServiceApi->doc_id           = $pan;    // ← stored for cache lookup

            if ($actor_field) {
                $vendorServiceApi->{$actor_field} = $actor_id;
            }

            $vendorServiceApi->save();

            // =================================================================
            // 8. PROCESS RESULT
            // =================================================================
            return $this->process_pan_result(
                $result,
                $applicant_type,
                $borrower_id,
                $aadharDob,
                $vendorServiceApi,
                false   // is_cached
            );

        } catch (Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'data'    => [],
            ], 422);
        }
    }

    // =========================================================================
    // PRIVATE: process PAN API result (new or cached)
    // =========================================================================
    private function process_pan_result(
        $result,
        string $applicant_type,
        int $borrower_id,
        ?string $aadharDob,
        VendorServiceApi $vendorServiceApi,
        bool $is_cached
    ): JsonResponse {

        // Helper: build the standard data payload from result
        $build_data = fn() => [
            'pan'              => $result->result->pan              ?? '',
            'status'           => $result->status                   ?? 'success',
            'status_code'      => $result->result_code              ?? '',
            'name'             => $result->result->fullname         ?? '',
            'dob'              => $result->result->dob              ?? '',
            'name_match'       => $result->result->name_match       ?? '',
            'name_match_score' => $result->result->name_match_score ?? '',
        ];

        // =====================================================================
        // CASE A: Successful API response
        // =====================================================================
        if (isset($result->http_response_code) && $result->http_response_code == 200) {

            // --- PAN inactive / invalid ---
            if ($result->result_code != 101) {
                return $this->send_response(
                    'failed',
                    $result->message ?? 'Invalid pan data',
                    422
                );
            }

            $is_active = true;
            $message   = 'Pan details fetched';
            $panDob    = $result->result->dob ?? null;

            // --- Aadhar vs PAN DOB cross-check (only when aadharDob is available) ---
            if (!empty($aadharDob) && !empty($panDob)) {
                $dob1     = new DateTime($aadharDob);
                $dob2     = DateTime::createFromFormat('d/m/Y', $panDob);
                $diffDays = abs($dob1->diff($dob2)->days);

                // 1096 days = just over 3 years
                if ($diffDays >= 1096) {
                    $loan_details = LoanBasicDetails::find($borrower_id);

                    if ($applicant_type === 'BORROWER') {
                        $message = 'You are not eligible for a loan';
                        if ($loan_details) {
                            $loan_details->status        = 'REJECTED';
                            $loan_details->admin_remarks = 'Date of birth difference in aadhar and pan is more than 3 years.';
                            $loan_details->save();
                        }
                    } else {
                        $message = 'Date of birth difference in aadhar and pan is more than 3 years, please change the ' . $applicant_type;
                    }

                    return response()->json([
                        'status'      => 'failed',
                        'is_active'   => $is_active,
                        'result_code' => $result->result_code ?? '',
                        'message'     => $message,
                        'data'        => $build_data(),
                    ], 422);
                }
            }

            // --- Name match score check (admin only had this — applied to all for consistency) ---
            if (isset($result->result->name_match_score) && $result->result->name_match_score < 50) {
                return $this->send_response(
                    'failed',
                    'PAN name does not match with aadhar',
                    422,
                    $build_data()
                );
            }

            return response()->json([
                'status'      => 'success',
                'is_active'   => $is_active,
                'result_code' => $result->result_code ?? '',
                'message'     => $message,
                'data'        => $build_data(),
            ], 200);

        // =====================================================================
        // CASE B: API returned non-200
        // =====================================================================
        } else {
            return response()->json([
                'status'    => 'failed',
                'is_active' => false,
                'message'   => 'No results',
                'data'      => [
                    'pan'              => '',
                    'status'           => '',
                    'status_code'      => '',
                    'name'             => '',
                    'dob'              => '',
                    'name_match'       => '',
                    'name_match_score' => '',
                ],
            ], 200);
        }
    }
}