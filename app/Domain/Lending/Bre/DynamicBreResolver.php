<?php

declare(strict_types=1);

namespace App\Domain\Lending\Bre;

use App\Domain\Lending\Bre\Engine\{RuleSetCompiler, RuleEvaluator, FeeCalculator, FactExtractor};
use App\Models\{OemDynamicProduct, LoanApplicationQuote};
use App\Traits\CalculatesLoanSchedule;

final class DynamicBreResolver
{
    use CalculatesLoanSchedule;

    public function __construct(
        private readonly RuleSetCompiler $compiler,
        private readonly RuleEvaluator   $evaluator,
        private readonly FeeCalculator   $fee,
        private readonly FactExtractor   $facts,
    ) {}

    /**
     * @param array $ctx   caller-assembled context (credit_score, residence_type, pincode, state, gender, age, dealer_borrower_km)
     * @param int   $loanAmount  requested amount
     * @param int   $tenure      requested months
     */
    public function resolve(
        OemDynamicProduct $product,
        ?int $dealerId,
        array $ctx,
        int $loanAmount,
        int $tenure,
        int $applicationId,
        string $stage = 'offer'
    ): array {
        $facts = $this->facts->normalize($ctx);
        $set   = $this->compiler->compile($product, $dealerId);

        $matched = [];
        $reasons = [];

        // 1) hard gates (constraints)
        $matcher = app(\App\Domain\Lending\Bre\Engine\ConditionMatcher::class);

        foreach ($this->evaluator->constraints($set) as $rule) {
            $out  = $rule->output;
            $gate = $out['gate'] ?? null;

            if ($gate === 'distance'
                && $facts['dealer_borrower_km'] > (float) $out['max_km']) {
                $reasons['distance'] = "Distance exceeds {$out['max_km']} km";
            }

            if ($gate === 'rented_pincode' && $facts['residence_type'] === 'rented'
                && !$matcher->allMatch($rule->conditions, $facts)) {
                $reasons['rented_pincode'] = 'Rented residence not allowed in this pincode';
            }

            if ($gate === 'require' && !$matcher->allMatch($rule->conditions, $facts)) {
                $reasons[$out['reason_key'] ?? 'eligibility'] = $out['message'] ?? 'Eligibility condition not met';
            }

            if ($gate === 'require_any') {
                $applies = $rule->conditions->isEmpty() || $matcher->allMatch($rule->conditions, $facts);
                if ($applies) {
                    $anyTrue = false;
                    foreach (($out['facts'] ?? []) as $f) {
                        if (!empty($facts[$f])) { $anyTrue = true; break; }
                    }
                    if (!$anyTrue) {
                        $reasons[$out['reason_key'] ?? 'eligibility'] = $out['message'] ?? 'Required party missing';
                    }
                }
            }
        }

        // 2) pricing (first-match per type)
        $funding = $this->evaluator->first($set, 'funding', $facts);
        $roi     = $this->evaluator->first($set, 'roi', $facts);
        $tenureR = $this->evaluator->first($set, 'tenure', $facts);
        $feeR    = $this->evaluator->first($set, 'processing_fee', $facts);
        $comps   = $this->evaluator->collectComponents($set, $facts);

        // funding: rule {min,amount} OR product columns
        $fundMin = $funding ? (float) ($funding['output']['min'] ?? 0)      : (float) ($product->min_loan_amount ?? 0);
        $fundMax = $funding ? (float) ($funding['output']['amount'] ?? 0)   : (float) ($product->max_loan_amount ?? 0);

        // min-loan floor is the only funding-side rejection
        if ($fundMin > 0 && $loanAmount < $fundMin) {
            $reasons['loan_amount'] = "Loan amount must be at least ₹" . number_format($fundMin);
        }

        $eligible = empty($reasons);

        $resolved = null;
        if ($eligible) {
            $fundAmount = min($fundMax, (float) $loanAmount);

            // roi: rule OR product columns
            $rate    = $roi ? (float) $roi['output']['rate']              : (float) ($product->interest ?? 0);
            $intType = $roi ? ($roi['output']['interest_type'] ?? $product->interest_type) : $product->interest_type;

            // processing fee: rule OR product flat column (GST inclusive)
            $feeCalc = $feeR
                ? $this->fee->compute($feeR['output'], $fundAmount)
                : $this->fee->compute(['mode' => 'flat', 'amount' => (float) ($product->processing_fee ?? 0), 'gst' => 'inclusive'], $fundAmount);

            // component amounts: rule per key OR product column
            $c = fn(string $k, float $default) => isset($comps[$k])
                ? (float) ($comps[$k]['output']['amount'] ?? 0)
                : $default;

            $iot        = $c('iot',          (float) ($product->iot ?? 0));
            $ins1       = $c('insurance_y1', (float) ($product->insurance_1st_year ?? 0));
            $ins2raw    = $c('insurance_y2', (float) ($product->insurance_2nd_year ?? 0));
            $ins2       = ($tenure > 15 && $tenure <= 24) ? $ins2raw : 0.0;
            $subvention = $c('subvention',   (float) ($product->subvention_amount ?? 0));
            $fldg       = $c('fldg',         (float) ($product->fldg ?? 0));

            $netLoan     = $fundAmount + $iot + $ins2;
            $netDisbursal = $netLoan - ($feeCalc['total'] + $subvention + $ins1 + $fldg + $iot + $ins2);

            $emi = $this->generateInterestSchedule($netLoan, $rate, $tenure, $intType);

            $matched = array_filter([
                'funding' => $funding['rule_id'] ?? null,
                'roi'     => $roi['rule_id'] ?? null,
                'tenure'  => $tenureR['rule_id'] ?? null,
                'fee'     => $feeR['rule_id'] ?? null,
                'components' => array_map(fn($x) => $x['rule_id'], $comps),
            ]);

            $resolved = [
                'funding_min'   => $fundMin,
                'funding_cap'   => $fundMax,
                'loan_amount'   => $fundAmount,
                'roi'           => $rate,
                'interest_type' => $intType,
                'tenure'        => [
                    'min' => $tenureR ? ($tenureR['output']['min'] ?? null) : ($product->min_loan_tenure ?? null),
                    'max' => $tenureR ? ($tenureR['output']['max'] ?? null) : ($product->max_loan_tenure ?? null),
                    'selected' => $tenure,
                ],
                'processing_fee' => $feeCalc,
                'components'    => ['iot' => $iot, 'insurance_y1' => $ins1, 'insurance_y2' => $ins2, 'subvention' => $subvention, 'fldg' => $fldg],
                'net_loan_amount' => round($netLoan, 2),
                'net_disbursal'   => round($netDisbursal, 2),
                'emi_amount'      => $emi['emi_amount'],
                'repayment_schedule' => $emi['schedule'],
            ];
        }

        // 3) snapshot
        LoanApplicationQuote::create([
            'application_id'   => $applicationId,
            'product_id'       => $product->id,
            'default_set_id'   => $set->defaultSetId,
            'dealer_set_id'    => $set->dealerSetId,
            'stage'            => $stage,
            'eligible'         => $eligible,
            'facts'            => $facts,
            'resolved'         => $resolved,
            'matched_rule_ids' => $matched,
            'reasons'          => $eligible ? null : $reasons,
        ]);

        return ['eligible' => $eligible, 'reasons' => $reasons, 'resolved' => $resolved];
    }
}
