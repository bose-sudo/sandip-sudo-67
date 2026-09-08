<?php

declare(strict_types=1);

namespace App\Domain\Lending\Bre\Engine;

final class FactExtractor
{
    /**
     * Normalize a caller-assembled context into engine facts.
     * Caller passes primitives it already has (from KYC/address/summary).
     */
    public function normalize(array $ctx): array
    {
        $score = (int) ($ctx['credit_score'] ?? 0);
        return [
            'credit_score'       => $score,
            'is_ntc'             => ($ctx['is_ntc'] ?? ($score === 0)) ? true : false,
            'residence_type'     => strtolower((string) ($ctx['residence_type'] ?? '')), // owned|rented
            'pincode'            => (string) ($ctx['pincode'] ?? ''),
            'state'              => strtoupper((string) ($ctx['state'] ?? '')),
            'gender'             => strtolower((string) ($ctx['gender'] ?? '')),
            'age'                => (int) ($ctx['age'] ?? 0),
            'dealer_borrower_km' => (float) ($ctx['dealer_borrower_km'] ?? 0),

            'years_at_address'      => (float) ($ctx['years_at_address'] ?? 0),
            'years_in_city'         => (float) ($ctx['years_in_city'] ?? 0),
            'has_co_applicant'      => ($ctx['has_co_applicant'] ?? false) ? true : false,
            'has_guarantor'         => ($ctx['has_guarantor'] ?? false) ? true : false,
            'guarantor_owns_house'  => ($ctx['guarantor_owns_house'] ?? false) ? true : false,
        ];
    }
}
