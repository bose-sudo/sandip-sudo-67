<?php

// =============================================================================
// FILE: app/Traits/AadharOcrTrait.php
//
// HOW TO USE — in each controller:
//
// B2CController   → use AadharOcrTrait;
//   public function aadhar_ocr_api(Request $request) {
//       return $this->unified_aadhar_ocr($request, 'b2c');
//   }
//
// AdminController → use AadharOcrTrait;
//   public function aadhar_ocr_api(Request $request) {
//       return $this->unified_aadhar_ocr($request, 'admin');
//   }
//
// B2BController   → use AadharOcrTrait;
//   public function aadhar_ocr_api(Request $request) {
//       return $this->unified_aadhar_ocr($request, 'b2b');
//   }
// =============================================================================

namespace App\Traits;

use App\Models\LoanBasicDetails;
use App\Models\VendorServiceApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

trait AadharOcrTrait
{
    // =========================================================================
    // PUBLIC ENTRY POINT
    // Caller: 'b2c' | 'admin' | 'b2b'
    //
    // Differences per caller:
    //   b2c   → borrower_id from get_borrower_id(), actor field: none
    //   admin → borrower_id + user_id from request, actor field: admin_user_id
    //   b2b   → borrower_id + user_id from request, actor field: executive_id
    // =========================================================================
    public function unified_aadhar_ocr(Request $request, string $caller): JsonResponse
    {
        date_default_timezone_set('Asia/Kolkata');

        $input = $request->all();
        $ip    = $this->get_user_ip();

        // =====================================================================
        // 1. CALLER-SPECIFIC: resolve IDs + actor field
        // =====================================================================
        $is_delegated = in_array($caller, ['admin', 'b2b']);

        if ($is_delegated) {
            if (empty($request->user_id)) {
                return $this->send_response('failed', 'User id is required', 422);
            }
            if (empty($request->borrower_id)) {
                return $this->send_response('failed', 'Borrower id is required', 422);
            }
            $user_id     = $request->user_id;
            $borrower_id = (int) $request->borrower_id;
        } else {
            // b2c
            $user_id     = $request->user()->id;
            $borrower_id = (int) $this->get_borrower_id($user_id);
        }

        $actor_id    = $request->user()->id;
        $actor_field = match ($caller) {
            'admin' => 'admin_user_id',
            'b2b'   => 'executive_id',
            default => null,
        };

        // =====================================================================
        // 2. VALIDATE LOAN
        // =====================================================================
        $loan_details = LoanBasicDetails::find($borrower_id);
        if (!$loan_details) {
            return $this->send_response('failed', 'Invalid request', 422);
        }

        // =====================================================================
        // 3. VALIDATE REQUEST FIELDS
        //    File rules are handled separately after Validator since Laravel's
        //    Validator handles uploaded files via $request->file(), not $input[].
        // =====================================================================
        $validator = Validator::make($input, [
            'user_type'   => ['required', 'in:BORROWER,COAPPLICANT,GUARANTOR'],
            'aadhar_type' => ['required', 'in:AADHAR_FRONT,AADHAR_BACK'],
        ], [
            'user_type.required'   => 'User type is required',
            'user_type.in'         => "User type can be only 'BORROWER', 'COAPPLICANT', or 'GUARANTOR'",
            'aadhar_type.required' => 'Aadhar type is required',
            'aadhar_type.in'       => "Aadhar type can be only 'AADHAR_FRONT' or 'AADHAR_BACK'",
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        // =====================================================================
        // 4. VALIDATE FILE SEPARATELY
        //    Kept outside Validator::make because file validation on a
        //    multipart upload is more reliably done via $request->file().
        // =====================================================================
        $fileValidator = Validator::make($request->allFiles(), [
            'aadhar_image' => ['required', 'file', 'mimes:jpeg,jpg,png', 'max:5120'],
        ], [
            'aadhar_image.required' => 'Aadhar image is required',
            'aadhar_image.file'     => 'Invalid aadhar image',
            'aadhar_image.mimes'    => 'Only JPG, PNG, and JPEG files are allowed',
            'aadhar_image.max'      => 'Aadhar image size must not exceed 5 MB',
        ]);

        if ($fileValidator->fails()) {
            return $this->send_response('failed', $fileValidator->errors()->first(), 422);
        }

        $user_type       = $input['user_type'];
        $aadhar_type     = $input['aadhar_type'];
        $aadhar_doc_type = ($aadhar_type === 'AADHAR_FRONT')
            ? 'AADHAR_CARD_OCR_FRONT'
            : 'AADHAR_CARD_OCR_BACK';

        // =====================================================================
        // 5. STORE FILE
        // =====================================================================
        $file      = $request->file('aadhar_image');
        $fileName  = $this->generate_random_string(20) . '.' . $file->getClientOriginalExtension();
        $path      = $file->storeAs('public/aadhar_ocr/' . $user_type, $fileName);
        $localPath = $file->getPathname();

        // =====================================================================
        // 6. CALL DIGITAP OCR API
        // =====================================================================
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => 'https://api.digitap.ai/ocr/v1/aadhaar',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => [
                'imageUrl'            => new \CURLFile($localPath),
                'clientRefId'         => $this->generate_random_id(6),
                'outputmaskedAadhaar' => 'yes',
                'isBlackWhiteCheck'   => 'yes',
                'confidence'          => 'yes',
                'maskAadhaarNumber'   => 'yes',
                'fraudCheck'          => 'yes',
            ],
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $this->prod_authorization_key,
            ],
        ]);

        $rawResponse = curl_exec($curl);
        curl_close($curl);

        $result = json_decode($rawResponse);

        // =====================================================================
        // 7. PERSIST API LOG — always, regardless of result
        // =====================================================================
        $api_status = (isset($result->statusCode) && $result->statusCode == "200")
            ? 'VALID'
            : 'INVALID';

        $vendorServiceApi                   = new VendorServiceApi;
        $vendorServiceApi->user_id          = $user_id;
        $vendorServiceApi->ip_address       = $ip;
        $vendorServiceApi->date_time        = date('Y-m-d H:i:s');
        $vendorServiceApi->status           = $api_status;
        $vendorServiceApi->api_flag         = $aadhar_doc_type;
        $vendorServiceApi->request          = base64_encode(serialize(json_encode($input)));
        $vendorServiceApi->response         = base64_encode(serialize($rawResponse));
        $vendorServiceApi->borrower_id      = $borrower_id;
        $vendorServiceApi->applicant_type   = $user_type;

        if ($actor_field) {
            $vendorServiceApi->{$actor_field} = $actor_id;
        }

        $vendorServiceApi->save();

        // =====================================================================
        // 8. EVALUATE OCR RESULT
        // =====================================================================
        if (
            isset($result->status, $result->statusCode)
            && $result->status    === 'success'
            && $result->statusCode === '200'
        ) {
            $ocr_data             = $result->result[0]->details ?? null;
            $response_aadhar_type = $result->result[0]->type    ?? null;

            if (!$this->is_ocr_confidence_valid($ocr_data, $aadhar_type)) {
                Storage::delete($path);
                return $this->send_response('failed', 'Please upload valid aadhar card', 422);
            }

            return $this->send_response(
                'success',
                'File uploaded successfully',
                200,
                $this->build_ocr_result_data($result, $ocr_data, $response_aadhar_type)
            );
        }

        Storage::delete($path);

        if (
            isset($result->status, $result->statusCode)
            && $result->status    === 'failure'
            && $result->statusCode === '501'
        ) {
            return $this->send_response(
                'failed',
                $result->error ?? 'Please upload valid aadhar card',
                422
            );
        }

        return $this->send_response('failed', 'Please upload valid aadhar card', 422);
    }

    // =========================================================================
    // PRIVATE: confidence threshold checks
    //
    // AADHAR_FRONT requires:
    //   - aadhaar->conf >= 90  (Aadhaar number clearly read)
    //   - aadhaar->value not empty
    //   - name->conf   >= 90  (name clearly read — added per new requirement)
    //
    // AADHAR_BACK requires:
    //   - address->conf >= 75
    // =========================================================================
    private function is_ocr_confidence_valid(?\stdClass $ocr_data, string $aadhar_type): bool
    {
        if ($ocr_data === null) {
            return false;
        }

        if ($aadhar_type === 'AADHAR_FRONT') {
            return isset($ocr_data->aadhaar->conf, $ocr_data->aadhaar->value, $ocr_data->name->conf)
                && $ocr_data->aadhaar->conf  >= 90
                && !empty($ocr_data->aadhaar->value)
                && $ocr_data->name->conf     >= 90;
        }

        if ($aadhar_type === 'AADHAR_BACK') {
            return isset($ocr_data->address->conf)
                && $ocr_data->address->conf >= 75;
        }

        return false;
    }

    // =========================================================================
    // PRIVATE: build normalised result payload
    // =========================================================================
    private function build_ocr_result_data(
        \stdClass  $result,
        \stdClass  $ocr_data,
        ?string    $response_aadhar_type
    ): array {
        return [
            'status'      => $result->status     ?? '',
            'statusCode'  => $result->statusCode ?? '',
            'type'        => $response_aadhar_type ?? '',
            'aadhaar'     => $ocr_data->aadhaar        ?? '',
            'name'        => $ocr_data->name->value    ?? '',
            'dob'         => $ocr_data->dob->value     ?? '',
            'gender'      => $ocr_data->gender->value  ?? '',
            'phone'       => $ocr_data->phone->value   ?? '',
            'father'      => $ocr_data->father->value  ?? '',
            'mother'      => $ocr_data->mother->value  ?? '',
            'husband'     => $ocr_data->husband->value ?? '',
            'care_of'     => $ocr_data->care_of->value ?? '',
            'pin'         => $ocr_data->pin->value     ?? '',
            'address'     => $ocr_data->address        ?? '',
        ];
    }
}