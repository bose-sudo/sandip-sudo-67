<?php
// =============================================================================
// FILE: app/Traits/SyncsDigilockerKyc.php
//
// Pushes verified DigiLocker data into the KYC row for BORROWER / COAPPLICANT
// / GUARANTOR. Caller-agnostic (b2c, b2b, admin) — pass borrower_id + type.
//
// REQUIRES on the using class: send_response()
//
// USAGE — gate before save, sync after:
//   if ($gate = $this->require_digilocker_verified($borrower_id, 'BORROWER', $full_name, $mobile)) {
//       return $gate;
//   }
//   ...
//   $this->sync_digilocker_to_kyc($borrower_id, 'BORROWER', $kyc->id, $full_name, $mobile);
//
// IDENTITY SCOPING:
//   Pass $full_name + $mobile to bind the lookup to the identity actually sent
//   to Digitap (read back from the originating generate-url log). Without them
//   the newest verification for the borrower+type wins, which is wrong when a
//   borrower has more than one co-applicant or guarantor.
//
// Method names are prefixed to avoid trait collisions with
// DigilockerVerificationTrait when a controller uses both.
// =============================================================================

declare(strict_types=1);

namespace App\Traits;

use App\Models\BorrowerKyc;
use App\Models\CoapplicantKyc;
use App\Models\Guarantor;
use App\Models\VendorServiceApi;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

trait SyncsDigilockerKyc
{
    private const DIGILOCKER_DETAILS_FLAG  = 'AADHAAR_DIGILOCKER_GET_DETAILS';
    private const DIGILOCKER_GENERATE_FLAG = 'AADHAAR_DIGILOCKER_GENERATE_URL';

    /** How many generate-url rows to scan when matching identity. */
    private const SYNC_IDENTITY_SCAN_LIMIT = 20;

    /**
     * applicant_type => [model, aadhaar column, verified-flag column]
     */
    private const KYC_TARGETS = [
        'BORROWER' => [
            'model'         => BorrowerKyc::class,
            'aadhar_column' => 'aadhar',
            'verified_flag' => 'is_verified_aadhar',
        ],
        'COAPPLICANT' => [
            'model'         => CoapplicantKyc::class,
            'aadhar_column' => 'aadhar',
            'verified_flag' => 'is_verified_aadhar',
        ],
        'GUARANTOR' => [
            'model'         => Guarantor::class,
            'aadhar_column' => 'aadhar',
            'verified_flag' => 'is_verified_aadhar',
        ],
    ];

    /**
     * @param  int|null     $kyc_id     Target row id. Required for COAPPLICANT/GUARANTOR
     *                                  when a borrower can have more than one.
     * @param  string|null  $full_name  Submitted name — scopes lookup to that identity.
     * @param  string|null  $mobile     Submitted mobile — scopes lookup to that identity.
     * @return bool  true = synced; false = nothing to sync (not an error).
     */
    public function sync_digilocker_to_kyc(
        int $borrower_id,
        string $applicant_type,
        ?int $kyc_id = null,
        ?string $full_name = null,
        ?string $mobile = null
    ): bool {
        $applicant_type = strtoupper($applicant_type);

        if (! isset(self::KYC_TARGETS[$applicant_type])) {
            Log::warning('digilocker.sync.unknown_type', compact('applicant_type', 'borrower_id'));
            return false;
        }

        $data = $this->extract_digilocker_kyc_data($borrower_id, $applicant_type, $full_name, $mobile);
        if ($data === null) {
            return false;
        }

        $target = self::KYC_TARGETS[$applicant_type];
        $row    = $this->resolve_kyc_row($target['model'], $borrower_id, $kyc_id);

        if (! $row) {
            Log::warning('digilocker.sync.row_not_found', compact('borrower_id', 'applicant_type', 'kyc_id'));
            return false;
        }

        $row->{$target['aadhar_column']} = $data['masked_aadhaar'];
        $row->{$target['verified_flag']} = true;
        $row->name_in_aadhar             = $data['name'];
        $row->aadhar_address             = $data['address'];
        $row->save();

        return true;
    }

    /**
     * Gate: DigiLocker must be verified before KYC details can be saved.
     * Returns null when verified, a 422 JsonResponse otherwise.
     */
    private function require_digilocker_verified(
        int $borrower_id,
        string $applicant_type,
        ?string $full_name = null,
        ?string $mobile = null
    ): ?JsonResponse {
        $applicant_type = strtoupper($applicant_type);

        if ($this->extract_digilocker_kyc_data($borrower_id, $applicant_type, $full_name, $mobile) !== null) {
            return null;
        }

        $label = match ($applicant_type) {
            'COAPPLICANT' => 'Co-applicant',
            'GUARANTOR'   => 'Guarantor',
            default       => 'Borrower',
        };

        return $this->send_response(
            'failed',
            "{$label} Aadhaar verification via DigiLocker is required before submitting details",
            422
        );
    }

    /**
     * Latest successful DigiLocker details payload for this borrower + type,
     * optionally scoped to the submitted name + mobile.
     *
     * @return array{masked_aadhaar:string,name:string,address:array,dob:string,gender:string,transaction_id:string}|null
     */
    private function extract_digilocker_kyc_data(
        int $borrower_id,
        string $applicant_type,
        ?string $full_name = null,
        ?string $mobile = null
    ): ?array {
        $query = VendorServiceApi::where('api_flag', self::DIGILOCKER_DETAILS_FLAG)
            ->where('borrower_id', $borrower_id)
            ->where('applicant_type', $applicant_type)
            ->where('status', 'VALID');

        // Bind to the identity actually sent to Digitap, when the caller knows it.
        if ($full_name !== null && $mobile !== null) {
            $identity        = $this->sync_identity_key_from_full_name($full_name, $mobile);
            $transaction_ids = $this->sync_transaction_ids_for_identity($borrower_id, $applicant_type, $identity);

            if ($transaction_ids === []) {
                return null;
            }

            $query->whereIn('doc_id', $transaction_ids);
        }

        // Only ever decode one details row — the response blob carries the base64
        // Aadhaar photo and is expensive to unserialize.
        $record = $query->latest('date_time')->first();

        if (! $record) {
            return null;
        }

        $body = $this->decode_digilocker_log($record);
        $m    = $body['model'] ?? [];

        // Same success contract as the verification trait — never trust status alone.
        $is_ok = ($body['code'] ?? null) == '200'
            && strtolower((string) ($m['status'] ?? '')) === 's'
            && ! empty($m['maskedAdharNumber']);

        if (! $is_ok) {
            return null;
        }

        $a = $m['address'] ?? [];

        return [
            'transaction_id' => (string) $record->doc_id,
            'masked_aadhaar' => (string) $m['maskedAdharNumber'],
            'name'           => (string) ($m['name']   ?? ''),
            'gender'         => (string) ($m['gender'] ?? ''),
            'dob'            => (string) ($m['dob']    ?? ''),
            'address'        => [
                'house'    => $a['house']    ?? '',
                'street'   => $a['street']   ?? '',
                'landmark' => $a['landmark'] ?? '',
                'loc'      => $a['loc']      ?? '',
                'po'       => $a['po']       ?? '',
                'dist'     => $a['dist']     ?? '',
                'subdist'  => $a['subdist']  ?? '',
                'vtc'      => $a['vtc']      ?? '',
                'pincode'  => $a['pc']       ?? '',
                'state'    => $a['state']    ?? '',
                'country'  => $a['country']  ?? '',
                'care_of'  => $m['careOf']   ?? '',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // IDENTITY SCOPING
    // Mirrors DigilockerVerificationTrait::digilocker_identity_key(). Prefixed to
    // avoid a trait method collision when both traits sit on one controller.
    // -------------------------------------------------------------------------

    /** Letters-only uppercase name + last 10 mobile digits. */
    private function sync_identity_key(string $first_name, string $last_name, string $mobile): string
    {
        $name   = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $first_name . $last_name));
        $digits = (string) preg_replace('/\D/', '', $mobile);

        return $name . '|' . substr($digits, -10);
    }

    /**
     * The generate-url payload splits the name into first/last, but the key strips
     * all non-letters — so the whole name can be hashed in one piece here.
     */
    private function sync_identity_key_from_full_name(string $full_name, string $mobile): string
    {
        return $this->sync_identity_key($full_name, '', $mobile);
    }

    /** Identity fingerprint of a stored generate-url log, or null if undecodable. */
    private function sync_record_identity(VendorServiceApi $record): ?string
    {
        $req = $this->decode_digilocker_request($record);

        if (empty($req['mobile'])) {
            return null;
        }

        return $this->sync_identity_key(
            (string) ($req['firstName'] ?? ''),
            (string) ($req['lastName']  ?? ''),
            (string) $req['mobile']
        );
    }

    /**
     * Transaction ids whose originating generate-url payload carried this identity.
     * Generate payloads are ~300 bytes, so scanning a capped batch is cheap.
     *
     * @return array<int,string>
     */
    private function sync_transaction_ids_for_identity(int $borrower_id, string $applicant_type, string $identity): array
    {
        return VendorServiceApi::where('api_flag', self::DIGILOCKER_GENERATE_FLAG)
            ->where('borrower_id', $borrower_id)
            ->where('applicant_type', $applicant_type)
            ->where('status', 'VALID')
            ->whereNotNull('doc_id')
            ->orderByDesc('date_time')
            ->limit(self::SYNC_IDENTITY_SCAN_LIMIT)
            ->get(['id', 'doc_id', 'request'])
            ->filter(fn (VendorServiceApi $r) => $this->sync_record_identity($r) === $identity)
            ->pluck('doc_id')
            ->all();
    }

    // -------------------------------------------------------------------------

    private function resolve_kyc_row(string $modelClass, int $borrower_id, ?int $kyc_id): ?Model
    {
        $query = $modelClass::where('borrower_id', $borrower_id);

        if ($kyc_id !== null) {
            $query->where('id', $kyc_id);
        }

        return $query->latest('id')->first();
    }

    private function decode_digilocker_log(VendorServiceApi $record): array
    {
        try {
            $raw = unserialize(base64_decode((string) $record->response));
            $arr = json_decode(is_string($raw) ? $raw : '', true);
            return is_array($arr) ? $arr : [];
        } catch (Exception) {
            return [];
        }
    }

    private function decode_digilocker_request(VendorServiceApi $record): array
    {
        try {
            $raw = unserialize(base64_decode((string) $record->request));
            $arr = json_decode(is_string($raw) ? $raw : '', true);
            return is_array($arr) ? $arr : [];
        } catch (Exception) {
            return [];
        }
    }
}