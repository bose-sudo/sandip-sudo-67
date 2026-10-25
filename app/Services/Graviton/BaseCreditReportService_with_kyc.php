<?php

namespace App\Services\Graviton;

use App\Models\BorrowerKyc;
use App\Models\Documents;
use App\Models\FamilyMember;
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

    /**
     * Upload documents — CIBIL PDF, Profile Image, and KYC (PAN / AADHAAR / ELECTRICITY BILL).
     */
    protected function uploadDocuments(int $resourceId, Documents $documents, ?int $borrowerId = null): void
    {
        $borrower = $this->getBorrower();
        if (!$borrower) return;

        $applicantClientId   = $borrower->graviton_client_id;
        $guarantorFamilyId   = $this->getFamilyMemberId('guarantor_push');
        $coapplicantFamilyId = $this->getFamilyMemberId('coapplicant_push');

        $uploadService = app(GravitonDocumentUploadService::class);

        // ═══════════════════════════════════════════════════════
        // 1️⃣ CIBIL REPORT PDF UPLOAD
        // ═══════════════════════════════════════════════════════
        $this->uploadCibilReport($resourceId, $borrower, $borrowerId);

        // ═══════════════════════════════════════════════════════
        // 2️⃣ PROFILE IMAGE UPLOAD
        // ═══════════════════════════════════════════════════════

        // Applicant Profile Image → /clients/{clientId}/images
        if (!empty($documents->borrower_avtar) && $applicantClientId) {
            $fullUrl = $this->resolveFullPath($documents->borrower_avtar);
            if ($fullUrl) {
                try {
                    $uploadService->uploadImageFromUrl('clients', $applicantClientId, $fullUrl);
                    Log::info('Applicant profile image uploaded', ['clientId' => $applicantClientId]);
                } catch (\Throwable $e) {
                    Log::warning('Applicant image upload failed', ['error' => $e->getMessage()]);
                }
            }
        }

        // Guarantor Profile Image → /familymember/{familyMemberId}/images
        if (!empty($documents->guarantor_avtar) && $guarantorFamilyId) {
            $fullUrl = $this->resolveFullPath($documents->guarantor_avtar);
            if ($fullUrl) {
                try {
                    $uploadService->uploadImageFromUrl('familymember', $guarantorFamilyId, $fullUrl);
                    Log::info('Guarantor profile image uploaded', ['familyMemberId' => $guarantorFamilyId]);
                } catch (\Throwable $e) {
                    Log::warning('Guarantor image upload failed', ['error' => $e->getMessage()]);
                }
            }
        }

        // Co-applicant Profile Image → /familymember/{familyMemberId}/images
        if (!empty($documents->coapplicant_avtar) && $coapplicantFamilyId) {
            $fullUrl = $this->resolveFullPath($documents->coapplicant_avtar);
            if ($fullUrl) {
                try {
                    $uploadService->uploadImageFromUrl('familymember', $coapplicantFamilyId, $fullUrl);
                    Log::info('Co-applicant profile image uploaded', ['familyMemberId' => $coapplicantFamilyId]);
                } catch (\Throwable $e) {
                    Log::warning('Co-applicant image upload failed', ['error' => $e->getMessage()]);
                }
            }
        }

        // ═══════════════════════════════════════════════════════
        // 3️⃣ KYC UPLOAD — PAN, AADHAAR, ELECTRICITY BILL
        // ═══════════════════════════════════════════════════════

        // Borrower KYC
        $this->uploadKycForEntity(
            $uploadService,
            $documents,
            $applicantClientId,
            'borrower',
            $borrowerId
        );

        // Co-applicant KYC
        $coapplicantClientId = $this->getFamilyMemberClientId('coapplicant_push');
        $this->uploadKycForEntity(
            $uploadService,
            $documents,
            $coapplicantClientId,
            'coapplicant',
            $borrowerId
        );

        // Guarantor KYC
        $guarantorClientId = $this->getFamilyMemberClientId('guarantor_push');
        $this->uploadKycForEntity(
            $uploadService,
            $documents,
            $guarantorClientId,
            'guarantor',
            $borrowerId
        );
    }

    // ═══════════════════════════════════════════════════════════
    // ✅ NEW — KYC METHODS (PAN / AADHAAR / ELECTRICITY BILL)
    // ═══════════════════════════════════════════════════════════

    /**
     * Family member theke graviton client_id ber koro.
     */
    protected function getFamilyMemberClientId(string $step): ?int
    {
        $familyId = $this->getFamilyMemberId($step);
        if (!$familyId) return null;

        $member = BorrowerKyc::find($familyId);

        return $member && !empty($member->graviton_client_id)
            ? (int) $member->graviton_client_id
            : null;
    }

    /**
     * KYC records fetch kore documentTypeId diye map banao.
     *
     * @return array<int, array> [documentTypeId => kycRecord]
     */
    protected function getKycMapByClientId(?int $clientId): array
    {
        if (!$clientId) return [];

        try {
            $response = $this->auth->client()->get("/kyc/client/{$clientId}");

            if (!$response->successful()) {
                Log::warning('KYC fetch failed', [
                    'clientId' => $clientId,
                    'status'   => $response->status(),
                ]);
                return [];
            }

            $map = [];
            foreach ($response->json() ?? [] as $kyc) {
                if (empty($kyc['isActive'])) continue;

                $docTypeId = (int) ($kyc['documentTypeId'] ?? 0);
                if ($docTypeId === 0) continue;

                $map[$docTypeId] = [
                    'kycId'          => (int) $kyc['kycId'],
                    'documentTypeId' => $docTypeId,
                    'documentName'   => $kyc['documentName'] ?? null,
                    'isFrontSide'    => (bool) ($kyc['isFrontSide'] ?? false),
                    'isBackSide'     => (bool) ($kyc['isBackSide'] ?? false),
                ];
            }

            return $map;

        } catch (\Throwable $e) {
            Log::error('KYC fetch exception', [
                'clientId' => $clientId,
                'error'    => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Documents column map — only PAN (2), AADHAAR (1), ELECTRICITY BILL (11).
     */
    protected function getKycColumnMap(string $entity): array
    {
        $maps = [
            'borrower' => [
                1  => ['front' => 'aadhar_front',      'back' => 'aadhar_back'],
                2  => ['front' => 'pan_doc',           'back' => null],
                11 => ['front' => 'per_address_proof', 'back' => null], // ⚠️ confirm per vs res
            ],
            'coapplicant' => [
                1  => ['front' => 'coAadhar_front',     'back' => 'coAadhar_back'],
                2  => ['front' => 'coPan_doc',          'back' => null],
                11 => ['front' => 'coPerAddress_proof', 'back' => null], // ⚠️ confirm
            ],
            'guarantor' => [
                1  => ['front' => 'guarantor_aadhar_front',     'back' => 'guarantor_aadhar_back'],
                2  => ['front' => 'guarantor_pan_doc',          'back' => null],
                11 => ['front' => 'guarantor_peraddress_proof', 'back' => null], // ⚠️ confirm
            ],
        ];

        return $maps[$entity] ?? [];
    }

    /**
     * Ek entity er KYC upload handle koro.
     */
    protected function uploadKycForEntity(
        GravitonDocumentUploadService $uploadService,
        Documents $documents,
        ?int $clientId,
        string $entity,
        ?int $borrowerId
    ): void {
        if (!$clientId) return;

        $kycMap    = $this->getKycMapByClientId($clientId);
        $columnMap = $this->getKycColumnMap($entity);

        if (empty($kycMap)) {
            Log::info('No KYC records found', ['entity' => $entity, 'clientId' => $clientId]);
            return;
        }

        foreach ($columnMap as $docTypeId => $columns) {
            if (empty($kycMap[$docTypeId])) continue;

            $kyc = $kycMap[$docTypeId];

            // ── FRONT ──────────────────────────────────────
            if (!empty($columns['front'])) {
                $filePath = $documents->{$columns['front']} ?? null;

                if (!empty($filePath) && $kyc['isFrontSide']) {
                    $this->uploadKycFile(
                        $uploadService, $filePath, $kyc,
                        'client_identifiers', 'FRONT', $borrowerId
                    );
                }
            }

            // ── BACK ───────────────────────────────────────
            if (!empty($columns['back'])) {
                $filePath = $documents->{$columns['back']} ?? null;

                if (!empty($filePath) && $kyc['isBackSide']) {
                    $this->uploadKycFile(
                        $uploadService, $filePath, $kyc,
                        'client_identifiers_back', 'BACK', $borrowerId
                    );
                }
            }
        }
    }

    /**
     * Single KYC file upload wrapper.
     */
    protected function uploadKycFile(
        GravitonDocumentUploadService $uploadService,
        string $filePath,
        array $kyc,
        string $entityType,
        string $sideLabel,
        ?int $borrowerId
    ): void {
        $fullUrl = $this->resolveFullPath($filePath);
        if (!$fullUrl) return;

        $ext  = pathinfo(parse_url($fullUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
        $name = "{$kyc['documentName']}_{$sideLabel}.{$ext}";

        try {
            $uploadService->uploadDocumentFromUrl(
                entityType:        $entityType,
                entityId:          $kyc['kycId'], // ✅ kycId from /kyc/client/{clientId}
                fileUrl:           $fullUrl,
                name:              $name,
                description:       "KYC {$kyc['documentName']} {$sideLabel}",
                isKyc:             true,
                kycDocumentTypeId: $kyc['documentTypeId'],
                borrowerId:        $borrowerId
            );

            Log::info('KYC upload success', [
                'entityType' => $entityType,
                'kycId'      => $kyc['kycId'],
                'docName'    => $kyc['documentName'],
                'side'       => $sideLabel,
            ]);
        } catch (\Throwable $e) {
            Log::warning('KYC upload failed', [
                'entityType' => $entityType,
                'kycId'      => $kyc['kycId'],
                'docName'    => $kyc['documentName'],
                'error'      => $e->getMessage(),
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // EXISTING METHODS (unchanged)
    // ═══════════════════════════════════════════════════════════

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

    /**
     * ⚠️ DEPRECATED — purono KYC logic. Ekhon getKycColumnMap() + uploadKycForEntity() use hocche.
     * Delete korleo problem nai.
     */
    // protected function resolveUploadMap(string $column, ?int $applicantClientId, ?int $guarantorFamilyId, ?int $coapplicantFamilyId, int $reportId): ?array
    // {
    //     // ... purono code ...
    // }

    /**
     * ⚠️ DEPRECATED — purono KYC doc type resolver. Ekhon response er documentTypeId direct use hocche.
     */
    // protected function resolveKycDocTypeId(string $column): ?int
    // {
    //     // ... purono code ...
    // }

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