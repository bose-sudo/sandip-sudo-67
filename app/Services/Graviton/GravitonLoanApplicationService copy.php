<?php

namespace App\Services\Graviton;

use App\Models\BorrowerKyc;
use App\Models\GravitonReferenceData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GravitonLoanApplicationService
{
    public function __construct(
        private GravitonAuthService $auth
    ) {}

    /**
     * Push borrower as new loan application to Graviton
     * Only call if dedupe_status === 'no_match'
     */
    public function pushLoanApplication(BorrowerKyc $borrower): array
    {
        // Safety check
        if ($borrower->dedupe_status !== 'no_match') {
            throw new \Exception("Borrower {$borrower->id} is not approved (dedupe_status: {$borrower->dedupe_status})");
        }

        $ref = GravitonReferenceData::where('borrower_id', $borrower->id)->first();

        if (!$ref) {
            throw new \Exception("No graviton_reference_data found for borrower {$borrower->id}");
        }

        $payload = $this->buildPayload($borrower, $ref);

        Log::info('Pushing loan application', [
            'borrower_id' => $borrower->id,
            'payload'     => $payload,
        ]);

        $response = $this->auth->client()->post('/loans/newApplicationForm', $payload);

        if (!$response->successful()) {
            Log::error('Loan application failed', [
                'borrower_id' => $borrower->id,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \Exception('Loan application failed: HTTP ' . $response->status() . ' — ' . $response->body());
        }

        $data = $response->json();

        // Save Graviton IDs to borrower
        $borrower->graviton_client_id = $data['clientId'] ?? null;
        $borrower->save();

        Log::info('Loan application created', [
            'borrower_id' => $borrower->id,
            'client_id'   => $data['clientId'] ?? null,
            'loan_id'     => $data['loanId'] ?? null,
        ]);

        return $data;
    }

    /**
     * Build the complete payload
     */
    protected function buildPayload(BorrowerKyc $borrower, GravitonReferenceData $ref): array
    {
        $scheme   = $borrower->loanScheme;
        $address  = $borrower->address ?? null;
        $scheme   = $borrower->loanScheme ?? null;

        // Split name
        [$firstName, $lastName] = $this->splitName($borrower->name);

        return [
            'entity'      => 'client',
            'entityType'  => 'client',
            'identifiers' => [],

            // ═══════════════════════════════════════════
            // clientData
            // ═══════════════════════════════════════════
            'clientData' => [
                // From graviton_reference_data
                'legalFormId' => (int) ($ref->legal_form_id ?? 1),
                'salutation'  => (int) ($ref->salutation_id ?? 0),

                // ENV
                'officeId'    => (int) config('graviton.defaults.office_id', 1),

                // Fixed
                'active'          => true,
                'submittedOnDate' => now()->format('d F Y'),
                'dateFormat'      => 'dd MMMM yyyy',
                'locale'          => 'en',

                // From borrower_kycs
                'firstName'             => $firstName,
                'lastName'              => $lastName,
                'fatherName'            => $borrower->father_name,
                'genderId'              => $this->mapGender($borrower->gender),
                'dateOfBirth'           => $borrower->dob_date ? date('d F Y', strtotime($borrower->dob_date)) : null,
                'mobileNo'              => $borrower->mobile,
                'alternateContactNumber'=> $borrower->alternate_number1,
                'emailAddress'          => $borrower->email,
                'isMobileVerified'      => (bool) $borrower->is_verified_mobile,
                'isEarner'              => true,

                // Address
                'address' => [
                    [
                        'addressTypeId'    => 0, // Owned
                        'addressSubTypeId' => 0, // Current
                        'addressLine1'     => $address->current_address ?? '',
                        'city'             => $borrower->state ?? '',
                        'state'            => $borrower->state ?? '',
                        'country'          => $borrower->country ?? 'India',
                        'postalCode'       => (string) ($borrower->postal ?? ''),
                        'isCommunicationAddress' => true,
                        'isActive'         => true,
                        'dateFormat'       => 'dd MMMM yyyy',
                        'locale'           => 'en',
                    ],
                ],
            ],

            // ═══════════════════════════════════════════
            // loanData
            // ═══════════════════════════════════════════
            'loanData' => [
                // ENV
                'productId'      => (int) config('graviton.defaults.product_id', 36),
                'officeId'       => (int) config('graviton.defaults.office_id', 1),
                'internalSalesId'=> (int) config('graviton.defaults.internal_sales_id', 158),

                // Fixed
                'loanType'                      => 'individual',
                'repaymentFrequencyType'        => 2,
                'loanTermFrequencyType'         => 2,
                'interestCalculationPeriodType' => 1,
                'amortizationType'              => 1,
                'isLoanApplication'             => true,
                'isSelfSourced'                 => false,
                'interestUpfront'               => false,
                'isBrokenPeriodInterestUpfront' => false,
                'interestType'                  => $this->resolveInterestType($scheme->interest_type ?? null),
                'dateFormat'                    => 'dd MMMM yyyy',
                'locale'                        => 'en',
                'submittedOnDate'               => now()->format('d F Y'),

                // From graviton_reference_data
                'repaymentEvery'                  => (int) ($ref->repayment_every ?? 1),
                'loanTermFrequency'               => (int) ($ref->loan_term_frequency ?? 6),
                'transactionProcessingStrategyId' => (int) ($ref->transaction_processing_strategy_id ?? 1),
                'loanPurposeId'                   => (int) ($ref->loan_purpose_id ?? 0),

                // From borrower_loan_schemes
                'principal'             => (float) ($scheme->loan_amount ?? 0),
                'numberOfRepayments'    => (int) ($scheme->max_loan_tenure ?? 1),
                'interestRatePerPeriod' => (float) ($scheme->interest ?? 0),

                // Empty arrays
                'charges'            => [],
                'disbursementData'   => [],
                'checklistTemplate'  => [],
            ],

            // ═══════════════════════════════════════════
            // kycData
            // ═══════════════════════════════════════════
            'kycData' => $this->buildKycData($borrower, $ref),
        ];
    }

    /**
     * Split full name into first + last
     */
    protected function splitName(?string $name): array
    {
        $name = trim($name ?? '');
        if (empty($name)) return ['', ''];

        $parts = explode(' ', $name, 2);
        return [
            $parts[0] ?? '',
            $parts[1] ?? '',
        ];
    }

    /**
     * Map gender string → Graviton gender ID
     */
    protected function mapGender(?string $gender): int
    {
        return match (strtolower(trim($gender ?? ''))) {
            'male'   => 449,
            'female' => 450,
            default  => 449,
        };
    }

    /**
     * Interest type logic — flat হলে 1, নাহলে 0
     */
    protected function resolveInterestType(?string $schemeType): int
    {
        return strtolower(trim($schemeType ?? '')) === 'flat' ? 1 : 0;
    }

    /**
     * Build KYC data array
     */
    protected function buildKycData(BorrowerKyc $borrower, GravitonReferenceData $ref): array
    {
        $kyc = [];

        // PAN (documentType = 2)
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

        // Aadhaar (documentType = 1)
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