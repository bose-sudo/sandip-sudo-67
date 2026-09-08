<?php

namespace App\Http\Controllers;

use App\Models\BorrowerIdentifier;
use App\Models\BorrowerIdentifierMapping;
use App\Models\LoanApplicationSummary;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImpactDashboardController extends Controller
{
    use ApiResponse;
    protected $helper_controller;

    const SUPER_USER_TYPE = 13;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    // ─────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────

    private function isSuperUser(): bool
    {
        return auth()->user()->user_type === self::SUPER_USER_TYPE;
    }

    private function getUserBorrowerIds(): array
    {
        $userId = auth()->user()->id;

        // Step 1: find the identifier whose `users` JSON array contains this user
        $identifier = BorrowerIdentifier::whereRaw(
            'users @> ?::jsonb',
            [json_encode([$userId])]
        )->first();

        if (!$identifier) {
            return [];
        }

        // Step 2: get all active borrower_ids for that identifier
        return BorrowerIdentifierMapping::where('identifier_id', $identifier->id)
            ->where('row_status', 'A')
            ->pluck('borrower_id')
            ->toArray();
    }

    /**
     * Apply borrower_id filter directly on loan_application_summaries queries.
     * Pass the fully-qualified column when the table is aliased, e.g. 'las.borrower_id'
     */
    private function applyLasFilter($query, string $column = 'las.borrower_id'): void
    {
        if (!$this->isSuperUser()) {
            // Cast to string to match varchar borrower_id in loan_application_summaries
            $ids = array_map('strval', $this->getUserBorrowerIds());
            $query->whereIn($column, $ids);
        }
    }

    /**
     * Apply borrower filter on borrower_repayment_schedules queries
     * by scoping through loan_application_summaries via borrower_id.
     */
    private function applyRepaymentFilter($query): void
    {
        if (!$this->isSuperUser()) {
            // bigint borrower_id in repayment schedules — no cast needed
            $query->whereIn('borrower_id', $this->getUserBorrowerIds());
        }
    }

    // ─────────────────────────────────────────────
    //  ENDPOINTS
    // ─────────────────────────────────────────────

    public function cibilDistribution(Request $request)
    {
        $month = (int) $request->input('month', now()->month);
        $year  = (int) $request->input('year', now()->year);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        $query = DB::table('loan_application_summaries as las')
            ->whereNotNull('cibil_score')
            ->whereBetween('created_at', [$start, $end]);

        $this->applyLasFilter($query);

        $counts = $query->selectRaw("
            COUNT(*) FILTER (WHERE cibil_score < 650)            AS below_650,
            COUNT(*) FILTER (WHERE cibil_score BETWEEN 650 AND 750) AS between_650_750,
            COUNT(*) FILTER (WHERE cibil_score > 750)            AS above_750
        ")->first();

        $data = [
            'labels'   => ['< 650', '650-750', '> 750'],
            'datasets' => [
                [
                    'data' => [
                        (int) $counts->below_650,
                        (int) $counts->between_650_750,
                        (int) $counts->above_750,
                    ],
                    'backgroundColor' => ['#6366f1', '#22c55e', '#f97316'],
                    'borderWidth'     => 0,
                ]
            ]
        ];

        return $this->helper_controller->send_response('success', "Cibil distribution fetched", 200, $data);
    }

    public function inclusionStats(Request $request)
    {
        $month = (int) $request->input('month', now()->month);
        $year  = (int) $request->input('year', now()->year);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        // $baseQuery = DB::table('loan_application_summaries')
        //     ->whereBetween('las.created_at', [$start, $end]);

        // $this->applyLasFilter($baseQuery);

        $baseQuery = DB::table('loan_application_summaries as las')
            ->whereBetween('las.created_at', [$start, $end]);

        $this->applyLasFilter($baseQuery);
        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            return response()->json([
                'labels'   => ['New to Credit', 'Women', 'Daily Wage(Pre E-Rickshaw)', 'Living on Rent'],
                'datasets' => [[
                    'label'           => '%',
                    'data'            => [0, 0, 0, 0],
                    'backgroundColor' => '#6366f1',
                    'borderRadius'    => 12,
                ]]
            ]);
        }

        // 1️⃣ New to Credit (cibil_score = 0 OR NULL)
        // $newToCredit = (clone $baseQuery)
        //     ->where(function ($q) {
        //         $q->whereNull('cibil_score')
        //             ->orWhere('cibil_score', 0);
        //     })
        //     ->count();

        // // 2️⃣ Women borrowers
        // $women = (clone $baseQuery)
        //     ->from('loan_application_summaries as las')
        //     ->join('borrower_kycs as bk', DB::raw('bk.borrower_id::text'), '=', 'las.borrower_id')
        //     ->whereBetween('las.created_at', [$start, $end])
        //     ->whereRaw("LOWER(bk.gender) = 'female'")
        //     ->count();

        // // 3️⃣ Daily wage labour (occupation_type = 4)
        // $dailyWage = (clone $baseQuery)
        //     ->from('loan_application_summaries as las')
        //     ->join('borrower_occupations as bo', DB::raw('bo.borrower_id::text'), '=', 'las.borrower_id')
        //     ->whereBetween('las.created_at', [$start, $end])
        //     ->where('bo.occupation_type', 4)
        //     ->count();

        // // 4️⃣ Living on Rent
        // $livingOnRent = (clone $baseQuery)
        //     ->whereRaw("LOWER(resience_type) = 'rented'")
        //     ->count();
        $newToCredit = (clone $baseQuery)
            ->where(function ($q) {
                $q->whereNull('las.cibil_score')
                  ->orWhere('las.cibil_score', 0);
            })
            ->count();

        $women = (clone $baseQuery)
            ->join('borrower_kycs as bk', 'bk.borrower_id', '=', 'las.borrower_id')
            ->whereRaw("LOWER(bk.gender) = 'female'")
            ->count();

        $dailyWage = (clone $baseQuery)
            ->join('borrower_occupations as bo', 'bo.borrower_id', '=', 'las.borrower_id')
            ->where('bo.occupation_type', 4)
            ->count();

        $livingOnRent = (clone $baseQuery)
            ->whereRaw("LOWER(las.resience_type) = 'rented'")
            ->count();

        $percent = fn($v) => round(($v / $total) * 100);

        $data = [
            'labels'   => ['New to Credit', 'Women', 'Daily Wage(Pre E-Rickshaw)', 'Living on Rent'],
            'datasets' => [[
                'label'           => '%',
                'data'            => [
                    $percent($newToCredit),
                    $percent($women),
                    $percent($dailyWage),
                    $percent($livingOnRent),
                ],
                'backgroundColor' => '#6366f1',
                'borderRadius'    => 12,
            ]],
        ];

        return $this->helper_controller->send_response('success', "Inclustion set fetched", 200, $data);
    }

    public function livelyHoodImpact()
    {
        $data = [
            'labels'   => ["Income Growth", "Primary Earners"],
            'datasets' => [[
                'label'           => "%",
                'data'            => [0, 0],
                'backgroundColor' => "#22c55e",
                'borderRadius'    => 12,
            ]],
        ];

        return $this->helper_controller->send_response('success', "Livelyhood impacted data fetched", 200, $data);
    }

    public function portfolioAtRisk(Request $request)
    {
        $month = (int) $request->input('month', now()->month);
        $year  = (int) $request->input('year', now()->year);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        $baseQuery = DB::table('borrower_repayment_schedules')
            ->whereBetween('due_date', [$start, $end]);

        $this->applyRepaymentFilter($baseQuery);

        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            return response()->json([
                'labels'   => ['PAR 30', 'PAR 60', 'PAR 90', 'NPA'],
                'datasets' => [[
                    'label'           => 'Portfolio at Risk (%)',
                    'data'            => [0, 0, 0, 0],
                    'backgroundColor' => ['#facc15', '#f97316', '#dc2626', '#7f1d1d'],
                    'borderRadius'    => 12,
                    'barThickness'    => 95,
                ]]
            ]);
        }

        $rows = (clone $baseQuery)
            ->selectRaw("
                CASE
                    WHEN payment_date IS NOT NULL
                        THEN GREATEST((payment_date - due_date - 1), 0)
                    ELSE (CURRENT_DATE - due_date)
                END AS delay_days,
                payment_date
            ")
            ->get();

        $par30 = 0;
        $par60 = 0;
        $par90 = 0;
        $npa   = 0;

        foreach ($rows as $row) {
            $delay = (int) $row->delay_days;

            if ($delay > 30 && $delay <= 60) {
                $par30++;
            } elseif ($delay > 60 && $delay <= 90) {
                $par60++;
            } elseif ($delay > 90) {
                $par90++;
                if ($row->payment_date === null) {
                    $npa++;
                }
            }
        }

        $pct = fn($v) => round(($v / $total) * 100, 1);

        $data = [
            'labels'   => ['PAR 30', 'PAR 60', 'PAR 90', 'NPA'],
            'datasets' => [[
                'label'           => 'Portfolio at Risk (%)',
                'data'            => [$pct($par30), $pct($par60), $pct($par90), $pct($npa)],
                'backgroundColor' => ['#facc15', '#f97316', '#dc2626', '#7f1d1d'],
                'borderRadius'    => 12,
                'barThickness'    => 95,
            ]],
        ];

        return $this->helper_controller->send_response('success', "Portfolio at risk data fetched", 200, $data);
    }

    public function cibilJourney()
    {
        $data = [
            'labels'   => ['At Sanction', 'Latest', 'After 1 Year'],
            'datasets' => [[
                'label'           => 'Avg CIBIL',
                'data'            => [0, 0, 0],
                'borderColor'     => '#22c55e',
                'backgroundColor' => 'rgba(34,197,94,0.2)',
                'fill'            => true,
                'tension'         => 0.4,
                'pointRadius'     => 5,
            ]],
        ];

        return $this->helper_controller->send_response('success', 'Portfolio at risk data fetched', 200, $data);
    }

    public function paymentModeStats(Request $request)
    {
        $month = (int) $request->input('month', now()->month);
        $year  = (int) $request->input('year', now()->year);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        $baseQuery = DB::table('borrower_repayment_schedules')
            ->whereBetween('payment_date', [$start, $end]);

        $this->applyRepaymentFilter($baseQuery);

        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            $data = [
                'labels'   => ['UPI', 'Bank Transfer', 'Enach', 'Cheque'],
                'datasets' => [[
                    'data'            => [0, 0, 0, 0],
                    'backgroundColor' => ['#6366f1', '#22c55e', '#f97316', '#a855f7'],
                ]]
            ];

            return $this->helper_controller->send_response('success', 'Payment mode data fetched', 200, $data);
        }

        $upi = (clone $baseQuery)
            ->whereRaw("UPPER(payment_mode) = 'UPI'")
            ->count();

        $bankTransfer = (clone $baseQuery)
            ->whereRaw("UPPER(payment_mode) IN ('NEFT','IMPS')")
            ->count();

        $enach = (clone $baseQuery)
            ->whereRaw("UPPER(payment_mode) = 'ENACH'")
            ->count();

        $cheque = (clone $baseQuery)
            ->whereRaw("UPPER(payment_mode) = 'CHEQUE'")
            ->count();

        $percent = fn($v) => round(($v / $total) * 100);

        $data = [
            'labels'   => ['UPI', 'Bank Transfer', 'Enach', 'Cheque'],
            'datasets' => [[
                'data'            => [
                    $percent($upi),
                    $percent($bankTransfer),
                    $percent($enach),
                    $percent($cheque),
                ],
                'backgroundColor' => ['#6366f1', '#22c55e', '#f97316', '#a855f7'],
            ]],
        ];

        return $this->helper_controller->send_response('success', 'Payment mode data fetched', 200, $data);
    }

    public function homeStayStats(Request $request)
    {
        $month = (int) $request->input('month', now()->month);
        $year  = (int) $request->input('year', now()->year);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        $baseQuery = DB::table('loan_application_summaries as las')
            ->whereBetween('created_at', [$start, $end]);

        $this->applyLasFilter($baseQuery);

        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            $data = [
                'labels'   => ['Own House', 'Rental'],
                'datasets' => [[
                    'data'            => [0, 0],
                    'backgroundColor' => ['#22c55e', '#6366f1'],
                ]]
            ];

            return $this->helper_controller->send_response('success', 'Home stay data fetched', 200, $data);
        }

        $owned = (clone $baseQuery)
            ->whereRaw("LOWER(resience_type) = 'owned'")
            ->count();

        $rented = (clone $baseQuery)
            ->whereRaw("LOWER(resience_type) = 'rented'")
            ->count();

        $percent = fn($v) => round(($v / $total) * 100);

        $data = [
            'labels'   => ['Own House', 'Rental'],
            'datasets' => [[
                'data'            => [$percent($owned), $percent($rented)],
                'backgroundColor' => ['#22c55e', '#6366f1'],
            ]],
        ];

        return $this->helper_controller->send_response('success', 'Home stay data fetched', 200, $data);
    }

    public function incomeImpact()
    {
        $data = [
            'labels'   => ['Before E-Rickshaw', 'After E-Rickshaw'],
            'datasets' => [[
                'label'           => 'Avg Monthly Income (₹)',
                'data'            => [0, 0],
                'backgroundColor' => ['#f97316', '#22c55e'],
                'borderRadius'    => 12,
            ]],
        ];

        return $this->helper_controller->send_response('success', 'Income impact data fetched', 200, $data);
    }

    public function vehicleTypeStats(Request $request)
    {
        $month = (int) $request->input('month', now()->month);
        $year  = (int) $request->input('year', now()->year);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        $baseQuery = DB::table('loan_application_summaries as las')
            ->whereBetween('created_at', [$start, $end]);

        $this->applyLasFilter($baseQuery);

        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            return $this->helper_controller->send_response(
                'success',
                'Vehicle type data fetched',
                200,
                [
                    'labels'   => ['2 Wheeler', '3 Wheeler', 'L5', '4 Wheeler'],
                    'datasets' => [[
                        'data'            => [0, 0, 0, 0],
                        'backgroundColor' => ['#6366f1', '#22c55e', '#0ea5e9', '#f97316'],
                    ]]
                ]
            );
        }

        $twoWheeler   = (clone $baseQuery)->whereIn('product_id', [3])->count();
        $threeWheeler = (clone $baseQuery)->where('product_id', 1)->count();
        $l5           = (clone $baseQuery)->where('product_id', 2)->count();

        $percent = fn($v) => round(($v / $total) * 100);

        $data = [
            'labels'   => ['2 Wheeler', '3 Wheeler', 'L5', '4 Wheeler'],
            'datasets' => [[
                'data'            => [
                    $percent($twoWheeler),
                    $percent($threeWheeler),
                    $percent($l5),
                    0,
                ],
                'backgroundColor' => ['#6366f1', '#22c55e', '#0ea5e9', '#f97316'],
            ]],
        ];

        return $this->helper_controller->send_response('success', 'Vehicle type data fetched', 200, $data);
    }

    public function borrowerGenderSplit(Request $request)
    {
        $month = (int) $request->input('month', now()->month);
        $year  = (int) $request->input('year', now()->year);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        // NOTE: original used alias 'las' — we keep it and pass the qualified column
        $baseQuery = DB::table('loan_application_summaries as las')
            ->whereBetween('las.created_at', [$start, $end]);

        $this->applyLasFilter($baseQuery, 'las.borrower_id');

        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            return $this->helper_controller->send_response(
                'success',
                'Borrower gender split fetched',
                200,
                [
                    'labels'   => ['Male', 'Female'],
                    'datasets' => [[
                        'data'            => [0, 0],
                        'backgroundColor' => ['#2563eb', '#ec4899'],
                    ]]
                ]
            );
        }

        $male = (clone $baseQuery)
            ->join('borrower_kycs as bk', 'bk.borrower_id', '=', 'las.borrower_id')
            ->whereRaw("LOWER(bk.gender) = 'male'")
            ->count();

        $female = (clone $baseQuery)
            ->join('borrower_kycs as bk', 'bk.borrower_id', '=', 'las.borrower_id')
            ->whereRaw("LOWER(bk.gender) = 'female'")
            ->count();

        $percent = fn($v) => round(($v / $total) * 100);

        $data = [
            'labels'   => ['Male', 'Female'],
            'datasets' => [[
                'data'            => [$percent($male), $percent($female)],
                'backgroundColor' => ['#2563eb', '#ec4899'],
            ]],
        ];

        return $this->helper_controller->send_response('success', 'Borrower gender split fetched', 200, $data);
    }

    public function portfolioSnapshot(Request $request)
    {
        $month = (int) $request->input('month', now()->month);
        $year  = (int) $request->input('year', now()->year);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        // Resolve borrower IDs once — reused across all sub-queries below

        $borrowerIds = !$this->isSuperUser() ? $this->getUserBorrowerIds() : null;

        // Scoped loan_ids for repayment schedule filtering
        // $scopedLoanIds = $borrowerIds !== null
        //     ? DB::table('loan_application_summaries')
        //         ->whereIn('borrower_id', $borrowerIds)
        //         ->pluck('borrower_id')
        //     : null;

        // Cast to string for loan_application_summaries (varchar borrower_id)
        $borrowerIdsAsText = $borrowerIds !== null
            ? array_map('strval', $borrowerIds)
            : null;

        /* ── ON-TIME PAYMENT % ── */
        $totalEmis = DB::table('borrower_repayment_schedules')
            ->whereBetween('due_date', [$start, $end])
            ->when($borrowerIds, fn($q) => $q->whereIn('borrower_id', $borrowerIds))
            ->count();

        $onTimeEmis = DB::table('borrower_repayment_schedules')
            ->whereBetween('due_date', [$start, $end])
            ->where('payment_status', 'PAID')
            ->whereBetween('payment_date', [$start, $end])
            ->whereRaw("payment_date <= due_date + INTERVAL '1 day'")
            ->when($borrowerIds, fn($q) => $q->whereIn('borrower_id', $borrowerIds))
            ->count();

        $onTimePct = $totalEmis > 0 ? round(($onTimeEmis / $totalEmis) * 100) : 0;

        /* ── DIGITAL REPAYMENT % ── */
        $digitalCount = DB::table('borrower_repayment_schedules')
            ->whereBetween('payment_date', [$start, $end])
            ->whereRaw("UPPER(payment_mode) != 'CHEQUE'")
            ->when($borrowerIds, fn($q) => $q->whereIn('borrower_id', $borrowerIds))
            ->count();

        $digitalPct = $totalEmis ? round(($digitalCount / $totalEmis) * 100) : 0;

        /* ── AVERAGES ── */
        $avgFamily = DB::table('borrower_addresses')
            ->whereBetween('created_at', [$start, $end])
            ->when($borrowerIds, fn($q) => $q->whereIn('borrower_id', $borrowerIds))
            ->avg('family_members') ?? 0;

        $avgEarning = DB::table('borrower_addresses')
            ->whereBetween('created_at', [$start, $end])
            ->when($borrowerIds, fn($q) => $q->whereIn('borrower_id', $borrowerIds))
            ->avg('earning_members') ?? 0;

        $avgLoanSanctioned = DB::table('loan_basic_details')
            ->where('status', 'DISBURSED')
            ->whereBetween('created_at', [$start, $end])
            ->when($borrowerIds, fn($q) => $q->whereIn('borrower_id', $borrowerIds))
            ->avg('loan_amount') ?? 0;

        $avgLoanDisbursed = DB::table('disbursement_details')
            ->whereBetween('disbursement_date', [$start, $end])
            ->when($borrowerIds, fn($q) => $q->whereIn('borrower_id', $borrowerIds))
            ->avg('disbursement_amount') ?? 0;

        $avgDownPayment = DB::table('loan_basic_details')
            ->whereBetween('created_at', [$start, $end])
            ->when($borrowerIds, fn($q) => $q->whereIn('borrower_id', $borrowerIds))
            ->avg('down_payment') ?? 0;

        /* ── OUTSTANDING & OVERDUE ── */
        $unpaid = DB::table('borrower_repayment_schedules')
            ->whereBetween('due_date', [$start, $end])
            ->where('payment_status', 'UNPAID')
            ->when($borrowerIds, fn($q) => $q->whereIn('borrower_id', $borrowerIds))
            ->sum('emi_amount');

        $paid = DB::table('borrower_repayment_schedules')
            ->whereBetween('payment_date', [$start, $end])
            ->where('payment_status', 'PAID')
            ->when($borrowerIds, fn($q) => $q->whereIn('borrower_id', $borrowerIds))
            ->sum('emi_amount');

        $outstanding = $unpaid - $paid;

        $currentOverdue = DB::table('borrower_repayment_schedules')
            ->where('payment_status', 'UNPAID')
            ->whereBetween('due_date', [$start, $end])
            ->where('due_date', '<', now())
            ->when($borrowerIds, fn($q) => $q->whereIn('borrower_id', $borrowerIds))
            ->sum('emi_amount');

        /* ── INTEREST ── */
        $avgInterest = DB::table('borrower_loan_schemes')
            ->whereBetween('created_at', [$start, $end])
            ->when($borrowerIds, fn($q) => $q->whereIn('borrower_id', $borrowerIds))
            ->avg('interest') ?? 0;

        /* ── LOAN TO INCOME RATIO ── */
        $avgIncome = DB::table('borrower_occupations')
            ->whereBetween('created_at', [$start, $end])
            ->when($borrowerIds, fn($q) => $q->whereIn('borrower_id', $borrowerIds))
            ->avg('monthly_earning') ?? 0;

        /* ── RESPONSE ── */
        $data = [
            'on_time_payment_percentage'   => $onTimePct,
            'interest_incentive_support'   => 0,
            'digital_repayment_percentage' => $digitalPct,
            'co2_saved_per_vehicle'        => 0,
            'avg_family_members'           => round($avgFamily),
            'avg_earning_members'          => round($avgEarning),
            'avg_loan_sanctioned'          => round($avgLoanSanctioned, 2),
            'avg_loan_disbursed'           => round($avgLoanDisbursed, 2),
            'down_payment_taken'           => round($avgDownPayment, 2),
            'loan_to_income_ratio'         => round($avgIncome, 2),
            'outstanding_amount'           => round($outstanding, 2),
            'current_overdue'              => round($currentOverdue, 2),
            'interest_rate_pa'             => round($avgInterest, 2),
            'interest_incentive_paid'      => 0,
            'average_daily_driving'        => 0,
        ];

        return $this->helper_controller->send_response('success', 'Portfolio snapshot fetched', 200, $data);
    }

    public function stateDistribution()
    {
        $data = [
            'labels'   => ['MP', 'UP', 'Bihar', 'Rajasthan', 'Others'],
            'datasets' => [[
                'data'            => [0, 0, 0, 0, 0],
                'backgroundColor' => ['#6366f1', '#22c55e', '#f97316', '#dc2626', '#a855f7'],
            ]],
        ];

        return $this->helper_controller->send_response('success', 'State distribution data fetched', 200, $data);
    }

    private function getExcelPath(): string
    {
        // $path  = asset('/light-road-report');
        // $files = glob("{$path}/*.{xlsx,xls}", GLOB_BRACE);

        // if (empty($files)) {
        //     throw new \RuntimeException('No Excel file found in reports directory.');
        // }
        $file = public_path("light-road-report/Lightlroad_IOT_MIS_Corrected_Final.xlsx");

        return $file;
    }

    /**
     * GET /api/reports/risk
     */
    public function riskReport():JsonResponse
    {
        Cache::forget('risk_report');
        $data = Cache::remember('risk_report', now()->addHours(6), function () {
            $spreadsheet = IOFactory::load($this->getExcelPath());

            // Sheet 2 — Risk Summary (index 1)
            $riskSheet    = $spreadsheet->getSheet(1)->toArray();
            $riskSummaries = [];
            foreach (array_slice($riskSheet, 1) as $row) {
                [$category, $count] = $row;
                if (empty($category)) continue;
                $riskSummaries[trim($category)] = (int) $count;
            }

            // Sheet 1 — Customers (index 0)
            $customerSheet = $spreadsheet->getSheet(0)->toArray();
            $headings      = array_map('trim', $customerSheet[1]);
            
            $colMap        = array_flip($headings);

            $required = ['Borrower ID', 'Customer Name', 'Loan Amount (Final)', 'Final Risk Category'];
            // return $required;
            foreach ($required as $col) {
                if (!isset($colMap[$col])) {
                    throw new \RuntimeException("Missing column: {$col}");
                }
            }

            $grouped = [];
            foreach (array_slice($customerSheet, 2) as $row) {
                $category = trim($row[$colMap['Final Risk Category']] ?? '');
                if (empty($category)) continue;

                $grouped[$category][] = [
                    'Borrower ID'         => $row[$colMap['Borrower ID']] ?? '',
                    'Customer Name'       => $row[$colMap['Customer Name']] ?? '',
                    'Loan Amount (Final)' => $row[$colMap['Loan Amount (Final)']] ?? '',
                ];
            }

            // Build final result ordered by Sheet 2
            $result = [];
            foreach ($riskSummaries as $category => $count) {
                $result[$category] = [
                    'count' => $count,
                    'data'  => $grouped[$category] ?? [],
                ];
            }

            return $result;
        });

        return $this->send_response('success', 'Risk profile data fetched', 200, $data);
    }

    /**
     * GET /api/reports/risk?refresh=true
     */
    public function clearCache(): JsonResponse
    {
        Cache::forget('risk_report');
        return $this->riskReport();
    }
}
