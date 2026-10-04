<?php

namespace App\Services\Graviton;

class GravitonGuarantorCreditReportService extends BaseCreditReportService
{
    protected function getEntityType(): string
    {
        return 'guarantor';
    }

    protected function getFileColumns(): array
    {
        return [
            'guarantor_cibil_report',
            'guarantor_aadhar_front', 'guarantor_aadhar_back',
            'guarantor_driving_license', 'guarantor_pan_doc',
            'guarantor_peraddress_proof', 'guarantor_coresaddress_proof',
            'guarantor_avtar', 'guarantor_rent_doc',
            'guarantor_name_declaration', 'guarantor_income_proof_doc',
            'guarantor_dob_declaration',
        ];
    }

    protected function applyEntityFlags(array &$payload, array $extraData): void
    {
        $payload['isGuarantor']    = true;
        $payload['clientId']       = null;
        $payload['familyMemberId'] = (int) ($extraData['familyMemberId'] ?? 0);
        $payload['relationType']   = $extraData['relationType'] ?? 'Guarantor';
    }
}