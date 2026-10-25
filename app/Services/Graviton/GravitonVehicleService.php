<?php

namespace App\Services\Graviton;

use App\Models\BorrowerKyc;
use App\Models\Documents;
use App\Models\GravitonApplicationLog;
use App\Models\LoanBasicDetails;
use App\Models\Manufacturers;
use App\Models\OemDynamicProduct;
use Illuminate\Support\Facades\Log;

class GravitonVehicleService
{
    protected ?BorrowerKyc $borrower = null;
    protected ?Documents $documents = null;
    protected ?LoanBasicDetails $loanDetails = null;
    protected ?array $manufacturerCache = null;

    public function __construct(
        protected GravitonAuthService $auth
    ) {}

    // ══════════════════════════════════════════════════════════════
    //  MAIN ENTRY
    // ══════════════════════════════════════════════════════════════
    public function submit(BorrowerKyc $borrower): array
    {
        $this->borrower = $borrower;

        $log = GravitonApplicationLog::create([
            'borrower_id'  => $borrower->id,
            'step'         => 'vehicle_asset_create',
            'entity_type'  => 'vehicle',
            'endpoint'     => '/vehicleAsset/loan/{loanId}',
            'status'       => 'pending',
            'requested_at' => now(),
        ]);

        try {
            if (empty($borrower->graviton_loan_id)) {
                throw new \Exception("No graviton_loan_id for borrower_kyc.id={$borrower->id}");
            }
            $loanId = (int) $borrower->graviton_loan_id;

            $this->documents = Documents::where('borrower_id', $borrower->borrower_id)->first();
            if (!$this->documents) {
                throw new \Exception("No documents for borrower_id={$borrower->borrower_id}");
            }

            $this->loanDetails = LoanBasicDetails::find($borrower->borrower_id);

            // 1. Resolve master IDs
            $masterIds = $this->resolveVehicleMasterIds($log);

            // 2. Create vehicle
            $vehicleAssetId = $this->createVehicle($loanId, $masterIds, $log);
            $log->update(['resource_id' => $vehicleAssetId]);

            // 3. Upload docs
            $this->uploadVehicleDocuments($vehicleAssetId);

            $log->update(['status' => 'success', 'completed_at' => now()]);

            return [
                'success'        => true,
                'vehicleAssetId' => $vehicleAssetId,
                'loanId'         => $loanId,
                'type'           => 'vehicle',
            ];

        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            throw $e;
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  1. RESOLVE MASTER IDs
    // ══════════════════════════════════════════════════════════════
    protected function resolveVehicleMasterIds(GravitonApplicationLog $log): array
    {
        $template       = $this->fetchTemplate($log);
        $manufacturerId = $this->resolveManufacturerId($template, $log);
        $modelId        = $this->resolveModelId($manufacturerId, $log);
        $variantId      = $this->resolveVariantId($modelId, $log);
        $modelYear      = $this->resolveModelYear($variantId, $log);

        return [
            'vehicleAgeId'          => $this->pickEnumId($template['vehicleAge'] ?? [], 'NEW', 1),
            'vehicleTypeId'         => $this->pickEnumId($template['vehicleType'] ?? [], 'NON-COMMERCIAL', 1),
            'vehicleClassId'        => $this->pickVehicleClassId($template['vehicleClass'] ?? []),
            'fuelTypeId'            => $this->pickLookupId($template['fuelType'] ?? [], 'Electric', 724),
            'bodyTypeId'            => $this->pickLookupId($template['bodyType'] ?? [], 'Passenger', 730),
            'assetUseId'            => $this->pickLookupId($template['assetUse'] ?? [], 'PASSENGER USE', 748),
            'vehicleManufacturerId' => $manufacturerId,
            'vehicleModelId'        => $modelId,
            'vehicleVariantId'      => $variantId,
            'vehicleMakeYear'       => $modelYear,
            'sittingCapacity'       => 3,
        ];
    }

    protected function fetchTemplate(GravitonApplicationLog $log): array
    {
        try {
            $response = $this->auth->client()->get('/vehicleAsset/template');
            $data = $response->json();

            $log->update([
                'request_payload'  => ['endpoint' => '/vehicleAsset/template'],
                'response_payload' => $data,
                'http_status'      => $response->status(),
            ]);

            return $data ?? [];
        } catch (\Throwable $e) {
            Log::warning('Vehicle template fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    protected function resolveManufacturerId(array $template, GravitonApplicationLog $log): int
    {
        $localName     = $this->getLocalManufacturerName();
        $manufacturers = $this->fetchManufacturers($log);

        if ($localName) {
            foreach ($manufacturers as $m) {
                if (strcasecmp(trim($m['name'] ?? ''), trim($localName)) === 0) {
                    return (int) $m['id'];
                }
            }
        }

        foreach ($manufacturers as $m) {
            if (strcasecmp(trim($m['name'] ?? ''), 'OTHERS') === 0) {
                return (int) $m['id'];
            }
        }

        return 1328;
    }

    protected function fetchManufacturers(GravitonApplicationLog $log): array
    {
        if ($this->manufacturerCache !== null) {
            return $this->manufacturerCache;
        }

        try {
            $response = $this->auth->client()->get('/vehicleAssetGrid/manufacturer');
            $data = $response->json() ?? [];

            $log->update([
                'request_payload'  => ['endpoint' => '/vehicleAssetGrid/manufacturer'],
                'response_payload' => $data,
                'http_status'      => $response->status(),
            ]);

            return $this->manufacturerCache = $data;
        } catch (\Throwable $e) {
            Log::warning('Manufacturer fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    protected function getLocalManufacturerName(): ?string
    {
        if (!$this->loanDetails) return null;

        try {
            $oem = OemDynamicProduct::whereRaw('TRIM(oem_product_unq) = ?', [trim($this->loanDetails->model_id)])
                ->first();

            if ($oem && $oem->oem_id) {
                return Manufacturers::find($oem->oem_id)?->manufacturer;
            }
        } catch (\Throwable $e) {
            Log::warning('Local mfr lookup failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    protected function resolveModelId(int $manufacturerId, GravitonApplicationLog $log): int
    {
        $response = $this->auth->client()->get("/vehicleAssetGrid/model/{$manufacturerId}");
        $models = $response->json() ?? [];

        $log->update([
            'request_payload'  => ['endpoint' => "/vehicleAssetGrid/model/{$manufacturerId}"],
            'response_payload' => $models,
            'http_status'      => $response->status(),
        ]);

        $localName = $this->loanDetails?->model;
        if ($localName) {
            foreach ($models as $m) {
                if (strcasecmp(trim($m['name'] ?? ''), trim($localName)) === 0) {
                    return (int) $m['id'];
                }
            }
        }

        if (!empty($models[0]['id'])) {
            return (int) $models[0]['id'];
        }

        throw new \Exception("No models for manufacturer {$manufacturerId}");
    }

    protected function resolveVariantId(int $modelId, GravitonApplicationLog $log): int
    {
        $response = $this->auth->client()->get("/vehicleAssetGrid/variant/{$modelId}");
        $variants = $response->json() ?? [];

        $log->update([
            'request_payload'  => ['endpoint' => "/vehicleAssetGrid/variant/{$modelId}"],
            'response_payload' => $variants,
            'http_status'      => $response->status(),
        ]);

        if (!empty($variants[0]['id'])) {
            return (int) $variants[0]['id'];
        }

        throw new \Exception("No variants for model {$modelId}");
    }

    protected function resolveModelYear(int $variantId, GravitonApplicationLog $log): int
    {
        $response = $this->auth->client()->get("/vehicleAssetGrid/modelYear/{$variantId}");
        $data = $response->json() ?? [];

        $log->update([
            'request_payload'  => ['endpoint' => "/vehicleAssetGrid/modelYear/{$variantId}"],
            'response_payload' => $data,
            'http_status'      => $response->status(),
        ]);

        $years = $data['vehicleModelYearDataList'] ?? $data ?? [];

        $latest = 0;
        foreach ($years as $y) {
            $val = (int) ($y['modelYear'] ?? 0);
            if ($val > $latest) $latest = $val;
        }

        return $latest > 0 ? $latest : (int) date('Y');
    }

    protected function pickEnumId(array $items, string $value, int $fallback): int
    {
        foreach ($items as $item) {
            if (strcasecmp(trim($item['value'] ?? ''), $value) === 0) {
                return (int) $item['id'];
            }
        }
        return $fallback;
    }

    protected function pickLookupId(array $items, string $name, int $fallback): int
    {
        foreach ($items as $item) {
            if (strcasecmp(trim($item['name'] ?? ''), $name) === 0) {
                return (int) $item['id'];
            }
        }
        return $fallback;
    }

    protected function pickVehicleClassId(array $classes): int
    {
        foreach ($classes as $c) {
            if (stripos($c['value'] ?? '', 'E-Rickshaw') !== false) {
                return (int) $c['id'];
            }
        }
        return 12;
    }

    // ══════════════════════════════════════════════════════════════
    //  2. CREATE VEHICLE
    // ══════════════════════════════════════════════════════════════
    protected function createVehicle(int $loanId, array $masterIds, GravitonApplicationLog $log): int
    {
        $payload = array_merge([
            'collateralId' => 'COLL-' . $this->borrower->borrower_id,
            'dateFormat'   => 'dd-MM-yyyy',
            'locale'       => 'en',
        ], $masterIds);

        $log->update([
            'request_payload' => $payload,
            'endpoint'        => "/vehicleAsset/loan/{$loanId}",
        ]);

        $response = $this->auth->client()->post("/vehicleAsset/loan/{$loanId}", $payload);
        $data = $response->json();

        $log->update([
            'response_payload' => $data,
            'http_status'      => $response->status(),
        ]);

        if (!$response->successful()) {
            throw new \Exception('HTTP ' . $response->status() . ': ' . $response->body());
        }

        $vehicleAssetId = $data['resourceId'] ?? null;
        if (!$vehicleAssetId) {
            throw new \Exception('Vehicle create response missing resourceId');
        }

        return (int) $vehicleAssetId;
    }

    // ══════════════════════════════════════════════════════════════
    //  3. UPLOAD VEHICLE DOCUMENTS
    // ══════════════════════════════════════════════════════════════
    protected function uploadVehicleDocuments(int $vehicleAssetId): void
    {
        if (!$this->documents) return;

        $uploadService = app(GravitonDocumentUploadService::class);

        $map = [
            'vehicle_image'            => 'Vehicle Image',
            'battery_image'            => 'Battery Image',
            'motor_controller'         => 'Motor Controller Image',
            'chasis_number'            => 'Chassis Number Image',
            'vehicle_key_img'          => 'Vehicle Key Image',
            'battery_guaranty'         => 'Battery Guaranty',
            'warrant_document'         => 'Warranty Document',
            'vehicle_other_docs'       => 'Vehicle Other Documents',
            'invoice'                  => 'Vehicle Invoice',
            'performa'                 => 'Performa Invoice',
            'insurance'                => 'Insurance Policy',
            'mmr'                      => 'Margin Money Receipt',
            'down_payment_receipt'     => 'Down Payment Receipt',
            'vehicle_quotation'        => 'Vehicle Quotation',
            'rto_document'             => 'RTO Document',
            'registration_certificate' => 'Registration Certificate',
            'urc_certificate'          => 'URC Certificate',
            'noc_document'             => 'NOC Document',
            'rto_tax_receipt'          => 'RTO Tax Receipt',
        ];

        foreach ($map as $column => $label) {
            $filePath = $this->documents->{$column} ?? null;
            if (empty($filePath)) continue;

            $this->uploadOneDocument($uploadService, $vehicleAssetId, $filePath, $label);
        }
    }

    protected function uploadOneDocument(
        GravitonDocumentUploadService $uploadService,
        int $entityId,
        string $filePath,
        string $label
    ): void {
        $fullUrl = $this->resolveFullPath($filePath);
        if (!$fullUrl) return;

        $ext  = pathinfo(parse_url($fullUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
        $name = "{$label}.{$ext}";

        try {
            $uploadService->uploadDocumentFromUrl(
                entityType:  'VEHICLEASSET',
                entityId:    $entityId,
                fileUrl:     $fullUrl,
                name:        $name,
                description: $label,
                borrowerId:  $this->borrower->id
            );

            Log::info('Vehicle document uploaded', [
                'entityId' => $entityId,
                'label'    => $label,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Vehicle document upload failed', [
                'label' => $label,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  UTIL
    // ══════════════════════════════════════════════════════════════
    protected function resolveFullPath(string $path): ?string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) return $path;

        $cleanPath = preg_replace('#^storage/#', '', ltrim($path, '/'));

        $baseUrl = config('graviton.file_base_url', 'https://insta.finayo.tech');
        $appKey  = config('app.key');

        if (empty($appKey)) {
            Log::warning('APP_KEY not set');
            return null;
        }

        $expires   = time() + 3600;
        $query     = http_build_query(['expires' => $expires, 'path' => $cleanPath]);
        $fullUrl   = rtrim($baseUrl, '/') . '/api/files/stream?' . $query;
        $signature = hash_hmac('sha256', $fullUrl, $appKey);

        return $fullUrl . '&signature=' . $signature;
    }
}