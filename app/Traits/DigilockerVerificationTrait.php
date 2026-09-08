<?php
// =============================================================================
// FILE: app/Traits/DigilockerVerificationTrait.php
//
// USAGE (same pattern as PanVerificationTrait):
//   B2CController   → $this->unified_digilocker_generate_url($request, 'b2c');
//                     $this->unified_digilocker_status($request, 'b2c');
//                     $this->unified_digilocker_details($request, 'b2c');
//   B2BController   → ... 'b2b'
//   AdminController → ... 'admin'
//
// FLAGS WRITTEN TO vendor_service_api:
//   AADHAAR_DIGILOCKER_GENERATE_URL   doc_id = transactionId
//   AADHAAR_DIGILOCKER_GET_DETAILS    doc_id = transactionId
// =============================================================================

declare(strict_types=1);

namespace App\Traits;

use App\Models\LoanBasicDetails;
use App\Models\VendorServiceApi;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait DigilockerVerificationTrait
{
    // =====================================================================
    // UAT STUB — flip to true in UAT to short-circuit both endpoints.
    // Hard-guarded against production by digilocker_stub_enabled().
    // =====================================================================
    private const DIGILOCKER_UAT_STUB = true;

    /** Marks stubbed rows so they can be found and purged. */
    private const STUB_TRANSACTION_PREFIX = 'UATSTUB';


    private const FLAG_GENERATE = 'AADHAAR_DIGILOCKER_GENERATE_URL';
    private const FLAG_DETAILS  = 'AADHAAR_DIGILOCKER_GET_DETAILS';

    private const APPLICANT_TYPES = ['BORROWER', 'COAPPLICANT', 'GUARANTOR'];

    /** docType => storage file suffix */
    private const STORABLE_DOCS = [
        'DIGILOCKER_PDF' => 'digilocker',
        'PANCR'          => 'pancard',
    ];


    /** User can re-initiate; anything else needs manual/ops intervention. */
    private const RETRYABLE_ERRORS = ['DG1001', 'DG1003', 'DG1004', 'DG1006', 'DG1011', '500'];

    private const DIGILOCKER_ERRORS = [
        'DG1001' => 'Access denied by user.',
        'DG1002' => 'eAadhaar data is unavailable for this account.',
        'DG1003' => 'Either source is down or the user has not selected the Aadhaar document.',
        'DG1004' => 'The user has not selected the required documents, please re-initiate the process.',
        'DG1005' => 'The requested document is not available in the DigiLocker account.',
        'DG1006' => 'The document could not be fetched due to service timeout.',
        'DG1008' => 'Invalid or tampered transaction link.',
        'DG1009' => 'Transaction already completed. No further action needed.',
        'DG1011' => 'A technical issue occurred. Please try again later.',
        '400'    => 'Invalid or incomplete DigiLocker transaction.',
        '500'    => 'DigiLocker service is temporarily unavailable, please try again.',
    ];

    // =========================================================================
    // API 1 — GENERATE DIGILOCKER URL
    // Input: borrower_id (delegated only), user_type, full_name, mobile
    // =========================================================================
    public function unified_digilocker_generate_url(Request $request, string $caller): JsonResponse
    {
        $ctx = $this->resolve_digilocker_context($request, $caller);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }

        $validator = validator($request->all(), [
            'user_type' => ['required', 'in:' . implode(',', self::APPLICANT_TYPES)],
            'full_name' => ['required', 'string', 'min:2', 'max:120', 'regex:/^[A-Za-z\.\s]+$/'],
            'mobile'    => ['required', 'regex:/^[6-9][0-9]{9}$/'],
        ], [
            'user_type.required' => 'User type is required',
            'user_type.in'       => 'Invalid user type',
            'full_name.required' => 'Full name is required',
            'full_name.regex'    => 'Full name may contain letters, spaces and dots only',
            'mobile.required'    => 'Mobile number is required',
            'mobile.regex'       => 'Invalid mobile number',
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $borrower_id    = $ctx['borrower_id'];
        $applicant_type = $request->input('user_type');

        if ($this->digilocker_stub_enabled()) {
            $this->digilocker_stub_persist(
                $ctx,
                $applicant_type,
                (string) $request->input('full_name'),
                (string) $request->input('mobile')
            );

            return $this->send_response('success', 'DigiLocker verification already completed', 200, [
                'is_completed'   => true,
                'is_stub'        => true,
                'transaction_id' => $this->digilocker_stub_transaction_id((int) $borrower_id, $applicant_type),
            ]);
        }

        // ---- Serialize concurrent taps on the same borrower + applicant --------
        $lock = Cache::lock("digilocker:gen:{$borrower_id}:{$applicant_type}", 15);
        if (! $lock->get()) {
            return $this->send_response('failed', 'A DigiLocker request is already in progress, please wait.', 429);
        }

        try {
            // ---- Already completed? Do not burn another transaction -----------
            $completed = $this->latest_successful_details_record($borrower_id, $applicant_type);
            if ($completed) {
                return $this->send_response('success', 'DigiLocker verification already completed', 200, [
                    'is_completed'   => true,
                    'transaction_id' => $completed->doc_id,
                ]);
            }

            // ---- Reuse a live (non-expired) link ------------------------------
            $ttl      = (int) config('services.digitap.digilocker.link_ttl_min', 20);
            $existing = VendorServiceApi::where('api_flag', self::FLAG_GENERATE)
                ->where('borrower_id', $borrower_id)
                ->where('applicant_type', $applicant_type)
                ->where('status', 'VALID')
                ->where('date_time', '>=', now()->subMinutes($ttl)->format('Y-m-d H:i:s'))
                ->latest('date_time')
                ->first();

            if ($existing) {
                $cached = $this->decode_vsa_response($existing);
                if (! empty($cached['model']['kycUrl'])) {
                    return $this->send_response('success', 'DigiLocker URL generated', 200, [
                        'is_completed'   => false,
                        'is_cached'      => true,
                        'transaction_id' => $existing->doc_id,
                        'url'            => $cached['model']['url']    ?? '',
                        'kyc_url'        => $cached['model']['kycUrl'] ?? '',
                    ]);
                }
            }

            // ---- Fresh call ---------------------------------------------------
            [$firstName, $lastName] = $this->split_full_name((string) $request->input('full_name'));

            $payload = [
                'serviceId'               => (string) config('services.digitap.digilocker.service_id', '4'),
                'firstName'               => $firstName,
                'lastName'                => $lastName,
                'uid'                     => $this->build_digilocker_uid($borrower_id, $applicant_type),
                'mobile'                  => (string) $request->input('mobile'),
                'isSendOtp'               => true,
                'isHideExplanationScreen' => false,
                'redirectionUrl'          => (string) config('services.digitap.digilocker.redirect_url', "https://finayo.tech"),
            ];

            $call = $this->digitap_kyc_post('generate-url', $payload);
            $body = $call['json'];

            $transaction_id = $body['model']['transactionId'] ?? null;
            $is_ok          = ($body['code'] ?? null) == '200' && ! empty($body['model']['kycUrl']);

            $this->digilocker_log_vendor_api(
                flag:           self::FLAG_GENERATE,
                status:         $is_ok ? 'VALID' : 'INVALID',
                request_body:   $payload,
                raw_response:   $call['raw'],
                ctx:            $ctx,
                applicant_type: $applicant_type,
                doc_id:         $is_ok ? (string) $transaction_id : null,
            );

            if (! $is_ok) {
                $err = $this->map_digilocker_error($body);
                return $this->send_response('failed', $err['message'], 422, [
                    'is_completed' => false,
                    'error_code'   => $err['code'],
                    'is_retryable' => $err['is_retryable'],
                ]);
            }

            return $this->send_response('success', 'DigiLocker URL generated', 200, [
                'is_completed'   => false,
                'is_cached'      => false,
                'transaction_id' => $transaction_id,
                'url'            => $body['model']['url']    ?? '',
                'kyc_url'        => $body['model']['kycUrl'] ?? '',
            ]);
        } catch (Exception $e) {
            Log::error('digilocker.generate_url', ['borrower_id' => $borrower_id, 'e' => $e->getMessage()]);
            return $this->send_response('failed', 'Unable to generate DigiLocker URL, please try again', 422);
        } finally {
            $lock->release();
        }
    }

    // =========================================================================
    // API 2 — CHECK DIGILOCKER STATUS (cached-first)
    // Input: borrower_id (delegated only), user_type, transaction_id (optional)
    // =========================================================================
    public function unified_digilocker_status(Request $request, string $caller): JsonResponse
    {
        return $this->handle_digilocker_details($request, $caller, force: false);
    }

    // =========================================================================
    // API 3 — FORCE FETCH DETAILS (always hits vendor)
    // =========================================================================
    public function unified_digilocker_details(Request $request, string $caller): JsonResponse
    {
        return $this->handle_digilocker_details($request, $caller, force: true);
    }

    // =========================================================================
    // CORE: status / details
    // =========================================================================
    private function handle_digilocker_details(Request $request, string $caller, bool $force): JsonResponse
    {
        $ctx = $this->resolve_digilocker_context($request, $caller);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }

        $validator = validator($request->all(), [
            'user_type'      => ['required', 'in:' . implode(',', self::APPLICANT_TYPES)],
            'transaction_id' => ['nullable', 'string', 'max:64', 'regex:/^[\w\.\-@]+$/'],
        ], [
            'user_type.required'   => 'User type is required',
            'user_type.in'         => 'Invalid user type',
            'transaction_id.regex' => 'Invalid transaction id',
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $borrower_id    = $ctx['borrower_id'];
        $applicant_type = $request->input('user_type');

        if ($this->digilocker_stub_enabled()) {
            $data = $this->digilocker_stub_persist($ctx, $applicant_type, '', '0000000000');
            $data['is_stub'] = true;

            return $this->send_response('success', 'DigiLocker details fetched', 200, $data);
        }

        // ---- Resolve transaction id ------------------------------------------
        $transaction_id = $request->filled('transaction_id')
            ? (string) $request->input('transaction_id')
            : (string) optional(
                VendorServiceApi::where('api_flag', self::FLAG_GENERATE)
                    ->where('borrower_id', $borrower_id)
                    ->where('applicant_type', $applicant_type)
                    ->where('status', 'VALID')
                    ->whereNotNull('doc_id')
                    ->latest('date_time')
                    ->first()
            )->doc_id;

        if ($transaction_id === '') {
            return $this->send_response('failed', 'No DigiLocker request found, please initiate DigiLocker first', 422);
        }

        $lock = Cache::lock("digilocker:details:{$transaction_id}", 30);
        if (! $lock->get()) {
            return $this->send_response('failed', 'DigiLocker details are being fetched, please wait.', 429);
        }

        try {
            // ---- Cached success reuse ----------------------------------------
            if (! $force) {
                $cachedRecord = VendorServiceApi::where('api_flag', self::FLAG_DETAILS)
                    ->where('doc_id', $transaction_id)
                    ->where('borrower_id', $borrower_id)
                    ->where('applicant_type', $applicant_type)
                    ->where('status', 'VALID')
                    ->latest('date_time')
                    ->first();

                if ($cachedRecord) {
                    $cached = $this->decode_vsa_response($cachedRecord);
                    if ($this->is_details_success($cached)) {
                        return $this->send_response(
                            'success',
                            'DigiLocker details fetched',
                            200,
                            $this->build_digilocker_payload($cached, $borrower_id, $applicant_type, $transaction_id, true)
                        );
                    }
                }
            }

            // ---- Fresh call ---------------------------------------------------
            $payload = ['transactionId' => $transaction_id];
            $call    = $this->digitap_kyc_post('get-digilocker-details', $payload);
            $body    = $call['json'];
            $is_ok   = $this->is_details_success($body);

            $stored = [];
            if ($is_ok) {
                $stored = $this->store_digilocker_files(
                    $body['model']['digilockerFiles'] ?? [],
                    $borrower_id,
                    $applicant_type,
                    $transaction_id
                );
            }

            $this->digilocker_log_vendor_api(
                flag:           self::FLAG_DETAILS,
                status:         $is_ok ? 'VALID' : 'INVALID',
                request_body:   $payload,
                raw_response:   $call['raw'],
                ctx:            $ctx,
                applicant_type: $applicant_type,
                doc_id:         $transaction_id,
            );

            if (! $is_ok) {
                $err = $this->map_digilocker_error($body);
                return $this->send_response('failed', $err['message'], 422, [
                    'transaction_id' => $transaction_id,
                    'is_completed'   => false,
                    'error_code'     => $err['code'],
                    'is_retryable'   => $err['is_retryable'],
                ]);
            }

            $data              = $this->build_digilocker_payload($body, $borrower_id, $applicant_type, $transaction_id, false);
            $data['documents'] = $stored;

            return $this->send_response('success', 'DigiLocker details fetched', 200, $data);
        } catch (Exception $e) {
            Log::error('digilocker.get_details', ['transaction_id' => $transaction_id, 'e' => $e->getMessage()]);
            return $this->send_response('failed', 'Unable to fetch DigiLocker details, please try again', 422);
        } finally {
            $lock->release();
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Resolve borrower_id / user_id / actor field per caller.
     *
     * user_id  = the borrower's user (request body for delegated callers)
     * actor_id = the logged-in person making the call
     *            → admin_user_id for admin, executive_id for b2b, none for b2c
     */
    private function resolve_digilocker_context(Request $request, string $caller): array|JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return $this->send_response('failed', 'Unauthenticated', 401);
        }

        $is_delegated = in_array($caller, ['admin', 'b2b'], true);

        if ($is_delegated) {
            if (empty($request->user_id)) {
                return $this->send_response('failed', 'User id is required', 422);
            }
            if (empty($request->borrower_id) || ! is_numeric($request->borrower_id)) {
                return $this->send_response('failed', 'Borrower id is required', 422);
            }
            $user_id     = $request->user_id;
            $borrower_id = (int) $request->borrower_id;
        } else {
            $user_id     = $actor->id;
            $borrower_id = $this->get_borrower_id($user_id);
            if (! is_numeric($borrower_id)) {
                return $this->send_response('failed', 'Unknown error occurred, please try again', 422);
            }
            $borrower_id = (int) $borrower_id;
        }

        return [
            'user_id'     => $user_id,
            'borrower_id' => $borrower_id,
            'actor_id'    => $actor->id,
            'actor_field' => match ($caller) {
                'admin' => 'admin_user_id',
                'b2b'   => 'executive_id',
                default => null,
            },
        ];
    }

    private function digitap_kyc_post(string $endpoint, array $payload): array
    {
        $url = rtrim((string) config('services.digitap.kyc_base', 'https://api.digitap.ai/ent/v1/kyc'), '/') . '/' . ltrim($endpoint, '/');

        try {
            $res = Http::withHeaders([
                'Authorization' => $this->prod_authorization_key,
                'Content-Type'  => 'application/json',
            ])
                ->timeout(45)
                ->connectTimeout(10)
                // Connection-level failures only. Retrying a 4xx/5xx on generate-url
                // would burn additional billable transaction ids.
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->withBody(json_encode($payload, JSON_UNESCAPED_SLASHES), 'application/json')
                ->post($url);
        } catch (ConnectionException $e) {
            throw new Exception('Digitap connection failure: ' . $e->getMessage());
        }

        $raw  = $res->body();
        $json = json_decode($raw, true);

        return ['raw' => $raw, 'json' => is_array($json) ? $json : []];
    }

    private function is_details_success(array $body): bool
    {
        return ($body['code'] ?? null) == '200'
            && strtolower((string) ($body['model']['status'] ?? '')) === 's'
            && ! empty($body['model']['maskedAdharNumber']);
    }

    /** @return array{code:string,message:string,is_retryable:bool} */
    private function map_digilocker_error(array $body): array
    {
        // errorCode (DG1xxx) is more specific than the HTTP-ish `code` — check it first.
        $code = (string) ($body['errorCode'] ?? '');
        if ($code === '' || ! isset(self::DIGILOCKER_ERRORS[$code])) {
            $code = (string) ($body['code'] ?? '');
        }

        $message = self::DIGILOCKER_ERRORS[$code] ?? null;

        if ($message === null) {
            $vendorMsg = $body['msg'] ?? $body['message'] ?? null;
            $message   = is_string($vendorMsg) && $vendorMsg !== ''
                ? $vendorMsg
                : 'DigiLocker verification could not be completed, please try again.';
        }

        return [
            'code'         => $code,
            'message'      => $message,
            'is_retryable' => in_array($code, self::RETRYABLE_ERRORS, true),
        ];
    }

    /** Word chars, dots, @, hyphen only. */
    private function build_digilocker_uid(int $borrower_id, string $applicant_type): string
    {
        return sprintf(
            'FDIGI%s%d%s',
            now()->format('YmdHis'),
            $borrower_id,
            strtolower(Str::random(4))
        );
    }

    /** @return array{0:string,1:string} */
    private function split_full_name(string $full_name): array
    {
        $parts = preg_split('/\s+/', trim((string) preg_replace('/\s+/', ' ', $full_name))) ?: [];
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));

        if (count($parts) <= 1) {
            return [$parts[0] ?? '', ''];
        }

        $last = array_pop($parts);
        return [implode(' ', $parts), $last];
    }

    /**
     * public/Borrower/{borrower_id}/Digilocker/{user_type}/{transactionId}_{suffix}.{ext}
     * Deterministic — cached reads re-derive without an extra column.
     */
    private function digilocker_file_path(
        int $borrower_id,
        string $applicant_type,
        string $transaction_id,
        string $suffix,
        string $ext
    ): string {
        $loan_basic_details = LoanBasicDetails::find($borrower_id);
        if($loan_basic_details) {
            $borrower_id = $loan_basic_details->borrower_id;
        }
        return sprintf(
            'public/Borrower/%s/Digilocker/%s/%s_%s.%s',
            $borrower_id,
            $applicant_type,
            $transaction_id,
            $suffix,
            strtolower($ext !== '' ? $ext : 'pdf')
        );
    }

    /** @return array<int,array{doc_type:string,path:string,url:string}> */
    private function store_digilocker_files(array $files, int $borrower_id, string $applicant_type, string $transaction_id): array
    {
        $stored = [];

        foreach ($files as $file) {
            $type = (string) ($file['docType'] ?? '');
            $link = (string) ($file['docLink'] ?? '');

            if ($link === '' || ! isset(self::STORABLE_DOCS[$type])) {
                continue;
            }

            $path = $this->digilocker_file_path(
                $borrower_id,
                $applicant_type,
                $transaction_id,
                self::STORABLE_DOCS[$type],
                (string) ($file['docExtension'] ?? 'pdf')
            );

            try {
                if (! Storage::exists($path)) {
                    $res = Http::timeout(60)->connectTimeout(10)->get($link);
                    if (! $res->successful() || $res->body() === '') {
                        Log::warning('digilocker.file_download_failed', compact('type', 'transaction_id'));
                        continue;
                    }
                    Storage::put($path, $res->body());
                }

                $stored[] = [
                    'doc_type' => $type,
                    'path'     => $path,
                    'url'      => Storage::url(Str::after($path, 'public/')),
                ];
            } catch (Exception $e) {
                Log::warning('digilocker.file_store_error', ['type' => $type, 'e' => $e->getMessage()]);
            }
        }

        return $stored;
    }

    private function build_digilocker_payload(
        array $body,
        int $borrower_id,
        string $applicant_type,
        string $transaction_id,
        bool $is_cached
    ): array {
        $m = $body['model'] ?? [];
        $a = $m['address']  ?? [];

        $documents = [];
        foreach (($m['digilockerFiles'] ?? []) as $file) {
            $type = (string) ($file['docType'] ?? '');
            if (! isset(self::STORABLE_DOCS[$type])) {
                continue;
            }
            $path = $this->digilocker_file_path(
                $borrower_id,
                $applicant_type,
                $transaction_id,
                self::STORABLE_DOCS[$type],
                (string) ($file['docExtension'] ?? 'pdf')
            );
            if (Storage::exists($path)) {
                $documents[] = [
                    'doc_type' => $type,
                    'path'     => $path,
                    'url'      => Storage::url(Str::after($path, 'public/')),
                ];
            }
        }

        return [
            'is_completed'   => true,
            'is_cached'      => $is_cached,
            'transaction_id' => $transaction_id,
            'unique_id'      => $m['uniqueId']          ?? '',
            'masked_aadhaar' => $m['maskedAdharNumber'] ?? '',
            'name'           => $m['name']              ?? '',
            'gender'         => $m['gender']            ?? '',
            'dob'            => $this->normalize_digilocker_dob($m['dob'] ?? null),
            'care_of'        => $m['careOf']            ?? '',
            'image'          => $m['image']             ?? '',
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
            ],
            'documents' => $documents,
        ];
    }

    /** Digitap returns dd-MM-yyyy. Returns Y-m-d, or '' if unparseable. */
    private function normalize_digilocker_dob(?string $dob): string
    {
        $dob = trim((string) $dob);
        if ($dob === '') {
            return '';
        }

        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d'] as $format) {
            $dt = \DateTime::createFromFormat('!' . $format, $dob);
            if ($dt !== false && \DateTime::getLastErrors() === false) {
                return $dt->format('Y-m-d');
            }
        }

        Log::warning('digilocker.dob_parse_failed', ['dob' => $dob]);
        return '';
    }

    private function latest_successful_details_record(int $borrower_id, string $applicant_type): ?VendorServiceApi
    {
        $record = VendorServiceApi::where('api_flag', self::FLAG_DETAILS)
            ->where('borrower_id', $borrower_id)
            ->where('applicant_type', $applicant_type)
            ->where('status', 'VALID')
            ->latest('date_time')
            ->first();

        if (! $record) {
            return null;
        }

        return $this->is_details_success($this->decode_vsa_response($record)) ? $record : null;
    }

    private function decode_vsa_response(VendorServiceApi $record): array
    {
        try {
            $raw = unserialize(base64_decode((string) $record->response));
            $arr = json_decode(is_string($raw) ? $raw : '', true);
            return is_array($arr) ? $arr : [];
        } catch (Exception) {
            return [];
        }
    }

    private function digilocker_log_vendor_api(
        string $flag,
        string $status,
        array $request_body,
        string $raw_response,
        array $ctx,
        string $applicant_type,
        ?string $doc_id
    ): VendorServiceApi {
        $vsa                 = new VendorServiceApi;
        $vsa->user_id        = $ctx['user_id'];
        $vsa->ip_address     = $this->get_user_ip();
        $vsa->date_time      = now()->format('Y-m-d H:i:s');
        $vsa->status         = $status;
        $vsa->api_flag       = $flag;
        $vsa->request        = base64_encode(serialize(json_encode($request_body)));
        $vsa->response       = base64_encode(serialize($raw_response));
        $vsa->borrower_id    = $ctx['borrower_id'];
        $vsa->applicant_type = $applicant_type;

        if (! empty($doc_id)) {
            $vsa->doc_id = $doc_id;
        }
        if (! empty($ctx['actor_field'])) {
            $vsa->{$ctx['actor_field']} = $ctx['actor_id'];
        }

        $vsa->save();

        return $vsa;
    }

        /**
     * Stub is only ever active outside production, regardless of the constant.
     * Belt and braces: a stale `true` in a prod deploy must not bypass KYC.
     */
    private function digilocker_stub_enabled(): bool
    {
        return self::DIGILOCKER_UAT_STUB && ! app()->environment('production');
    }

    /** Deterministic per borrower+type so repeat calls return the same transaction. */
    private function digilocker_stub_transaction_id(int $borrower_id, string $applicant_type): string
    {
        return sprintf(
            '%s%d%s',
            self::STUB_TRANSACTION_PREFIX,
            $borrower_id,
            strtoupper(substr($applicant_type, 0, 3))
        );
    }

    /**
     * Vendor-shaped success body. Mirrors the real get-digilocker-details
     * response so downstream decoding, syncing and gating all behave normally.
     */
    private function digilocker_stub_body(int $borrower_id, string $applicant_type, string $name, string $mobile): array
    {
        return [
            'code'  => '200',
            'model' => [
                'status'            => 's',
                'uniqueId'          => $this->digilocker_stub_transaction_id($borrower_id, $applicant_type),
                'maskedAdharNumber' => 'xxxxxxxx' . substr(str_pad((string) preg_replace('/\D/', '', $mobile), 4, '0'), -4),
                'name'              => $name !== '' ? strtoupper($name) : 'UAT TEST USER',
                'gender'            => 'M',
                'dob'               => '01-01-1995',
                'careOf'            => '',
                'address'           => [
                    'house'    => '',
                    'street'   => 'UAT STREET',
                    'landmark' => '',
                    'loc'      => '',
                    'po'       => 'Siliguri',
                    'dist'     => 'Darjeeling',
                    'subdist'  => '',
                    'vtc'      => 'Siliguri',
                    'pc'       => '734001',
                    'state'    => 'West Bengal',
                    'country'  => 'India',
                ],
                'image'           => '',
                'xmlResponse'     => '',
                'digilockerFiles' => [],
            ],
        ];
    }

    /**
     * Persist a stubbed FLAG_DETAILS row if one isn't already there, so that
     * require_digilocker_verified() and sync_digilocker_to_kyc() see a
     * verification exactly as they would after a real vendor call.
     */
    private function digilocker_stub_persist(array $ctx, string $applicant_type, string $name, string $mobile): array
    {
        $borrower_id    = (int) $ctx['borrower_id'];
        $transaction_id = $this->digilocker_stub_transaction_id($borrower_id, $applicant_type);
        $body           = $this->digilocker_stub_body($borrower_id, $applicant_type, $name, $mobile);

        $exists = VendorServiceApi::where('api_flag', self::FLAG_DETAILS)
            ->where('doc_id', $transaction_id)
            ->where('borrower_id', $borrower_id)
            ->where('applicant_type', $applicant_type)
            ->where('status', 'VALID')
            ->exists();

        if (! $exists) {
            // A matching GENERATE row is required: identity scoping resolves
            // transaction ids through generate payloads.
            $this->digilocker_log_vendor_api(
                flag:           self::FLAG_GENERATE,
                status:         'VALID',
                request_body:   ['firstName' => $name, 'lastName' => '', 'mobile' => $mobile, 'stub' => true],
                raw_response:   json_encode([
                    'code'  => '200',
                    'model' => ['url' => '', 'transactionId' => $transaction_id, 'kycUrl' => ''],
                ]),
                ctx:            $ctx,
                applicant_type: $applicant_type,
                doc_id:         $transaction_id,
            );

            $this->digilocker_log_vendor_api(
                flag:           self::FLAG_DETAILS,
                status:         'VALID',
                request_body:   ['transactionId' => $transaction_id, 'stub' => true],
                raw_response:   json_encode($body),
                ctx:            $ctx,
                applicant_type: $applicant_type,
                doc_id:         $transaction_id,
            );

            Log::warning('digilocker.uat_stub_used', compact('borrower_id', 'applicant_type', 'transaction_id'));
        }

        return $this->build_digilocker_payload($body, $borrower_id, $applicant_type, $transaction_id, false);
    }
}