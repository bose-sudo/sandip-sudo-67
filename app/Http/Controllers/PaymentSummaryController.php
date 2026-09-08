<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\LoanBasicDetails;
use App\Traits\ApiResponse;
use App\Traits\ResolvesBaseQuery;
use App\Services\BorrowerVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Throwable;

class PaymentSummaryController extends Controller
{
    use ResolvesBaseQuery;
    use ApiResponse;

    public function __construct(
        HelperController $helper_controller,
        private readonly BorrowerVisibilityService $visibilityService
    ) {
        $this->helper_controller = $helper_controller;
    }

    public function get_payment_summary_report(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date'  => ['nullable', 'date'],
                'end_date'    => ['nullable', 'date', 'after_or_equal:start_date'],
                'month'       => ['nullable', 'string'],
                'year'        => ['nullable', 'integer'],
                'borrower_id' => ['nullable', 'regex:/^[a-zA-Z0-9\-]+$/'],
                'lender_id'   => ['nullable', 'integer'],
                'dealer_id'   => ['nullable', 'integer'],
                'rsm_id'      => ['nullable', 'integer'],
            ]);
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $tz   = 'Asia/Kolkata';
            $asOn = now($tz)->toDateString();

            // ── Period window (all metrics keyed on due_date) ─────────────────────
            if ($request->filled('start_date') && $request->filled('end_date')) {
                $pStart = Carbon::parse($request->start_date)->toDateString();
                $pEnd   = Carbon::parse($request->end_date)->toDateString();
            } else {
                $year  = $request->input('year', date('Y'));
                $month = $request->input('month', date('F')) ?: date('F');
                $base  = Carbon::parse("{$year}-{$month}-01");
                $pStart = $base->copy()->startOfMonth()->toDateString();
                $pEnd   = $base->copy()->endOfMonth()->toDateString();
            }
            // Current month → start..today; past month → full month (today > pEnd)
            $asOnRef = min($pEnd, $asOn);

            // ── Scope ─────────────────────────────────────────────────────────────
            [$scopedLoanQuery, , $empty] = $this->resolveBaseQuery(auth()->id(), $this->visibilityService);
            if ($empty) {
                return $this->helper_controller->send_response('success', 'Payment Summary Fetched', 200, $this->emptySummary($asOnRef, $pStart));
            }
            $borrowerIds = $scopedLoanQuery->pluck('id')->toArray();

            // ── Borrower-level filters → narrow IDs (keeps aggregate join-free) ───
            $filterQ = LoanBasicDetails::query()->where('status', 'DISBURSED')->whereIn('id', $borrowerIds);

            if ($request->filled('borrower_id')) {
                $input = $request->borrower_id;
                is_numeric($input)
                    ? $filterQ->where('id', $input)
                    : $filterQ->whereRaw('LOWER(borrower_id) LIKE ?', ['%' . strtolower($input) . '%']);
            }
            if ($request->filled('lender_id')) {
                $filterQ->whereExists(fn($q) => $q->select(DB::raw(1))->from('disbursement_details as dd')
                    ->whereColumn('dd.borrower_id', 'loan_basic_details.id')
                    ->where('dd.lender_id', $request->lender_id));
            }
            if ($request->filled('dealer_id')) {
                $filterQ->whereExists(fn($q) => $q->select(DB::raw(1))->from('dealers as d')
                    ->whereColumn('d.dealer_unq', 'loan_basic_details.dealer_id')
                    ->where('d.id', $request->dealer_id));
            }
            if ($request->filled('rsm_id')) {
                $rsm = AdminUser::where('id', $request->rsm_id)
                    ->where('user_type', AdminUser::RSM_USER)->where('is_deleted', false)->first();
                if ($rsm) {
                    $unqs = $this->visibilityService->resolveVisibleDealerUnqs($rsm->id);
                    $unqs->isNotEmpty() ? $filterQ->whereIn('dealer_id', $unqs) : $filterQ->whereRaw('1=0');
                }
            }
            $borrowerIds = $filterQ->pluck('id')->toArray();
            if (empty($borrowerIds)) {
                return $this->helper_controller->send_response('success', 'Payment Summary Fetched', 200, $this->emptySummary($asOnRef, $pStart));
            }

            // ── Single aggregate — everything by due_date, enach via EXISTS ───────
            $enach   = "EXISTS (SELECT 1 FROM enach_requests er
                                WHERE er.borrower_id = brs.borrower_id AND er.is_enach_activated = true)";
            $isEn    = "UPPER(brs.payment_mode)='ENACH'";
            $inMonth = "brs.due_date BETWEEN '{$pStart}' AND '{$asOnRef}'"; // demand + collection window
            $paid    = "brs.payment_status='PAID'";

            $r = DB::table('borrower_repayment_schedules as brs')
                ->whereIn('brs.borrower_id', $borrowerIds)
                ->selectRaw("
                    /* ── Outstanding (cumulative arrears, UNPAID, due <= as_on) ── */
                    COALESCE(SUM(brs.emi_amount) FILTER (WHERE brs.payment_status='UNPAID' AND brs.due_date <= '{$asOnRef}'), 0) AS os_total_amount,
                    COUNT(*)                     FILTER (WHERE brs.payment_status='UNPAID' AND brs.due_date <= '{$asOnRef}')     AS os_total_count,
                    COALESCE(SUM(brs.emi_amount) FILTER (WHERE brs.payment_status='UNPAID' AND brs.due_date <= '{$asOnRef}' AND {$enach}), 0)     AS os_enach_amount,
                    COUNT(*)                     FILTER (WHERE brs.payment_status='UNPAID' AND brs.due_date <= '{$asOnRef}' AND {$enach})         AS os_enach_count,
                    COALESCE(SUM(brs.emi_amount) FILTER (WHERE brs.payment_status='UNPAID' AND brs.due_date <= '{$asOnRef}' AND NOT {$enach}), 0) AS os_others_amount,
                    COUNT(*)                     FILTER (WHERE brs.payment_status='UNPAID' AND brs.due_date <= '{$asOnRef}' AND NOT {$enach})     AS os_others_count,

                    /* ── Aging (DPD on UNPAID) ── */
                    COALESCE(SUM(brs.emi_amount) FILTER (WHERE brs.payment_status='UNPAID' AND ('{$asOnRef}'::date - brs.due_date) BETWEEN 0 AND 30), 0)  AS aging_0_30,
                    COALESCE(SUM(brs.emi_amount) FILTER (WHERE brs.payment_status='UNPAID' AND ('{$asOnRef}'::date - brs.due_date) BETWEEN 31 AND 60), 0) AS aging_31_60,
                    COALESCE(SUM(brs.emi_amount) FILTER (WHERE brs.payment_status='UNPAID' AND ('{$asOnRef}'::date - brs.due_date) BETWEEN 61 AND 90), 0) AS aging_61_90,
                    COALESCE(SUM(brs.emi_amount) FILTER (WHERE brs.payment_status='UNPAID' AND ('{$asOnRef}'::date - brs.due_date) > 90), 0)             AS aging_90_plus,

                    /* ── Demand (EMIs due in window) ── */
                    COALESCE(SUM(brs.emi_amount) FILTER (WHERE {$inMonth}), 0) AS demand_total_amount,
                    COUNT(*)                     FILTER (WHERE {$inMonth})     AS demand_total_count,
                    COALESCE(SUM(brs.emi_amount) FILTER (WHERE {$inMonth} AND {$enach}), 0)     AS demand_enach_amount,
                    COUNT(*)                     FILTER (WHERE {$inMonth} AND {$enach})         AS demand_enach_count,
                    COALESCE(SUM(brs.emi_amount) FILTER (WHERE {$inMonth} AND NOT {$enach}), 0) AS demand_others_amount,
                    COUNT(*)                     FILTER (WHERE {$inMonth} AND NOT {$enach})     AS demand_others_count,

                    /* ── Collection (PAID EMIs due in window) ── */
                    COALESCE(SUM(brs.total_payment) FILTER (WHERE {$paid} AND {$inMonth}), 0) AS col_total_amount,
                    COUNT(*)                        FILTER (WHERE {$paid} AND {$inMonth})     AS col_total_count,

                    COALESCE(SUM(brs.total_payment) FILTER (WHERE {$paid} AND {$inMonth} AND {$isEn} AND COALESCE(brs.presentment_status,'') <> 'failed'), 0) AS col_enach_amount,
                    COUNT(*)                        FILTER (WHERE {$paid} AND {$inMonth} AND {$isEn} AND COALESCE(brs.presentment_status,'') <> 'failed')     AS col_enach_count,

                    COALESCE(SUM(brs.total_payment) FILTER (WHERE {$paid} AND {$inMonth} AND {$isEn} AND brs.presentment_status='failed'), 0) AS col_lender_enach_amount,
                    COUNT(*)                        FILTER (WHERE {$paid} AND {$inMonth} AND {$isEn} AND brs.presentment_status='failed')     AS col_lender_enach_count,

                    COALESCE(SUM(brs.total_payment) FILTER (WHERE {$paid} AND {$inMonth} AND (UPPER(brs.payment_mode) <> 'ENACH' OR brs.payment_mode IS NULL)), 0) AS col_others_amount,
                    COUNT(*)                        FILTER (WHERE {$paid} AND {$inMonth} AND (UPPER(brs.payment_mode) <> 'ENACH' OR brs.payment_mode IS NULL))     AS col_others_count,

                    /* ── Principal / Interest / Fine collected (PAID, due in window) ── */
                    COALESCE(SUM(brs.principal_amount) FILTER (WHERE {$paid} AND {$inMonth}), 0) AS principal_collected,
                    COALESCE(SUM(brs.interest_amount)  FILTER (WHERE {$paid} AND {$inMonth}), 0) AS interest_collected,
                    COALESCE(SUM(brs.fine_amount)      FILTER (WHERE {$paid} AND {$inMonth}), 0) AS fine_collected,

                    /* ── NACH returned (UNPAID + bank FAILURE, due in window) ── */
                    COUNT(*) FILTER (
                        WHERE {$inMonth}
                          AND brs.payment_status='UNPAID'
                          AND EXISTS (
                              SELECT 1 FROM presentment_status_updates psu
                              WHERE psu.merchant_request_number = brs.merchant_request_number
                                AND UPPER(psu.status_at_bank) = 'FAILURE'
                          )
                    ) AS nach_returned_count
                ")
                ->first();
                    // /* ── NACH bounces (due in window) ── */
                    // COUNT(*) FILTER (WHERE {$inMonth} AND {$isEn} AND brs.presentment_status='failed') AS nach_returned_count

            $demand    = (float) $r->demand_total_amount;
            $collected = (float) $r->col_total_amount;

            $resultData = [
                'as_on_date' => $asOnRef,
                'period'     => ['start' => $pStart, 'end' => $asOnRef, 'basis' => 'EMI Date'],

                'outstanding' => [
                    'total'  => ['amount' => (float) $r->os_total_amount,  'count' => (int) $r->os_total_count],
                    'enach'  => ['amount' => (float) $r->os_enach_amount,  'count' => (int) $r->os_enach_count],
                    'others' => ['amount' => (float) $r->os_others_amount, 'count' => (int) $r->os_others_count],
                    'aging'  => [
                        '0_30'    => (float) $r->aging_0_30,
                        '31_60'   => (float) $r->aging_31_60,
                        '61_90'   => (float) $r->aging_61_90,
                        '90_plus' => (float) $r->aging_90_plus,
                    ],
                ],

                'demand' => [
                    'total'  => ['amount' => $demand,                          'count' => (int) $r->demand_total_count],
                    'enach'  => ['amount' => (float) $r->demand_enach_amount,  'count' => (int) $r->demand_enach_count],
                    'others' => ['amount' => (float) $r->demand_others_amount, 'count' => (int) $r->demand_others_count],
                ],

                'collection' => [
                    'total'        => ['amount' => $collected,                          'count' => (int) $r->col_total_count],
                    'enach'        => ['amount' => (float) $r->col_enach_amount,        'count' => (int) $r->col_enach_count],
                    'lender_enach' => ['amount' => (float) $r->col_lender_enach_amount, 'count' => (int) $r->col_lender_enach_count],
                    'others'       => ['amount' => (float) $r->col_others_amount,       'count' => (int) $r->col_others_count],
                    'principal'    => (float) $r->principal_collected,
                    'interest'     => (float) $r->interest_collected,
                    'fine'         => (float) $r->fine_collected,
                ],

                'metrics' => [
                    'collection_efficiency_pct' => $demand > 0 ? round($collected / $demand * 100, 2) : 0,
                    'outstanding_amount'        => (float) $r->os_total_amount,
                    'outstanding_count'         => (int) $r->os_total_count,
                    'nach_returned_count'       => (int) $r->nach_returned_count,
                ],
            ];

            return $this->helper_controller->send_response('success', 'Payment Summary Fetched', 200, $resultData);

        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', 'Something went wrong: ' . $e->getMessage(), 422);
        }
    }

    private function emptySummary(string $asOn, string $pStart): array
    {
        $ac = ['amount' => 0, 'count' => 0];
        return [
            'as_on_date'  => $asOn,
            'period'      => ['start' => $pStart, 'end' => $asOn, 'basis' => 'EMI Date'],
            'outstanding' => [
                'total' => $ac, 'enach' => $ac, 'others' => $ac,
                'aging' => ['0_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0],
            ],
            'demand'      => ['total' => $ac, 'enach' => $ac, 'others' => $ac],
            'collection'  => ['total' => $ac, 'enach' => $ac, 'lender_enach' => $ac, 'others' => $ac, 'principal' => 0, 'interest' => 0, 'fine' => 0],
            'metrics'     => ['collection_efficiency_pct' => 0, 'outstanding_amount' => 0, 'outstanding_count' => 0, 'nach_returned_count' => 0],
        ];
    }
}