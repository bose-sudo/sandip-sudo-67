<?php

namespace App\Traits;

trait CalculatesLoanSchedule
{
    /**
     * Generate EMI schedule for flat or reducing-balance input.
     *
     * @param  float  $loan_amount
     * @param  float  $rate   Flat % or reducing (IRR) %, depending on $type
     * @param  int    $tenure Months
     * @param  string $type   'flat' | 'reducing'
     * @return array{
     *     schedule: array<int, array{month:int,principal:int,interest:int,total_payment:int,remaining_balance:int}>,
     *     total_interest: int,
     *     total_loan_amount: int,
     *     emi_amount: int,
     *     effective_irr: float,
     *     flat_rate: float,
     * }
     */
    public function generateInterestSchedule(float $loan_amount, float $rate, int $tenure, string $type = 'flat'): array
    {
        if ($type === 'flat') {
            $flat_interest  = $rate;
            $effectiveIRR   = $this->calculateEffectiveIRR($loan_amount, $flat_interest, $tenure);
            $targetInterest = round(($loan_amount * $flat_interest / 100) * ($tenure / 12));
            $emi            = round(($loan_amount + $targetInterest) / $tenure); // flat: total / n
        } else {
            $effectiveIRR   = $rate;
            $flat_interest  = null;
            $mr             = ($effectiveIRR / 100) / 12;
            $emi            = round($loan_amount * $mr / (1 - pow(1 + $mr, -$tenure))); // annuity
            $targetInterest = $emi * $tenure - $loan_amount; // EMI × n (CashKumar convention)
        }

        $monthlyInterestRate = ($effectiveIRR / 100) / 12;

        $schedule            = [];
        $remainingBalance    = $loan_amount;
        $actualPrincipalPaid = 0;
        $actualInterestPaid  = 0;

        for ($month = 1; $month <= $tenure; $month++) {
            $interestPortion  = round($remainingBalance * $monthlyInterestRate);
            $principalPortion = round($emi - $interestPortion);

            if ($month == $tenure) {
                $principalPortion = $loan_amount - $actualPrincipalPaid;
                $interestPortion  = $targetInterest - $actualInterestPaid;
                $emi = $principalPortion + $interestPortion;
            }

            $remainingBalance    -= $principalPortion;
            $actualPrincipalPaid += $principalPortion;
            $actualInterestPaid  += $interestPortion;

            $schedule[] = [
                'month'             => $month,
                'principal'         => (int) $principalPortion,
                'interest'          => (int) $interestPortion,
                'total_payment'     => (int) $emi,
                'remaining_balance' => (int) max(0, $remainingBalance),
            ];
        }

        if ($type !== 'flat') {
            $flat_interest = ($actualInterestPaid / $loan_amount) / ($tenure / 12) * 100;
        }

        return [
            'schedule'          => $schedule,
            'total_interest'    => (int) $actualInterestPaid,
            'total_loan_amount' => (int) ($loan_amount + $actualInterestPaid),
            'emi_amount'        => (int) $schedule[0]['total_payment'],
            'effective_irr'     => round($effectiveIRR, 2),
            'flat_rate'         => round($flat_interest, 2),
        ];
    }

    /**
     * EMI only — no schedule loop, no cumulative sums. Use this for previews/quotes
     * where the full amortization table isn't needed.
     */
    public function getEmiAmount(float $loan_amount, float $rate, int $tenure, string $type = 'flat'): int
    {
        if ($type === 'flat') {
            $effectiveIRR   = $this->calculateEffectiveIRR($loan_amount, $rate, $tenure);
            $targetInterest = round(($loan_amount * $rate / 100) * ($tenure / 12));

            return (int) round(($loan_amount + $targetInterest) / $tenure); // flat: total / n
        }

        $mr = ($rate / 100) / 12;

        return (int) round($loan_amount * $mr / (1 - pow(1 + $mr, -$tenure))); // reducing: annuity
    }

    /**
     * Bisection: derive reducing-balance IRR from a flat rate.
     */
    public function calculateEffectiveIRR(float $loan_amount, float $flat_interest, int $tenure): float
    {
        $flatInterest         = ($loan_amount * $flat_interest / 100) * ($tenure / 12);
        $totalRepaymentAmount = $loan_amount + $flatInterest;

        $low = 0.0;
        $high = 1.0;
        $accuracy = 0.00001;
        $maxIterations = 200;
        $iterations = 0;
        $mid = 0.0;

        while ($high - $low > $accuracy && $iterations < $maxIterations) {
            $mid = ($low + $high) / 2;
            $monthlyInterestRate = $mid / 12;
            $emi = $loan_amount * $monthlyInterestRate / (1 - pow(1 + $monthlyInterestRate, -$tenure));
            $totalRepayment = $emi * $tenure;

            if ($totalRepayment < $totalRepaymentAmount) {
                $low = $mid;
            } else {
                $high = $mid;
            }
            $iterations++;
        }

        return round($mid * 100, 2);
    }
}