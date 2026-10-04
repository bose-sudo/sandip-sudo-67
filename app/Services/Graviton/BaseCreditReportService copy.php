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

            $documents = Documents::where('borrower_id', $borrower->id)->first();
            if (!$documents) {
                throw new \Exception("No documents for borrower_kyc.id={$borrower->id}");
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
            // $this->uploadDocuments($resourceId, $documents);
            $this->uploadDocuments($resourceId, $documents, $borrower->id);  // ✅ borrowerId pass

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

    protected function applyEntityFlags(array &$payload, array $extraData): void
    {
        $payload['familyMemberId'] = null;
    }

    /**
     * ✅ Upload documents — full URL diye
     */
    // protected function uploadDocuments(int $resourceId, Documents $documents): void
    // {
    //     $uploadService = app(GravitonDocumentUploadService::class);

    //     foreach ($this->getFileColumns() as $column) {
    //         $value = $documents->$column ?? null;
    //         if (empty($value)) continue;

    //         $paths = array_filter(array_map('trim', explode(',', $value)));

    //         foreach ($paths as $path) {
    //             // ✅ Full URL banao
    //             $fullUrl = $this->resolveFullPath($path);
    //             if (!$fullUrl) continue;

    //             try {
    //                 $uploadService->uploadDocumentFromUrl(
    //                     entityType: 'creditReport',
    //                     entityId:   $resourceId,
    //                     fileUrl:    $fullUrl,
    //                     name:       basename($path),
    //                     description: "From documents.{$column}",
    //                 );
    //             } catch (\Throwable $e) {
    //                 Log::warning('Document upload failed', [
    //                     'column' => $column,
    //                     'url'    => $fullUrl,
    //                     'error'  => $e->getMessage(),
    //                 ]);
    //             }
    //         }
    //     }
    // }

    protected function uploadDocuments(int $resourceId, Documents $documents, ?int $borrowerId = null): void
    {
        $uploadService = app(GravitonDocumentUploadService::class);

        foreach ($this->getFileColumns() as $column) {
            $value = $documents->$column ?? null;
            if (empty($value)) continue;

            $paths = array_filter(array_map('trim', explode(',', $value)));

            foreach ($paths as $path) {
                $fullUrl = $this->resolveFullPath($path);
                if (!$fullUrl) continue;

                try {
                    $uploadService->uploadDocumentFromUrl(
                        entityType: 'creditReport',
                        entityId:   $resourceId,
                        fileUrl:    $fullUrl,
                        name:       basename($path),
                        description: "From documents.{$column}",
                        borrowerId: $borrowerId   // ✅ NEW
                    );
                } catch (\Throwable $e) {
                    Log::warning('Document upload failed', [
                        'column' => $column,
                        'url'    => $fullUrl,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * ✅ Relative path → Full URL
     * ⚠️ Storage::path() use korchi na
     */
    protected function resolveFullPath(string $path): ?string
    {
        // Already full URL
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // ✅ Config theke base URL (fallback: request root)
        $baseUrl = config('graviton.base_url') ?? request()->root();
        $cleanPath = ltrim($path, '/');

        return rtrim($baseUrl, '/') . '/' . $cleanPath;
    }

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