<?php

declare(strict_types=1);

namespace App\Services\Loan;

use App\Models\{
    User,
    LoanBasicDetails,
    LoanApplicationSummary,
    BorrowerLoanScheme,
    Dealer,
    LenderDealerGroup,
    ProductModel,
    OemDynamicProduct
};
use App\Traits\LogsApplicationStep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Validator};
use Throwable;

final class LoanBasicDetailsService
{
    use LogsApplicationStep;

    /** @return array{ok:bool,code:int,message:string,data?:mixed} */
    public function store(Request $request, string $channel): array
    {
        $cfg = [
            'b2c'   => ['actor_field' => null,            'log' => 'b2c',   'full' => false],
            'admin' => ['actor_field' => 'admin_user_id', 'log' => 'admin', 'full' => true],
            'b2b'   => ['actor_field' => 'executive_id',  'log' => 'b2b',   'full' => true],
        ][$channel];

        $rules = [
            'preferred_language' => 'required|string',
            'product_id'         => 'required',
            'dealer_id'          => 'required|string',
            'model'              => 'required|string',
            'model_id'           => 'required|string',
            'loan_amount'        => 'required|numeric',
            'loan_term'          => 'required|numeric',
            'borrower_id'        => 'nullable|string',
        ];
        if ($channel !== 'b2c') $rules['user_id'] = 'required|integer';

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return ['ok' => false, 'code' => 422, 'message' => $validator->errors()->first()];
        }

        $userId = $channel === 'b2c' ? $request->user()->id : (int) $request->user_id;

        DB::beginTransaction();
        try {
            date_default_timezone_set('Asia/Kolkata');

            // if (LoanBasicDetails::where('user_id', $userId)->exists()) {
            //     return $this->fail('An application already exists');
            // }

            $user = User::find($userId);

            // if (!$user) {
            //     return $this->fail('Invalid user');
            // }

            // if (!$user->approve_multi && LoanBasicDetails::where('user_id', $userId)->exists()) {
            //     return $this->fail('An application already exists');
            // }

            if (!$user) {
                return $this->fail('Invalid user');
            }

            $applicationCount = LoanBasicDetails::where('user_id', $userId)->count();

            if (!$user->approve_multi && $applicationCount >= 1) {
                return $this->fail('An application already exists');
            }

            if ($user->approve_multi && $applicationCount >= 3) {
                return $this->fail('Maximum 3 loan applications are allowed');
            }

            $dealer = Dealer::where('dealer_unq', $request->dealer_id)->first();
            if (!$dealer) return $this->fail('Invalid dealer id');

            // detect source: dynamic (FIN-DYP) vs legacy
            $dynamic = OemDynamicProduct::where('oem_product_unq', $request->model_id)->first();
            $legacy  = $dynamic ? null : ProductModel::where('model_unq', $request->model_id)->first();
            if (!$dynamic && !$legacy) return $this->fail('Invalid product model');

            // $d = $dynamic ? $this->dynamicDefaults($dynamic) : null;   // was $range = ... dynamicRange
            // $maxAllowed = $dynamic ? $d['max_amount'] : (float) $legacy->max_loan_amount;

            $maxAllowed = $dynamic ? (float) $dynamic->max_loan_amount : (float) $legacy->max_loan_amount;
            if ((float) $request->loan_amount > $maxAllowed) {
                return $this->fail("Max loan amount allowed for this product is " . $maxAllowed);
            }

            $lenderId = LenderDealerGroup::where('dealer_id', $dealer->id)
                ->where('status', 'active')->value('lender_id');

            $steps = json_encode([['step' => 1, 'details' => 'Loan Basic Details', 'status' => 'COMPLETED']]);

            $lbd                         = new LoanBasicDetails;
            $lbd->user_id                = $userId;
            $lbd->borrower_id            = $request->borrower_id ?? '';
            $lbd->loan_application_steps = $steps;
            $lbd->preferred_language     = $request->preferred_language;
            $lbd->dealer_id              = $request->dealer_id;
            $lbd->model                  = $request->model;
            $lbd->loan_amount            = $request->loan_amount;
            $lbd->loan_term              = $request->loan_term;
            $lbd->status                 = 'NEW';
            $lbd->product_id             = $request->product_id;
            $lbd->model_id               = $request->model_id;
            $lbd->lender_id              = $lenderId;
            if ($cfg['actor_field']) $lbd->{$cfg['actor_field']} = $request->user()->id;
            $lbd->save();

            if (empty($request->borrower_id)) {
                $this->generateBorrowerId($lbd->id);
            }

            $summary              = new LoanApplicationSummary;
            $summary->borrower_id = $lbd->id;
            $summary->user_id     = $userId;
            $summary->product_id  = $request->product_id;
            $summary->loan_amount = $request->loan_amount;
            $summary->tenure      = $request->loan_term;
            $summary->save();

            $this->storeScheme($lbd->id, $dynamic, $legacy);

            $this->logStep($lbd->id, 1, 'Loan Basic Details', $cfg['log']);

            DB::commit();
            return [
                'ok'      => true,
                'code'    => 201,
                'message' => 'Basic details stored successfully',
                'data'    => $cfg['full'] ? $lbd : '',
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            return ['ok' => false, 'code' => 422, 'message' => 'An unknown error occurred: ' . $e->getMessage()];
        }
    }

    private function fail(string $msg): array
    {
        DB::rollBack();
        return ['ok' => false, 'code' => 422, 'message' => $msg];
    }

    private function generateBorrowerId(int $id): string
    {
        $lbd = LoanBasicDetails::find($id);
        $borrowerId = 'FIN-' . str_pad((string) $lbd->id, 4, '0', STR_PAD_LEFT);
        $lbd->borrower_id = $borrowerId;
        $lbd->save();
        return $borrowerId;
    }

    /** v1 scheme: application-time snapshot, envelope only for dynamic. */
    private function storeScheme(int $borrowerId, ?OemDynamicProduct $dynamic, ?ProductModel $legacy): void
    {
        BorrowerLoanScheme::where('borrower_id', $borrowerId)->update(['is_current' => false]);

        $nextVersion = (BorrowerLoanScheme::withoutGlobalScope('current')
            ->where('borrower_id', $borrowerId)->max('version') ?? 0) + 1;

        $s = new BorrowerLoanScheme();
        $s->borrower_id = $borrowerId;
        $s->version     = $nextVersion;
        $s->is_current  = true;

        if ($dynamic) {
            $s->pricing_source      = 'oem_dynamic';
            $s->product_id          = $dynamic->id;
            $s->ex_showroom_price   = $dynamic->ex_showroom_price ?? 0;
            $s->loan_to_value_ratio = $dynamic->loan_to_value_ratio ?? '';
            $s->min_loan_amount     = $dynamic->min_loan_amount ?? 0;
            $s->max_loan_amount     = $dynamic->max_loan_amount ?? 0;
            $s->min_loan_tenure     = $dynamic->min_loan_tenure ?? 0;
            $s->max_loan_tenure     = $dynamic->max_loan_tenure ?? 0;
            $s->interest_type       = $dynamic->interest_type;
            $s->interest            = $dynamic->interest ?? 0;
            $s->processing_fee      = $dynamic->processing_fee ?? 0;
            $s->subvention_amount   = $dynamic->subvention_amount ?? 0;
            $s->fldg                = $dynamic->fldg ?? 0;
            $s->iot                 = $dynamic->iot ?? 0;
            $s->insurance_1st_year  = $dynamic->insurance_1st_year ?? 0;
            $s->insurance_2nd_year  = $dynamic->insurance_2nd_year ?? 0;
        } else {
            $s->pricing_source      = 'legacy';
            $s->product_id          = $legacy->id;
            $s->ex_showroom_price   = $legacy->ex_showroom_price ?? 0;
            $s->loan_to_value_ratio = $legacy->loan_to_value_ratio ?? '';
            $s->min_loan_amount     = $legacy->min_loan_amount ?? 0;
            $s->max_loan_amount     = $legacy->max_loan_amount ?? 0;
            $s->min_loan_tenure     = $legacy->min_loan_tenure ?? 0;
            $s->max_loan_tenure     = $legacy->max_loan_tenure ?? 0;
            $s->interest            = $legacy->interest ?? 0;
            $s->processing_fee      = $legacy->processing_fee ?? 0;
            $s->subvention_amount   = $legacy->subvention_amount ?? 0;
            $s->fldg                = $legacy->fldg ?? 0;
            $s->iot                 = $legacy->iot ?? 0;
            $s->insurance_1st_year  = $legacy->insurance_1st_year ?? 0;
            $s->insurance_2nd_year  = $legacy->insurance_2nd_year ?? 0;
            $s->interest_type       = $legacy->interest_type ?? null;
        }
        $s->save();
    }
}
