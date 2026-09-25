<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\LoanBasicDetails;

trait BorrowerEmiTrait
{
    public function getBorrowerEmiAmount(int $borrower_id): int
    {
        return $this->getBorrowerEmiDetails($borrower_id)['emi_amount'];
    }

    /** @return array{emi_amount:int,final_loan_amount:float,interest_rate:float,tenure:int,interest_type:string,iot_amount:float,second_year_insurance:float} */
    public function getBorrowerEmiDetails(int $borrower_id): array
    {
        $loan = LoanBasicDetails::with('latestLoanScheme')->find($borrower_id);

        if (!$loan) {
            return $this->emptyEmiPayload();
        }

        return $this->buildEmiPayload($loan);
    }

    /** @param \Illuminate\Support\Collection<int,LoanBasicDetails>|iterable<int> $borrower_ids */
    public function getBorrowerEmiAmounts(array $borrower_ids): array
    {
        return LoanBasicDetails::with('latestLoanScheme')
            ->whereIn('id', $borrower_ids)
            ->get()
            ->mapWithKeys(fn (LoanBasicDetails $loan) => [
                $loan->id => $this->buildEmiPayload($loan)['emi_amount'],
            ])
            ->all();
    }

    protected function buildEmiPayload(LoanBasicDetails $loan): array
    {
        $scheme        = $loan->latestLoanScheme;
        $interest_rate = (float) ($scheme->interest ?? 0);
        $interest_type = (string) ($scheme->interest_type ?? 'flat');
        $iot_amount    = (float) ($scheme->iot ?? 0);
        $tenure        = (int) ($loan->loan_term ?? 0);

        $second_year_insurance = ($tenure > 15 && $tenure <= 24)
            ? (float) ($scheme->insurance_2nd_year ?? 0)
            : 0.0;

        $final_loan_amount = (float) $loan->loan_amount + $iot_amount + $second_year_insurance;

        $emi_amount = ($tenure > 0 && $final_loan_amount > 0)
            ? $this->getEmiAmount($final_loan_amount, $interest_rate, $tenure, $interest_type)
            : 0;

        return [
            'emi_amount'            => $emi_amount,
            'final_loan_amount'     => $final_loan_amount,
            'interest_rate'         => $interest_rate,
            'tenure'                => $tenure,
            'interest_type'         => $interest_type,
            'iot_amount'            => $iot_amount,
            'second_year_insurance' => $second_year_insurance,
        ];
    }

    protected function emptyEmiPayload(): array
    {
        return [
            'emi_amount'            => 0,
            'final_loan_amount'     => 0.0,
            'interest_rate'         => 0.0,
            'tenure'                => 0,
            'interest_type'         => 'flat',
            'iot_amount'            => 0.0,
            'second_year_insurance' => 0.0,
        ];
    }
}