<?php

namespace App\Services\Graviton;

use App\Models\BorrowerKyc;
use App\Models\Documents;
use App\Models\GravitonApplicationLog;
use App\Models\GravitonReferenceData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

abstract class BaseCreditReportService
{
    protected ?BorrowerKyc $borrower = null;

    public function __construct(
        protected GravitonAuthService $auth
    ) {}

    abstract protected function getFileColumns(): array;
    abstract protected function getEntityType(): string;

    public function submit(BorrowerKyc $borrower, array $extraData = []): array
    {
        $this->borrower = $borrower;

        $log = GravitonApplicationLog::create([
            'borrower_id'  => $borrower->id,
            'step'         => "manual_credit_report_{$this->getEntityType()}",
            'entity_type'  => 'loan',
            'endpoint'     => '/creditBureauCheck/creditReportManual',
            'status'       => 'pending',
            'requested_at' => now(),
        ]);

        try {
            if (!$borrower->graviton_loan_id) {
                throw new \Exception("No graviton_loan_id for borrower_kyc.id={$borrower->id}");
            }

            $ref = GravitonReferenceData::where('borrower_id', $borrower->id)->first();
            if (!$ref) {
                throw new \Exception("No reference data for borrower_kyc.id={$borrower->id}");
            }

            $documents = Documents::where('borrower_id', $borrower->borrower_id)->first();
            if (!$documents) {
                throw new \Exception("No documents for borrower_id={$borrower->borrower_id}");
            }

            $payload = $this->buildPayload($borrower, $ref, $extraData);
            $log->update(['request_payload' => $payload]);

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

    protected function buildPayload(BorrowerKyc $borrower, GravitonReferenceData $ref, array $extraData): array
    {
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

    protected function uploadDocuments(int $resourceId, Documents $documents, ?int $borrowerId = null): void
    {
        $borrower = $this->getBorrower();
        if (!$borrower) return;

        $applicantClientId   = $borrower->graviton_client_id;
        $guarantorFamilyId   = $this->getFamilyMemberId('guarantor_push');
        $coapplicantFamilyId = $this->getFamilyMemberId('coapplicant_push');

        // ✅ CIBIL PDF upload
        $this->uploadCibilReport($resourceId, $borrower, $borrowerId);

        // ✅ KYC + other docs
        $uploadService = app(GravitonDocumentUploadService::class);

        foreach ($this->getFileColumns() as $column) {
            if (in_array($column, ['borrower_cibil_report', 'coapplicant_cibil_report', 'guarantor_cibil_report'])) {
                continue;
            }

            $value = $documents->$column ?? null;
            if (empty($value)) continue;

            $paths = array_filter(array_map('trim', explode(',', $value)));

            foreach ($paths as $path) {
                $fullUrl = $this->resolveFullPath($path);
                if (!$fullUrl) continue;

                $map = $this->resolveUploadMap($column, $applicantClientId, $guarantorFamilyId, $coapplicantFamilyId, $resourceId);
                if ($map === null) continue;

                try {
                    $uploadService->uploadDocumentFromUrl(
                        entityType:        $map['entityType'],
                        entityId:          $map['entityId'],
                        fileUrl:           $fullUrl,
                        name:              basename($path),
                        description:       "From documents.{$column}",
                        isKyc:             $map['isKyc'],
                        kycDocumentTypeId: $map['kycDocTypeId'],
                        borrowerId:        $borrowerId
                    );
                } catch (\Throwable $e) {
                    Log::warning('Document upload failed', ['column' => $column, 'error' => $e->getMessage()]);
                }
            }
        }

        // ✅ Profile images
        $this->uploadProfileImages($documents, $applicantClientId, $guarantorFamilyId, $coapplicantFamilyId);
    }

    protected function uploadCibilReport(int $resourceId, BorrowerKyc $borrower, ?int $borrowerId = null): void
    {
        $finayoId = $borrower->borrower_id;

        $this->uploadCibilPdf($resourceId, $finayoId, 'BORROWER', 'CIBIL Report — Applicant', $borrowerId);

        if ($this->getFamilyMemberId('coapplicant_push')) {
            $this->uploadCibilPdf($resourceId, $finayoId, 'COAPPLICANT', 'CIBIL Report — Co-Applicant', $borrowerId);
        }
        if ($this->getFamilyMemberId('guarantor_push')) {
            $this->uploadCibilPdf($resourceId, $finayoId, 'GUARANTOR', 'CIBIL Report — Guarantor', $borrowerId);
        }
    }

    protected function uploadCibilPdf(int $reportId, int $finayoId, string $applicantType, string $label, ?int $borrowerId = null): void
    {
        $suffix   = strtolower($applicantType);
        $fileName = "cibil-bureau-report-{$finayoId}-{$suffix}.pdf";
        $diskPath = "public/bureau_report/{$finayoId}/{$applicantType}/{$fileName}";

        if (!Storage::exists($diskPath)) {
            Log::info('CIBIL PDF not found', ['path' => $diskPath]);
            return;
        }

        $fullPath = Storage::path($diskPath);

        try {
            $uploadService = app(GravitonDocumentUploadService::class);
            $uploadService->uploadDocumentFromPath(
                entityType:  'creditReport',
                entityId:    $reportId,
                filePath:    $fullPath,
                name:        $fileName,
                description: $label,
                borrowerId:  $borrowerId
            );
        } catch (\Throwable $e) {
            Log::warning('CIBIL PDF upload failed', ['type' => $applicantType, 'error' => $e->getMessage()]);
        }
    }

    protected function resolveUploadMap(string $column, ?int $applicantClientId, ?int $guarantorFamilyId, ?int $coapplicantFamilyId, int $reportId): ?array
    {
        // Applicant KYC Front
        if (in_array($column, ['aadhar_front', 'pan_doc', 'driving_license', 'per_address_proof', 'res_address_proof'])) {
            if (!$applicantClientId) return null;
            return ['entityType' => 'client_identifiers', 'entityId' => $applicantClientId, 'isKyc' => true, 'kycDocTypeId' => $this->resolveKycDocTypeId($column)];
        }

        // Applicant KYC Back
        if ($column === 'aadhar_back') {
            if (!$applicantClientId) return null;
            return ['entityType' => 'client_identifiers_back', 'entityId' => $applicantClientId, 'isKyc' => true, 'kycDocTypeId' => $this->resolveKycDocTypeId($column)];
        }

        // Guarantor KYC Front
        if (in_array($column, ['guarantor_aadhar_front', 'guarantor_pan_doc', 'guarantor_driving_license', 'guarantor_peraddress_proof', 'guarantor_coresaddress_proof'])) {
            if (!$guarantorFamilyId) return null;
            return ['entityType' => 'client_identifiers', 'entityId' => $guarantorFamilyId, 'isKyc' => true, 'kycDocTypeId' => $this->resolveKycDocTypeId($column)];
        }

        // Guarantor KYC Back
        if ($column === 'guarantor_aadhar_back') {
            if (!$guarantorFamilyId) return null;
            return ['entityType' => 'client_identifiers_back', 'entityId' => $guarantorFamilyId, 'isKyc' => true, 'kycDocTypeId' => $this->resolveKycDocTypeId($column)];
        }

        // Co-applicant KYC Front
        if (in_array($column, ['coAadhar_front', 'coPan_doc', 'coDriving_license', 'coPerAddress_proof', 'coResAddress_proof'])) {
            if (!$coapplicantFamilyId) return null;
            return ['entityType' => 'client_identifiers', 'entityId' => $coapplicantFamilyId, 'isKyc' => true, 'kycDocTypeId' => $this->resolveKycDocTypeId($column)];
        }

        // Co-applicant KYC Back
        if ($column === 'coAadhar_back') {
            if (!$coapplicantFamilyId) return null;
            return ['entityType' => 'client_identifiers_back', 'entityId' => $coapplicantFamilyId, 'isKyc' => true, 'kycDocTypeId' => $this->resolveKycDocTypeId($column)];
        }

        // Default → creditReport
        return ['entityType' => 'creditReport', 'entityId' => $reportId, 'isKyc' => null, 'kycDocTypeId' => null];
    }

    protected function resolveKycDocTypeId(string $column): ?int
    {
        if (in_array($column, ['pan_doc', 'coPan_doc', 'guarantor_pan_doc'])) {
            return (int) config('graviton.kyc_doc_types.pan');
        }
        if (in_array($column, ['aadhar_front', 'aadhar_back', 'coAadhar_front', 'coAadhar_back', 'guarantor_aadhar_front', 'guarantor_aadhar_back'])) {
            return (int) config('graviton.kyc_doc_types.aadhaar');
        }
        if (in_array($column, ['driving_license', 'coDriving_license', 'guarantor_driving_license'])) {
            return (int) config('graviton.kyc_doc_types.dl');
        }
        return null;
    }

    protected function uploadProfileImages(Documents $documents, ?int $applicantClientId, ?int $guarantorFamilyId, ?int $coapplicantFamilyId): void
    {
        $uploadService = app(GravitonDocumentUploadService::class);

        if (!empty($documents->borrower_avtar) && $applicantClientId) {
            $fullUrl = $this->resolveFullPath($documents->borrower_avtar);
            if ($fullUrl) {
                try { $uploadService->uploadImageFromUrl('clients', $applicantClientId, $fullUrl); }
                catch (\Throwable $e) { Log::warning('Applicant image failed', ['error' => $e->getMessage()]); }
            }
        }

        if (!empty($documents->guarantor_avtar) && $guarantorFamilyId) {
            $fullUrl = $this->resolveFullPath($documents->guarantor_avtar);
            if ($fullUrl) {
                try { $uploadService->uploadImageFromUrl('familymember', $guarantorFamilyId, $fullUrl); }
                catch (\Throwable $e) { Log::warning('Guarantor image failed', ['error' => $e->getMessage()]); }
            }
        }

        if (!empty($documents->coapplicant_avtar) && $coapplicantFamilyId) {
            $fullUrl = $this->resolveFullPath($documents->coapplicant_avtar);
            if ($fullUrl) {
                try { $uploadService->uploadImageFromUrl('familymember', $coapplicantFamilyId, $fullUrl); }
                catch (\Throwable $e) { Log::warning('Co-app image failed', ['error' => $e->getMessage()]); }
            }
        }
    }

    protected function getFamilyMemberId(string $step): ?int
    {
        $borrower = $this->getBorrower();
        if (!$borrower) return null;

        return GravitonApplicationLog::where('borrower_id', $borrower->id)
            ->where('step', $step)
            ->where('status', 'success')
            ->whereNotNull('resource_id')
            ->latest()
            ->value('resource_id');
    }

    protected function resolveFullPath(string $path): ?string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) return $path;

        $cleanPath = ltrim($path, '/');
        $cleanPath = preg_replace('#^storage/#', '', $cleanPath);

        return $this->generateSignedUrl($cleanPath);
    }

    protected function generateSignedUrl(string $path): ?string
    {
        $baseUrl = config('graviton.file_base_url', 'https://insta.finayo.tech');
        $appKey  = config('app.key');

        if (empty($appKey)) {
            Log::warning('APP_KEY not set');
            return null;
        }

        $expires = time() + 3600;
        $query = http_build_query(['expires' => $expires, 'path' => $path]);
        $fullUrl = rtrim($baseUrl, '/') . '/api/files/stream?' . $query;
        $signature = hash_hmac('sha256', $fullUrl, $appKey);

        return $fullUrl . '&signature=' . $signature;
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

    protected function getBorrower(): ?BorrowerKyc
    {
        return $this->borrower;
    }
}