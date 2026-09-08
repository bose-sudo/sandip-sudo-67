<?php

// =============================================================================
// FILE: app/Traits/CreditScoreTrait.php
//
// HOW TO USE — in each controller, add the trait and call unified_credit_score():
//
// UserController   → use CreditScoreTrait;
//   public function get_credit_score_details(Request $request) {
//       return $this->unified_credit_score($request, 'user');
//   }
//
// AdminController  → use CreditScoreTrait;
//   public function get_credit_score_details(Request $request) {
//       return $this->unified_credit_score($request, 'admin');
//   }
//
// B2BController    → use CreditScoreTrait;
//   public function get_credit_score_details(Request $request) {
//       return $this->unified_credit_score($request, 'b2b');
//   }
// =============================================================================

namespace App\Traits;

use App\Models\BorrowerKyc;
use App\Models\LoanApplicationSummary;
use App\Models\LoanBasicDetails;
use App\Models\VendorServiceApi;
use DateTime;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

trait CreditScoreTrait
{
    // =========================================================================
    // PUBLIC ENTRY POINT
    // Caller: 'user' | 'admin' | 'b2b'
    //
    // Differences per caller:
    //   user  → borrower_id from get_borrower_id(), actor field: none
    //   admin → borrower_id from $request->borrower_id, actor field: admin_user_id
    //   b2b   → borrower_id from $request->borrower_id, actor field: executive_id
    // =========================================================================
    public function unified_credit_score(Request $request, string $caller): JsonResponse
    {
        date_default_timezone_set('Asia/Kolkata');

        $input = $request->all();
        $ip    = $this->get_user_ip();

        // =====================================================================
        // 1. CALLER-SPECIFIC: validate extra fields + resolve IDs
        // =====================================================================
        $is_delegated = in_array($caller, ['admin', 'b2b']); // admin/b2b pass user_id and borrower_id in request

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
            // 'user' — resolve from authenticated user
            $user_id     = $request->user()->id;
            $borrower_id = $this->get_borrower_id($user_id);
            if (!is_numeric($borrower_id)) {
                return $this->send_response('failed', 'Unknown error occurred, please try again', 422);
            }
            $borrower_id = (int) $borrower_id;
        }

        // Actor ID stored in VendorServiceApi (null for callers that don't use it)
        $actor_id    = $request->user()->id;  // the logged-in person making the call
        $actor_field = match($caller) {
            'admin' => 'admin_user_id',
            'b2b'   => 'executive_id',
            default => null,
        };

        // =====================================================================
        // 2. VALIDATE COMMON FIELDS — Validator::make, first error only
        // =====================================================================
        $validator = Validator::make($input, [
            'mobile'         => ['required', 'regex:/^(?:\+91|91)?[6-9]\d{9}$/'],
            'name'           => ['required', 'string', 'max:255'],
            'dob'            => ['required', 'string'],
            'pan'            => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'],
            'pin'            => ['required', 'regex:/^[0-9]{6}$/'],
            'applicant_type' => ['required', 'in:BORROWER,COAPPLICANT,GUARANTOR'],
        ], [
            'mobile.required'         => 'Mobile number is required',
            'mobile.regex'            => 'Invalid mobile number format',
            'name.required'           => 'Name is required',
            'dob.required'            => 'Date of birth is required',
            'pan.required'            => 'Pan number is required',
            'pan.regex'               => 'Invalid Pan number format',
            'pin.required'            => 'Pin code is required',
            'pin.regex'               => 'Invalid Pin code format',
            'applicant_type.required' => 'Applicant type is required',
            'applicant_type.in'       => 'Invalid applicant type',
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        // =====================================================================
        // 3. DOB — multi-format parse + age check (21–55)
        //    Supports: Y-m-d | d-m-Y | d/m/Y
        // =====================================================================
        $dobRaw      = trim($input['dob']);
        $dobDateTime = null;

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y'] as $format) {
            $dt = DateTime::createFromFormat($format, $dobRaw);
            if ($dt && $dt->format($format) === $dobRaw) {
                $dobDateTime = $dt;
                break;
            }
        }

        if (!$dobDateTime) {
            return $this->send_response('failed', 'Invalid date of birth', 422);
        }

        $age = (new DateTime())->diff($dobDateTime)->y;
        if ($age < 21 || $age > 55) {
            return $this->send_response('failed', 'Age must be within 21 to 55', 422);
        }

        // =====================================================================
        // 4. PREPARE VARIABLES
        // =====================================================================
        $cleanName  = str_replace('.', '', $input['name']);
        $nameParts  = explode(' ', trim($cleanName));
        $first_name = $nameParts[0];
        $last_name  = count($nameParts) > 1 ? end($nameParts) : $nameParts[0];

        $phone          = $input['mobile'];
        $pan            = $input['pan'];
        $pin            = $input['pin'];
        $dob            = $dobDateTime->format('Y-m-d');
        $applicant_type = $input['applicant_type'];

        // =====================================================================
        // 5. COAPPLICANT → GUARANTOR override if skip flag is set
        // =====================================================================
        if ($applicant_type === 'COAPPLICANT') {
            $borrower_kyc = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
            if ($borrower_kyc && $borrower_kyc->skip_co_applicant === true) {
                $applicant_type        = 'GUARANTOR';
                $input['applicant_type'] = 'GUARANTOR';
            }
        }

        // =====================================================================
        // 6. CACHE CHECK — reuse VALID response from last 45 days (all callers)
        //    Index: single on api_flag + composite (doc_id, date_time)
        // =====================================================================
        $cachedRecord = VendorServiceApi::where('api_flag', 'CREDIT_SCORE_API_SIGNZY_EXPERIAN')
            ->where('doc_id', $pan)                 // composite index: doc_id
            ->where('applicant_type', $applicant_type)
            ->where('borrower_id', $borrower_id)
            ->where('status', 'VALID')
            ->where('date_time', '>=', now()->subDays(45)->format('Y-m-d H:i:s'))  // composite index: date_time
            ->latest('date_time')
            ->first();

        if ($cachedRecord) {
            // $this->helper_controller->store_app_log(
            //     'read',
            //     "[{$caller}] Credit score served from cache for {$applicant_type}",
            //     'BORROWER',
            //     $borrower_id,
            //     ['vendor_service_apis' => $cachedRecord->id]
            // );

            return $this->process_credit_result(
                json_decode($cachedRecord->response),
                $applicant_type,
                $borrower_id,
                $user_id,
                $cachedRecord,
                true    // is_cached — skip saving bureau report again
            );
        }

        // =====================================================================
        // 7. CALL EXTERNAL API
        // =====================================================================
        // $this->helper_controller->store_app_log(
        //     'create',
        //     "[{$caller}] Credit details api initiated for {$applicant_type}",
        //     'BORROWER',
        //     $borrower_id
        // );

        $jsonRequestBody = json_encode([
            'phoneNumber' => intval($phone),
            'pan'         => $pan,
            'firstName'   => $first_name,
            'lastName'    => $last_name,
            'dateOfBirth' => $dob,
            'pincode'     => intval($pin),
            'consent'     => [
                'consentFlag'      => true,
                'consentTimestamp' => strtotime(date('Y-m-d')),
                'consentIpAddress' => $ip,
                'consentMessageId' => 'CM_1',
            ],
        ]);

        try {
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, [
                CURLOPT_URL            => "https://api.signzy.app/api/v3/bureau/experian-report",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 30,       // never 0 (infinite) — hard cap
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => $jsonRequestBody,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: ' . $this->signzy_auth_key,
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
            // 8. PERSIST API LOG — OUTSIDE the transaction
            //    This must always survive, even if downstream DB updates fail.
            //    Caller-specific actor field is set dynamically.
            // =================================================================
            $api_status = $this->resolve_credit_api_status($result);

            $vendorServiceApi                 = new VendorServiceApi;
            $vendorServiceApi->user_id        = $user_id;       // the borrower's user id
            $vendorServiceApi->ip_address     = $ip;
            $vendorServiceApi->date_time      = date('Y-m-d H:i:s');
            $vendorServiceApi->status         = $api_status;
            $vendorServiceApi->api_flag       = 'CREDIT_SCORE_API_SIGNZY_EXPERIAN';
            $vendorServiceApi->request        = json_encode($input);
            $vendorServiceApi->response       = $response;
            $vendorServiceApi->borrower_id    = $borrower_id;
            $vendorServiceApi->applicant_type = $applicant_type;
            $vendorServiceApi->doc_id         = $pan;

            // Set caller-specific actor column (admin_user_id or executive_id)
            if ($actor_field) {
                $vendorServiceApi->{$actor_field} = $actor_id;
            }

            $vendorServiceApi->save();  // ← OUTSIDE transaction intentionally

            // =================================================================
            // 9. PROCESS RESULT — transaction wraps only downstream DB updates
            // =================================================================
            return $this->process_credit_result(
                $result,
                $applicant_type,
                $borrower_id,
                $user_id,
                $vendorServiceApi,
                false   // is_cached
            );

        } catch (Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // =========================================================================
    // PRIVATE: resolve VALID / INVALID from raw API result
    // =========================================================================
    private function resolve_credit_api_status($result): string
    {
        if (isset($result->statusCode) && $result->statusCode == 200) {
            return 'VALID';
        }
        if (isset($result->error->type) && $result->error->type === 'Not Found') {
            return 'VALID';
        }
        return 'INVALID';
    }

    // =========================================================================
    // PRIVATE: process result (new or cached).
    //          Transaction wraps ONLY LoanBasicDetails + LoanApplicationSummary.
    //          VendorServiceApi log is already saved before this is called.
    // =========================================================================
    private function process_credit_result(
        $result,
        string $applicant_type,
        int $borrower_id,
        int $user_id,
        VendorServiceApi $vendorServiceApi,
        bool $is_cached
    ): JsonResponse {

        // Failure messages — defined once, used in all branches
        $failure_messages = [
            // 'BORROWER'    => 'You are not eligible for a loan. Please consider reapplying after few months.',
            'BORROWER'    => 'Please wait while we review your application.',
            'COAPPLICANT' => 'Kindly change the co-applicant to continue with the process.',
            'GUARANTOR'   => 'Kindly change the guarantor to continue with the process.',
        ];

        try {
            DB::beginTransaction();

            // Pre-load models shared across branches
            $update_loan_details = LoanBasicDetails::find($borrower_id);
            $borrower_finayo_id  = $update_loan_details->borrower_id ?? null;

            // LoanApplicationSummary: admin/b2b versions query by borrower_id only
            // user version queried by user_id + borrower_id — since user_id is now
            // always available from context, we use borrower_id which is unique enough
            // and consistent across all 3 callers.
            $loan_summary = LoanApplicationSummary::where('borrower_id', $borrower_id)->first();

            // =================================================================
            // CASE A: Bureau data returned (statusCode 200)
            // =================================================================
            if (isset($result->statusCode) && $result->statusCode == 200) {

                // --- DPD calculation ----------------------------------------
                $dpd_data     = ['dpd_in_3_month' => 0, 'dpd_in_1_year' => 0, 'dpd_in_2_year' => 0, 'dpd_in_3_year' => 0, 'year_wise_dpd' => []];
                $cais_details = $result->data->jsonExperianReport->CAIS_Account->CAIS_Account_DETAILS ?? null;

                if (!empty($cais_details[0]->CAIS_Account_History)) {
                    $dpd_data = $this->calculate_dpds($cais_details);
                }

                $is_willful_defaulter = !empty($cais_details[0]->SuitFiledWillfulDefaultWrittenOffStatus);
                $credit_score         = $result->data->jsonExperianReport->SCORE->FCIREXScore;

                // --- Save bureau Excel report (skip on cache hit) -----------
                if (!$is_cached && $borrower_finayo_id) {
                    $this->save_bureau_excel_report($result, $applicant_type, $borrower_finayo_id);
                }

                // --- Determine rejection ------------------------------------
                // Rejected if score 11-649 OR any DPD > 0 in any window
                // (score <= 10 is treated as thin-file / no history, not rejected)
                $is_rejected = ($credit_score > 10 && $credit_score < 650)
                    || $dpd_data['dpd_in_3_month'] > 0
                    || $dpd_data['dpd_in_1_year']  > 0
                    || $dpd_data['dpd_in_2_year']  > 0
                    || $dpd_data['dpd_in_3_year']  > 0
                    || $is_willful_defaulter;

                if ($is_rejected) {
                    // ---------------------------------------------------------
                    // manual_override === true → admin has force-approved this file
                    // Applied to ALL callers (user, admin, b2b)
                    // ---------------------------------------------------------
                    if ($update_loan_details && $update_loan_details->manual_override === true) {
                        $status  = 'success';
                        $message = 'Credit details fetched';
                        $code    = 200;
                    } else {
                        $status  = 'failed';
                        $message = $failure_messages[$applicant_type];
                        $code    = 422;

                        // Mark BORROWER as REJECTED and record rejection reason
                        if ($applicant_type === 'BORROWER' && $update_loan_details) {
                            $update_loan_details->status        = 'REJECTED';
                            $update_loan_details->admin_remarks = json_encode([
                                'reason' => [
                                    'credit_score'         => $credit_score,
                                    'dpd'                  => $dpd_data,
                                    'is_willful_defaulter' => $is_willful_defaulter,
                                ],
                            ]);
                            $update_loan_details->save();
                        }
                    }
                } else {
                    $status  = 'success';
                    $message = 'Credit details fetched';
                    $code    = 200;
                }

                // --- Update loan summary ------------------------------------
                $this->update_credit_loan_summary(
                    $loan_summary,
                    $applicant_type,
                    $credit_score,
                    $dpd_data['dpd_in_3_month'],
                    $dpd_data['dpd_in_3_month'],   // dpd_90 intentionally mirrors dpd_30 (matches original)
                    $is_willful_defaulter
                );

                $log_message = $status === 'failed'
                    ? 'File rejected: low credit score / DPD / willful defaulter'
                    : 'Credit details fetched successfully';

                // $this->helper_controller->store_app_log(
                //     'create', $log_message, 'BORROWER', $borrower_id,
                //     ['vendor_service_apis' => $vendorServiceApi->id]
                // );

                $result_response = [
                    'status'      => $status,
                    'result_code' => $result->statusCode,
                    'message'     => $message,
                    'data'        => [
                        'BureauScore'          => $credit_score,
                        'is_willful_defaulter' => $is_willful_defaulter,
                        'dpd_in_3_month'       => $dpd_data['dpd_in_3_month'],
                        'dpd_in_1_year'        => $dpd_data['dpd_in_1_year'],
                        'dpd_in_2_year'        => $dpd_data['dpd_in_2_year'],
                        'dpd_in_3_year'        => $dpd_data['dpd_in_3_year'],
                    ],
                ];

            // =================================================================
            // CASE B: No credit history (Not Found)
            // =================================================================
            } elseif (isset($result->error->type) && $result->error->type === 'Not Found') {

                $this->update_credit_loan_summary($loan_summary, $applicant_type, 0, 0, 0, false);

                // $this->helper_controller->store_app_log(
                //     'create', 'No credit history', 'BORROWER', $borrower_id,
                //     ['vendor_service_apis' => $vendorServiceApi->id]
                // );

                $result_response = [
                    'status'      => 'success',
                    'result_code' => $result->error->statusCode ?? '',
                    'message'     => 'No credit history',
                    'data'        => [
                        'BureauScore'          => 0,
                        'is_willful_defaulter' => false,
                        'dpd_in_3_month'       => 0,
                        'dpd_in_1_year'        => 0,
                        'dpd_in_2_year'        => 0,
                        'dpd_in_3_year'        => 0,
                    ],
                ];
                $code = 200;

            // =================================================================
            // CASE C: Unexpected / INVALID API response
            // =================================================================
            } else {

                // $this->helper_controller->store_app_log(
                //     'create', 'Credit API returned invalid response', 'BORROWER', $borrower_id,
                //     ['vendor_service_apis' => $vendorServiceApi->id]
                // );

                $result_response = [
                    'status'  => 'failed',
                    'message' => $result->message ?? 'Credit history not found',
                ];
                $code = 422;
            }

            DB::commit();
            return response()->json($result_response, $code ?? 200);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // =========================================================================
    // PRIVATE: save bureau Excel report to Storage + fix permissions
    // =========================================================================
    private function save_bureau_excel_report($result, string $applicant_type, string $borrower_finayo_id): void
    {
        if (empty($result->data->excelExperianReport)) {
            return;
        }

        $fileContents = @file_get_contents($result->data->excelExperianReport);
        if ($fileContents === false) {
            return;
        }

        $typeSuffix = strtolower($applicant_type);
        $path       = "public/bureau_report/{$borrower_finayo_id}/{$applicant_type}/bureau-report-{$borrower_finayo_id}-{$typeSuffix}.xlsx";

        Storage::put($path, $fileContents);

        $fullPath  = storage_path("app/{$path}");
        @chmod(dirname($fullPath), 0755);
        @chmod($fullPath, 0644);
    }

    // =========================================================================
    // PRIVATE: update LoanApplicationSummary — works for all 3 applicant types
    // =========================================================================
    private function update_credit_loan_summary(
        $summary,
        string $applicant_type,
        $credit_score,
        $dpd_30,
        $dpd_90,
        bool $is_willful_defaulter
    ): void {
        if (!$summary) {
            return;
        }

        switch ($applicant_type) {
            case 'BORROWER':
                $summary->cibil_score          = $credit_score;
                $summary->dpd_30_in_3_month    = $dpd_30;
                $summary->dpd_90_in_1_year     = $dpd_90;
                $summary->is_willful_defaulter = $is_willful_defaulter;
                break;

            case 'COAPPLICANT':
                $summary->coappli_cibil_score = $credit_score;
                $summary->coappli_dpd_30      = $dpd_30;
                $summary->coappli_dpd_90      = $dpd_90;
                $summary->coappli_defaulter   = $is_willful_defaulter;
                break;

            case 'GUARANTOR':
                $summary->guarantor_cibil_score = $credit_score;
                $summary->guarantor_dpd_30      = $dpd_30;
                $summary->guarantor_dpd_90      = $dpd_90;
                $summary->guarantor_defaulter   = $is_willful_defaulter;
                break;
        }

        $summary->save();
    }
}