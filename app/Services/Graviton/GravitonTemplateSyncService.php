<?php

namespace App\Services\Graviton;

use App\Models\GravitonReferenceData;
use Illuminate\Support\Facades\Log;

class GravitonTemplateSyncService
{
    public function __construct(private GravitonAuthService $auth) {}

    public function syncAll(): array
    {
        return [
            'family_member' => $this->syncFamilyMemberFields(),
            'loan'          => $this->syncLoanFields(),
        ];
    }

    public function syncFamilyMemberFields(): int
    {
        $response = $this->auth->client()->get('/clients/0/familymembers/template');

        if (!$response->successful()) {
            Log::error('FamilyMemberTemplate failed', ['body' => $response->body()]);
            throw new \Exception('FamilyMemberTemplate failed');
        }

        $data  = $response->json();
        $count = 0;

        // ✅ Extended map — 21 lists
        $map = [
            'salutation'              => 'salutation',
            'gender'                  => 'genderIdOptions',
            'relationship'            => 'relationShipIdOptions',
            'marital_status'          => 'maritalStatusIdOptions',
            'profession'              => 'professionIdOptions',
            'qualification'           => 'qualificationIdOptions',
            'cast'                    => 'cast',
            'religion'                => 'religion',
            'customer_identifier'     => 'customerIdentifierOptions',
            'client_legal_form'       => 'clientLegalFormOptions',
            'client_classification'   => 'clientClassificationOptions',
            'medical_condition'       => 'medicalConditionOptions',
            'impairment_type'         => 'typeofImpairment',
            'accommodation_required'  => 'accommodationRequired',
            'lead_rating'             => 'leadrating',
            'family_member_type'      => 'familyMemberTypeOptions',
            'address_type'            => 'addressTypeOptions',
            'address_subtype'         => 'addressSubTypeOptions',
            'relation_with_applicant' => 'relationWithApplicant',
            'country'                 => 'countryOptions',
            'state'                   => 'stateOptions',
            'verification_method'     => 'verificationMethodOptions',
        ];

        foreach ($map as $type => $key) {
            foreach ($data[$key] ?? [] as $item) {
                $this->upsert($type, $item);
                $count++;
            }
        }

        return $count;
    }

    public function syncLoanFields(?int $productId = null): int
    {
        // ✅ No change
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

    private function upsert(string $type, array $item): void
    {
        // ✅ String cast + Enum support
        GravitonReferenceData::updateOrCreate(
            ['type' => $type, 'graviton_id' => (string) $item['id']],
            [
                'code'         => $item['code']  ?? null,
                'label'        => $item['name']  ?? $item['value'] ?? null,
                'position'     => $item['position'] ?? null,
                'is_active'    => $item['active'] ?? true,
                'is_mandatory' => $item['mandatory'] ?? false,
                'raw_response' => $item,
                'synced_at'    => now(),
            ]
        );
    }
}