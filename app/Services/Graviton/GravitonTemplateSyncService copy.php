<?php

namespace App\Services\Graviton;

use App\Models\GravitonReferenceData;
use Illuminate\Support\Facades\Log;

class GravitonTemplateSyncService
{
    public function __construct(private GravitonAuthService $auth) {}

    /**
     * Sync all missing-field reference data
     */
    public function syncAll(): array
    {
        return [
            'family_member' => $this->syncFamilyMemberFields(),
            'loan'          => $this->syncLoanFields(),
        ];
    }

    /**
     * Family Member Template — sudhu missing field er jonno
     * Missing: salutation, gender, address_type, address_subtype,
     *          verification_method, customer_identifier
     */
    public function syncFamilyMemberFields(): int
    {
        $response = $this->auth->client()->get('/clients/0/familymembers/template');

        if (!$response->successful()) {
            Log::error('FamilyMemberTemplate failed', ['body' => $response->body()]);
            throw new \Exception('FamilyMemberTemplate failed');
        }

        $data  = $response->json();
        $count = 0;

        // Sudhu missing field er list
        $map = [
            'salutation'          => 'salutation',
            'gender'              => 'genderIdOptions',
            'address_type'        => 'addressTypeOptions',
            'address_subtype'     => 'addressSubTypeOptions',
            'verification_method' => 'verificationMethodOptions',
            'customer_identifier' => 'customerIdentifierOptions',
        ];

        foreach ($map as $type => $key) {
            foreach ($data[$key] ?? [] as $item) {
                $this->upsert($type, $item);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Loan Template — sudhu missing field er jonno
     * Missing: interest_type, amortization_type, interest_calc_period_type,
     *          repayment_frequency_type, term_frequency_type,
     *          repayment_strategy, loan_purpose
     */
    public function syncLoanFields(?int $productId = null): int
    {
        $params = [
            'templateType' => 'individual',
            'activeOnly'   => 'true',
        ];

        if ($productId) {
            $params['productId'] = $productId;
        }

        $response = $this->auth->client()->get('/loans/template', $params);

        if (!$response->successful()) {
            Log::error('LoanTemplate failed', ['body' => $response->body()]);
            throw new \Exception('LoanTemplate failed');
        }

        $data  = $response->json();
        $count = 0;

        // Sudhu missing field er list
        $map = [
            'interest_type'             => 'interestTypeOptions',
            'amortization_type'         => 'amortizationTypeOptions',
            'interest_calc_period_type' => 'interestCalculationPeriodTypeOptions',
            'repayment_frequency_type'  => 'repaymentFrequencyTypeOptions',
            'term_frequency_type'       => 'termFrequencyTypeOptions',
            'repayment_strategy'        => 'repaymentStrategyOptions',
            'loan_purpose'              => 'loanPurposeOptions',
        ];

        foreach ($map as $type => $key) {
            foreach ($data[$key] ?? [] as $item) {
                $this->upsert($type, $item);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Upsert
     */
    private function upsert(string $type, array $item): void
    {
        GravitonReferenceData::updateOrCreate(
            ['type' => $type, 'graviton_id' => $item['id']],
            [
                'code'         => $item['code'] ?? null,
                'label'        => $item['name'] ?? $item['value'] ?? null,
                'position'     => $item['position'] ?? null,
                'is_active'    => $item['active'] ?? true,
                'is_mandatory' => $item['mandatory'] ?? false,
                'raw_response' => $item,
                'synced_at'    => now(),
            ]
        );
    }
}