<?php

namespace App\Services\Graviton;

use App\Models\BorrowerKyc;

class GravitonCoapplicantCreditReportService extends BaseCreditReportService
{
    protected ?BorrowerKyc $borrower = null;

    public function submit(BorrowerKyc $borrower, array $extraData = []): array
    {
        $this->borrower = $borrower;
        return parent::submit($borrower, $extraData);
    }

    protected function getEntityType(): string { return 'coapplicant'; }

    protected function getFileColumns(): array
    {
        return [
            'coapplicant_cibil_report',
            'coAadhar_front', 'coAadhar_back', 'coPan_doc', 'coDriving_license',
            'coPerAddress_proof', 'coResAddress_proof', 'coapplicant_avtar',
            'coapplicant_rent_doc', 'coapplicant_name_declaration',
            'coapplicant_income_proof_doc', 'coapplicant_dob_declaration',
        ];
    }

    protected function applyEntityFlags(array &$payload, array $extraData): void
    {
        $payload['isCoapplicant']  = true;
        $payload['clientId']       = null;
        $payload['familyMemberId'] = (int) ($extraData['familyMemberId'] ?? 0);
        $payload['relationType']   = $extraData['relationType'] ?? 'Co-Applicant';
    }

    protected function getBorrower(): ?BorrowerKyc { return $this->borrower; }
}