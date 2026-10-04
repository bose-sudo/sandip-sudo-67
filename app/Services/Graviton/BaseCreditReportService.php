<?php

namespace App\Services\Graviton;

use App\Models\BorrowerKyc;
use App\Models\Documents;
use App\Models\GravitonApplicationLog;
use App\Models\GravitonReferenceData;
use Illuminate\Support\Facades\Log;

abstract class BaseCreditReportService
{
    public function __construct(
        protected GravitonAuthService $auth
    ) {}

    abstract protected function getFileColumns(): array;
    abstract protected function getEntityType(): string;

    /**
     * ✅ Main submit method
     */
    // public function submit(BorrowerKyc $borrower, array $extraData = []): array
    // {
    //     $log = GravitonApplicationLog::create([
    //         'borrower_id'  => $borrower->id,
    //         'step'         => "manual_credit_report_{$this->getEntityType()}",
    //         'entity_type'  => 'loan',
    //         'endpoint'     => '/creditBureauCheck/creditReportManual',
    //         'status'       => 'pending',
    //         'requested_at' => now(),
    //     ]);

    //     try {
    //         // ───────────────────────────────────────
    //         // Validations
    //         // ───────────────────────────────────────
    //         if (!$borrower->graviton_loan_id) {
    //             throw new \Exception("No graviton_loan_id for borrower_kyc.id={$borrower->id}");
    //         }

    //         $ref = GravitonReferenceData::where('borrower_id', $borrower->id)->first();
    //         if (!$ref) {
    //             throw new \Exception("No reference data for borrower_kyc.id={$borrower->id}");
    //         }

    //         $documents = Documents::where('borrower_id', $borrower->id)->first();
    //         if (!$documents) {
    //             throw new \Exception("No documents for borrower_kyc.id={$borrower->id}");
    //         }

    //         // ───────────────────────────────────────
    //         // Build payload
    //         // ───────────────────────────────────────
    //         $payload = $this->buildPayload($borrower, $ref, $extraData);
    //         $log->update(['request_payload' => $payload]);

    //         // ───────────────────────────────────────
    //         // API call — Credit Report create
    //         // ───────────────────────────────────────
    //         $response = $this->auth->client()->post(
    //             '/creditBureauCheck/creditReportManual',
    //             $payload
    //         );
    //         $data = $response->json();

    //         $log->update([
    //             'response_payload' => $data,
    //             'http_status'      => $response->status(),
    //         ]);

    //         if (!$response->successful()) {
    //             throw new \Exception('HTTP ' . $response->status() . ': ' . $response->body());
    //         }

    //         $resourceId = $data['resourceId'] ?? null;
    //         if (!$resourceId) {
    //             throw new \Exception('Empty response — no resourceId');
    //         }

    //         // ───────────────────────────────────────
    //         // Save resourceId
    //         // ───────────────────────────────────────
    //         $borrower->graviton_credit_report_resource_id = $resourceId;
    //         $borrower->graviton_credit_report_status      = 'submitted';
    //         $borrower->graviton_credit_report_synced_at   = now();
    //         $borrower->graviton_credit_report_error       = null;
    //         $borrower->save();

    //         $log->update([
    //             'status'       => 'success',
    //             'resource_id'  => $resourceId,
    //             'completed_at' => now(),
    //         ]);

    //         // ───────────────────────────────────────
    //         // Document upload
    //         // ───────────────────────────────────────
    //         $this->uploadDocuments($resourceId, $documents, $borrower->id);

    //         return [
    //             'success'    => true,
    //             'resourceId' => $resourceId,
    //             'commandId'  => $data['commandId'] ?? null,
    //             'type'       => $this->getEntityType(),
    //         ];

    //     } catch (\Throwable $e) {
    //         $borrower->graviton_credit_report_status = 'failed';
    //         $borrower->graviton_credit_report_error  = $e->getMessage();
    //         $borrower->save();

    //         $log->update([
    //             'status'        => 'failed',
    //             'error_message' => $e->getMessage(),
    //             'completed_at'  => now(),
    //         ]);

    //         throw $e;
    //     }
    // }

    public function submit(BorrowerKyc $borrower, array $extraData = []): array
    {
        $log = GravitonApplicationLog::create([
            'borrower_id'  => $borrower->id,
            'step'         => "manual_credit_report_{$this->getEntityType()}",
            'entity_type'  => 'loan',
            'endpoint'     => '/creditBureauCheck/creditReportManual',
            'status'       => 'pending',
            'requested_at' => now(),
        ]);

        try {
            // ───────────────────────────────────────
            // Validations
            // ───────────────────────────────────────
            if (!$borrower->graviton_loan_id) {
                throw new \Exception("No graviton_loan_id for borrower_kyc.id={$borrower->id}");
            }

            $ref = GravitonReferenceData::where('borrower_id', $borrower->id)->first();
            if (!$ref) {
                throw new \Exception("No reference data for borrower_kyc.id={$borrower->id}");
            }

            // ✅ FIX — borrower_kycs.borrower_id use koro
            $documents = Documents::where('borrower_id', $borrower->borrower_id)->first();
            if (!$documents) {
                throw new \Exception("No documents for borrower_id={$borrower->borrower_id}");
            }

            // ───────────────────────────────────────
            // Build payload
            // ───────────────────────────────────────
            $payload = $this->buildPayload($borrower, $ref, $extraData);
            $log->update(['request_payload' => $payload]);

            // ───────────────────────────────────────
            // API call
            // ───────────────────────────────────────
            $response = $this->auth->client()->post(
                '/creditBureauCheck/creditReportManual',
                $payload
            );
            $data = $response->json();

            $log->update([
                'response_payload' => $data,
                'http_status'      => $response->status(),
            ]);

            if (!$response->successful()) {
                throw new \Exception('HTTP ' . $response->status() . ': ' . $response->body());
            }

            $resourceId = $data['resourceId'] ?? null;
            if (!$resourceId) {
                throw new \Exception('Empty response — no resourceId');
            }

            // ───────────────────────────────────────
            // Save resourceId
            // ───────────────────────────────────────
            $borrower->graviton_credit_report_resource_id = $resourceId;
            $borrower->graviton_credit_report_status      = 'submitted';
            $borrower->graviton_credit_report_synced_at   = now();
            $borrower->graviton_credit_report_error       = null;
            $borrower->save();

            $log->update([
                'status'       => 'success',
                'resource_id'  => $resourceId,
                'completed_at' => now(),
            ]);

            // ───────────────────────────────────────
            // Document upload
            // ───────────────────────────────────────
            $this->uploadDocuments($resourceId, $documents, $borrower->id);

            return [
                'success'    => true,
                'resourceId' => $resourceId,
                'commandId'  => $data['commandId'] ?? null,
                'type'       => $this->getEntityType(),
            ];

        } catch (\Throwable $e) {
            $borrower->graviton_credit_report_status = 'failed';
            $borrower->graviton_credit_report_error  = $e->getMessage();
            $borrower->save();

            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);

            throw $e;
        }
    }

    /**
     * ✅ Build payload — sudhu mandatory fields
     */
    protected function buildPayload(
        BorrowerKyc $borrower,
        GravitonReferenceData $ref,
        array $extraData
    ): array {
        $bureauTemplate = $ref->credit_bureau_template_data ?? [];
        $loanTemplate   = $ref->loan_template_data ?? [];
        $product        = $loanTemplate['product'] ?? [];

        $creditBureauTypeId = $product['creditBureauTypeId'] ?? 1;
        $creditReportTypeId = $product['creditReportTypeId'] ?? 6;
        $statusId           = $bureauTemplate['reportStatus'][0]['id'] ?? 421;
        $decisionId         = $this->resolvePositiveDecisionId($bureauTemplate);

        $payload = [
            'name'               => $extraData['name'] ?? $borrower->name,
            'creditBureauTypeId' => (int) $creditBureauTypeId,
            'creditReportTypeId' => (int) $creditReportTypeId,
            'statusId'           => (int) $statusId,
            'decisionId'         => (int) $decisionId,
            'loanId'             => (int) $borrower->graviton_loan_id,
            'locale'             => 'en',
        ];

        $this->applyEntityFlags($payload, $extraData);

        $payload['file'] = (object) [];

        return $payload;
    }

    /**
     * ✅ Entity-specific flags — child override kore
     */
    protected function applyEntityFlags(array &$payload, array $extraData): void
    {
        $payload['familyMemberId'] = null;
    }

    /**
     * ✅ Upload documents — signed URL diye
     */
    protected function uploadDocuments(int $resourceId, Documents $documents, ?int $borrowerId = null): void
    {
        $uploadService = app(GravitonDocumentUploadService::class);

        foreach ($this->getFileColumns() as $column) {
            $value = $documents->$column ?? null;
            if (empty($value)) continue;

            $paths = array_filter(array_map('trim', explode(',', $value)));

            foreach ($paths as $path) {
                // ✅ Signed URL generate koro
                $signedUrl = $this->resolveFullPath($path);
                if (!$signedUrl) {
                    Log::warning('Skipping file — no signed URL', [
                        'column' => $column,
                        'path'   => $path,
                    ]);
                    continue;
                }

                try {
                    $uploadService->uploadDocumentFromUrl(
                        entityType: 'creditReport',
                        entityId:   $resourceId,
                        fileUrl:    $signedUrl,
                        name:       basename($path),
                        description: "From documents.{$column}",
                        borrowerId: $borrowerId
                    );
                } catch (\Throwable $e) {
                    Log::warning('Document upload failed', [
                        'column' => $column,
                        'url'    => $signedUrl,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * ✅ Relative path → Signed URL
     *
     * Input:  /storage/Borrower/FIN-0586/BORROWER_KYC/AADHAR_FRONT.jpg
     * Output: http://13.205.48.55/api/files/stream?expires=...&path=...&signature=...
     */
    protected function resolveFullPath(string $path): ?string
    {
        // Already full URL
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // ✅ Clean path — /storage/ prefix remove
        $cleanPath = ltrim($path, '/');
        $cleanPath = preg_replace('#^storage/#', '', $cleanPath);

        // ✅ Signed URL generate koro
        return $this->generateSignedUrl($cleanPath);
    }

    /**
     * ✅ Signed URL generate koro
     *
     * Formula: HMAC-SHA256(fullUrl, APP_KEY)
     * Verified: F1 match korche ✅
     */
    protected function generateSignedUrl(string $path): ?string
    {
        $baseUrl = config('graviton.file_base_url', 'http://13.205.48.55');
        $appKey  = config('app.key');

        // ⚠️ APP_KEY na thakle null
        if (empty($appKey)) {
            Log::warning('APP_KEY not set — cannot generate signed URL');
            return null;
        }

        $expires = time() + 3600;   // 1 hour validity

        // ✅ Query string build koro
        $query = http_build_query([
            'expires' => $expires,
            'path'    => $path,
        ]);

        // ✅ Full URL
        $fullUrl = rtrim($baseUrl, '/') . '/api/files/stream?' . $query;

        // ✅ Signature — HMAC-SHA256(fullUrl, APP_KEY)
        $signature = hash_hmac('sha256', $fullUrl, $appKey);

        return $fullUrl . '&signature=' . $signature;
    }

    /**
     * ✅ POSITIVE decision ID ber koro
     */
    protected function resolvePositiveDecisionId(array $bureauTemplate): int
    {
        foreach (($bureauTemplate['creditDecision'] ?? []) as $d) {
            if (strtoupper($d['name'] ?? '') === 'POSITIVE') {
                return (int) $d['id'];
            }
        }
        return 411;
    }
}