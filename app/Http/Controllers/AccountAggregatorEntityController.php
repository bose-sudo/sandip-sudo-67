<?php

namespace App\Http\Controllers;

use App\Helpers\AesEncryptionHelper;
use App\Models\AaLenderCode;
use App\Models\AaEntityApiLog;
use App\Models\AaEntityRequestDetails;
use App\Models\AaWebhookResponse;
use App\Models\Dealer;
use App\Models\Lenders;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Account Aggregator flow for non-borrower entities (Dealer, OEM, LSP, …).
 *
 * Entity type is stored as a plain string so new types can be added
 * without any schema change. Constants are provided below for consistency.
 */
class AccountAggregatorEntityController extends Controller
{
    // ── Entity type constants (add more as needed) ─────────────────
    const ENTITY_DEALER = 'dealer';
    const ENTITY_OEM    = 'oem';
    const ENTITY_LSP    = 'lsp';

    // ── Env / API settings ─────────────────────────────────────────
    protected $helper_controller;
    protected $api_env;
    protected $webhook_secret;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
        $this->api_env           = 'UAT'; // [UAT, PROD]
        $this->webhook_secret    = config('services.ignosis.prod.webhook_secret');
    }

    // ──────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Fetch AA API credentials for a given lender + env.
     * Reuses AaLenderCode exactly like the borrower controller.
     */
    private function get_aa_api_key(int $lender_id, string $env): array
    {
        return [
            'status' => true,
            'key' => '58e11f3b4b41663855c557d4e6', //$api_key,
            'url' => 'https://fiupreprod.ignosis.ai/fiu/api/pirimid/', //$api_url
        ];
        
        $lender_key = AaLenderCode::where('lender_id', $lender_id)
            ->where('env', $env)
            ->first();

        if (!$lender_key) {
            return ['status' => false];
        }

        $helper  = new AesEncryptionHelper();
        $api_key = $helper->decrypt($lender_key->key);

        return [
            'status' => true,
            'key'    => $api_key,
            'url'    => $lender_key->url,
        ];
    }

    /**
     * Resolve & validate the entity record based on type + id.
     * Returns ['status' => true, 'entity' => Model, 'tracking_prefix' => string]
     * or      ['status' => false, 'message' => string]
     */
    private function resolve_entity(string $entity_type, int $entity_id): array
    {
        switch (strtolower($entity_type)) {
            case self::ENTITY_DEALER:
                $entity = Dealer::find($entity_id);
                if (!$entity) {
                    return ['status' => false, 'message' => 'Dealer not found'];
                }
                // Use dealer_unq as the human-readable prefix in trackingId
                return [
                    'status'           => true,
                    'entity'           => $entity,
                    'tracking_prefix'  => $entity->dealer_unq,
                ];

            // Extend here for OEM / LSP when needed:
            // case self::ENTITY_OEM: ...

            default:
                return ['status' => false, 'message' => 'Unsupported entity type: ' . $entity_type];
        }
    }

    /**
     * Persist a log entry into aa_entity_api_logs.
     */
    private function log_api_call(
        int    $entity_id,
        string $entity_type,
        ?int   $lender_id,
        string $api_flag,
        mixed  $request_data,
        mixed  $response_data,
        string $status,
        int    $created_by,
        string $ip,
    ): void {
        $log                = new AaEntityApiLog();
        $log->entity_id     = $entity_id;
        $log->entity_type   = $entity_type;
        $log->lender_id     = $lender_id;
        $log->api_flag      = $api_flag;
        $log->request       = is_string($request_data) ? $request_data : json_encode($request_data);
        $log->response      = is_string($response_data) ? $response_data : json_encode($response_data);
        $log->status        = $status;
        $log->created_by    = $created_by;
        $log->created_by_ip = $ip;
        $log->date_time     = now()->format('Y-m-d H:i:s');
        $log->save();
    }

    // ──────────────────────────────────────────────────────────────
    // Public endpoints
    // ──────────────────────────────────────────────────────────────

    /**
     * GET /admin/aa/entity/fips
     *
     * Payload:
     * {
     *   "lender_id": 1
     * }
     */
    public function get_fips(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'lender_id' => ['required', 'integer', 'exists:lenders,id'],
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $lender = Lenders::find($request->lender_id);
            if (!$lender || !$lender->is_active || $lender->is_deleted) {
                return $this->helper_controller->send_response('failed', 'Lender is not active', 422);
            }

            $aa_api_data = $this->get_aa_api_key($request->lender_id, $this->api_env);
            if (!$aa_api_data['status']) {
                return $this->helper_controller->send_response('failed', 'No AA credentials found for this lender', 422);
            }

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, [
                CURLOPT_URL            => $aa_api_data['url'] . 'web/fip',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => 'GET',
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'API_KEY: ' . $aa_api_data['key'],
                ],
            ]);

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

            if ($httpCode !== 200) {
                $msg = $result['errorMsg'] ?? 'Failed to fetch FIP list';
                return $this->helper_controller->send_response('failed', $msg, $result['errorCode'] ?? 422);
            }

            // Filter out disabled FIPs
            if (isset($result['fip']) && is_array($result['fip'])) {
                $result['fip'] = array_values(
                    array_filter($result['fip'], fn($item) => $item['enabled'] !== false)
                );
            }

            if (count($result['fip'] ?? []) === 0) {
                return $this->helper_controller->send_response('failed', 'No banks are enabled for this lender', 422);
            }

            return $this->helper_controller->send_response('success', 'FIP list fetched', 200, $result);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    /**
     * POST /admin/aa/entity/initiate
     *
     * Payload:
     * {
     *   "lender_id":   1,
     *   "entity_id":   42,
     *   "entity_type": "dealer",
     *   "mobile":      "9876543210",
     *   "pan_no":      "ABCDE1234F",
     *   "fip_id":      "HDFC-FIP"
     * }
     */
    public function initiate_request(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'lender_id'   => ['required', 'integer', 'exists:lenders,id'],
                'entity_id'   => ['required', 'integer'],
                'entity_type' => ['required', 'string'],
                'mobile'      => ['required', 'digits:10'],
                'pan_no'      => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'],
                'fip_id'      => ['required', 'string'],
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $admin_user_id = $request->user()->id;
            $ip            = $this->helper_controller->get_user_ip();

            // Validate lender is active
            $lender = Lenders::find($request->lender_id);
            if (!$lender || !$lender->is_active || $lender->is_deleted) {
                return $this->helper_controller->send_response('failed', 'Lender is not active', 422);
            }

            // Resolve entity (dealer / oem / lsp …)
            $entity_resolution = $this->resolve_entity($request->entity_type, $request->entity_id);
            if (!$entity_resolution['status']) {
                return $this->helper_controller->send_response('failed', $entity_resolution['message'], 422);
            }

            $tracking_prefix = $entity_resolution['tracking_prefix'];
            $trackingId      = $tracking_prefix . '-' . date('Y-m-d-H-i-s');

            // Fetch AA credentials
            $aa_api_data = $this->get_aa_api_key($request->lender_id, $this->api_env);
            if (!$aa_api_data['status']) {
                return $this->helper_controller->send_response('failed', 'No AA credentials found for this lender', 422);
            }

            $data = [
                'phoneNumber'          => 9000000000, // $request->mobile,
                'templateTypes'        => ['UNDERWRITING', 'MONITORING'],
                'trackingId'           => $trackingId,
                'fipIds'               => [$request->fip_id],
                'secondaryIdentifiers' => [
                    ['type' => 'PAN', 'value' => 'AAAAA1111A'],
                ],
                'redirectionUrl' => 'https://finayo.tech',
            ];

            $jsonData = json_encode($data, JSON_PRETTY_PRINT);

            // Persist the request record before hitting the external API
            $aa_request                  = new AaEntityRequestDetails();
            $aa_request->entity_id       = $request->entity_id;
            $aa_request->entity_type     = strtolower($request->entity_type);
            $aa_request->lender_id       = $request->lender_id;
            $aa_request->mobile          = $request->mobile;
            $aa_request->pan_no          = $request->pan_no;
            $aa_request->fip_ids         = $request->fip_id;
            $aa_request->tracking_id     = $trackingId;
            $aa_request->aa_status       = 'PENDING';
            $aa_request->created_by      = $admin_user_id;
            $aa_request->created_by_ip   = $ip;
            $aa_request->input_data      = $data;
            $aa_request->save();

            // Call Ignosis
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, [
                CURLOPT_URL            => $aa_api_data['url'] . 'web/multi-consent/initiate/phone-number',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => $jsonData,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'API_KEY: ' . $aa_api_data['key'],
                ],
            ]);

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
            if ($httpCode === 200) {
                $status         = 'success';
                $api_status     = 'VALID';
                $responseMsg    = 'Request initiated successfully';
                $resultData     = ['redirectionUrl' => $result['redirectionUrl'] ?? ''];
            } else {
                $status      = 'failed';
                $api_status  = 'INVALID';
                $responseMsg = $result['errorMsg'] ?? 'Failed to initiate request';
            }

            $this->log_api_call(
                $request->entity_id,
                strtolower($request->entity_type),
                $request->lender_id,
                'AA_ENTITY_INITIATE_REQUEST',
                $request->all(),
                $response,
                $api_status,
                $admin_user_id,
                $ip,
            );

            return $this->helper_controller->send_response($status, $responseMsg, $httpCode, $resultData);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    /**
     * GET /admin/aa/entity/dealer-details
     *
     * Payload:
     * {
     *   "dealer_id": 42     ← dealers.id (PK)
     * }
     */
    public function get_dealer_details_for_account_aggregator(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'dealer_id' => ['required', 'integer', 'exists:dealers,id'],
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $dealer = Dealer::find($request->dealer_id);

            $data = [
                'id'         => $dealer->id,
                'dealer_id'  => $dealer->dealer_unq,   // the human-readable unique code
                'name'       => $dealer->name,
                'mobile'     => $dealer->mobile,
                'pan'        => $dealer->pan,
            ];

            return $this->helper_controller->send_response('success', 'Dealer details fetched', 200, $data);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    /**
     * GET /admin/aa/entity/download-excel
     *
     * Payload:
     * {
     *   "entity_id":   42,
     *   "entity_type": "dealer",
     *   "lender_id":   1
     * }
     */
    public function download_excel_report(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'entity_id'   => ['required', 'integer'],
                'entity_type' => ['required', 'string'],
                'lender_id'   => ['required', 'integer', 'exists:lenders,id'],
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $admin_user_id = $request->user()->id;
            $ip            = $this->helper_controller->get_user_ip();
            $entity_type   = strtolower($request->entity_type);

            $lender = Lenders::find($request->lender_id);
            if (!$lender || !$lender->is_active || $lender->is_deleted) {
                return $this->helper_controller->send_response('failed', 'Lender is not active', 422);
            }

            $entity_resolution = $this->resolve_entity($entity_type, $request->entity_id);
            if (!$entity_resolution['status']) {
                return $this->helper_controller->send_response('failed', $entity_resolution['message'], 422);
            }

            $aa_api_data = $this->get_aa_api_key($request->lender_id, $this->api_env);
            if (!$aa_api_data['status']) {
                return $this->helper_controller->send_response('failed', 'Lender key not available', 422);
            }

            $aa_request = AaEntityRequestDetails::where('entity_id', $request->entity_id)
                ->where('entity_type', $entity_type)
                ->latest()
                ->first();

            if (!$aa_request) {
                return $this->helper_controller->send_response('failed', 'Account aggregator authorization not initiated yet', 422);
            }

            if ($aa_request->aa_status !== 'COMPLETED') {
                return $this->helper_controller->send_response('failed', 'The entity did not complete Account Aggregator authorization.', 422);
            }

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, [
                CURLOPT_URL            => $aa_api_data['url'] . 'web/fetch/analytics/excel?trackingId=' . $aa_request->tracking_id . '&referenceId=' . $aa_request->reference_id,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => 'GET',
                CURLOPT_HTTPHEADER     => ['API_KEY: ' . $aa_api_data['key']],
            ]);

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }

            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($httpCode === 200) {
                $dateTime     = date('YmdHis');
                $filename     = strtoupper($entity_type) . '-AA-Report-' . $entity_resolution['tracking_prefix'] . '-' . $dateTime . '.xlsx';
                $relativePath = "AA_Entity_Report/{$entity_type}/{$request->entity_id}/{$filename}";
                $storagePath  = "public/{$relativePath}";

                $oldUmask = umask(0);
                Storage::put($storagePath, $response);
                umask($oldUmask);

                $absolutePath = storage_path("app/public/{$relativePath}");
                $directory    = dirname($absolutePath);
                if (is_dir($directory)) {
                    @chmod($directory, 0755);
                }
                if (file_exists($absolutePath)) {
                    @chmod($absolutePath, 0644);
                }

                $publicUrl = Storage::url($relativePath);

                $this->log_api_call(
                    $request->entity_id, $entity_type, $request->lender_id,
                    'AA_ENTITY_DOWNLOAD_EXCEL', $request->all(), $publicUrl,
                    'VALID', $admin_user_id, $ip,
                );

                return $this->helper_controller->send_response('success', 'Excel report stored successfully', 200, [
                    'download_link' => asset($publicUrl),
                ]);
            }

            $this->log_api_call(
                $request->entity_id, $entity_type, $request->lender_id,
                'AA_ENTITY_DOWNLOAD_EXCEL', $request->all(), $response,
                'INVALID', $admin_user_id, $ip,
            );

            return $this->helper_controller->send_response('failed', 'Failed to fetch AA Excel report', 422);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    /**
     * GET /admin/aa/entity/download-pdf
     *
     * Payload:
     * {
     *   "entity_id":   42,
     *   "entity_type": "dealer",
     *   "lender_id":   1
     * }
     */
    public function download_pdf_report(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'entity_id'   => ['required', 'integer'],
                'entity_type' => ['required', 'string'],
                'lender_id'   => ['required', 'integer', 'exists:lenders,id'],
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $admin_user_id = $request->user()->id;
            $ip            = $this->helper_controller->get_user_ip();
            $entity_type   = strtolower($request->entity_type);

            $lender = Lenders::find($request->lender_id);
            if (!$lender || !$lender->is_active || $lender->is_deleted) {
                return $this->helper_controller->send_response('failed', 'Lender is not active', 422);
            }

            $entity_resolution = $this->resolve_entity($entity_type, $request->entity_id);
            if (!$entity_resolution['status']) {
                return $this->helper_controller->send_response('failed', $entity_resolution['message'], 422);
            }

            $aa_api_data = $this->get_aa_api_key($request->lender_id, $this->api_env);
            if (!$aa_api_data['status']) {
                return $this->helper_controller->send_response('failed', 'Lender key not available', 422);
            }

            $aa_request = AaEntityRequestDetails::where('entity_id', $request->entity_id)
                ->where('entity_type', $entity_type)
                ->latest()
                ->first();

            if (!$aa_request) {
                return $this->helper_controller->send_response('failed', 'Account aggregator authorization not initiated yet', 422);
            }

            if ($aa_request->aa_status !== 'COMPLETED') {
                return $this->helper_controller->send_response('failed', 'The entity did not complete Account Aggregator authorization.', 422);
            }

            // Return cached file if it already exists
            $filename     = strtoupper($entity_type) . '-AA-Report-' . $entity_resolution['tracking_prefix'] . '.pdf';
            $relativePath = "AA_Entity_Report/{$entity_type}/{$request->entity_id}/{$filename}";
            $storagePath  = "public/{$relativePath}";

            if (Storage::exists($storagePath)) {
                $publicUrl = Storage::url($relativePath);
                return $this->helper_controller->send_response('success', 'File already exists', 200, [
                    'download_link' => asset($publicUrl),
                ]);
            }

            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, [
                CURLOPT_URL            => $aa_api_data['url'] . 'web/fetch/analytics/pdf?trackingId=' . $aa_request->tracking_id . '&referenceId=' . $aa_request->reference_id,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => 'GET',
                CURLOPT_HTTPHEADER     => ['API_KEY: ' . $aa_api_data['key']],
            ]);

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }

            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($httpCode === 200) {
                $oldUmask = umask(0);
                Storage::put($storagePath, $response);
                umask($oldUmask);

                $absolutePath = storage_path("app/public/{$relativePath}");
                $directory    = dirname($absolutePath);
                if (is_dir($directory)) {
                    @chmod($directory, 0755);
                }
                if (file_exists($absolutePath)) {
                    @chmod($absolutePath, 0644);
                }

                $publicUrl = Storage::url($relativePath);

                $this->log_api_call(
                    $request->entity_id, $entity_type, $request->lender_id,
                    'AA_ENTITY_DOWNLOAD_PDF', $request->all(), $publicUrl,
                    'VALID', $admin_user_id, $ip,
                );

                return $this->helper_controller->send_response('success', 'PDF report stored successfully', 200, [
                    'download_link' => asset($publicUrl),
                ]);
            }

            $this->log_api_call(
                $request->entity_id, $entity_type, $request->lender_id,
                'AA_ENTITY_DOWNLOAD_PDF', $request->all(), $response,
                'INVALID', $admin_user_id, $ip,
            );

            return $this->helper_controller->send_response('failed', 'Failed to fetch AA PDF report', 422);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    /**
     * POST /webhook/aa-entity   (no auth middleware — validated by ApiKeyAuth header)
     *
     * Shared webhook endpoint for entity AA callbacks from Ignosis.
     * Routes on trackingId prefix; falls through gracefully if not found here
     * (the borrower webhook handler is a separate method in AccountAggregatorController).
     */
    public function store_webhook_response(Request $request)
    {
        // Validate API Key
        $apiKey = $request->header('ApiKeyAuth');
        if (!$apiKey || $apiKey !== $this->webhook_secret) {
            Log::warning('Unauthorized AA entity webhook access attempt.', [
                'ip'           => $this->helper_controller->get_user_ip(),
                'provided_key' => $apiKey,
            ]);
            return $this->helper_controller->send_response('failed', 'Unauthorized', 401);
        }

        Log::info('AA Entity Webhook Received at ' . now());

        $validator = Validator::make($request->all(), [
            'notificationType' => 'required|string',
            'status'           => 'required|string',
            'trackingId'       => 'required|string',
            'referenceId'      => 'required|string',
            'accounts'         => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        // Always persist the raw webhook payload
        $webhook                    = new AaWebhookResponse();
        $webhook->notification_type = $request->input('notificationType');
        $webhook->status            = $request->input('status');
        $webhook->tracking_id       = $request->input('trackingId');
        $webhook->reference_id      = $request->input('referenceId');
        $webhook->response_data     = json_encode($request->all());
        $webhook->ip_address        = $this->helper_controller->get_user_ip();
        $webhook->save();

        // Try to match against entity request table first
        $entityRequest = AaEntityRequestDetails::where('tracking_id', $request->input('trackingId'))->first();

        if ($entityRequest) {
            $entityRequest->reference_id = $request->input('referenceId');
            $entityRequest->aa_status    = $request->input('status');
            $entityRequest->save();

            return $this->helper_controller->send_response('success', 'Webhook received and stored successfully', 200);
        }

        // Fall back: try the borrower table so a single Ignosis webhook URL covers both flows
        $borrowerRequest = \App\Models\AaRequestDetails::where('tracking_id', $request->input('trackingId'))->first();

        if ($borrowerRequest) {
            $borrowerRequest->reference_id = $request->input('referenceId');
            $borrowerRequest->aa_status    = $request->input('status');
            $borrowerRequest->save();
        }

        // Always return 200 to Ignosis regardless of match outcome
        return $this->helper_controller->send_response('success', 'Webhook received and stored successfully', 200);
    }
}