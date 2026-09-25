<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelperController;
use App\Models\AdminUser;
use App\Models\BatteryTypes;
use App\Models\BorrowerAccountDetails;
use App\Models\BorrowerAddress;
use App\Models\BorrowerIotDetails;
use App\Models\BorrowerKyc;
use App\Models\BorrowerLoanProducts;
use App\Models\BorrowerLoanScheme;
use App\Models\BorrowerOccupation;
use App\Models\BorrowerReferenceDetails;
use App\Models\BorrowerIdentifierMapping;
use App\Models\BorrowerIdentifier;
use App\Models\BorrowerRepaymentSchedule;
use App\Models\CoapplicantKyc;
use App\Models\CoapplicantOccupation;
use App\Models\Dealer;
use App\Models\DisbursementDetails;
use App\Models\FieldInspectionDetails;
use App\Models\Guarantor;
use App\Models\GuarantorOccupation;
use App\Models\LenderBorrowerGroup;
use App\Models\LenderDealerGroup;
use App\Models\Lenders;
use App\Models\LenderDirectors;
use App\Models\Languages;
use App\Models\LenderServiceProvider;
use App\Models\LoanApplicationSummary;
use App\Models\LoanServiceProvider;
use App\Models\LoanBasicDetails;
use App\Models\LspExecutiveDetails;
use App\Models\Manufacturers;
use App\Models\MaritalStatus;
use App\Models\Occupation;
use App\Models\OccupationTypes;
use App\Models\ProductModel;
use App\Models\ProductTypes;
use App\Models\Relations;
use App\Models\ResidentTypes;
use App\Models\SalesExecutiveDealerGrouping;
use App\Models\User;
use App\Models\VehicleTypes;
use App\Services\BorrowerVisibilityService;
use App\Services\FileStorageService;
use App\Traits\ApiResponse;
use App\Traits\CalculatesLoanSchedule;
use Carbon\Carbon;
use Exception;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class AdminDashboardController extends Controller
{
    use ApiResponse;
    use CalculatesLoanSchedule;
    // private $helper_controller;
    public function __construct(
        private readonly HelperController        $helper_controller,
        private readonly BorrowerVisibilityService $visibilityService,
        private readonly FileStorageService $fileStorage,
    ) {}

    // =========================================================================
    // Shared: resolve the base loan query scope for the current user
    // =========================================================================

    /**
     * Determines the correct query scope and a flag indicating whether
     * rejection reasons should be shown.
     *
     * Returns:
     *   [Builder|null $query, bool $showRejectionReason, bool $empty]
     *
     * $empty = true means the caller should return zero-result response
     * immediately (e.g. LSP with no executives assigned).
     *
     * Precedence (matches existing logic exactly):
     *   1. LSP user            → scope by their executive IDs
     *   2. Dealer auth user    → scope by their dealer_unq
     *   3. OEM user            → scope by dealers linked via ProductModel
     *   4. user_type === 36    → scope by BorrowerIdentifierMapping
     *   5. Admin full access   → no scope (see all)
     *   6. Admin hierarchy     → scope by visible dealer_unq set
     */
    private function resolveBaseQuery(int $authId): array
    {
        $base = LoanBasicDetails::query();

        // ── 1. LSP ────────────────────────────────────────────────────────────
        $lsp = LoanServiceProvider::where('lsp_auth_id', $authId)->first();
        if ($lsp) {
            $execIds = LspExecutiveDetails::where('lsp_id', $lsp->id)->pluck('id');

            if ($execIds->isEmpty()) {
                return [null, false, true]; // empty result
            }

            $base->whereIn('executive_id', $execIds);
            return [$base, false, false];
        }

        // ── 2. Dealer auth ────────────────────────────────────────────────────
        $dealerAuth = Dealer::where('dealer_auth_id', $authId)->first();
        if ($dealerAuth) {
            $base->where('dealer_id', $dealerAuth->dealer_unq);
            return [$base, false, false];
        }

        // ── 3. OEM user ───────────────────────────────────────────────────────
        $manufacturer = Manufacturers::where('oem_auth_id', $authId)->first();
        if ($manufacturer) {
            $dealerIds = ProductModel::whereRaw(
                    'LOWER(manufacture_name) LIKE ?',
                    ['%' . strtolower($manufacturer->manufacturer) . '%']
                )
                ->distinct()
                ->pluck('dealer_id');

            if ($dealerIds->isEmpty()) {
                return [null, false, true]; // OEM exists but no linked dealers → empty
            }

            // dealer_id on LoanBasicDetails is dealer_unq — resolve it
            $dealerUnqs = Dealer::whereIn('id', $dealerIds)->pluck('dealer_unq');

            if ($dealerUnqs->isEmpty()) {
                return [null, false, true];
            }

            $base->whereIn('dealer_id', $dealerUnqs);
            return [$base, false, false];
        }

        // ── 4. user_type === 36 ───────────────────────────────────────────────
        $user = auth()->user();
        if ($user->user_type === 36) {
            $identifier = BorrowerIdentifier::whereRaw(
                'users @> ?::jsonb',
                [json_encode([$authId])]
            )->first();

            if ($identifier) {
                $borrowerIds = BorrowerIdentifierMapping::where('identifier_id', $identifier->id)
                    ->where('row_status', 'A')
                    ->pluck('borrower_id')
                    ->toArray();

                if (!empty($borrowerIds)) {
                    $base->whereIn('id', $borrowerIds);
                }
            }

            return [$base, true, false];
        }

        // ── 5 & 6. Admin user — full access or hierarchy ──────────────────────
        if ($user->is_all_borrower_accessible) {
            return [$base, true, false];
        }

        $visibleDealerUnqs = $this->visibilityService->resolveVisibleDealerUnqs($authId);

        if ($visibleDealerUnqs->isEmpty()) {
            return [null, true, true];
        }

        $base->whereIn('dealer_id', $visibleDealerUnqs);
        return [$base, true, false];
    }

    // =========================================================================
    // getDashboardDetails
    // =========================================================================

    // public function getDashboardDetails(Request $request)
    // {
    //     try {
    //         date_default_timezone_set('Asia/Kolkata');

    //         $year  = $request->filled('year')  ? (int) $request->year  : (int) date('Y');
    //         $month = $request->filled('month') ? $request->month        : date('F');

    //         $request->validate([
    //             'month' => 'nullable|date_format:F',
    //             'year'  => 'nullable|integer',
    //         ]);

    //         $emptyResponse = [
    //             'total_application'          => 0,
    //             'total_loan_amount'          => 0,
    //             'step1_count'                => 0,
    //             'step2_count'                => 0,
    //             'step3_count'                => 0,
    //             'step4_count'                => 0,
    //             'total_disbursed_application' => 0,
    //             'total_disbursed_amount' => 0,
    //         ];

    //         $authId = auth()->id();
    //         [$baseQuery,, $empty] = $this->resolveBaseQuery($authId);

    //         if ($empty) {
    //             return response()->json($emptyResponse, 200);
    //         }

    //         $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;

    //         // Clone the scoped query for the month
    //         $loanDetails = (clone $baseQuery)
    //             ->whereYear('created_at', $year)
    //             ->whereMonth('created_at', $monthNumber)
    //             ->select(['id', 'loan_application_steps', 'status', 'loan_amount', 'created_at'])
    //             ->get();

    //         // Step and disbursal counts
    //         $step1 = $step2 = $step3 = $step4 = $totalApp = $totalDisbursed = 0;

    //         foreach ($loanDetails as $loan) {
    //             if ($loan->loan_application_steps) {
    //                 $steps = json_decode($loan->loan_application_steps, true);
    //                 if (is_array($steps) && !empty($steps)) {
    //                     $lastStep = (int) (end($steps)['step'] ?? 0);
    //                     if ($lastStep >= 1  && $lastStep < 8) {
    //                         $step1++;
    //                     } elseif ($lastStep >= 8  && $lastStep < 26) {
    //                         $step2++;
    //                     } elseif ($lastStep >= 26 && $lastStep < 27) {
    //                         $step3++;
    //                     } elseif ($lastStep >= 27) {
    //                         $step4++;
    //                     }
    //                     $totalApp++;
    //                 }
    //             }
    //             if ($loan->status === 'DISBURSED') {
    //                 $totalDisbursed++;
    //             }
    //         }

    //         $totalLoanAmount = (clone $baseQuery)
    //             ->whereYear('created_at', $year)
    //             ->whereMonth('created_at', $monthNumber)
    //             ->sum('loan_amount');

    //         // Daily aggregates — done in DB, not PHP
    //         $dailyData = (clone $baseQuery)
    //             ->select(
    //                 DB::raw('EXTRACT(DAY FROM created_at)::int AS day'),
    //                 DB::raw('COUNT(*) AS total'),
    //                 DB::raw('SUM(loan_amount) AS loan_amount')
    //             )
    //             ->whereYear('created_at', $year)
    //             ->whereMonth('created_at', $monthNumber)
    //             ->groupBy(DB::raw('EXTRACT(DAY FROM created_at)'))
    //             ->orderBy(DB::raw('EXTRACT(DAY FROM created_at)'))
    //             ->get()
    //             ->toArray();

    //         $formattedDailyData = $this->formatDailyData($dailyData, $monthNumber, $year);

    //         $visibleLoanIds = (clone $baseQuery)->pluck('id')->toArray();

    //         // Disbursed this month — based on disbursement_date, joined via borrower_id
    //         $disbursedData = DisbursementDetails::whereYear('disbursement_date', $year)
    //             ->whereMonth('disbursement_date', $monthNumber)
    //             ->whereIn('borrower_id', $visibleLoanIds)
    //             ->selectRaw('COUNT(DISTINCT borrower_id) AS disbursed_count, SUM(disbursement_amount) AS disbursed_amount')
    //             ->first();
    //         $totalDisbursed       = (int) ($disbursedData->disbursed_count ?? 0);
    //         $totalDisbursedAmount = (float) ($disbursedData->disbursed_amount ?? 0);
    //         $disbursedLoanIds     = DisbursementDetails::whereYear('disbursement_date', $year)
    //             ->whereMonth('disbursement_date', $monthNumber)
    //             ->whereIn('borrower_id', $visibleLoanIds)
    //             ->distinct('borrower_id')
    //             ->pluck('borrower_id')
    //             ->toArray();
    //         $disbursedLoanIdSet = array_flip($disbursedLoanIds);

    //         // For day-wise bucketing — map borrower_id => disbursement_date
    //         $disbursedDayMap = DisbursementDetails::whereYear('disbursement_date', $year)
    //             ->whereMonth('disbursement_date', $monthNumber)
    //             ->whereIn('borrower_id', $visibleLoanIds)
    //             ->pluck('disbursement_date', 'borrower_id'); // [loan_basic_details.id => date]

    //         // Step counts per day
    //         $daysInMonth = Carbon::createFromDate($year, $monthNumber)->daysInMonth;
    //         $dailyStepCounts = [
    //             'step1'     => array_fill(0, $daysInMonth, 0),
    //             'step2'     => array_fill(0, $daysInMonth, 0),
    //             'step3'     => array_fill(0, $daysInMonth, 0),
    //             'step4'     => array_fill(0, $daysInMonth, 0),
    //             'disbursed' => array_fill(0, $daysInMonth, 0),
    //         ];

    //         foreach ($loanDetails as $loan) {
    //             $day = Carbon::parse($loan->created_at)->day - 1;
    //             if ($loan->loan_application_steps) {
    //                 $steps = json_decode($loan->loan_application_steps, true);
    //                 if (is_array($steps) && !empty($steps)) {
    //                     $lastStep = (int) (end($steps)['step'] ?? 0);
    //                     if ($lastStep >= 1  && $lastStep < 8) {
    //                         $dailyStepCounts['step1'][$day]++;
    //                     } elseif ($lastStep >= 8  && $lastStep < 26) {
    //                         $dailyStepCounts['step2'][$day]++;
    //                     } elseif ($lastStep >= 26 && $lastStep < 27) {
    //                         $dailyStepCounts['step3'][$day]++;
    //                     } elseif ($lastStep >= 27) {
    //                         $dailyStepCounts['step4'][$day]++;
    //                     }
    //                 }
    //             }
    //             // if ($loan->status === 'DISBURSED') {
    //             //     $dailyStepCounts['disbursed'][$day]++;
    //             // }
    //             if (isset($disbursedDayMap[$loan->id])) {
    //                 $disbursedDay = Carbon::parse($disbursedDayMap[$loan->id])->day - 1;
    //                 $dailyStepCounts['disbursed'][$disbursedDay]++;
    //             }
    //         }



    //         return response()->json([
    //             'total_application'           => $totalApp,
    //             'total_loan_amount'           => $totalLoanAmount,
    //             'step1_count'                 => $step1,
    //             'step2_count'                 => $step2,
    //             'step3_count'                 => $step3,
    //             // 'step4_count'                 => ($step4 - $totalDisbursed),
    //             'step4_count'                 => $step4,
    //             'total_disbursed_application' => $totalDisbursed,
    //             'total_disbursed_amount'      => $totalDisbursedAmount,
    //             'daywise_totalApplication'    => $formattedDailyData['total'],
    //             'daywise_step1Application'    => $dailyStepCounts['step1'],
    //             'daywise_step2Application'    => $dailyStepCounts['step2'],
    //             'daywise_step3Application'    => $dailyStepCounts['step3'],
    //             'daywise_step4Application'    => $dailyStepCounts['step4'],
    //             'daywise_loanAmount'          => $formattedDailyData['loan_amount'],
    //         ], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'error'   => 'An error occurred while fetching the dashboard details.',
    //             'message' => $e->getMessage(),
    //         ], 422);
    //     }
    // }

    public function getDashboardDetails(Request $request)
    {
        try {
            date_default_timezone_set('Asia/Kolkata');

            $year  = $request->filled('year')  ? (int) $request->year  : (int) date('Y');
            $month = $request->filled('month') ? $request->month        : date('F');

            $request->validate([
                'month' => 'nullable|date_format:F',
                'year'  => 'nullable|integer',
            ]);

            $emptyResponse = [
                'total_application'          => 0,
                'total_loan_amount'          => 0,
                'step1_count'                => 0,
                'step2_count'                => 0,
                'step3_count'                => 0,
                'step4_count'                => 0,
                'total_disbursed_application' => 0,
                'total_disbursed_amount' => 0,
                'total_sanctioned_amount' => 0,
            ];

            $authId = auth()->id();
            [$baseQuery,, $empty] = $this->resolveBaseQuery($authId);

            if ($empty) {
                return response()->json($emptyResponse, 200);
            }

            $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;

            // Clone the scoped query for the month
            $loanDetails = (clone $baseQuery)
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $monthNumber)
                ->select(['id', 'loan_application_steps', 'status', 'loan_amount', 'created_at'])
                ->get();

            // Step and disbursal counts
            $step1 = $step2 = $step3 = $step4 = $totalApp = $totalDisbursed = 0;

            foreach ($loanDetails as $loan) {
                if ($loan->loan_application_steps) {
                    $steps = json_decode($loan->loan_application_steps, true);
                    if (is_array($steps) && !empty($steps)) {
                        $lastStep = (int) (end($steps)['step'] ?? 0);
                        if ($lastStep >= 1  && $lastStep < 8) {
                            $step1++;
                        } elseif ($lastStep >= 8  && $lastStep < 26) {
                            $step2++;
                        } elseif ($lastStep >= 26 && $lastStep < 27) {
                            $step3++;
                        } elseif ($lastStep >= 27) {
                            $step4++;
                        }
                        $totalApp++;
                    }
                }
                if ($loan->status === 'DISBURSED') {
                    $totalDisbursed++;
                }
            }

            $totalLoanAmount = (clone $baseQuery)
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $monthNumber)
                ->sum('loan_amount');

            // Daily aggregates — done in DB, not PHP
            $dailyData = (clone $baseQuery)
                ->select(
                    DB::raw('EXTRACT(DAY FROM created_at)::int AS day'),
                    DB::raw('COUNT(*) AS total'),
                    DB::raw('SUM(loan_amount) AS loan_amount')
                )
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $monthNumber)
                ->groupBy(DB::raw('EXTRACT(DAY FROM created_at)'))
                ->orderBy(DB::raw('EXTRACT(DAY FROM created_at)'))
                ->get()
                ->toArray();

            $formattedDailyData = $this->formatDailyData($dailyData, $monthNumber, $year);

            $visibleLoanIds = (clone $baseQuery)->pluck('id')->toArray();

            // Disbursed this month — based on disbursement_date, joined via borrower_id
            $disbursedData = DisbursementDetails::whereYear('disbursement_date', $year)
                ->whereMonth('disbursement_date', $monthNumber)
                ->whereIn('borrower_id', $visibleLoanIds)
                ->selectRaw('COUNT(DISTINCT borrower_id) AS disbursed_count, SUM(disbursement_amount) AS disbursed_amount')
                ->first();
            $totalDisbursed       = (int) ($disbursedData->disbursed_count ?? 0);
            $totalDisbursedAmount = (float) ($disbursedData->disbursed_amount ?? 0);
            $disbursedLoanIds     = DisbursementDetails::whereYear('disbursement_date', $year)
                ->whereMonth('disbursement_date', $monthNumber)
                ->whereIn('borrower_id', $visibleLoanIds)
                ->distinct('borrower_id')
                ->pluck('borrower_id')
                ->toArray();
            $disbursedLoanIdSet = array_flip($disbursedLoanIds);

            // For day-wise bucketing — map borrower_id => disbursement_date
            $disbursedDayMap = DisbursementDetails::whereYear('disbursement_date', $year)
                ->whereMonth('disbursement_date', $monthNumber)
                ->whereIn('borrower_id', $visibleLoanIds)
                ->pluck('disbursement_date', 'borrower_id'); // [loan_basic_details.id => date]


            // ⬇️ ADD #2 START

            $disbursedBorrowerIds = DisbursementDetails::whereYear('disbursement_date', $year)
                ->whereMonth('disbursement_date', $monthNumber)
                ->whereIn('borrower_id', $visibleLoanIds)
                ->pluck('borrower_id')
                ->unique()
                ->values()
                ->all();

            $totalDisbursedLoanAmountOnly = (float) LoanBasicDetails::whereIn('id', $disbursedBorrowerIds)
                ->where('status', 'DISBURSED')
                ->sum('loan_amount');

            $totalIotAmount = (float) DB::table('borrower_loan_schemes')
                ->whereIn('borrower_id', $disbursedBorrowerIds)
                ->where('is_current', true)
                ->sum('iot');

            $totalSanctionedAmount = $totalDisbursedLoanAmountOnly + $totalIotAmount;

            // ⬆️ ADD #2 END

            // Step counts per day
            $daysInMonth = Carbon::createFromDate($year, $monthNumber)->daysInMonth;
            $dailyStepCounts = [
                'step1'     => array_fill(0, $daysInMonth, 0),
                'step2'     => array_fill(0, $daysInMonth, 0),
                'step3'     => array_fill(0, $daysInMonth, 0),
                'step4'     => array_fill(0, $daysInMonth, 0),
                'disbursed' => array_fill(0, $daysInMonth, 0),
            ];

            foreach ($loanDetails as $loan) {
                $day = Carbon::parse($loan->created_at)->day - 1;
                if ($loan->loan_application_steps) {
                    $steps = json_decode($loan->loan_application_steps, true);
                    if (is_array($steps) && !empty($steps)) {
                        $lastStep = (int) (end($steps)['step'] ?? 0);
                        if ($lastStep >= 1  && $lastStep < 8) {
                            $dailyStepCounts['step1'][$day]++;
                        } elseif ($lastStep >= 8  && $lastStep < 26) {
                            $dailyStepCounts['step2'][$day]++;
                        } elseif ($lastStep >= 26 && $lastStep < 27) {
                            $dailyStepCounts['step3'][$day]++;
                        } elseif ($lastStep >= 27) {
                            $dailyStepCounts['step4'][$day]++;
                        }
                    }
                }
                // if ($loan->status === 'DISBURSED') {
                //     $dailyStepCounts['disbursed'][$day]++;
                // }
                if (isset($disbursedDayMap[$loan->id])) {
                    $disbursedDay = Carbon::parse($disbursedDayMap[$loan->id])->day - 1;
                    $dailyStepCounts['disbursed'][$disbursedDay]++;
                }
            }



            return response()->json([
                'total_application'           => $totalApp,
                'total_loan_amount'           => $totalLoanAmount,
                'step1_count'                 => $step1,
                'step2_count'                 => $step2,
                'step3_count'                 => $step3,
                // 'step4_count'                 => ($step4 - $totalDisbursed),
                'step4_count'                 => $step4,
                'total_disbursed_application' => $totalDisbursed,
                'total_disbursed_amount'      => $totalDisbursedAmount,
                'daywise_totalApplication'    => $formattedDailyData['total'],
                'daywise_step1Application'    => $dailyStepCounts['step1'],
                'daywise_step2Application'    => $dailyStepCounts['step2'],
                'daywise_step3Application'    => $dailyStepCounts['step3'],
                'daywise_step4Application'    => $dailyStepCounts['step4'],
                'daywise_loanAmount'          => $formattedDailyData['loan_amount'],
                'total_sanctioned_amount'     => $totalSanctionedAmount,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'An error occurred while fetching the dashboard details.',
                'message' => $e->getMessage(),
            ], 422);
        }
    }
    /**
     * Format daily data to ensure days with no data default to 0.
     *
     * @param array $dailyData
     * @param int $monthNumber
     * @param int $year
     * @return array
     */

    private function formatDailyData(array $dailyData, int $month, int $year)
    {
        $daysInMonth = Carbon::createFromDate($year, $month)->daysInMonth;
        $formattedDailyData = [
            'total' => array_fill(0, $daysInMonth, 0),
            'loan_amount' => array_fill(0, $daysInMonth, 0.0),
        ];

        foreach ($dailyData as $data) {
            $day = $data['day'] - 1; // Convert 1-based day to 0-based index
            $formattedDailyData['total'][$day] = (int)$data['total'];
            $formattedDailyData['loan_amount'][$day] = (float)$data['loan_amount'];
        }

        return $formattedDailyData;
    }

    // =========================================================================
    // getYearlyCount
    // =========================================================================

    public function getYearlyCount(Request $request)
    {
        try {
            date_default_timezone_set('Asia/Kolkata');

            $year = $request->filled('year') ? (int) $request->year : (int) date('Y');

            $request->validate(['year' => 'nullable|integer']);

            $emptyResponse = [
                'total_application'    => 0,
                'total_loan_amount'    => 0,
                'step1_count'          => 0,
                'step2_count'          => 0,
                'step3_count'          => 0,
                'step4_count'          => 0,
                'loan_pipeline'        => 0,
                'rejected_application' => 0,
                'approved_application' => 0,
                'disbursed_application' => 0,
                'disbursed_amount' => 0,
                'total_sanctioned_amount' => 0,
            ];

            $authId = auth()->id();
            [$baseQuery,, $empty] = $this->resolveBaseQuery($authId);

            if ($empty) {
                return response()->json($emptyResponse, 200);
            }

            $yearQuery = (clone $baseQuery)->whereYear('created_at', $year);

            // Pull minimal columns needed for step counting
            $loanDetails = (clone $yearQuery)
                ->select(['id', 'loan_application_steps', 'status'])
                ->get();

            $step1 = $step2 = $step3 = $step4 = $totalApp = $totalDisbursed = 0;

            foreach ($loanDetails as $loan) {
                if ($loan->loan_application_steps) {
                    $steps = json_decode($loan->loan_application_steps, true);
                    if (is_array($steps) && !empty($steps)) {
                        $lastStep = (int) (end($steps)['step'] ?? 0);
                        if ($lastStep >= 1  && $lastStep < 8) {
                            $step1++;
                        } elseif ($lastStep >= 8  && $lastStep < 26) {
                            $step2++;
                        } elseif ($lastStep >= 26 && $lastStep < 27) {
                            $step3++;
                        } elseif ($lastStep >= 27) {
                            $step4++;
                        }
                        $totalApp++;
                    }
                }
                // if ($loan->status === 'DISBURSED') {
                //     $totalDisbursed++;
                // }
            }

            // Aggregates via DB — no PHP loops needed
            $totalLoanAmount    = (clone $yearQuery)->sum('loan_amount');
            $totalRejected      = (clone $yearQuery)->where('status', 'REJECTED')->count();
            // $totalDisbursedAgg  = (clone $yearQuery)->where('status', 'DISBURSED')->count();

            $visibleLoanIds = (clone $baseQuery)->pluck('id')->toArray();

            // Disbursed this year — based on disbursement_date
            $disbursedData = DisbursementDetails::whereYear('disbursement_date', $year)
                ->whereIn('borrower_id', $visibleLoanIds)
                ->selectRaw('COUNT(DISTINCT borrower_id) AS disbursed_count, SUM(disbursement_amount) AS disbursed_amount')
                ->first();
            $totalDisbursed       = (int) ($disbursedData->disbursed_count ?? 0);
            $totalDisbursedAmount = (float) ($disbursedData->disbursed_amount ?? 0);

            // ⬇️ ADD #2 START

            $disbursedBorrowerIds = DisbursementDetails::whereYear('disbursement_date', $year)
                ->whereIn('borrower_id', $visibleLoanIds)
                ->pluck('borrower_id')
                ->unique()
                ->values()
                ->all();

            $totalDisbursedLoanAmountOnly = (float) LoanBasicDetails::whereIn('id', $disbursedBorrowerIds)
                ->where('status', 'DISBURSED')
                ->sum('loan_amount');

            $totalIotAmount = (float) DB::table('borrower_loan_schemes')
                ->whereIn('borrower_id', $disbursedBorrowerIds)
                ->where('is_current', true)
                ->sum('iot');

            $totalSanctionedAmount = $totalDisbursedLoanAmountOnly + $totalIotAmount;

            // ⬆️ ADD #2 END

            $totalDisbursedAgg = $totalDisbursed;
            $loanPipeline       = $totalApp - $totalRejected - $totalDisbursedAgg;

            return response()->json([
                'total_application'     => $totalApp,
                'total_loan_amount'     => $totalLoanAmount,
                'step1_count'           => $step1,
                'step2_count'           => $step2,
                'step3_count'           => $step3,
                'step4_count'           => $step4,
                'loan_pipeline'         => max(0, $loanPipeline),
                'rejected_application'  => $totalRejected,
                'approved_application'  => $totalDisbursed,
                'disbursed_application' => $totalDisbursed,
                'disbursed_amount' => $totalDisbursedAmount,
                'total_sanctioned_amount' => $totalSanctionedAmount,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'An error occurred while fetching the yearly count.',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // =========================================================================
    // get_borrower_list
    // =========================================================================

    public function get_borrower_list(Request $request)
    {
        date_default_timezone_set('Asia/Kolkata');

        try {
            $validator = Validator::make($request->all(), [
                'page'              => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'start_date'        => 'date|nullable',
                'end_date'          => 'date|nullable|after_or_equal:start_date',
                'step'              => 'integer|min:1|nullable',
                'borrower_name'     => 'string|regex:/^[a-zA-Z\s]+$/|nullable',
                'borrower_id'       => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable',
                'mobile_no'         => 'digits:10|nullable',
                'pan_no'            => 'string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|nullable',
                'aadhar_no'         => 'string|min:4|max:12|nullable',
                'dob'               => 'date|nullable',
                'dealer_id'         => 'nullable|integer',
                'lsp_id'            => 'nullable|integer',
                'product_type_id'   => 'nullable|integer',
                'category'          => 'nullable|string|in:B2B,B2C',
                'application_date'  => 'date|nullable',
                'status'            => 'nullable|string|in:NEW,APPROVED,REJECTED,HOLD,CLOSED,DISBURSED,CANCELLED',
                'year'              => 'nullable|integer',
                'month'             => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            $authId = auth()->id();

            // ── Resolve base scope ────────────────────────────────────────────
            [$baseQuery, $showRejectionReason, $empty] = $this->resolveBaseQuery($authId);

            if ($empty) {
                return $this->send_response('success', 'Borrower list fetched', 200, []);
            }

            // ── Apply admin-level request filters (ignored for lsp/dealer) ────
            $user    = auth()->user();
            $lsp     = LoanServiceProvider::where('lsp_auth_id', $authId)->first();
            $dealerAuth = Dealer::where('dealer_auth_id', $authId)->first();
            $isAdminUser = !$lsp && !$dealerAuth && $user->user_type !== 36;

            if ($isAdminUser) {
                if ($request->filled('dealer_id')) {
                    $dealer = Dealer::find($request->dealer_id);
                    if ($dealer) {
                        $baseQuery->where('dealer_id', $dealer->dealer_unq);
                    }
                }

                if ($request->filled('lsp_id')) {
                    $execIds = LspExecutiveDetails::where('lsp_id', $request->lsp_id)->pluck('id');
                    if ($execIds->isNotEmpty()) {
                        $baseQuery->whereIn('executive_id', $execIds);
                    }
                }
            }

            if ($request->filled('rsm_id')) {
                $rsm = AdminUser::where('id', $request->rsm_id)
                    ->where('user_type', AdminUser::RSM_USER)
                    ->where('is_deleted', false)
                    ->first();

                if ($rsm) {
                    $rsmDealerUnqs = $this->visibilityService->resolveVisibleDealerUnqs($rsm->id);

                    if ($rsmDealerUnqs->isNotEmpty()) {
                        $baseQuery->whereIn('dealer_id', $rsmDealerUnqs);
                    }
                }
            }

            // ── Common filters ────────────────────────────────────────────────
            if ($request->filled('category')) {
                if ($request->category === 'B2B') {
                    $baseQuery->whereNotNull('executive_id');
                } elseif ($request->category === 'B2C') {
                    $baseQuery->whereNull('executive_id');
                }
            }

            if ($request->filled('product_type_id')) {
                $baseQuery->where('product_id', $request->product_type_id);
            }

            if ($request->filled('status')) {
                $baseQuery->where('status', $request->status);
            }

            $isDisbursedWithDateFilter = false; // ← track this before date filters

            if ($request->input('status') === 'DISBURSED') {
                $disbQuery = DisbursementDetails::query()->select('borrower_id');
                $hasDateFilter = false;

                if ($request->filled('start_date')) {
                    $disbQuery->where('disbursement_date', '>=', Carbon::parse($request->start_date)->startOfDay());
                    $hasDateFilter = true;
                }
                if ($request->filled('end_date')) {
                    $disbQuery->where('disbursement_date', '<=', Carbon::parse($request->end_date)->endOfDay());
                    $hasDateFilter = true;
                }
                if ($request->filled('application_date')) {
                    $disbQuery->whereDate('disbursement_date', $request->application_date);
                    $hasDateFilter = true;
                }
                if ($request->filled('year') || $request->filled('month')) {
                    $year = $request->input('year', date('Y'));
                    $disbQuery->whereYear('disbursement_date', $year);
                    $hasDateFilter = true;

                    if ($request->filled('month')) {
                        $monthNumber = Carbon::parse("{$year}-{$request->month}-01")->month;
                        $disbQuery->whereMonth('disbursement_date', $monthNumber);
                    }
                }

                if ($hasDateFilter) {
                    $isDisbursedWithDateFilter = true; // ← skip created_at filters below

                    $visibleLoanIds = (clone $baseQuery)->pluck('loan_basic_details.id')->toArray();

                    if (empty($visibleLoanIds)) {
                        $baseQuery->whereRaw('1 = 0');
                    } else {
                        $disbursedLoanIds = $disbQuery
                            ->whereIn('borrower_id', $visibleLoanIds)
                            ->distinct()
                            ->pluck('borrower_id')
                            ->toArray();

                        if (empty($disbursedLoanIds)) {
                            $baseQuery->whereRaw('1 = 0');
                        } else {
                            $baseQuery->whereIn('loan_basic_details.id', $disbursedLoanIds);
                        }
                    }
                }
            }

            // ── Date filters on created_at — skipped when disbursement date filter handled above ──
            if (!$isDisbursedWithDateFilter) {

                if ($request->filled('start_date')) {
                    $baseQuery->where('created_at', '>=', Carbon::parse($request->start_date)->startOfDay());
                }

                if ($request->filled('end_date')) {
                    $baseQuery->where('created_at', '<=', Carbon::parse($request->end_date)->endOfDay());
                }

                if ($request->filled('application_date')) {
                    $baseQuery->whereDate('created_at', $request->application_date);
                }

                if ($request->filled('year')) {
                    $baseQuery->whereYear('created_at', $request->year);
                }

                if ($request->filled('month')) {
                    $year  = $request->input('year', date('Y'));
                    $month = $request->input('month', date('F'));
                    $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
                    $baseQuery->whereYear('created_at', $year)
                        ->whereMonth('created_at', $monthNumber);
                }
            }

            // ── Borrower KYC filters — narrowed via whereHas ──────────────────
            $kycFilters = [];

            if ($request->filled('borrower_name')) {
                $name = strtolower($request->borrower_name);
                $baseQuery->where(function ($q) use ($name) {
                    $q->whereHas(
                        'latestBorrowerKyc',
                        fn($k) =>
                        $k->whereRaw('LOWER(name) LIKE ?', ["%{$name}%"])
                    )->orWhereHas(
                        'user_detail',
                        fn($u) =>
                        $u->whereRaw('LOWER(name) LIKE ?', ["%{$name}%"])
                    );
                });
            }

            if ($request->filled('borrower_id')) {
                $baseQuery->whereRaw('LOWER(borrower_id) LIKE ?', ['%' . strtolower($request->borrower_id) . '%']);
            }

            if ($request->filled('mobile_no')) {
                $mobile = $request->mobile_no;
                $baseQuery->where(function ($q) use ($mobile) {
                    $q->whereHas('latestBorrowerKyc', fn($k) => $k->where('mobile', 'like', "%{$mobile}%"))
                        ->orWhereHas('user_detail', fn($u) => $u->where('mobile', 'like', "%{$mobile}%"));
                });
            }

            foreach (['pan_no' => 'pan', 'aadhar_no' => 'aadhar', 'dob' => 'dob_date'] as $req => $col) {
                if ($request->filled($req)) {
                    $baseQuery->whereHas(
                        'latestBorrowerKyc',
                        fn($k) =>
                        $col === 'dob_date'
                            ? $k->whereDate($col, $request->$req)
                            : $k->where($col, 'like', '%' . $request->$req . '%')
                    );
                }
            }

            // ── Step filter — stays in DB, not PHP ───────────────────────────
            if ($request->filled('step')) {
                $step = (int) $request->step;
                $baseQuery->whereRaw("
                    CASE
                        WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int >= 27            AND :step = 4 THEN true
                        WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 21 AND 26 AND :step = 3 THEN true
                        WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 8  AND 20 AND :step = 2 THEN true
                        WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 1  AND 7  AND :step = 1 THEN true
                        ELSE false
                    END
                ", ['step' => $step]);
            }

            // ── Eager load everything needed — eliminates all N+1 queries ─────
            $baseQuery->with([
                'dealer_detail:id,dealer_unq',
                'user_detail:id,mobile,name',
                'latestBorrowerKyc:id,borrower_id,name,mobile,dob_date,marital_status,husband_name,father_name,gender,email,pan,aadhar,driving_license_no,alternate_number1',
                // Credit summary — was fetched inside transform() loop (N+1 fix)
                'latestCreditSummary:id,borrower_id,cibil_score,coappli_cibil_score,guarantor_cibil_score',
                // Lender — was two separate queries per row inside get_borrower_lender_details() (N+1 fix)
                'lenderGroup:id,borrower_id,lender_id',
                'lenderGroup.lender:id,name,lender_unq',
                'externalIdentifier:id,dealer_unq'
            ])->select([
                'id',
                'borrower_id',
                'status',
                'user_id',
                'loan_amount',
                'model',
                'loan_term',
                'loan_application_steps',
                'created_at',
                'dealer_id',
                'admin_remarks',
                'down_payment',
                'executive_id',
            ]);

            $numberOfRecords = (int) $request->input('number_of_records', 10);
            $page            = (int) $request->input('page', 1);

            $paginated = $baseQuery->orderBy('loan_basic_details.created_at', 'desc')
                ->paginate($numberOfRecords, ['*'], 'page', $page);

            $parseCibil = fn($value) => ($value === null || $value === '') ? '' : ($value == '0' ? 'NTC' : $value);

            $paginated->getCollection()->transform(function ($borrower) use ($showRejectionReason, $parseCibil) {
                $kyc     = $borrower->latestBorrowerKyc;
                $user    = $borrower->user_detail;
                $dealer  = $borrower->dealer_detail;
                $summary = $borrower->latestCreditSummary;
                $externalIdentifier = $borrower->externalIdentifier;

                // Lender — resolved from eager-loaded relation (no extra queries)
                $lender       = $borrower->lenderGroup?->lender;
                $lender_name  = $lender
                    ? $lender->name . ($lender->lender_unq ? ' (' . $lender->lender_unq . ')' : '')
                    : '';

                // Step status
                $stepStatus = null;
                $lastStep   = null;
                if ($borrower->loan_application_steps) {
                    $steps = json_decode($borrower->loan_application_steps, true);
                    if (is_array($steps) && !empty($steps)) {
                        $lastStep = end($steps);
                        $step     = (int) ($lastStep['step'] ?? 0);
                        $stepStatus = match (true) {
                            $step < 8              => 'step1',
                            $step >= 8 && $step <= 20  => 'step2',
                            $step >= 21 && $step < 27  => 'step3',
                            default                    => 'step4',
                        };
                    }
                }

                // Rejection reason
                $rejectionReason = null;
                if ($showRejectionReason && !empty($borrower->admin_remarks)) {
                    $decoded = json_decode($borrower->admin_remarks, true);
                    $rejectionReason = (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
                        ? $decoded
                        : ['admin_remarks' => $borrower->admin_remarks];
                }

                // NOTE: user_address_details() is an external API / heavy call.
                // It is intentionally omitted from the list for performance.
                // It remains available on the detail endpoint (get_borrower_details).
                $user_address_details = $this->helper_controller->user_address_details($borrower->id, "BORROWER");

                return [
                    'borrower_name'      => $kyc->name ?? $user->name ?? '',
                    'borrower_id'        => $borrower->borrower_id ?? '',
                    'loan_status'        => $borrower->status ?? '',
                    'model'              => $borrower->model ?? '',
                    'mobile_no'          => $kyc->mobile ?? $user->mobile ?? '',
                    'alternate_number1'  => $kyc->alternate_number1 ?? '',
                    'date_of_birth'      => $kyc->dob_date ?? '',
                    'marital_status'     => $kyc->marital_status ?? '',
                    'spouse_name'        => $kyc->husband_name ?? '',
                    'fathers_name'       => $kyc->father_name ?? '',
                    'gender'             => $kyc->gender ?? '',
                    'email_id'           => $kyc->email ?? '',
                    'pan_no'             => $kyc->pan ?? '',
                    'aadhar_no'          => $kyc->aadhar ?? '',
                    'driving_license_no' => $kyc->driving_license_no ?? '',
                    'borrower_cibil'     => $parseCibil($summary->cibil_score ?? null),
                    'coapplicant_cibil'  => $parseCibil($summary->coappli_cibil_score ?? null),
                    'guarantor_cibil'    => $parseCibil($summary->guarantor_cibil_score ?? null),
                    'loan_amount'        => $this->calculateNetLoanAmount($borrower->loan_amount, $borrower->loan_term),
                    'down_payment'       => $borrower->down_payment ?? 0,
                    'loan_id'            => '',
                    'address'           => $user_address_details ?? [],
                    'created_on'         => !empty($borrower->created_at)
                        ? date('d-m-Y H:i:s', strtotime($borrower->created_at))
                        : '',
                    'dealer_id'          => $dealer->id ?? '',
                    'dealer_unq'         => $dealer->dealer_unq ?? '',
                    'lender'             => $lender_name,
                    'rejection_reason'   => $rejectionReason,
                    'step_status'        => $stepStatus,
                    'last_step'          => $lastStep ? [
                        'application_step' => $lastStep['step'] ?? '',
                        'details'          => $lastStep['details'] ?? '',
                        'status'           => $lastStep['status'] ?? '',
                    ] : null,
                    // 'external_identifier'    => $externalIdentifier ? 'Green Raha' : null,
                    'external_identifier'    => match (true) {
                        !empty($externalIdentifier) => 'Green Raha',
                        $borrower->executive_id !== null => 'B2B',
                        default => null,
                    },
                    'action' => '',
                ];
            });

            return $this->send_response('success', 'Borrower details fetched', 200, $paginated);
        } catch (Exception $e) {
            return response()->json([
                'error'   => 'An error occurred while fetching loan records.',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function get_loan_status_list()
    {
        // 'NEW', 'APPROVED', 'REJECTED', 'HOLD', 'AWAITED', 'INPROCESS', 'DISBURSED', 'CLOSED', 'INTRANSIT', 'SOFT_APPROVED', 'CREDITCHECK', 'FI', 'CAM_REPORT'
        $statuses = [
            'NEW',
            // 'APPROVED',
            'DISBURSED',
            'CANCELLED',
            // 'CLOSED',
            // 'HOLD',
            'REJECTED',
        ];

        return $this->send_response('success', "Loan statuses fetched", 200, $statuses);
    }

    public function get_borrower_details($borrower_id = null)
    {
        try {
            if (is_null($borrower_id)) {
                return $this->send_response('failed', 'Borrower id is required', 422);
            }

            // ── Step 1: lightweight fetch — just enough to check access ───────
            // We fetch only dealer_id and id first so we can gate access
            // without loading all the expensive relations unnecessarily.
            $stub = LoanBasicDetails::where('borrower_id', (string) $borrower_id)
                ->select(['id', 'dealer_id'])
                ->first();

            if (!$stub) {
                return $this->send_response('failed', 'Invalid borrower id', 422);
            }

            // ── Step 2: access check ──────────────────────────────────────────
            $authId = auth()->id();
            [$scopedQuery,, $empty] = $this->resolveBaseQuery($authId);

            if ($empty) {
                return $this->send_response('failed', 'You do not have access to this borrower.', 422);
            }

            // resolveBaseQuery returns a query already scoped to what the user
            // can see. We check whether this borrower's ID falls within that scope.
            $hasAccess = (clone $scopedQuery)
                ->where('loan_basic_details.id', $stub->id)
                ->exists();

            if (!$hasAccess) {
                return $this->send_response('failed', 'You do not have access to this borrower.', 422);
            }

            // ── Step 3: full fetch now that access is confirmed ───────────────
            $loan_basic_details = LoanBasicDetails::with([
                'dealer',
                'latestBorrowerKyc',
                'latestBorrowerAddress',
                'latestBorrowerOccupation',
                'latestCoapplicantKyc',
                'latestCoapplicantOccupation',
                'latestGuarantor',
                'latestGuarantorOccupation',
                'latestDocuments',
                'latestLoanScheme',
            ])->where('borrower_id', (string) $borrower_id)->first();

            if (!$loan_basic_details) {
                return $this->send_response('failed', 'Invalid borrower id', 422);
            }

            $dealer_details = $loan_basic_details->dealer;
            
            $loanScheme = $loan_basic_details->latestLoanScheme;
            $product_details = null;
            if (!empty($loan_basic_details->model_id)) {
                $product_details = ProductModel::where('model_unq', $loan_basic_details->model_id)->first();
            } elseif ($dealer_details) {
                $product_details = ProductModel::where('dealer_id', $dealer_details->id)
                    ->where('name', $loan_basic_details->model)
                    ->first();
            }

            $loan_summary_details = LoanApplicationSummary::where('borrower_id', $loan_basic_details->id)->latest()->first();
            $interest_rate       = $loanScheme->interest ?? 0;
            $insurance_2nd_year  = $loanScheme->insurance_2nd_year ?? 0;
            $iot_amount          = $loanScheme->iot ?? 0;
            $second_year_insurance = ($loan_basic_details->loan_term > 15 && $loan_basic_details->loan_term <= 24) ? $insurance_2nd_year : 0;
            $final_loan_amount   = $loan_basic_details->loan_amount + $iot_amount + $second_year_insurance;

            $emi_chart_details = $this->generateInterestSchedule($final_loan_amount, $interest_rate, $loan_basic_details->loan_term, $loanScheme->interest_type ?? 'flat');
            $emi_amount        = $emi_chart_details['emi_amount'];

            $result_array = [
                'loan_details' => [
                    'id'                    => $loan_basic_details->id,
                    'borrower_id'           => $borrower_id,
                    'dealer_name'           => $dealer_details->name ?? '',
                    'model_name'            => $product_details->name ?? $loan_basic_details->model,
                    'product_type'          => $product_details->product_type ?? '',
                    'loan_amount'           => $final_loan_amount,
                    'roi'                   => $interest_rate,
                    'tenure'                => $loan_basic_details->loan_term,
                    'emi'                   => $emi_amount,
                    'iot_amount'            => $loanScheme->iot ?? 0,
                    'second_year_insurance' => $second_year_insurance,
                    'processing_fee'        => $loanScheme->processing_fee ?? 0,
                    'down_payment'          => $loan_basic_details->down_payment ?? 0,
                    'subvention_amount'     => $loanScheme->subvention_amount ?? 0,
                    'fldg'                  => $loanScheme->fldg ?? 0,
                    'first_year_insurance'  => $loanScheme->insurance_1st_year ?? 0,
                    'paid_amount'           => 0,
                    'amount_due'            => 0,
                    'status'                => $loan_basic_details->status,
                    'created_at'            => date('d-m-Y H:i:s', strtotime($loan_basic_details->created_at)) ?? '',
                ],
                'borrower_details'          => $this->transformBorrowerDetails($loan_basic_details->latestBorrowerKyc, $loan_basic_details->down_payment),
                'borrower_address'          => $this->transformBorrowerAddress($loan_basic_details->latestBorrowerAddress, $loan_basic_details->id),
                'borrower_occupation'       => $this->transformBorrowerOccupation($loan_basic_details->latestBorrowerOccupation),
                'co_applicant_details'      => $this->transformCoApplicantDetails($loan_basic_details->latestCoapplicantKyc),
                'co_applicant_address'      => $this->transformCoApplicantAddress($loan_basic_details->latestCoapplicantOccupation, $loan_basic_details->id),
                'guarantor_details'         => $this->transformGuarantorDetails($loan_basic_details->latestGuarantor),
                'guarantor_occupation'      => $this->transformGuarantorOccupation($loan_basic_details->latestGuarantorOccupation, $loan_basic_details->id),
                'credit_details'            => $this->transformCreditSummary($loan_basic_details->latestCreditSummary),
                'documents'                 => $this->transformDocuments($loan_basic_details->latestDocuments),
                'iot_details'               => $this->get_borrower_iot_details($loan_basic_details->id),
                'disbursement_details'      => $this->get_borrower_disbursement_details($loan_basic_details->id),
                'lender_details'            => $this->get_borrower_lender_details($loan_basic_details->id),
                'account_details'           => $this->get_borrower_account_details($loan_basic_details->id),
                'field_inspection_details'  => $this->get_borrower_field_inspection_details($loan_basic_details->id),
                'loan_scheme'               => $this->get_borrower_loan_scheme($loan_basic_details->id, $loan_basic_details->down_payment),
                'emi_chart'                 => $this->get_borrower_loan_chart($loan_basic_details->id),
                'reference_details'         => $this->transformReferenceData($loan_basic_details->latestReference),
            ];

            return $this->send_response('success', 'Borrower details fetched', 200, $result_array);
        } catch (Exception $e) {
            return $this->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    private function calculateNetLoanAmount($loan_amount, $loan_term)
    {
        $iot_amount = 0;
        $second_year_insurance = ($loan_term > 15 && $loan_term <= 24) ? 0 : 0;
        return $loan_amount + $iot_amount + $second_year_insurance;
    }

    private function transformBorrowerDetails($borrower_kyc, $down_payment = 0)
    {
        if (!$borrower_kyc) return [];
        $borrower_aadhar = '';
        if ($borrower_kyc->aadhar != '') {
            $borrower_aadhar = $this->helper_controller->maskAadharNumber($borrower_kyc->aadhar);
        }
        return [
            'name' => $borrower_kyc->name ?? '',
            'father_name' => $borrower_kyc->father_name ?? '',
            'mobile' => $borrower_kyc->mobile ?? '',
            'alternate_number1' => $borrower_kyc->alternate_number1 ?? '',
            'email' => $borrower_kyc->email ?? '',
            'dob_date' => isset($borrower_kyc->dob_date) ? date('d-m-Y', strtotime($borrower_kyc->dob_date)) : '',
            'marital_status' => $borrower_kyc->marital_status ?? '',
            'spouse_name' => $borrower_kyc->husband_name ?? '',
            'gender' => $borrower_kyc->gender ?? '',
            'pan' => $borrower_kyc->pan ?? '',
            'aadhar' => $borrower_aadhar ?? '',
            'driving_license_no' => $borrower_kyc->driving_license_no ?? '',
            'skip_co_applicant' => $borrower_kyc->skip_co_applicant ?? '',
            'skip_guarantor' => $borrower_kyc->skip_guarantor ?? '',
            'policy_number' => $borrower_kyc->policy_number ?? '',
            'policy_premium' => $borrower_kyc->policy_premium ?? '',
            'policy_start_date' => $borrower_kyc->policy_start_date ?? '',
            'policy_end_date' => $borrower_kyc->policy_end_date ?? '',
            'chasis_number' => $borrower_kyc->chasis_number ?? '',
            'downpayment' => $down_payment ?? 0,
            'udyam_aadhaar_number' => $borrower_kyc->udyam_aadhaar_number ?? '',
        ];
    }

    private function transformDocuments($documents)
    {
        if (!$documents) return [];

        // return [
        //     'borrower_aadhar_front' => $documents->aadhar_front ?? '',
        //     'borrower_aahar_back' => $documents->aadhar_back ?? '',
        //     'borrower_pan_doc' => $documents->pan_doc ?? '',
        //     'borrower_driving_license' => $documents->driving_license ?? '',
        //     'borrower_per_address_proof' => $documents->per_address_proof ?? '',
        //     'borrower_res_address_proof' => $documents->res_address_proof ?? '',
        //     'vehicle_quotation' => $documents->vehicle_quotation ?? '',
        //     'coAadhar_front' => $documents->coAadhar_front ?? '',
        //     'coAahar_back' => $documents->coAadhar_back ?? '',
        //     'coPan_doc' => $documents->coPan_doc ?? '',
        //     'coDriving_license' => $documents->coDriving_license ?? '',
        //     'coPerAddress_proof' => $documents->coPerAddress_proof ?? '',
        //     'coResAddress_proof' => $documents->coResAddress_proof ?? '',
        //     'additional_doc' => $documents->additional_doc ?? '',
        //     'invoice' => $documents->invoice ?? '',
        //     'performa' => $documents->performa ?? '',
        //     'down_payment_receipt' => $documents->down_payment_receipt ?? '',
        //     'insurance' => $documents->insurance ?? '',
        //     'rto_tax_receipt' => $documents->rto_tax_receipt ?? '',
        //     'dl_receipt' => $documents->dl_receipt ?? '',
        //     'documents_form' => $documents->documents_form ?? '',
        //     'guarantor_aadhar_front' => $documents->guarantor_aadhar_front ?? '',
        //     'guarantor_aadhar_back' => $documents->guarantor_aadhar_back ?? '',
        //     'guarantor_driving_license' => $documents->guarantor_driving_license ?? '',
        //     'guarantor_pan_doc' => $documents->guarantor_pan_doc ?? '',
        //     'guarantor_peraddress_proof' => $documents->guarantor_peraddress_proof ?? '',
        //     'guarantor_coresaddress_proof' => $documents->guarantor_coresaddress_proof ?? '',
        //     'borrower_avtar' => $documents->borrower_avtar ?? '',
        //     'coapplicant_avtar' => $documents->coapplicant_avtar ?? '',
        //     'guarantor_avtar' => $documents->guarantor_avtar ?? '',
        //     'loan_file' => $documents->loan_file ?? '',
        //     'battery_guaranty' => $documents->battery_guaranty ?? '',
        //     'warrant_document' => $documents->warrant_document ?? '',
        //     'rto_document' => $documents->rto_document ?? '',
        //     'mmr' => $documents->mmr ?? '',
        //     'vehicle_image' => $documents->vehicle_image ?? '',
        //     'urc_certificate' => $documents->urc_certificate ?? '',
        //     'borrower_signed_cheque' => $documents->borrower_signed_cheque ?? '',
        //     'vehicle_signed_cheque' => $documents->vehicle_signed_cheque ?? '',
        //     'doh' => $documents->doh ?? '',
        //     'borrower_other_docs' => $documents->borrower_other_docs ?? '',
        //     'vehicle_other_docs' => $documents->vehicle_other_docs ?? '',
        //     'borrower_rent_doc' => $documents->borrower_rent_doc ?? '',
        //     'coapplicant_rent_doc' => $documents->coapplicant_rent_doc ?? '',
        //     'guarantor_rent_doc' => $documents->guarantor_rent_doc ?? '',
        //     'borrower_name_declaration' => $documents->borrower_name_declaration ?? '',
        //     'coapplicant_name_declaration' => $documents->coapplicant_name_declaration ?? '',
        //     'guarantor_name_declaration' => $documents->guarantor_name_declaration ?? '',
        //     'chasis_number' => $documents->chasis_number ?? '',
        //     'motor_controller' => $documents->motor_controller ?? '',
        //     'battery_image' => $documents->battery_image ?? '',
        //     'borrower_income_proof_doc' => $documents->borrower_income_proof_doc ?? '',
        //     'coapplicant_income_proof_doc' => $documents->coapplicant_income_proof_doc ?? '',
        //     'guarantor_income_proof_doc' => $documents->guarantor_income_proof_doc ?? '',
        //     'borrower_dob_declaration' => $documents->borrower_dob_declaration ?? '',
        //     'coapplicant_dob_declaration' => $documents->coapplicant_dob_declaration ?? '',
        //     'guarantor_dob_declaration' => $documents->guarantor_dob_declaration ?? '',
        // ];

        $map = [
            'borrower_aadhar_front'         => $documents->aadhar_front,
            'borrower_aahar_back'           => $documents->aadhar_back,
            'borrower_pan_doc'              => $documents->pan_doc,
            'borrower_driving_license'      => $documents->driving_license,
            'borrower_per_address_proof'    => $documents->per_address_proof,
            'borrower_res_address_proof'    => $documents->res_address_proof,
            'vehicle_quotation'             => $documents->vehicle_quotation,
            'coAadhar_front'                => $documents->coAadhar_front,
            'coAahar_back'                  => $documents->coAadhar_back,
            'coPan_doc'                     => $documents->coPan_doc,
            'coDriving_license'             => $documents->coDriving_license,
            'coPerAddress_proof'            => $documents->coPerAddress_proof,
            'coResAddress_proof'            => $documents->coResAddress_proof,
            'additional_doc'                => $documents->additional_doc,
            'invoice'                       => $documents->invoice,
            'performa'                      => $documents->performa,
            'down_payment_receipt'          => $documents->down_payment_receipt,
            'insurance'                     => $documents->insurance,
            'rto_tax_receipt'               => $documents->rto_tax_receipt,
            'dl_receipt'                    => $documents->dl_receipt,
            'documents_form'                => $documents->documents_form,
            'guarantor_aadhar_front'        => $documents->guarantor_aadhar_front,
            'guarantor_aadhar_back'         => $documents->guarantor_aadhar_back,
            'guarantor_driving_license'     => $documents->guarantor_driving_license,
            'guarantor_pan_doc'             => $documents->guarantor_pan_doc,
            'guarantor_peraddress_proof'    => $documents->guarantor_peraddress_proof,
            'guarantor_coresaddress_proof'  => $documents->guarantor_coresaddress_proof,
            'borrower_avtar'                => $documents->borrower_avtar,
            'coapplicant_avtar'             => $documents->coapplicant_avtar,
            'guarantor_avtar'               => $documents->guarantor_avtar,
            'loan_file'                     => $documents->loan_file,
            'battery_guaranty'              => $documents->battery_guaranty,
            'warrant_document'              => $documents->warrant_document,
            'rto_document'                  => $documents->rto_document,
            'mmr'                           => $documents->mmr,
            'vehicle_image'                 => $documents->vehicle_image,
            'urc_certificate'               => $documents->urc_certificate,
            'borrower_signed_cheque'        => $documents->borrower_signed_cheque,
            'vehicle_signed_cheque'         => $documents->vehicle_signed_cheque,
            'doh'                           => $documents->doh,
            'borrower_other_docs'           => $documents->borrower_other_docs,
            'vehicle_other_docs'            => $documents->vehicle_other_docs,
            'borrower_rent_doc'             => $documents->borrower_rent_doc,
            'coapplicant_rent_doc'          => $documents->coapplicant_rent_doc,
            'guarantor_rent_doc'            => $documents->guarantor_rent_doc,
            'borrower_name_declaration'     => $documents->borrower_name_declaration,
            'coapplicant_name_declaration'  => $documents->coapplicant_name_declaration,
            'guarantor_name_declaration'    => $documents->guarantor_name_declaration,
            'chasis_number'                 => $documents->chasis_number,
            'motor_controller'              => $documents->motor_controller,
            'battery_image'                 => $documents->battery_image,
            'loi_permit'                    => $documents->loi_permit,
            'vehicle_key_img'               => $documents->vehicle_key_img,
            'borrower_income_proof_doc'     => $documents->borrower_income_proof_doc,
            'coapplicant_income_proof_doc'  => $documents->coapplicant_income_proof_doc,
            'guarantor_income_proof_doc'    => $documents->guarantor_income_proof_doc,
            'borrower_dob_declaration'      => $documents->borrower_dob_declaration,
            'coapplicant_dob_declaration'   => $documents->coapplicant_dob_declaration,
            'guarantor_dob_declaration'     => $documents->guarantor_dob_declaration,
            'borrower_cibil_report'         => $documents->borrower_cibil_report,
            'coapplicant_cibil_report'      => $documents->coapplicant_cibil_report,
            'guarantor_cibil_report'        => $documents->guarantor_cibil_report,
        ];

        return array_map(
            fn ($path) => $path ? ($this->fileStorage->urlOrNull($path) ?? '') : '',
            $map
        );
    }

    private function transformCreditSummary($creditSummary)
    {
        if (!$creditSummary) return [];

        // $borrower_cibil = $creditSummary->cibil_score ?? '';
        // $borrower_cibil = ($borrower_cibil >= 0) ? (($borrower_cibil == 0 || $borrower_cibil == '-1') ? 'NTC' : $borrower_cibil) : '';
        $borrower_cibil = $creditSummary->cibil_score ?? '';
        $borrower_cibil = ($borrower_cibil >= 0)
            ? (($borrower_cibil <= 10 || $borrower_cibil == '-1') ? 'NTC' : $borrower_cibil)
            : '';

        // $coapplicant_cibil = $creditSummary->coappli_cibil_score ?? '';
        // $coapplicant_cibil = ($coapplicant_cibil >= 0) ? (($coapplicant_cibil == 0 || $coapplicant_cibil == '-1') ? 'NTC' : $coapplicant_cibil) : '';
        $coapplicant_cibil = $creditSummary->coappli_cibil_score ?? '';
        $coapplicant_cibil = ($coapplicant_cibil >= 0)
            ? (($coapplicant_cibil <= 10 || $coapplicant_cibil == '-1') ? 'NTC' : $coapplicant_cibil)
            : '';

        // $guarantor_cibil = $creditSummary->guarantor_cibil_score ?? '';
        // $guarantor_cibil = ($guarantor_cibil >= 0) ? (($guarantor_cibil == 0 || $guarantor_cibil == '-1') ? 'NTC' : $guarantor_cibil) : '';
        $guarantor_cibil = $creditSummary->guarantor_cibil_score ?? '';
        $guarantor_cibil = ($guarantor_cibil >= 0)
            ? (($guarantor_cibil <= 10 || $guarantor_cibil == '-1') ? 'NTC' : $guarantor_cibil)
            : '';

        return [
            'borrower_cibil_score' => $borrower_cibil, // !empty($creditSummary->cibil_score) ? $creditSummary->cibil_score : 'NTC',
            'borrower_dpd_30' => $creditSummary->dpd_30_in_3_month ?? '',
            'borrower_dpd_90' => $creditSummary->dpd_90_in_1_year ?? '',
            'borrower_is_npa' => $creditSummary->is_npa ?? '',
            'borrower_is_defaulter' => $creditSummary->is_willful_defaulter ?? '',
            'coappli_cibil_score' => $coapplicant_cibil, // !empty($creditSummary->coappli_cibil_score) ? $creditSummary->coappli_cibil_score : 'NTC',
            'coappli_dpd_30' => $creditSummary->coappli_dpd_30 ?? '',
            'coappli_dpd_90' => $creditSummary->coappli_dpd_90 ?? '',
            'coappli_is_npa' => $creditSummary->coappli_is_npa ?? '',
            'coappli_defaulter' => $creditSummary->coappli_defaulter ?? '',
            'guarantor_cibil_score' => $guarantor_cibil, // !empty($creditSummary->guarantor_cibil_score) ? $creditSummary->guarantor_cibil_score : 'NTC',
            'guarantor_dpd_30' => $creditSummary->guarantor_dpd_30 ?? '',
            'guarantor_dpd_90' => $creditSummary->guarantor_dpd_90 ?? '',
            'guarantor_is_npa' => $creditSummary->guarantor_is_npa ?? '',
            'guarantor_defaulter' => $creditSummary->guarantor_defaulter ?? '',
        ];
    }

    private function transformBorrowerAddress($borrower_address, $borrower_primary)
    {
        if (!$borrower_address) return [];

        $aadhar_address = $this->helper_controller->user_address_details($borrower_primary, 'BORROWER');
        $address_details = isset($aadhar_address['status']) && $aadhar_address['status'] == 'success'
            ? $aadhar_address['data']
            : '';

        return [
            'resident_type' => $borrower_address->resident_type ?? '',
            'current_address' => $borrower_address->current_address ?? '',
            'years_living' => $borrower_address->years_living ?? '',
            'permanent_address' => $borrower_address->permanent_address ?? '',
            'family_members' => $borrower_address->family_members ?? '',
            'earning_members' => $borrower_address->earning_members ?? '',
            'family_income' => $borrower_address->family_income ?? '',
            'address_details' => $address_details,
        ];
    }

    private function transformBorrowerOccupation($borrower_occupation)
    {
        if (!$borrower_occupation) return [];

        $b_occupation_type = isset($borrower_occupation->occupation_type)
            ? $this->helper_controller->get_occupation_type($borrower_occupation->occupation_type)
            : '';

        return [
            'occupation_type' => $b_occupation_type,
            'current_occupation' => $borrower_occupation->current_occupation ?? '',
            'driving_experience' => isset($borrower_occupation->driving_experience) ? intval($borrower_occupation->driving_experience) . ' Years' : '',
            'vehicle_type' => $borrower_occupation->vehicle_type ?? '',
            'monthly_earning' => $borrower_occupation->monthly_earning ?? '',
            'expected_monthly_earning' => $borrower_occupation->expected_monthly_earning ?? '',
            'extra_source_income' => $borrower_occupation->extra_source_income ?? '',
            'source_income_area' => $borrower_occupation->source_income_area ?? '',
            'total_income' => $borrower_occupation->total_income ?? '',
        ];
    }

    private function transformCoApplicantDetails($co_applicant_details)
    {
        if (!$co_applicant_details) return [];
        // $co_applicant_aadhar = $this->helper_controller->maskAadharNumber($co_applicant_details->aadhar);
        $co_applicant_aadhar = '';
        if ($co_applicant_details->aadhar != '') {
            $co_applicant_aadhar = $this->helper_controller->maskAadharNumber($co_applicant_details->aadhar);
        }
        return [
            'name' => $co_applicant_details->name ?? '',
            'father_name' => $co_applicant_details->father_name ?? '',
            'marital_status' => $co_applicant_details->marital_status ?? '',
            'gender' => $co_applicant_details->gender ?? '',
            'mobile' => $co_applicant_details->mobile ?? '',
            'husband_name' => $co_applicant_details->husband_name ?? '',
            'pan' => $co_applicant_details->pan ?? '',
            'aadhar' => $co_applicant_aadhar ?? '',
            'dob_date' => isset($co_applicant_details->dob_date) ? date('d-m-Y', strtotime($co_applicant_details->dob_date)) : '',
        ];
    }

    private function transformCoApplicantAddress($coapplicant_address, $borrower_primary)
    {
        if (!$coapplicant_address) return [];

        $coapplicant_aadhar_address = $this->helper_controller->user_address_details($borrower_primary, 'COAPPLICANT');
        $address_details = isset($coapplicant_aadhar_address['status']) && $coapplicant_aadhar_address['status'] == 'success'
            ? $coapplicant_aadhar_address['data']
            : '';
        $c_occupation_type = isset($coapplicant_address->occupation_type)
            ? $this->helper_controller->get_occupation_type($coapplicant_address->occupation_type)
            : '';

        return [
            'occupation_type' => $c_occupation_type ?? '',
            'current_occupation' => $coapplicant_address->current_occupation ?? '',
            'monthly_earning' => $coapplicant_address->monthly_earning ?? '',
            'total_income' => $coapplicant_address->total_income ?? '',
            'source_income_area' => $coapplicant_address->source_income_area ?? '',
            'vehicle_type' => $coapplicant_address->vehicle_type ?? '',
            'resident_type' => $coapplicant_address->resident_type ?? '',
            'current_address' => $coapplicant_address->current_address ?? '',
            'years_living' => $coapplicant_address->years_living ?? '',
            'permanent_address' => $coapplicant_address->permanent_address ?? '',
            'resident_type' => $coapplicant_address->resident_type ?? '',
            'address_details' => $address_details,
        ];
    }

    private function transformReferenceData($reference_details)
    {
        if (!$reference_details) return [];

        return [
            'reference_1' => $reference_details->reference_1 ?? '',
            'mobile_1' => $reference_details->mobile_1 ?? '',
            'relation_1' => $reference_details->relation_1 ?? '',
            'address_1' => $reference_details->address_1 ?? '',
            'reference_2' => $reference_details->reference_2 ?? '',
            'mobile_2' => $reference_details->mobile_2 ?? '',
            'relation_2' => $reference_details->relation_2 ?? '',
            'address_2' => $reference_details->address_2 ?? '',
        ];
    }

    private function transformGuarantorDetails($guarantor_details)
    {
        if (!$guarantor_details) return [];

        // $guarantor_aadhar = $this->helper_controller->maskAadharNumber($guarantor_details->aadhar);
        $guarantor_aadhar = '';
        if ($guarantor_details->aadhar != '') {
            $guarantor_aadhar = $this->helper_controller->maskAadharNumber($guarantor_details->aadhar);
        }
        return [
            'name' => $guarantor_details->name ?? '',
            'father_name' => $guarantor_details->father_name ?? '',
            'marital_status' => $guarantor_details->marital_status ?? '',
            'gender' => $guarantor_details->gender ?? '',
            'mobile' => $guarantor_details->mobile ?? '',
            'email' => $guarantor_details->email ?? '',
            'husband_name' => $guarantor_details->husband_name ?? '',
            'pan' => $guarantor_details->pan ?? '',
            'aadhar' => $guarantor_aadhar ?? '',
            'dob_date' => isset($guarantor_details->dob_date) ? date('d-m-Y', strtotime($guarantor_details->dob_date)) : '',
        ];
    }

    private function transformGuarantorOccupation($guarantor_occupation, $borrower_primary)
    {
        if (!$guarantor_occupation) return [];
        $guarantor_aadhar_address = $this->helper_controller->user_address_details($borrower_primary, 'GUARANTOR');
        $address_details = isset($guarantor_aadhar_address['status']) && $guarantor_aadhar_address['status'] == 'success'
            ? $guarantor_aadhar_address['data']
            : '';

        $g_occupation_type = isset($guarantor_occupation->occupation_type)
            ? $this->helper_controller->get_occupation_type($guarantor_occupation->occupation_type)
            : '';

        return [
            'occupation_type' => $g_occupation_type,
            'current_occupation' => $guarantor_occupation->current_occupation ?? '',
            'monthly_earning' => $guarantor_occupation->monthly_earning ?? '',
            'total_income' => $guarantor_occupation->total_income ?? '',
            'source_income_area' => $guarantor_occupation->source_income_area ?? '',
            'vehicle_type' => $guarantor_occupation->vehicle_type ?? '',
            'resident_type' => $guarantor_occupation->resident_type ?? '',
            'current_address' => $guarantor_occupation->current_address ?? '',
            'years_living' => $guarantor_occupation->years_living ?? '',
            'permanent_address' => $guarantor_occupation->permanent_address ?? '',
            'address_details' => $address_details,
        ];
    }

    public function getLanguages()
    {
        return $this->fetchRecords(Languages::class, 'Language', 'language');
    }

    public function getProductTypes()
    {
        return $this->fetchRecords(ProductTypes::class, 'Product type', 'product_type');
    }

    public function getBatteryTypes()
    {
        return $this->fetchRecords(BatteryTypes::class, 'Battery sype', 'battery_type');
    }

    public function getManufacturers()
    {
        return $this->fetchRecords(Manufacturers::class, 'Manufacturer', 'manufacturer');
    }

    public function getMaritalStatuses()
    {
        return $this->fetchRecords(MaritalStatus::class, 'Marital status', 'status');
    }

    public function getResidentTypes()
    {
        return $this->fetchRecords(ResidentTypes::class, 'Resident type', 'resident_type');
    }

    public function getOccupationTypes()
    {
        return $this->fetchRecords(OccupationTypes::class, 'Occupation type', 'occupation');
    }

    public function getVehicleTypes()
    {
        return $this->fetchRecords(VehicleTypes::class, 'Vehicle type', 'vehicle_type');
    }

    public function getRelations()
    {
        return $this->fetchRecords(Relations::class, 'Relation', 'relation');
    }

    protected function fetchRecords($modelClass, $module, $filterColumn = null)
    {
        try {
            $request = request();

            // Validation rules
            $validator = Validator::make($request->all(), [
                'start_date' => 'date|nullable',
                'end_date' => 'date|nullable|after_or_equal:start_date',
                'year' => 'integer|nullable|min:1900|max:' . date('Y'),
                'month' => 'string|nullable|in:January,February,March,April,May,June,July,August,September,October,November,December',
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                $filterColumn => 'string|nullable|max:255',
            ]);

            // Check if validation fails
            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            // Start query
            $query = $modelClass::where('is_active', 1)->where('is_deleted', 0);

            // Apply filters based on request parameters with timezone adjustment

            // Filter by start date (beginning of the day in Asia/Kolkata timezone)
            if ($request->has('start_date') && !empty($request->input('start_date'))) {
                $startDate = Carbon::parse($request->input('start_date'), 'Asia/Kolkata')->startOfDay();
                $query->where('created_at', '>=', $startDate);
            }

            // Filter by end date (end of the day in Asia/Kolkata timezone)
            if ($request->has('end_date') && !empty($request->input('end_date'))) {
                $endDate = Carbon::parse($request->input('end_date'), 'Asia/Kolkata')->endOfDay();
                $query->where('created_at', '<=', $endDate);
            }

            // Filter by year
            if ($request->has('year')) {
                $query->whereYear('created_at', $request->input('year'));
            }

            // Filter by month
            if ($request->has('month')) {
                $monthNumber = Carbon::parse($request->input('month'), 'Asia/Kolkata')->month;
                $query->whereMonth('created_at', $monthNumber);
            }

            // if ($filterColumn && $request->has($filterColumn)) {
            //     $query->where($filterColumn, 'LIKE', '%' . $request->input($filterColumn) . '%');
            // }
            if ($filterColumn && $request->has($filterColumn)) {
                $query->whereRaw('LOWER(' . $filterColumn . ') LIKE ?', ['%' . strtolower($request->input($filterColumn)) . '%']);
            }

            // Apply pagination
            $page = $request->get('page', 1);
            $numberOfRecords = $request->get('number_of_records', 10);
            // $records = $query->orderBy('created_at', 'desc')->paginate($numberOfRecords, ['*'], 'page', $page);
            $records = $query->orderBy($filterColumn, 'asc')->paginate($numberOfRecords, ['*'], 'page', $page);

            if ($records->count() > 0) {
                return response()->json($records, 200);
                // return $this->send_response('success', "$module list fetched", 200, $records);
            } else {
                return $this->send_response('success', "No records found", 200);
            }
        } catch (Exception $e) {
            return $this->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    public function get_borrower_iot_details($borrower_id = null)
    {
        try {
            if (!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $borrower_iot_details = BorrowerIotDetails::where('borrower_id', $borrower_id)->first();
            if (!$borrower_iot_details) {
                return [];
            }

            return [
                'vehicle_id' => $borrower_iot_details->vehicle_id ?? '',
                'vehicle_uuid' => $borrower_iot_details->vehicle_uuid ?? '',
                'status' => $borrower_iot_details->status ?? '',
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    public function get_borrower_disbursement_details($borrower_id = null)
    {
        try {
            if (!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $disbursement_details = DisbursementDetails::select(
                [
                    'disbursement_details.*',
                    'lenders.name as lender_name'
                ]
            )
                ->leftJoin('lenders', DB::raw('CAST(disbursement_details.lender_id AS INTEGER)'), '=', 'lenders.id')
                ->where('borrower_id', $borrower_id)->first();
            if (!$disbursement_details) {
                return [];
            }

            if (isset($disbursement_details->disbursement_date) && !empty($disbursement_details->disbursement_date)) {
                $disbursement_date = date('d-m-Y', strtotime($disbursement_details->disbursement_date));
            }

            return [
                'utr_number' => $disbursement_details->utr_number ?? '',
                'disbursement_amount' => $disbursement_details->disbursement_amount ?? '',
                'disbursement_date' => $disbursement_date ?? '',
                'loan_id' => $disbursement_details->loan_id ?? '',
                'transaction_id' => $disbursement_details->transaction_id ?? '',
                'lender_name' => $disbursement_details->lender_name ?? '',
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    public function get_borrower_lender_details($borrower_id = null)
    {
        try {
            if (!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $borrower_lender_details = LenderBorrowerGroup::where('borrower_id', $borrower_id)->first();
            if (!$borrower_lender_details) {
                return [];
            }

            $lender = Lenders::find($borrower_lender_details->lender_id);
            if (!$lender) {
                return [];
            }

            $lender_name = $lender->name ?? '';
            $lender_unq = $lender->lender_unq ?? '';

            $response_name = $lender_name . ($lender_unq ? ' (' . $lender_unq . ')' : '');
            return [
                'lender' => $response_name,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    public function get_borrower_account_details($borrower_id = null)
    {
        try {
            if (!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $account_detail = BorrowerAccountDetails::where('borrower_id', $borrower_id)->first();
            if (!$account_detail) {
                return [];
            }

            $loan_basic_details = LoanBasicDetails::find($borrower_id);
            if (!$loan_basic_details) {
                return [];
            }

            return [
                'emi_debit_consent' =>  $loan_basic_details->emi_debit_consent ? 'Accepted' : '',
                'account_holder_name' => $account_detail->account_holder_name,
                'account_number' => $account_detail->account_number,
                'ifsc' => ($account_detail->ifsc) ? strtoupper($account_detail->ifsc) : '',
                'bank_name' => $account_detail->bank_name,
                'branch_code' => $account_detail->branch_code,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    public function get_borrower_product_scheme($borrower_id)
    {
        try {
            if (is_null($borrower_id)) {
                return $this->send_response('failed', "Borrower id is required", 422);
            }

            $loan_basic_details = LoanBasicDetails::with([
                'dealer'
            ])->where('borrower_id', $borrower_id)->first();
            if (!$loan_basic_details) {
                return $this->send_response('failed', "Invalid borrower", 422);
            }
            $dealer_details = $loan_basic_details->dealer;

            if (!empty($loan_basic_details->model_id)) {

                $product_details = $dealer_details
                    ? ProductModel::where('dealer_id', $dealer_details->id)->where('model_unq', $loan_basic_details->model_id)->first()
                    : null;
            } else {
                $product_details = $dealer_details
                    ? ProductModel::where('dealer_id', $dealer_details->id)->where('name', $loan_basic_details->model)->first()
                    : null;
            }

            $product_scheme = [
                'model_unq' => $product_details->model_unq ?? '',
                'name' => $product_details->name ?? '',
                'battery_type' => $product_details->battery_type ?? '',
                'ex_showroom_price' => $product_details->ex_showroom_price ?? '',
                'loan_to_value_ratio' => $product_details->loan_to_value_ratio ?? '',
                'product_type' => $product_details->product_type ?? '',
                'interest' => $product_details->interest ?? '',
                'manufacture_name' => $product_details->manufacture_name ?? '',
                'min_loan_amount' => $product_details->min_loan_amount ?? '',
                'max_loan_amount' => $product_details->max_loan_amount ?? '',
                'min_loan_tenure' => $product_details->min_loan_tenure ?? '',
                'max_loan_tenure' => $product_details->max_loan_tenure ?? '',
                'processing_fee' => $product_details->processing_fee ?? '',
                'subvention_amount' => $product_details->subvention_amount ?? '',
                'fldg' => $product_details->fldg ?? '',
                'iot' => $product_details->iot ?? '',
                'insurance_1st_year' => $product_details->insurance_1st_year ?? '',
                'insurance_2nd_year' => $product_details->insurance_1nd_year ?? '',
            ];

            return $this->send_response('success', "Product scheme fetched", 200, $product_scheme);
        } catch (Exception $e) {
            return $this->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    // public function get_borrower_field_inspection_details($borrower_id = null)
    // {
    //     try {
    //         if (!isset($borrower_id) || empty($borrower_id)) {
    //             return [];
    //         }

    //         $fi_details = FieldInspectionDetails::where('borrower_id', $borrower_id)->first();
    //         if (!$fi_details) {
    //             return [];
    //         }

    //         return [
    //             'name' => $fi_details->name ?? '',
    //             'mobile' => $fi_details->mobile ?? '',
    //             'field_verification_status' => $fi_details->is_inspection_verified ?? '',
    //             'verification_time' => isset($fi_details->verification_time) ? date('d-m-Y H:i:s', strtotime($fi_details->verification_time)) : '',
    //             'field_inspection_image' => $fi_details->fi_image ?? '',
    //             'latitude' => $fi_details->latitude ?? '',
    //             'longitude' => $fi_details->longitude ?? '',
    //             'distance' => $fi_details->distance ?? '',
    //             'address' => $fi_details->address ?? '',
    //             'meter_image' => $fi_details->meter_image ?? '',
    //             'meter_verification_status' => $fi_details->meter_verification_status ?? '',
    //             'meter_remarks' => $fi_details->meter_remarks ?? '',
    //             'meter_uploaded_at' => !empty($fi_details->meter_uploaded_at) ? date('d-m-Y H:i:s', strtotime($fi_details->meter_uploaded_at)) : '',
    //             'meter_verified_at' => !empty($fi_details->meter_verified_at) ? date('d-m-Y H:i:s', strtotime($fi_details->meter_verified_at)) : '',
    //         ];
    //     } catch (Exception $e) {
    //         return [];
    //     }
    // }

    public function get_borrower_field_inspection_details($borrower_id = null)
    {
        try {
            if (!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $fi_details = FieldInspectionDetails::where('borrower_id', $borrower_id)->latest('id')->first();
            if (!$fi_details) {
                return [];
            }

            $fmt = fn ($v) => !empty($v) ? date('d-m-Y H:i:s', strtotime($v)) : '';

            $partyOrder = ['borrower' => 0, 'co_applicant' => 1, 'guarantor' => 2];

            $parties = $fi_details->parties()
                ->get()
                ->sortBy(fn ($p) => $partyOrder[$p->party_type] ?? 9)
                ->values()
                ->map(fn ($p) => [
                    'party_type'                => $p->party_type,
                    'label'                     => match ($p->party_type) {
                        'co_applicant' => 'Co-applicant',
                        'guarantor'    => 'Guarantor',
                        default        => 'Borrower',
                    },
                    'name'                      => $p->name ?? '',
                    'mobile'                    => $p->mobile ?? '',
                    'field_verification_status' => (bool) $p->is_verified,
                    'verification_time'         => $fmt($p->verification_time),
                    'field_inspection_image'    => !empty($p->fi_image) ? $this->fileStorage->urlOrNull($p->fi_image) : '',
                    'meter_image'               => !empty($p->meter_image) ? $this->fileStorage->urlOrNull($p->meter_image) : '',
                    'fi_image_2'                => $p->party_type === 'borrower' ? (!empty($p->fi_image_2) ? $this->fileStorage->urlOrNull($p->fi_image_2) : '') : '',
                    'fi_image_3'                => $p->party_type === 'borrower' ? (!empty($p->fi_image_3) ? $this->fileStorage->urlOrNull($p->fi_image_3) : '') : '',
                    'meter_verification_status' => $p->meter_verification_status ?? '',
                    'meter_remarks'             => $p->meter_remarks ?? '',
                    'meter_uploaded_at'         => $fmt($p->meter_uploaded_at),
                    'meter_verified_at'         => $fmt($p->meter_verified_at),
                    'latitude'                  => $p->latitude ?? '',
                    'longitude'                 => $p->longitude ?? '',
                    'distance'                  => $p->distance ?? '',
                ])
                ->all();

            // Borrower party mirrored to top level for backward compatibility.
            $borrower = collect($parties)->firstWhere('party_type', 'borrower') ?? [];

            return [
                // ── legacy flat borrower fields (source: borrower party) ──
                'name'                      => $borrower['name'] ?? ($fi_details->name ?? ''),
                'mobile'                    => $borrower['mobile'] ?? ($fi_details->mobile ?? ''),
                'field_verification_status' => $fi_details->is_inspection_verified ?? '', // application-level aggregate
                'verification_time'         => $borrower['verification_time'] ?? '',
                'field_inspection_image'    => !empty($borrower['field_inspection_image']) ? $this->fileStorage->urlOrNull($borrower['field_inspection_image']) : '',
                'latitude'                  => $borrower['latitude'] ?? '',
                'longitude'                 => $borrower['longitude'] ?? '',
                'distance'                  => $borrower['distance'] ?? '',
                'address'                   => $fi_details->address ?? '',
                'meter_image'               => !empty($borrower['meter_image']) ? $this->fileStorage->urlOrNull($borrower['meter_image']) : '',
                'fi_image_2'                => !empty($borrower['fi_image_2']) ? $this->fileStorage->urlOrNull($borrower['fi_image_2']) : '',
                'fi_image_3'                => !empty($borrower['fi_image_3']) ? $this->fileStorage->urlOrNull($borrower['fi_image_3']) : '',
                'meter_verification_status' => $borrower['meter_verification_status'] ?? '',
                'meter_remarks'             => $borrower['meter_remarks'] ?? '',
                'meter_uploaded_at'         => $borrower['meter_uploaded_at'] ?? '',
                'meter_verified_at'         => $borrower['meter_verified_at'] ?? '',

                // ── new per-party breakdown ──
                'is_completed'              => (bool) $fi_details->is_inspection_verified,
                'completed_at'              => $fmt($fi_details->completed_at),
                'required_parties'          => $fi_details->requiredPartyTypes(),
                'parties'                   => $parties,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    public function get_borrower_loan_scheme($borrower_id = null, $down_payment = 0)
    {
        try {
            if (!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $loan_scheme = BorrowerLoanScheme::where('borrower_id', $borrower_id)->first();
            if (!$loan_scheme) {
                return [];
            }

            $product = ProductModel::find($loan_scheme->product_id);

            return [
                'product_name' => $product->name ?? '',
                'product_unq' => $product->model_unq ?? 0,
                'ex_showroom_price' => $loan_scheme->ex_showroom_price ?? 0,
                'loan_to_value_ratio' => $loan_scheme->loan_to_value_ratio ?? '',
                'min_loan_amount' => $loan_scheme->min_loan_amount ?? 0,
                'max_loan_amount' => $loan_scheme->max_loan_amount ?? 0,
                'min_loan_tenure' => $loan_scheme->min_loan_tenure ?? 0,
                'max_loan_tenure' => $loan_scheme->max_loan_tenure ?? 0,
                'interest' => $loan_scheme->interest ?? 0,
                'processing_fee' => $loan_scheme->processing_fee ?? 0,
                'subvention_amount' => $loan_scheme->subvention_amount ?? 0,
                'fldg' => $loan_scheme->fldg ?? 0,
                'iot' => $loan_scheme->iot ?? 0,
                'insurance_1st_year' => $loan_scheme->insurance_1st_year ?? 0,
                'insurance_2nd_year' => $loan_scheme->insurance_2nd_year ?? 0,
                'down_payment_amount' => $down_payment ?? 0,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    public function get_borrower_loan_chart($borrower_id = null)
    {
        try {
            if (!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $emiSchedules = BorrowerRepaymentSchedule::select([
                'id',
                'payment_status',
                'emi_amount',
                'number_of_installment',
                'principal_amount',
                'interest_amount',
                'fine_amount',
                'total_payment',
                'receipt_no',
                'payment_date',
                'due_date',
                'payment_mode',
                'reference',
                'remarks',
            ])->where('borrower_id', $borrower_id)
                ->orderBy('id', 'asc')
                ->get();

            if ($emiSchedules->isEmpty()) {
                return [];
            }

            // Map each schedule to an array of its properties
            $chartData = $emiSchedules->map(function ($schedule) {
                return [
                    'number_of_installment' => $schedule->number_of_installment ?? '',
                    'payment_status' => $schedule->payment_status ?? '',
                    'emi_amount' => $schedule->emi_amount ?? 0,
                    'principal_amount' => $schedule->principal_amount ?? 0,
                    'interest_amount' => $schedule->interest_amount ?? 0,
                    'fine_amount' => $schedule->fine_amount ?? 0,
                    'total_payment' => $schedule->total_payment ?? 0,
                    'receipt_no' => $schedule->receipt_no ?? '',
                    'due_date' => isset($schedule->due_date) ? date('d-m-Y', strtotime($schedule->due_date)) : '',
                    'payment_date' => isset($schedule->payment_date) ? date('d-m-Y', strtotime($schedule->payment_date)) : '',
                    'payment_mode' => $schedule->payment_mode ?? '',
                    'reference' => $schedule->reference ?? '',
                    'remarks' => $schedule->remarks ?? '',
                ];
            });

            return $chartData;
        } catch (Exception $e) {
            return [];
        }
    }

    private function parseAddress($address)
    {
        if (empty($address)) {
            return [
                'line1' => '',
                'line2' => '',
                'city' => '',
                'district' => '',
                'state' => '',
                'pincode' => ''
            ];
        }

        // Extract pincode (6 digits)
        preg_match('/\b\d{6}\b/', $address, $pincode_match);
        $pincode = $pincode_match[0] ?? '';

        // Remove pincode from address
        $address_without_pincode = preg_replace('/\b\d{6}\b/', '', $address);

        // Split by comma or common delimiters
        $parts = preg_split('/[,\n]/', $address_without_pincode);
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts);

        return [
            'line1' => $parts[0] ?? '',
            'line2' => $parts[1] ?? '',
            'city' => $parts[count($parts) - 2] ?? '',
            'district' => '',
            'state' => $parts[count($parts) - 1] ?? '',
            'pincode' => $pincode
        ];
    }

    // public function get_dealers_list(Request $request)
    // {
    //     try {
    //         $authId = auth()->id();
    //         $user   = auth()->user();

    //         // ── Lender scope (unchanged) ──────────────────────────────────────
    //         $lenderDealerIds = null;

    //         $lender_details = Lenders::where('lender_auth_id', $authId)->first();
    //         if (!$lender_details) {
    //             $lender_director_details = LenderDirectors::where('auth_id', $authId)->first();
    //             if ($lender_director_details) {
    //                 $lender_id = $lender_director_details->lender_id;
    //             }
    //         } else {
    //             $lender_id = $lender_details->id;
    //         }

    //         if (isset($lender_id)) {
    //             // Lender user — restrict to their dealer group (unchanged behaviour)
    //             $lenderDealerIds = LenderDealerGroup::where('lender_id', $lender_id)->pluck('dealer_id');
    //         }

    //         // ── Admin user scope ──────────────────────────────────────────────
    //         // Only evaluated when lenderDealerIds is null (i.e. not a lender user)
    //         $visibleDealerUnqs = null;

    //         if (is_null($lenderDealerIds)) {
    //             if ($user->is_all_borrower_accessible) {
    //                 // Full access — no dealer restriction, $visibleDealerUnqs stays null
    //             } else {
    //                 // Hierarchy-based — resolve visible dealer set from mappings
    //                 $resolved = $this->visibilityService->resolveVisibleDealerUnqs($authId);

    //                 if ($resolved->isEmpty()) {
    //                     // User has no dealer mappings — return empty
    //                     return response()->json([
    //                         'data'          => [],
    //                         'current_page'  => 1,
    //                         'per_page'      => (int) $request->get('number_of_records', 10),
    //                         'total'         => 0,
    //                         'last_page'     => 1,
    //                     ], 200);
    //                 }

    //                 $visibleDealerUnqs = $resolved;
    //             }
    //         }

    //         // ── Validation ────────────────────────────────────────────────────
    //         $page            = (int) $request->get('page', 1);
    //         $numberOfRecords = (int) $request->get('number_of_records', 10);

    //         $validator = Validator::make($request->all(), [
    //             'page'              => 'integer|min:1',
    //             'number_of_records' => 'integer|min:1',
    //             'start_date'        => 'date|nullable',
    //             'end_date'          => 'date|nullable|after_or_equal:start_date',
    //             'is_active'         => 'boolean|nullable',
    //             'is_deleted'        => 'boolean|nullable',
    //             'dealer_id'         => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable',
    //             'dealer_name'       => 'string|regex:/^[a-zA-Z0-9\- ]+$/|nullable',
    //             'dealer_type'       => 'nullable|string|in:B2B,B2C',
    //             'mobile'            => ['string', 'nullable', 'regex:/^[6-9]\d{9}$/'],
    //             'email'             => ['string', 'nullable', 'email:rfc,dns'],
    //             'pan'               => ['string', 'nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'],
    //             'aadhar'            => ['string', 'nullable', 'regex:/^\d{12}$/'],
    //         ]);

    //         if ($validator->fails()) {
    //             return $this->send_response('failed', $validator->errors()->first(), 422);
    //         }

    //         // ── Base query ────────────────────────────────────────────────────
    //         // $query = Dealer::query();
    //         $query = Dealer::with([
    //             'accountAggregator:id,entity_id,entity_type,mobile,pan_no,fip_ids,tracking_id,aa_status'
    //         ]);

    //         // Apply scope — priority: lender > hierarchy > full access (no filter)
    //         if (!is_null($lenderDealerIds)) {
    //             // Lender: filter by dealer primary IDs
    //             $query->whereIn('id', $lenderDealerIds);
    //         } elseif (!is_null($visibleDealerUnqs)) {
    //             // Hierarchy: filter by dealer_unq values from dealer_user_map
    //             $query->whereIn('dealer_unq', $visibleDealerUnqs);
    //         }
    //         // Full access: no scope applied — sees all dealers

    //         // ── Filters ───────────────────────────────────────────────────────
    //         if ($request->filled('start_date')) {
    //             $query->where('created_at', '>=', Carbon::parse($request->start_date, 'Asia/Kolkata')->startOfDay());
    //         }

    //         if ($request->filled('end_date')) {
    //             $query->where('created_at', '<=', Carbon::parse($request->end_date, 'Asia/Kolkata')->endOfDay());
    //         }

    //         if ($request->filled('is_active')) {
    //             $query->where('is_active', $request->is_active);
    //         }

    //         $query->where('is_deleted', $request->filled('is_deleted') ? $request->is_deleted : false);

    //         if ($request->filled('mobile')) {
    //             $query->where('mobile', 'like', '%' . $request->mobile . '%');
    //         }

    //         if ($request->filled('email')) {
    //             $query->whereRaw('LOWER(email) LIKE ?', ['%' . strtolower($request->email) . '%']);
    //         }

    //         if ($request->filled('pan')) {
    //             $query->where('pan', 'like', '%' . $request->pan . '%');
    //         }

    //         if ($request->filled('aadhar')) {
    //             $query->where('aadhar', 'like', '%' . $request->aadhar . '%');
    //         }

    //         if ($request->filled('year')) {
    //             $query->whereYear('created_at', $request->year);
    //         }

    //         if ($request->filled('month')) {
    //             $query->whereMonth('created_at', Carbon::parse($request->month, 'Asia/Kolkata')->month);
    //         }

    //         if ($request->filled('dealer_id')) {
    //             $query->whereRaw('LOWER(dealer_unq) LIKE ?', ['%' . strtolower(trim($request->dealer_id)) . '%']);
    //         }

    //         if ($request->filled('dealer_name')) {
    //             $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower(trim($request->dealer_name)) . '%']);
    //         }

    //         if ($request->filled('dealer_type')) {
    //             $query->whereRaw('LOWER(dealer_type) LIKE ?', ['%' . strtolower($request->dealer_type) . '%']);
    //         }

    //         // ── Paginate + transform ──────────────────────────────────────────
    //         $dealers = $query
    //             ->with(['beneficiaryDetails' => fn ($q) => $q->where('user_type', 'DEALER')])
    //             ->withCount('disbursedLoans')
    //             ->orderBy('created_at', 'desc')
    //             ->paginate($numberOfRecords, ['*'], 'page', $page);

    //         $dealers->getCollection()->transform(function ($dealer) {
    //             $b = $dealer->beneficiaryDetails;

    //             $data = array_merge($dealer->toArray(), [
    //                 'beneficiary_name' => $b?->beneficiary_name ?? '',
    //                 'account_number'   => $b?->account_number   ?? '',
    //                 'ifsc_code'        => $b?->ifsc_code        ?? '',
    //                 'micr_code'        => $b?->micr_code        ?? '',
    //             ]);

    //             unset($data['beneficiary_details']);
    //             return $data;
    //         });

    //         return response()->json($dealers, 200);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'error'   => 'An error occurred while fetching dealer records.',
    //             'message' => $e->getMessage(),
    //         ], 400);
    //     }
    // }
    public function get_dealers_list(Request $request)
    {
        try {
            $authId = auth()->id();
            $user   = auth()->user();

            // ── Lender scope ──────────────────────────────────────────────────────
            $lenderDealerIds = null;

            $lender_details = Lenders::where('lender_auth_id', $authId)->first();
            if (!$lender_details) {
                $lender_director_details = LenderDirectors::where('auth_id', $authId)->first();
                if ($lender_director_details) {
                    $lender_id = $lender_director_details->lender_id;
                }
            } else {
                $lender_id = $lender_details->id;
            }

            if (isset($lender_id)) {
                $lenderDealerIds = LenderDealerGroup::where('lender_id', $lender_id)->pluck('dealer_id');
            }

            // ── OEM scope ─────────────────────────────────────────────────────────
            $oemDealerIds = null;

            if (is_null($lenderDealerIds)) {
                $find_manufacturer = Manufacturers::where('oem_auth_id', $authId)->first();

                if ($find_manufacturer) {
                    $manufacturer_name = $find_manufacturer->manufacturer;

                    $oemDealerIds = ProductModel::whereRaw(
                            'LOWER(manufacture_name) LIKE ?',
                            ['%' . strtolower($manufacturer_name) . '%']
                        )
                        ->distinct()
                        ->pluck('dealer_id');

                    // OEM exists but has no linked dealers — return empty early
                    if ($oemDealerIds->isEmpty()) {
                        return response()->json([
                            'data'         => [],
                            'current_page' => 1,
                            'per_page'     => (int) $request->get('number_of_records', 10),
                            'total'        => 0,
                            'last_page'    => 1,
                        ], 200);
                    }
                }
            }

            // ── Admin user scope ──────────────────────────────────────────────────
            // Only evaluated when neither lender nor OEM scope applies
            $visibleDealerUnqs = null;

            if (is_null($lenderDealerIds) && is_null($oemDealerIds)) {
                if ($user->is_all_borrower_accessible) {
                    // Full access — no dealer restriction
                } else {
                    $resolved = $this->visibilityService->resolveVisibleDealerUnqs($authId);

                    if ($resolved->isEmpty()) {
                        return response()->json([
                            'data'         => [],
                            'current_page' => 1,
                            'per_page'     => (int) $request->get('number_of_records', 10),
                            'total'        => 0,
                            'last_page'    => 1,
                        ], 200);
                    }

                    $visibleDealerUnqs = $resolved;
                }
            }

            // ── Validation ────────────────────────────────────────────────────────
            $page            = (int) $request->get('page', 1);
            $numberOfRecords = (int) $request->get('number_of_records', 10);

            $validator = Validator::make($request->all(), [
                'page'              => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'start_date'        => 'date|nullable',
                'end_date'          => 'date|nullable|after_or_equal:start_date',
                'is_active'         => 'boolean|nullable',
                'is_deleted'        => 'boolean|nullable',
                'dealer_id'         => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable',
                'dealer_name'       => 'string|regex:/^[a-zA-Z0-9\- ]+$/|nullable',
                'dealer_type'       => 'nullable|string|in:B2B,B2C',
                'mobile'            => ['string', 'nullable', 'regex:/^[6-9]\d{9}$/'],
                'email'             => ['string', 'nullable', 'email:rfc,dns'],
                'pan'               => ['string', 'nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'],
                'aadhar'            => ['string', 'nullable', 'regex:/^\d{12}$/'],
            ]);

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            // ── Base query ────────────────────────────────────────────────────────
            $query = Dealer::with([
                'accountAggregator:id,entity_id,entity_type,mobile,pan_no,fip_ids,tracking_id,aa_status'
            ]);

            // Apply scope — priority: lender > OEM > hierarchy > full access
            if (!is_null($lenderDealerIds)) {
                $query->whereIn('id', $lenderDealerIds);
            } elseif (!is_null($oemDealerIds)) {
                $query->whereIn('id', $oemDealerIds);
            } elseif (!is_null($visibleDealerUnqs)) {
                $query->whereIn('dealer_unq', $visibleDealerUnqs);
            }
            // Full access: no scope applied

            // ── Filters (unchanged) ───────────────────────────────────────────────
            if ($request->filled('start_date')) {
                $query->where('created_at', '>=', Carbon::parse($request->start_date, 'Asia/Kolkata')->startOfDay());
            }

            if ($request->filled('end_date')) {
                $query->where('created_at', '<=', Carbon::parse($request->end_date, 'Asia/Kolkata')->endOfDay());
            }

            if ($request->filled('is_active')) {
                $query->where('is_active', $request->is_active);
            }

            $query->where('is_deleted', $request->filled('is_deleted') ? $request->is_deleted : false);

            if ($request->filled('mobile')) {
                $query->where('mobile', 'like', '%' . $request->mobile . '%');
            }

            if ($request->filled('email')) {
                $query->whereRaw('LOWER(email) LIKE ?', ['%' . strtolower($request->email) . '%']);
            }

            if ($request->filled('pan')) {
                $query->where('pan', 'like', '%' . $request->pan . '%');
            }

            if ($request->filled('aadhar')) {
                $query->where('aadhar', 'like', '%' . $request->aadhar . '%');
            }

            if ($request->filled('year')) {
                $query->whereYear('created_at', $request->year);
            }

            if ($request->filled('month')) {
                $query->whereMonth('created_at', Carbon::parse($request->month, 'Asia/Kolkata')->month);
            }

            if ($request->filled('dealer_id')) {
                $query->whereRaw('LOWER(dealer_unq) LIKE ?', ['%' . strtolower(trim($request->dealer_id)) . '%']);
            }

            if ($request->filled('dealer_name')) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower(trim($request->dealer_name)) . '%']);
            }

            if ($request->filled('dealer_type')) {
                $query->whereRaw('LOWER(dealer_type) LIKE ?', ['%' . strtolower($request->dealer_type) . '%']);
            }

            // ── Paginate + transform ──────────────────────────────────────────────
            $dealers = $query
                ->with(['beneficiaryDetails' => fn ($q) => $q->where('user_type', 'DEALER')])
                ->withCount('disbursedLoans')
                ->orderBy('created_at', 'desc')
                ->paginate($numberOfRecords, ['*'], 'page', $page);

            $dealers->getCollection()->transform(function ($dealer) {
                $b = $dealer->beneficiaryDetails;

                $data = array_merge($dealer->toArray(), [
                    'beneficiary_name' => $b?->beneficiary_name ?? '',
                    'account_number'   => $b?->account_number   ?? '',
                    'ifsc_code'        => $b?->ifsc_code        ?? '',
                    'micr_code'        => $b?->micr_code        ?? '',
                ]);

                unset($data['beneficiary_details']);
                return $data;
            });

            return response()->json($dealers, 200);

        } catch (Exception $e) {
            return response()->json([
                'error'   => 'An error occurred while fetching dealer records.',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function get_product_list(Request $request)
    {
        try {
            $authId = auth()->id();
            $user   = auth()->user();

            // ── Lender scope (unchanged) ──────────────────────────────────────
            $lenderDealerIds = null;

            $lender_details = Lenders::where('lender_auth_id', $authId)->first();
            if (!$lender_details) {
                $lender_director_details = LenderDirectors::where('auth_id', $authId)->first();
                if ($lender_director_details) {
                    $lender_id = $lender_director_details->lender_id;
                }
            } else {
                $lender_id = $lender_details->id;
            }

            if (isset($lender_id)) {
                $lenderDealerIds = LenderDealerGroup::where('lender_id', $lender_id)->pluck('dealer_id');
            }

            // ── Admin user scope ──────────────────────────────────────────────
            $visibleDealerIds = null; // dealer primary IDs for the JOIN filter

            if (is_null($lenderDealerIds)) {
                if ($user->is_all_borrower_accessible) {
                    // Full access — no restriction, $visibleDealerIds stays null
                } else {
                    // Hierarchy — resolve dealer_unq set, then get primary IDs
                    // product_models.dealer_id references dealers.id (primary key),
                    // so we need to convert dealer_unq → dealers.id for the filter.
                    $visibleDealerUnqs = $this->visibilityService->resolveVisibleDealerUnqs($authId);

                    if ($visibleDealerUnqs->isEmpty()) {
                        return response()->json([
                            'data'         => [],
                            'current_page' => 1,
                            'per_page'     => (int) $request->get('number_of_records', 10),
                            'total'        => 0,
                            'last_page'    => 1,
                        ], 200);
                    }

                    // Convert dealer_unq → dealer primary IDs in one query
                    // This resolves the mismatch: product_models.dealer_id = dealers.id
                    $visibleDealerIds = DB::table('dealers')
                        ->whereIn('dealer_unq', $visibleDealerUnqs)
                        ->where('is_deleted', false)
                        ->pluck('id');
                }
            }

            // ── Validation ────────────────────────────────────────────────────
            $validator = Validator::make($request->all(), [
                'page'              => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'start_date'        => 'date|nullable',
                'end_date'          => 'date|nullable|after_or_equal:start_date',
                'max_loan_amount'   => 'numeric|nullable',
                'min_loan_tenure'   => 'integer|nullable',
                'max_loan_tenure'   => 'integer|nullable',
                'product_type'      => 'string|nullable',
                'dealer_id'         => 'string|nullable',
                'model_unq'         => 'string|nullable',
                'name'              => 'string|nullable',
                'battery_type'      => 'string|nullable',
                'ex_showroom_price' => 'numeric|nullable',
                'loan_to_value_ratio'=> 'numeric|nullable',
                'manufacture_name'  => 'string|nullable',
                'interest'          => 'numeric|nullable',
            ]);

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            $page            = (int) $request->get('page', 1);
            $numberOfRecords = (int) $request->get('number_of_records', 10);

            // ── Base query ────────────────────────────────────────────────────
            $query = DB::table('product_models')
                ->join('dealers', 'product_models.dealer_id', '=', 'dealers.id')
                ->where('dealers.is_deleted', false)
                // ->where('product_models.is_deleted', false)
                ->select(
                    'dealers.dealer_unq',
                    'dealers.name as dealer_name',
                    'product_models.*',
                );

            // Apply scope — priority: lender > hierarchy > full access (no filter)
            if (!is_null($lenderDealerIds)) {
                // Lender: restrict by dealer primary ID (matches LenderDealerGroup.dealer_id)
                $query->whereIn('product_models.dealer_id', $lenderDealerIds);
            } elseif (!is_null($visibleDealerIds)) {
                // Hierarchy: restrict to tagged dealers' products
                $query->whereIn('product_models.dealer_id', $visibleDealerIds);
            }
            // Full access: no scope applied

            // ── Filters ───────────────────────────────────────────────────────
            if ($request->filled('start_date')) {
                $query->where('product_models.created_at', '>=',
                    Carbon::parse($request->start_date, 'Asia/Kolkata')->startOfDay());
            }

            if ($request->filled('end_date')) {
                $query->where('product_models.created_at', '<=',
                    Carbon::parse($request->end_date, 'Asia/Kolkata')->endOfDay());
            }

            if ($request->filled('max_loan_amount')) {
                $query->where('product_models.max_loan_amount', $request->max_loan_amount);
            }

            if ($request->filled('min_loan_tenure')) {
                $query->where('product_models.min_loan_tenure', $request->min_loan_tenure);
            }

            if ($request->filled('max_loan_tenure')) {
                $query->where('product_models.max_loan_tenure', $request->max_loan_tenure);
            }

            if ($request->filled('product_type')) {
                $query->where('product_models.product_type', $request->product_type);
            }

            if ($request->filled('dealer_id')) {
                $query->whereRaw('LOWER(dealers.dealer_unq) LIKE ?',
                    ['%' . strtolower(trim($request->dealer_id)) . '%']);
            }

            if ($request->filled('model_unq')) {
                $query->whereRaw('LOWER(product_models.model_unq) LIKE ?',
                    ['%' . strtolower($request->model_unq) . '%']);
            }

            if ($request->filled('name')) {
                $query->whereRaw('LOWER(product_models.name) LIKE ?',
                    ['%' . strtolower($request->name) . '%']);
            }

            if ($request->filled('battery_type')) {
                $query->whereRaw('LOWER(product_models.battery_type) LIKE ?',
                    ['%' . strtolower($request->battery_type) . '%']);
            }

            if ($request->filled('ex_showroom_price')) {
                $query->where('product_models.ex_showroom_price', $request->ex_showroom_price);
            }

            if ($request->filled('loan_to_value_ratio')) {
                $query->where('product_models.loan_to_value_ratio', $request->loan_to_value_ratio);
            }

            if ($request->filled('manufacture_name')) {
                $query->whereRaw('LOWER(product_models.manufacture_name) LIKE ?',
                    ['%' . strtolower($request->manufacture_name) . '%']);
            }

            if ($request->filled('interest')) {
                $query->where('product_models.interest', $request->interest);
            }

            $products = $query->orderBy('product_models.id', 'desc')
                ->paginate($numberOfRecords, ['*'], 'page', $page);

            return response()->json($products, 200);
        } catch (Exception $e) {
            return response()->json([
                'error'   => 'An error occurred while fetching product records.',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function get_rsm_screener()
    {
        try {
            $authUser = auth()->user();

            $query = AdminUser::select(['id', 'name', 'mobile'])
                ->where('user_type', AdminUser::RSM_USER)
                ->where('is_deleted', false);

            // If logged-in user is RSM → restrict to only himself
            if ($authUser->user_type === AdminUser::RSM_USER) {
                $query->where('id', $authUser->id);
            }

            $rsm_users = $query->get();

            return $this->send_response('success', "RSM list fetched", 200, $rsm_users);
        } catch (Throwable $e) {
            return $this->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }    
}
