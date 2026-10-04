<?php

namespace App\Services\Graviton;

use App\Models\BorrowerKyc;
use App\Models\GravitonApplicationLog;
use App\Models\GravitonReferenceData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GravitonLoanApplicationService
{
    public function __construct(
        private GravitonAuthService $auth
    ) {}

    /**
     * Push borrower as new loan application with full null check + logging
     */
    public function pushLoanApplication(BorrowerKyc $borrower): array
    {
        $log = GravitonApplicationLog::create([
            'borrower_id'  => $borrower->id,
            'step'         => 'loan_application',
            'entity_type'  => 'client',
            'endpoint'     => '/loans/newApplicationForm',
            'status'       => 'pending',
            'requested_at' => now(),
        ]);

        try {
            if ($borrower->dedupe_status !== 'no_match') {
                throw new \Exception("Borrower {$borrower->id} is not approved (dedupe_status: {$borrower->dedupe_status})");
            }

            $ref = GravitonReferenceData::where('borrower_id', $borrower->id)->first();
            if (!$ref) {
                throw new \Exception("No graviton_reference_data for borrower {$borrower->id}");
            }

            $scheme = $borrower->loanScheme;
            if (!$scheme) {
                throw new \Exception("No loan scheme for borrower {$borrower->id}");
            }

            $payload = $this->buildPayload($borrower, $ref, $scheme);

            $missingFields = $this->checkMissingFields($payload);

            if (!empty($missingFields)) {
                $log->update([
                    'status'            => 'failed',
                    'request_payload'   => $payload,
                    'error_message'     => 'Missing mandatory fields: ' . implode(', ', $missingFields),
                    'validation_errors' => $missingFields,
                    'completed_at'      => now(),
                ]);

                throw new \Exception('Missing mandatory fields: ' . implode(', ', $missingFields));
            }

            $log->update(['request_payload' => $payload]);

            $response = $this->auth->client()->post('/loans/newApplicationForm', $payload);
            $data     = $response->json();

            $log->update([
                'response_payload' => $data,
                'http_status'      => $response->status(),
            ]);

            if (!$response->successful()) {
                $log->update([
                    'status'            => 'failed',
                    'error_message'     => 'HTTP ' . $response->status() . ': ' . $response->body(),
                    'validation_errors' => $data['errors'] ?? null,
                    'completed_at'      => now(),
                ]);

                throw new \Exception('Loan application failed: HTTP ' . $response->status());
            }

            $borrower->graviton_client_id = $data['clientId'] ?? null;
            $borrower->graviton_loan_id   = $data['loanId']   ?? null;
            $borrower->save();

            $log->update([
                'status'             => 'success',
                'graviton_client_id' => $data['clientId']   ?? null,
                'graviton_loan_id'   => $data['loanId']     ?? null,
                'resource_id'        => $data['resourceId'] ?? null,
                'office_id'          => $data['officeId']   ?? null,
                'completed_at'       => now(),
            ]);

            Log::info('Loan application created', [
                'borrower_id' => $borrower->id,
                'client_id'   => $data['clientId'] ?? null,
                'loan_id'     => $data['loanId'] ?? null,
            ]);

            return $data;
        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);

            Log::error('Loan application push FAILED', [
                'borrower_id' => $borrower->id,
                'error'       => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * ✅ Check for missing mandatory fields
     */
    protected function checkMissingFields(array $payload): array
    {
        $missing = [];

        $clientMandatory = [
            'legalFormId', 'salutation', 'officeId',
            'active', 'submittedOnDate', 'dateFormat', 'locale',
            'firstName', 'lastName', 'mobileNo',
        ];

        foreach ($clientMandatory as $field) {
            if (!isset($payload['clientData'][$field]) || $payload['clientData'][$field] === null || $payload['clientData'][$field] === '') {
                $missing[] = "clientData.{$field}";
            }
        }

        // Address mandatory
        if (empty($payload['clientData']['address'])) {
            $missing[] = 'clientData.address (empty)';
        } else {
            $addr = $payload['clientData']['address'][0] ?? [];
            $addressMandatory = [
                'addressTypeId', 'addressSubTypeId', 'dateFormat', 'locale',
                'addressLine1', 'landmark', 'townVillage', 'city',
                'district', 'state', 'country', 'postalCode',
            ];
            foreach ($addressMandatory as $f) {
                if (!isset($addr[$f]) || $addr[$f] === null || $addr[$f] === '') {
                    $missing[] = "clientData.address[0].{$f}";
                }
            }
        }

        $loanMandatory = [
            'productId', 'officeId', 'loanType', 'principal',
            'numberOfRepayments', 'repaymentEvery', 'repaymentFrequencyType',
            'loanTermFrequency', 'loanTermFrequencyType', 'interestRatePerPeriod',
            'interestType', 'interestCalculationPeriodType', 'amortizationType',
            'transactionProcessingStrategyId', 'submittedOnDate',
            'dateFormat', 'locale', 'isLoanApplication', 'internalSalesId',
            'interestUpfront', 'isBrokenPeriodInterestUpfront',
        ];

        foreach ($loanMandatory as $field) {
            if (!isset($payload['loanData'][$field]) || $payload['loanData'][$field] === null || $payload['loanData'][$field] === '') {
                $missing[] = "loanData.{$field}";
            }
        }

        if (empty($payload['kycData'])) {
            $missing[] = 'kycData (empty)';
        }

        return $missing;
    }

    /**
     * Build payload
     */
    protected function buildPayload(BorrowerKyc $borrower, GravitonReferenceData $ref, $scheme): array
    {
        $address = $borrower->address ?? null;
        [$firstName, $lastName] = $this->splitName($borrower->name);

        // ───────────────────────────────────────────
        // Address parsing
        // ───────────────────────────────────────────
        $addressLine = $address->current_address ?? '';

        // Extract postal code (6 digits)
        $postalCode = '000000';
        if (preg_match('/(\d{6})/', $addressLine, $m)) {
            $postalCode = $m[1];
        }

        // Extract city / state from address (fallback chain)
        $city    = $borrower->state ?: 'N/A';
        $state   = $borrower->state ?: 'West Bengal';
        $country = $borrower->country ?: 'India';

        // ───────────────────────────────────────────
        // Principal — fallback
        // ───────────────────────────────────────────
        // $principal = (float) ($scheme->loan_amount ?: $scheme->min_loan_amount ?: 50000);

        $loanAmt = (float) $scheme->loan_amount;
        $minAmt  = (float) $scheme->min_loan_amount;

        $principal = $loanAmt > 0 ? $loanAmt : ($minAmt > 0 ? $minAmt : 50000);
        $principal = max(10000, min(150000, $principal));

        // ───────────────────────────────────────────
        // Gender + Salutation
        // ───────────────────────────────────────────
        $genderId     = $this->mapGender($borrower->gender);
        $salutationId = $this->mapSalutation($borrower->gender);

        // ───────────────────────────────────────────
        // Loan term frequency (must equal repaymentEvery × numberOfRepayments)
        // ───────────────────────────────────────────
        $repaymentEvery     = (int) ($ref->repayment_every ?: 1);
        $numberOfRepayments = (int) ($scheme->max_loan_tenure ?: 6);
        $loanTermFrequency  = $repaymentEvery * $numberOfRepayments;

        // ═══════════════════════════════════════════
        // ✅ FIX — transactionProcessingStrategyId
        // ═══════════════════════════════════════════
        // Product 36 (BATTERY L3) default = 6
        // Priority: ref value → product 36 default (6)
        $strategyId = (int) ($ref->transaction_processing_strategy_id ?? 6);
        
        // If ref has wrong value (1), force product 36 default (6)
        if (!in_array($strategyId, [1, 2, 3, 4, 5, 6, 7, 8, 9])) {
            $strategyId = 6;
        }

        return [
            'entity'      => 'client',
            'entityType'  => 'client',
            'identifiers' => [],
            'clientData'  => [
                'legalFormId'     => (int) $ref->legal_form_id,
                'salutation'      => $salutationId,
                'officeId'        => (int) config('graviton.defaults.office_id'),
                'active'          => true,
                'submittedOnDate' => now()->format('d F Y'),
                'dateFormat'      => 'dd MMMM yyyy',
                'locale'          => 'en',
                'firstName'       => $firstName,
                'lastName'        => $lastName,
                'fatherName'      => $borrower->father_name,
                'genderId'        => $genderId,
                'dateOfBirth'     => $borrower->dob_date ? date('d F Y', strtotime($borrower->dob_date)) : null,
                'mobileNo'        => $borrower->mobile,
                'emailAddress'    => $borrower->email,
                // 'isMobileVerified'=> (bool) $borrower->is_verified_mobile,
                'isMobileVerified' => (bool) ($borrower->is_verified_mobile ?? false),

                // ───────────────────────────────────
                // ✅ ADDRESS — সব mandatory field সহ
                // ───────────────────────────────────
                'address' => [[
                    'addressTypeId'    => 0,
                    'addressSubTypeId' => 0,
                    'addressLine1'     => $addressLine ?: 'N/A',
                    'landmark'         => 'N/A',                    // ✅ NEW — Mandatory
                    'townVillage'      => 'N/A',                    // ✅ NEW — Recommended
                    'city'             => $city,
                    'district'         => 'N/A',                    // ✅ NEW — Recommended
                    'state'            => $state,
                    'country'          => $country,
                    'postalCode'       => $postalCode,
                    'isCommunicationAddress' => true,
                    'isActive'         => true,
                    'dateFormat'       => 'dd MMMM yyyy',
                    'locale'           => 'en',
                ]],
            ],

            'loanData' => [
                'productId'       => (int) config('graviton.defaults.product_id'),
                'officeId'        => (int) config('graviton.defaults.office_id'),
                'internalSalesId' => (int) config('graviton.defaults.internal_sales_id'),
                'loanType'        => 'individual',

                'repaymentEvery'                => $repaymentEvery,
                'repaymentFrequencyType'        => 2,
                'loanTermFrequency'             => $loanTermFrequency,
                'loanTermFrequencyType'         => 2,
                'numberOfRepayments'            => $numberOfRepayments,

                'interestRatePerPeriod'         => (float) $scheme->interest,
                'interestType'                  => $this->resolveInterestType($scheme->interest_type ?? null),
                'interestCalculationPeriodType' => 1,
                'amortizationType'              => 1,
                'isLoanApplication'             => true,
                'interestUpfront'               => false,
                'isBrokenPeriodInterestUpfront' => false,

                // 'transactionProcessingStrategyId' => (int) $ref->transaction_processing_strategy_id,

                'transactionProcessingStrategyId' => 6,
                
                'loanPurposeId'                   => (int) $ref->loan_purpose_id,

                'dateFormat'      => 'dd MMMM yyyy',
                'locale'          => 'en',
                'submittedOnDate' => now()->format('d F Y'),

                'principal'             => $principal,
                'maxOutstandingLoanBalance' => (float) $principal,    // ← NEW
                'charges'           => [],
                // 'disbursementData'  => [],
                'disbursementData' => [[
                    'expectedDisbursementDate' => now()->format('d F Y'),
                    'principal'                => $principal,
                ]],
                'checklistTemplate' => [],
                // ✅ NEW — 4 default fields
                'brokenPeriodInterestComputationType'          => 0,
                'allowPartialPeriodInterestCalcualtion'        => false,
                'principalThresholdForLastInstalment'          => 0,
                'principalThresholdForLastInstallmentofLoan'   => 0,
                'useSop' => true,
            ],

            'kycData' => $this->buildKycData($borrower),
        ];
    }

    protected function mapGender(?string $gender): int
    {
        return match (strtolower(trim($gender ?? ''))) {
            'male'   => 286,
            'female' => 285,
            'other'  => 287,
            default  => 286,
        };
    }

    protected function mapSalutation(?string $gender): int
    {
        return match (strtolower(trim($gender ?? ''))) {
            'male'   => 288,
            'female' => 290,
            default  => 291,
        };
    }

    protected function splitName(?string $name): array
    {
        $name = trim($name ?? '');
        if (empty($name)) return ['', ''];
        $parts = explode(' ', $name, 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    protected function resolveInterestType(?string $schemeType): int
    {
        return strtolower(trim($schemeType ?? '')) === 'flat' ? 1 : 0;
    }

    protected function buildKycData(BorrowerKyc $borrower): array
    {
        $kyc = [];

        if (!empty($borrower->pan)) {
            $kyc[] = [
                'entity'             => 'client',
                'verificationTypeId' => 1,
                'documentTypeId'     => 2,
                'documentKey'        => $borrower->pan,
                'kycTypeId'          => 0,
                'validationStatusId' => $borrower->is_verified_pan ? 1 : 0,
                'legalFormTypeId'    => 1,
                'isFrontSide'        => true,
                'isBackSide'         => false,
                'isOcr'              => false,
                'locale'             => 'en',
            ];
        }

        if (!empty($borrower->aadhar)) {
            $kyc[] = [
                'entity'             => 'client',
                'verificationTypeId' => 1,
                'documentTypeId'     => 1,
                'documentKey'        => $borrower->aadhar,
                'kycTypeId'          => 0,
                'validationStatusId' => $borrower->is_verified_aadhar ? 1 : 0,
                'legalFormTypeId'    => 1,
                'isFrontSide'        => true,
                'isBackSide'         => true,
                'isOcr'              => false,
                'locale'             => 'en',
            ];
        }

        return $kyc;
    }
}