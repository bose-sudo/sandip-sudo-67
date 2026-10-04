<?php

namespace App\Services\Graviton;

class GravitonApplicantCreditReportService extends BaseCreditReportService
{
    protected function getEntityType(): string
    {
        return 'applicant';
    }

    protected function getFileColumns(): array
    {
        return [
            'borrower_cibil_report',
            'aadhar_front', 'aadhar_back', 'pan_doc', 'driving_license',
            'per_address_proof', 'res_address_proof', 'vehicle_quotation',
            'borrower_avtar', 'borrower_signed_cheque', 'borrower_other_docs',
            'borrower_rent_doc', 'borrower_name_declaration',
            'borrower_income_proof_doc', 'borrower_dob_declaration',
            'additional_doc', 'invoice', 'performa', 'down_payment_receipt',
            'insurance', 'rto_tax_receipt', 'dl_receipt', 'documents_form',
            'loan_file', 'battery_guaranty', 'warrant_document', 'rto_document',
            'mmr', 'vehicle_image', 'urc_certificate', 'vehicle_signed_cheque',
            'doh', 'vehicle_other_docs', 'chasis_number', 'motor_controller',
            'battery_image', 'registration_certificate', 'bank_statement',
            'battery_signed_cheque', 'noc_document', 'loi_permit', 'vehicle_key_img',
        ];
    }

    protected function applyEntityFlags(array &$payload, array $extraData): void
    {
        $payload['familyMemberId'] = null;
    }
}