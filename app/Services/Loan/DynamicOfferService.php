<?php
declare(strict_types=1);

namespace App\Services\Loan;

use App\Domain\Lending\Bre\DynamicBreResolver;
use App\Models\{OemDynamicProduct, BorrowerLoanScheme, Dealer, BorrowerKyc, BorrowerAddress,
                CoapplicantKyc, Guarantor, GuarantorOccupation, LoanApplicationSummary};
use App\Traits\LogsApplicationStep;
use App\Traits\CalculatesLoanSchedule;

final class DynamicOfferService
{
    use LogsApplicationStep;
    use CalculatesLoanSchedule;

    public function __construct(
        private readonly DynamicBreResolver $resolver,
        private readonly \App\Http\Controllers\HelperController $helper_controller
    ) {}

    /**
     * Resolve a dynamic-product offer.
     * @param callable $sanction  fn($borrower_id) => void  (controller passes its sendSanctionMail)
     * @param callable $stepUpdate fn() => void             (controller handles updateApplicationStep — differing arg counts)
     * @return array{code:int, body:array}
     */
    public function resolve(
        OemDynamicProduct $product,
        $loan_basic_details,
        LoanApplicationSummary $summary,
        int|string $borrower_id,
        string $channel,
        callable $sanction,
        callable $stepUpdate
    ): array {
        $loan_amount = intval($loan_basic_details->loan_amount);
        $tenure      = intval($loan_basic_details->loan_term);
        $dealerPk = Dealer::where('dealer_unq', $loan_basic_details->dealer_id)->value('id')
            ?? (is_numeric($loan_basic_details->dealer_id) ? (int) $loan_basic_details->dealer_id : null);

        $ctx = $this->buildContext($borrower_id, $summary);

        $out = $this->resolver->resolve($product, $dealerPk, $ctx, $loan_amount, $tenure, (int) $borrower_id, 'offer');

        if (!$out['eligible']) {
            return ['code' => 422, 'body' => [
                'status'  => 'failed',
                'message' => 'You are not eligible',
                'reason'  => $out['reasons'],
                // 'debug' => $this->debugDump($product, $dealerPk, $ctx), // TODO remove
            ]];
        }

        $r = $out['resolved'];
        $this->writeSchemeV2($borrower_id, $product, $r);

        $emi     = $this->generateInterestSchedule($r['net_loan_amount'], $r['roi'], $tenure, $r['interest_type']);
        $emiAmt  = $emi['emi_amount'];
        $chart   = $emi['schedule'];

        $offers = [[
            'id' => "2", 'lender_id' => "3", 'lender' => "Offer 2",
            'loan_amount'   => intval($r['net_loan_amount']),
            'interest'      => $r['roi'],
            'terms'         => $tenure . " month",
            'emi_per_month' => number_format($emiAmt, 2, ".", ""),
            'loan_details_break_down' => [
                'loan_amount' => intval($r['loan_amount']),
                'roi' => floatval($r['roi']),
                'tenure' => $tenure . ' months',
                'iot_amount' => $r['components']['iot'],
                'first_year_insurance_amount' => $r['components']['insurance_y1'],
                'second_year_insurance_amount' => $r['components']['insurance_y2'],
                'processing_fee' => $r['processing_fee']['total'],
                'subvention_amount' => $r['components']['subvention'],
                'fldg' => $r['components']['fldg'],
                'net_loan_amount' => $r['net_loan_amount'],
                'net_disbursal' => $r['net_disbursal'],
                'emi' => round($emiAmt),
            ],
            'repayment_schedule' => $chart,
        ]];

        // $sanction($borrower_id);
        $stepUpdate();                       // controller-specific arg count handled by closure
        $this->logStep($borrower_id, 9, 'Loan Offers Completed', $channel);

        return ['code' => 200, 'body' => [
            'status'  => 'success',
            'message' => 'You are eligible for loan',
            'data'    => $ctx,
            'offers'  => $offers,
        ]];
    }

    private function buildContext(int|string $borrower_id, LoanApplicationSummary $summary): array
    {
        $kyc     = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
        $address = BorrowerAddress::where('borrower_id', $borrower_id)->latest()->first();
        $coApp   = CoapplicantKyc::where('borrower_id', $borrower_id)->latest()->first();
        $guar    = Guarantor::where('borrower_id', $borrower_id)->latest()->first();
        $guarOcc = GuarantorOccupation::where('borrower_id', $borrower_id)->latest()->first();

        $age = 0;
        if (!empty($kyc?->dob_date)) {
            $ts  = strtotime($kyc->dob_date);
            $age = date('Y') - date('Y', $ts);
            if (date('md') < date('md', $ts)) $age--;
        }

        $addr  = $this->helper_controller->user_address_details($borrower_id, 'BORROWER');
        $state = ($addr['status'] ?? '') === 'success' ? ($addr['data']['full_address']['state'] ?? '') : '';
        $pin   = ($addr['status'] ?? '') === 'success' ? ($addr['data']['full_address']['pc'] ?? '') : '';

        $skipCo  = $kyc->skip_co_applicant ?? false;
        $skipGua = $kyc->skip_guarantor ?? false;

        return [
            'credit_score'         => $summary->cibil_score ?? 0,
            'gender'               => strtolower((string) ($kyc->gender ?? '')),
            'state'                => $state,
            'pincode'              => (string) $pin,
            'residence_type'       => strtolower((string) ($address->resident_type ?? '')),
            'years_at_address'     => (float) ($address->years_living ?? 0),
            'age'                  => $age,
            'has_co_applicant'     => !empty($coApp?->id) && !$skipCo,
            'has_guarantor'        => !empty($guar?->id) && !$skipGua,
            'guarantor_owns_house' => $guarOcc ? strtolower((string) $guarOcc->resident_type) === 'owned' : false,
            'dealer_borrower_km'   => 0,
        ];
    }

    private function writeSchemeV2(int|string $borrowerId, OemDynamicProduct $product, array $r): void
    {
        BorrowerLoanScheme::where('borrower_id', $borrowerId)->update(['is_current' => false]);
        $nextVersion = (BorrowerLoanScheme::withoutGlobalScope('current')
            ->where('borrower_id', $borrowerId)->max('version') ?? 0) + 1;

        $s = new BorrowerLoanScheme();
        $s->borrower_id        = $borrowerId;
        $s->version            = $nextVersion;
        $s->is_current         = true;
        $s->pricing_source     = 'oem_dynamic';
        $s->product_id         = $product->id;
        $s->ex_showroom_price  = $product->ex_showroom_price ?? 0;
        $s->loan_to_value_ratio= $product->loan_to_value_ratio ?? '';
        $s->min_loan_amount    = $r['funding_min'] ?? 0;
        $s->max_loan_amount    = $r['funding_cap'] ?? $r['loan_amount'];
        $s->min_loan_tenure    = $r['tenure']['min'] ?? 0;
        $s->max_loan_tenure    = $r['tenure']['max'] ?? 0;
        $s->interest           = $r['roi'];
        $s->interest_type      = $r['interest_type'];
        $s->processing_fee     = $r['processing_fee']['total'];
        $s->subvention_amount  = $r['components']['subvention'];
        $s->fldg               = $r['components']['fldg'];
        $s->iot                = $r['components']['iot'];
        $s->insurance_1st_year = $r['components']['insurance_y1'];
        $s->insurance_2nd_year = $r['components']['insurance_y2'];
        $s->loan_amount        = $r['loan_amount'];
        $s->total_interest     = round(array_sum(array_column($r['repayment_schedule'], 'interest')), 2);
        $s->loan_emi           = $r['emi_amount'];
        $s->save();
    }

    // TODO: DEBUG ONLY — remove before production
    public function debugDump(OemDynamicProduct $product, ?int $dealerPk, array $ctx): array
    {
        $facts   = app(\App\Domain\Lending\Bre\Engine\FactExtractor::class)->normalize($ctx);
        $set     = app(\App\Domain\Lending\Bre\Engine\RuleSetCompiler::class)->compile($product, $dealerPk);
        $matcher = app(\App\Domain\Lending\Bre\Engine\ConditionMatcher::class);

        $rulesTrace = [];
        foreach ($set->byType as $type => $rules) {
            foreach ($rules as $rule) {
                $conds = $rule->conditions->map(fn ($c) => [
                    'attribute' => $c->attribute, 'operator' => $c->operator, 'expected' => $c->value,
                    'borrower_has' => $facts[$c->attribute] ?? '(missing)',
                    'pass' => array_key_exists($c->attribute, $facts) && $matcher->allMatch(collect([$c]), $facts),
                ])->all();
                $rulesTrace[] = ['type' => $type, 'priority' => $rule->priority, 'output' => $rule->output,
                    'all_pass' => $matcher->allMatch($rule->conditions, $facts), 'conditions' => $conds];
            }
        }
        return ['facts' => $facts, 'default_set_id' => $set->defaultSetId, 'dealer_set_id' => $set->dealerSetId, 'rules' => $rulesTrace];
    }
}