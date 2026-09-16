<?php
// app/Services/Graviton/GravitonLookupService.php

namespace App\Services\Graviton;

use App\Models\GravitonReferenceData;

class GravitonLookupService
{
    // ═══════════════════════════════════════════════════
    // FIXED VALUES (from config)
    // ═══════════════════════════════════════════════════
    
    public function amortizationType(): int
    {
        return (int) config('graviton.defaults.amortization_type', 1);
    }

    public function interestCalcPeriodType(): int
    {
        return (int) config('graviton.defaults.interest_calc_period_type', 1);
    }

    public function repaymentFrequencyType(): int
    {
        return (int) config('graviton.defaults.repayment_frequency_type', 2);
    }

    public function termFrequencyType(): int
    {
        return (int) config('graviton.defaults.term_frequency_type', 2);
    }

    // ═══════════════════════════════════════════════════
    // LOOKUP (DB theke)
    // ═══════════════════════════════════════════════════
    
    public function salutation(?string $gender): int
    {
        $key = match (strtolower($gender ?? '')) {
            'male'   => 'Mr.',
            'female' => 'Ms.',
            default  => 'Mr.',
        };
        return (int) $this->lookup('salutation', $key, 288);
    }

    public function gender(?string $g): ?int
    {
        return $this->lookup('gender', $g);
    }

    public function addressType(?string $t): int
    {
        return (int) $this->lookup('address_type', $t, 0);
    }

    public function addressSubType(?string $t = 'Current'): int
    {
        return (int) $this->lookup('address_subtype', $t, 0);
    }

    public function verificationMethod(?string $t = 'Automated System Check'): int
    {
        return (int) $this->lookup('verification_method', $t, 414);
    }

    public function documentType(?string $t): ?int
    {
        return $this->lookup('customer_identifier', $t);
    }

    // public function interestType(?string $t = 'Flat'): int
    // {
    //     return (int) $this->lookup('interest_type', $t, 1);
    // }

    // public function interestType(): int
    // {
    //     return (int) config('graviton.defaults.interest_type', 0);
    // }

    /**
     * Interest Type from borrower_loan_schemes.interest_type
     * 
     * Rule:
     *   "flat"     → 1 (Flat)
     *   otherwise  → 0 (Declining — default)
     */
    public function interestType(?string $dbValue): int
    {
        return strtolower(trim($dbValue ?? '')) === 'flat' ? 1 : 0;
    }

    public function repaymentStrategy(?string $t = 'mifos-standard-strategy'): int
    {
        return (int) $this->lookup('repayment_strategy', $t, 4);  // 4 default
    }

    public function loanPurpose(?string $t): ?int
    {
        return $this->lookup('loan_purpose', $t);
    }

    public function lookup(string $type, ?string $key, $default = null)
    {
        if (empty($key)) return $default;

        return GravitonReferenceData::where('type', $type)
            ->where(function ($q) use ($key) {
                $q->where('label', $key)->orWhere('code', $key);
            })
            ->where('is_active', true)
            ->value('graviton_id') ?? $default;
    }
}