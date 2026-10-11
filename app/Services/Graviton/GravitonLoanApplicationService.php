<?php

namespace App\Services\Graviton;

use App\Models\BorrowerKyc;
use App\Models\DisbursementDetails;
use App\Models\GravitonApplicationLog;
use App\Models\GravitonReferenceData;
use App\Models\LoanBasicDetails;
use App\Models\ProductModel;
use App\Models\OemDynamicProduct;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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

            $borrower->graviton_client_id = $data['clientId'] ?? $borrower->graviton_client_id;
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
     * Build payload — with all mapping fixes
     */
    protected function buildPayload(BorrowerKyc $borrower, GravitonReferenceData $ref, $scheme): array
    {
        $address    = $borrower->address    ?? null;
        $occupation = $borrower->occupation ?? null;

        // ✅ Split name with father name fallback
        [$firstName, $lastName] = $this->splitName($borrower->name ?? null, $borrower->father_name ?? null);

        // ───────────────────────────────────────────
        // Address parsing
        // ───────────────────────────────────────────
        $addressLine = trim((string) ($address->current_address ?? ''));

        // ✅ Pincode — address theke, na thakle borrower->postal
        $postalCode = '000000';
        if (preg_match('/(\d{6})/', $addressLine, $m)) {
            $postalCode = $m[1];
        } elseif (!empty($borrower->postal ?? null)) {
            $postalCode = (string) ($borrower->postal ?? null);
        }

        // ✅ City — address theke parse
        $city = 'N/A';
        if (preg_match('/,\s*([^,]+),\s*[^,]+,\s*\d{6}/', $addressLine, $m)) {
            $city = trim($m[1]);
        } elseif (!empty($borrower->city ?? null)) {
            $city = $borrower->city ?? null;
        } elseif (!empty($borrower->state ?? null)) {
            $city = $borrower->state ?? null;
        }

        // ✅ State — address theke parse
        $state = 'N/A';
        if (preg_match('/,\s*([^,]+),\s*\d{6}/', $addressLine, $m)) {
            $state = trim($m[1]);
        } elseif (!empty($borrower->state ?? null)) {
            $state = $borrower->state ?? null;
        }

        // ✅ District — address theke parse
        $district = 'N/A';
        if (preg_match('/,\s*([^,]+),\s*[^,]+,\s*[^,]+,\s*\d{6}/', $addressLine, $m)) {
            $district = trim($m[1]);
        }

        // ✅ Address lines split (max 100 chars each)
        $addressLine1 = mb_substr($addressLine, 0, 100);
        $addressLine2 = mb_strlen($addressLine) > 100 ? mb_substr($addressLine, 100, 100) : null;
        $addressLine3 = mb_strlen($addressLine) > 200 ? mb_substr($addressLine, 200, 100) : null;

        $country = ($borrower->country ?? null) ?: 'India';

        // ───────────────────────────────────────────
        // Principal — fallback
        // ───────────────────────────────────────────
        $loanAmt = (float) ($scheme->loan_amount     ?? 0);
        $minAmt  = (float) ($scheme->min_loan_amount ?? 0);

        $principal = $loanAmt > 0 ? $loanAmt : ($minAmt > 0 ? $minAmt : 50000);
        $principal = max(10000, min(150000, $principal));

        // ───────────────────────────────────────────
        // ✅ Sanction Amount — principal + iot
        // ───────────────────────────────────────────
        $iotAmount      = (float) ($scheme->iot ?? 0);
        $sanctionAmount = $principal + $iotAmount;

        // ───────────────────────────────────────────
        // ✅ Disbursed Amount — from disbursement_details
        // ───────────────────────────────────────────
        $disbursement = DisbursementDetails::where('borrower_id', $borrower->id)
            ->latest()
            ->first();

        $disbursedAmount = ($disbursement?->disbursement_amount ?? null)
            ? (float) $disbursement->disbursement_amount
            : null;

        $disbursementDate = ($disbursement?->disbursement_date ?? null)
            ? date('d F Y', strtotime($disbursement->disbursement_date))
            : null;

        // ───────────────────────────────────────────
        // ✅ Product resolution — LoanBasicDetails theke
        // ───────────────────────────────────────────
        $productType = '';
        $productId   = 32;   // default

        try {
            $loanBasicDetails = LoanBasicDetails::with('dealer')
                ->where('borrower_id', $borrower->borrower_id ?? $borrower->id)
                ->first();

            $dealerDetails = $loanBasicDetails->dealer ?? null;
            $modelId       = $loanBasicDetails->model_id ?? null;
            $modelName     = $loanBasicDetails->model    ?? null;

            // 1️⃣ Dynamic product — FIRST PRIORITY
            $dynamicProduct = null;
            if (!empty($modelId)) {
                $dynamicProduct = OemDynamicProduct::where('oem_product_unq', $modelId)->first();
            }

            if ($dynamicProduct) {
                $productType = $dynamicProduct->product_type ?? '';
                Log::info('Product resolved from OemDynamicProduct', [
                    'borrower_id'  => $borrower->id,
                    'model_id'     => $modelId,
                    'product_type' => $productType,
                ]);
            } else {
                // 2️⃣ Legacy ProductModel
                $legacyProduct = null;

                if (!empty($modelId)) {
                    $legacyProduct = $dealerDetails
                        ? ProductModel::where('dealer_id', $dealerDetails->id)
                            ->where('model_unq', $modelId)
                            ->first()
                        : ProductModel::where('model_unq', $modelId)->first();
                }

                if (!$legacyProduct && !empty($modelName)) {
                    $legacyProduct = $dealerDetails
                        ? ProductModel::where('dealer_id', $dealerDetails->id)
                            ->where('name', $modelName)
                            ->first()
                        : ProductModel::where('name', $modelName)->first();
                }

                if ($legacyProduct) {
                    $productType = $legacyProduct->product_type ?? '';
                    Log::info('Product resolved from ProductModel (legacy)', [
                        'borrower_id'  => $borrower->id,
                        'model_id'     => $modelId,
                        'model'        => $modelName,
                        'product_type' => $productType,
                    ]);
                }
            }

            if (empty($productType)) {
                Log::warning('Product not found — default 36 used', [
                    'borrower_id' => $borrower->id,
                    'model_id'    => $modelId,
                    'model'       => $modelName,
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('Product lookup failed — default 36 used', [
                'borrower_id' => $borrower->id,
                'error'       => $e->getMessage(),
            ]);
        }

        // 3️⃣ product_type → productId (L3 → 36, L5 → 32, else → 36 default)
        $productId = $this->resolveProductId($productType);

        // ───────────────────────────────────────────
        // ✅ Gender + Salutation — with marital status
        // ───────────────────────────────────────────
        $genderId     = $this->mapGender($borrower->gender ?? null);
        $salutationId = $this->mapSalutation($borrower->gender ?? null, $borrower->marital_status ?? null);

        // ───────────────────────────────────────────
        // ✅ Loan term frequency
        // ───────────────────────────────────────────
        $repaymentEvery     = (int) (($ref->repayment_every ?? null) ?: 1);
        $numberOfRepayments = (int) (($scheme->max_loan_tenure ?? null) ?: 6);
        $loanTermFrequency  = $repaymentEvery * $numberOfRepayments;

        // ───────────────────────────────────────────
        // ✅ Transaction processing strategy
        // ───────────────────────────────────────────
        $strategyId = (int) ($ref->transaction_processing_strategy_id ?? 6);
        if (!in_array($strategyId, [1, 2, 3, 4, 5, 6, 7, 8, 9])) {
            $strategyId = 6;
        }

        // ───────────────────────────────────────────
        // ✅ Sanction Date from teleduce_mail_logs (latest record)
        // ───────────────────────────────────────────
        $tz = config('app.timezone', 'Asia/Kolkata');

        $sanctionDateRaw = DB::table('teleduce_mail_logs')
            ->where('reference', 'sanction-borrower-' . ($borrower->borrower_id ?? $borrower->id))
            ->orderByDesc('created_at')
            ->value('created_at');

        $sanctionDate = $sanctionDateRaw
            ? Carbon::parse($sanctionDateRaw, $tz)
            : null;

        // ───────────────────────────────────────────
        // ✅ Age calculation — DOB vs Sanction Date
        // ───────────────────────────────────────────
        $age = ($borrower->dob_date && $sanctionDate)
            ? (int) Carbon::parse($borrower->dob_date)->diffInYears($sanctionDate)
            : null;

        return [
            'entity'      => 'client',
            'entityType'  => 'client',
            'identifiers' => [],
            'clientData'  => [
                // ═══════════════════════════════════════
                // Existing mandatory fields
                // ═══════════════════════════════════════
                'legalFormId'      => (int) ($ref->legal_form_id ?? 0),
                'salutation'       => $salutationId ?? null,
                'officeId'         => (int) config('graviton.defaults.office_id'),
                'active'           => true,
                'submittedOnDate'  => now()->format('d F Y'),
                'dateFormat'       => 'dd MMMM yyyy',
                'locale'           => 'en',
                'firstName'        => $firstName ?? null,
                'lastName'         => $lastName ?? null,
                'fatherName'       => $borrower->father_name ?? null,
                'genderId'         => $genderId ?? null,
                'dateOfBirth'      => ($borrower->dob_date ?? null) ? date('d F Y', strtotime($borrower->dob_date)) : null,
                'mobileNo'         => $borrower->mobile ?? null,
                'emailAddress'     => $borrower->email ?? null,
                'isMobileVerified' => (bool) ($borrower->is_verified_mobile ?? false),

                // ═══════════════════════════════════════
                // ✅ Category A fields (source ache)
                // ═══════════════════════════════════════
                'middleName'         => $borrower->middle_name ?? null,
                'motherName'         => $borrower->mother_name ?? null,
                'profession'         => $occupation->profession ?? $occupation->current_occupation ?? null,
                'caste'              => $borrower->caste ?? null,
                'religion'           => $borrower->religion ?? null,
                'medicalCondition'   => $borrower->medical_condition ?? null,
                'placeOfBirth'       => $borrower->place_of_birth ?? null,
                'numberOfDependents' => $address->family_members ?? null,
                'qualification'      => $occupation->qualification ?? null,
                'preferredLanguage'  => $borrower->preferred_language ?? null,
                'age'                => $age,

                // ═══════════════════════════════════════
                // Address
                // ═══════════════════════════════════════
                'address' => [[
                    'addressTypeId'          => 0,
                    'addressSubTypeId'       => 0,
                    'addressLine1'           => $addressLine1 ?: 'N/A',
                    'addressLine2'           => $addressLine2 ?? null,
                    'addressLine3'           => $addressLine3 ?? null,
                    'landmark'               => $address->landmark ?? 'N/A',
                    'townVillage'            => 'N/A',
                    'city'                   => $city ?? 'N/A',
                    'district'               => $district ?? 'N/A',
                    'state'                  => $state ?? 'N/A',
                    'country'                => $country ?? 'India',
                    'postalCode'             => $postalCode ?? '000000',
                    'longitude'              => $address->longitude ?? null,
                    'latitude'               => $address->latitude ?? null,
                    'isCommunicationAddress' => (bool) ($address->is_communication ?? true),
                    'isActive'               => true,
                    'dateFormat'             => 'dd MMMM yyyy',
                    'locale'                 => 'en',
                ]],
            ],

            // ═══════════════════════════════════════
            // loanData
            // ═══════════════════════════════════════
            'loanData' => [
                'productId'       => $productId,
                'officeId'        => (int) config('graviton.defaults.office_id'),
                'internalSalesId' => (int) config('graviton.defaults.internal_sales_id'),
                'loanType'        => 'individual',

                'repaymentEvery'         => $repaymentEvery,
                'repaymentFrequencyType' => 2,
                'loanTermFrequency'      => $loanTermFrequency,
                'loanTermFrequencyType'  => 2,
                'numberOfRepayments'     => $numberOfRepayments,

                'interestRatePerPeriod'         => (float) ($scheme->interest ?? 0),
                'interestType'                  => $this->resolveInterestType($scheme->interest_type ?? null),
                'interestCalculationPeriodType' => 1,
                'amortizationType'              => 1,
                'isLoanApplication'             => true,
                'interestUpfront'               => false,
                'isBrokenPeriodInterestUpfront' => false,

                'transactionProcessingStrategyId' => $strategyId,
                'loanPurposeId'                   => (int) ($ref->loan_purpose_id ?? 0),

                'dateFormat'      => 'dd MMMM yyyy',
                'locale'          => 'en',
                'submittedOnDate' => now()->format('d F Y'),

                'principal'                 => $principal,
                'maxOutstandingLoanBalance' => (float) $principal,

                'sanctionAmount'   => $sanctionAmount,
                'approvedAmount'   => $sanctionAmount,
                'disbursedAmount'  => $disbursedAmount,
                'disbursementDate' => $disbursementDate,

                'assetId'        => $borrower->asset_id ?? null,
                'loginAmount'    => $principal,
                'comfortableEmi' => $borrower->comfortable_emi ?? null,
                'dst'            => $borrower->dst ?? null,
                'channelType'    => $borrower->channel_type ?? null,
                'sourceName'     => $borrower->source_name ?? null,
                'subSourceName'  => $borrower->sub_source_name ?? null,
                'externalId'     => $borrower->external_id ?? null,

                'charges'          => [],
                'disbursementData' => [[
                    'expectedDisbursementDate' => now()->format('d F Y'),
                    'principal'                => $principal,
                ]],
                'checklistTemplate' => [],

                'brokenPeriodInterestComputationType'        => 0,
                'allowPartialPeriodInterestCalcualtion'      => false,
                'principalThresholdForLastInstalment'        => 0,
                'principalThresholdForLastInstallmentofLoan' => 0,
            ],

            'kycData' => $this->buildKycData($borrower),
        ];
    }

    /**
     * ✅ Product ID resolve — L3 → 36, L5 → 32, else → 36
     */
    protected function resolveProductId(string $productType): int
    {
        $type = strtoupper(trim($productType ?? ''));

        if (str_contains($type, 'L3')) {
            return 28;
        }
        if (str_contains($type, 'L5')) {
            return 32;
        }

        return 36;   // default
    }

    /**
     * ✅ Split name — last name empty hole "So {fatherName}"
     */
    protected function splitName(?string $name, ?string $fatherName = null): array
    {
        $name = trim($name ?? '');
        if (empty($name)) {
            return ['N/A', 'N/A'];
        }

        $parts     = explode(' ', $name, 2);
        $firstName = trim($parts[0] ?? '');
        $lastName  = trim($parts[1] ?? '');

        if (empty($lastName)) {
            $lastName = !empty($fatherName)
                ? 'So ' . trim($fatherName ?? '')
                : $firstName;
        }

        if (empty($firstName)) {
            $firstName = $lastName;
        }

        return [$firstName, $lastName];
    }

    /**
     * ✅ Gender mapping
     */
    protected function mapGender(?string $gender): int
    {
        return match (strtolower(trim($gender ?? ''))) {
            'male'   => 286,
            'female' => 285,
            'other'  => 287,
            default  => 286,
        };
    }

    /**
     * ✅ Salutation — gender + marital status onujayi
     */
    protected function mapSalutation(?string $gender, ?string $maritalStatus = null): int
    {
        $gender        = strtolower(trim($gender ?? ''));
        $maritalStatus = strtolower(trim($maritalStatus ?? ''));

        if ($gender === 'male') {
            return 288;   // Mr.
        }

        if ($gender === 'female') {
            return $maritalStatus === 'married' ? 290 : 289;   // Mrs. : Ms.
        }

        return 291;   // M/s.
    }

    /**
     * ✅ Interest type — flat hole 1, otherwise 0
     */
    protected function resolveInterestType(?string $schemeType): int
    {
        return strtolower(trim($schemeType ?? '')) === 'flat' ? 1 : 0;
    }

    /**
     * ✅ Build KYC data
     */
    protected function buildKycData(BorrowerKyc $borrower): array
    {
        $kyc = [];

        // PAN
        if (!empty($borrower->pan ?? null)) {
            $kyc[] = [
                'entity'             => 'client',
                'verificationTypeId' => 1,
                'documentTypeId'     => 2,
                'documentKey'        => $borrower->pan ?? null,
                'kycTypeId'          => 0,
                'validationStatusId' => ($borrower->is_verified_pan ?? false) ? 1 : 0,
                'legalFormTypeId'    => 1,
                'isFrontSide'        => true,
                'isBackSide'         => false,
                'isOcr'              => false,
                'locale'             => 'en',
            ];
        }

        // Aadhaar
        if (!empty($borrower->aadhar ?? null)) {
            $kyc[] = [
                'entity'             => 'client',
                'verificationTypeId' => 1,
                'documentTypeId'     => 1,
                'documentKey'        => $borrower->aadhar ?? null,
                'kycTypeId'          => 0,
                'validationStatusId' => ($borrower->is_verified_aadhar ?? false) ? 1 : 0,
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