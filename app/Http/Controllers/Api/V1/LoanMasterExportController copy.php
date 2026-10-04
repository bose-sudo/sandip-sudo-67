<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BorrowerIdentifier;
use App\Models\BorrowerIdentifierMapping;
use App\Models\Dealer;
use App\Models\LoanBasicDetails;
use App\Models\LoanServiceProvider;
use App\Models\LspExecutiveDetails;
use App\Models\Manufacturers;
use App\Models\ProductModel;
use App\Traits\ApiResponse;
use App\Services\BorrowerVisibilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use App\Http\Controllers\HelperController;

class LoanMasterExportController extends Controller
{
    use ApiResponse;

    public function __construct(
    private readonly BorrowerVisibilityService $visibilityService,
    private readonly HelperController $helper_controller,
) {}

    // =========================================================================
    // ENTRY POINT
    // =========================================================================
    public function export(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'year'       => 'nullable|integer|min:2000|max:2100',
            'month'      => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'lender_id'  => 'nullable|integer',
            'lsp_id'     => 'nullable|integer',
            'dealer_id'  => 'nullable|integer',
            'status'     => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        ini_set('memory_limit', '1024M');
        ini_set('max_execution_time', 600);

        $authId = auth()->id();
        [$baseQuery, $empty] = $this->resolveBaseQuery($authId);

        if ($empty) {
            return $this->send_response('success', 'No records found', 200, []);
        }

        // ── Filters ───────────────────────────────────────────────────────────
        $this->applyFilters($baseQuery, $request);

        // ── Fetch loan master rows ────────────────────────────────────────────
        $loans = $baseQuery
            ->with([
                'dealer_detail:id,dealer_unq,name',
                'user_detail:id,name,mobile,email',
                'latestBorrowerKyc:id,borrower_id,name,mobile,pan,email,dob_date,gender',
                'latestBorrowerAddress:id,borrower_id,current_address,permanent_address',
                'latestBorrowerOccupation:id,borrower_id,current_occupation,monthly_earning,total_income',
                'latestLoanScheme:id,borrower_id,iot,interest,processing_fee,ex_showroom_price',
                'lender:id,name,lender_unq',                              // ✅ direct
                'latestDisbursement:id,borrower_id,disbursement_date,disbursement_amount,transaction_id,utr_number',
                'firstRepaymentSchedule2:id,borrower_id,due_date,emi_amount',
                'latestRepaymentSchedule:id,borrower_id,due_date',
            ])
            ->select([
                'loan_basic_details.id',
                'loan_basic_details.borrower_id',
                'loan_basic_details.user_id',
                'loan_basic_details.loan_amount',
                'loan_basic_details.loan_term',
                'loan_basic_details.status',
                'loan_basic_details.dealer_id',
                'loan_basic_details.product_id',
                'loan_basic_details.model_id',
                'loan_basic_details.model',
                'loan_basic_details.created_at',
                'loan_basic_details.executive_id',
                'loan_basic_details.down_payment',
                'loan_basic_details.lender_id',                           // ✅ direct
            ])
            ->orderBy('loan_basic_details.created_at', 'desc')
            ->get();

        if ($loans->isEmpty()) {
            return $this->send_response('success', 'No records found', 200, []);
        }

        $loanIds = $loans->pluck('id')->all();

        // ── Period end for monthly snapshot ───────────────────────────────────
        $periodEnd = $this->resolvePeriodEnd($request);

        // ── Fetch / build other sheets ────────────────────────────────────────
        $repaymentTxns      = $this->fetchRepaymentTxns($loanIds);
        $monthlyPerformance = $this->buildMonthlyPerformance($loans, $repaymentTxns, $periodEnd);
        $applicationFunnel  = $this->buildApplicationFunnel($loans);

        // ── Build Excel ───────────────────────────────────────────────────────
        return $this->buildWorkbook(
            $loans,
            $monthlyPerformance,
            $repaymentTxns,
            $applicationFunnel,
            $request
        );
    }

    // =========================================================================
    // Access control (null-safe)
    // =========================================================================
    private function resolveBaseQuery(int $authId): array
    {
        $base = LoanBasicDetails::query();

        // ── LSP ───────────────────────────────────────────────────────────────
        $lsp = LoanServiceProvider::where('lsp_auth_id', $authId)->first();
        if ($lsp) {
            $execIds = LspExecutiveDetails::where('lsp_id', $lsp->id)->pluck('id');
            if ($execIds->isEmpty()) return [null, true];
            $base->whereIn('executive_id', $execIds);
            return [$base, false];
        }

        // ── Dealer ────────────────────────────────────────────────────────────
        $dealerAuth = Dealer::where('dealer_auth_id', $authId)->first();
        if ($dealerAuth) {
            $base->where('dealer_id', $dealerAuth->dealer_unq);
            return [$base, false];
        }

        $user = auth()->user();
        if (!$user) return [null, true];

        // ── OEM ───────────────────────────────────────────────────────────────
        $manufacturer = Manufacturers::where('oem_auth_id', $authId)->first();
        if ($manufacturer) {
            $dealerUnqs = ProductModel::whereRaw(
                'LOWER(manufacture_name) LIKE ?',
                ['%' . strtolower($manufacturer->manufacturer) . '%']
            )->distinct()->pluck('dealer_id')
             ->pipe(fn($ids) => Dealer::whereIn('id', $ids)->pluck('dealer_unq'));

            if ($dealerUnqs->isEmpty()) return [null, true];
            $base->whereIn('dealer_id', $dealerUnqs);
            return [$base, false];
        }

        // ── user_type 36 (null-safe) ──────────────────────────────────────────
        $userType = $user->user_detail->user_type ?? $user->user_type ?? null;
        if ((int) $userType === 36) {
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
            return [$base, false];
        }

        // ── Full access (null-safe) ───────────────────────────────────────────
        $isAllAcc = $user->user_detail->is_all_borrower_accessible
                 ?? $user->is_all_borrower_accessible
                 ?? false;

        if ($isAllAcc) {
            return [$base, false];
        }

        // ── Hierarchy fallback ────────────────────────────────────────────────
        $visibleDealerUnqs = $this->visibilityService->resolveVisibleDealerUnqs($authId);
        if ($visibleDealerUnqs->isEmpty()) return [null, true];

        $base->whereIn('dealer_id', $visibleDealerUnqs);
        return [$base, false];
    }

    // =========================================================================
    // Filters (YTD support + direct lender filter)
    // =========================================================================
    private function applyFilters($query, Request $request): void
    {
        $tz  = 'Asia/Kolkata';
        $now = Carbon::now($tz);

        // ── Year / Month / Range ──────────────────────────────────────────────
        if ($request->filled('year')) {
            $year = (int) $request->year;

            // Single month
            if ($request->filled('month')) {
                $monthInput = trim($request->month);
                $monthNum   = is_numeric($monthInput)
                    ? (int) $monthInput
                    : Carbon::parse("1 {$monthInput} {$year}")->month;

                $query->whereYear('loan_basic_details.created_at', $year)
                      ->whereMonth('loan_basic_details.created_at', $monthNum);
            }
            // Custom date range
            elseif ($request->filled('start_date') || $request->filled('end_date')) {
                if ($request->filled('start_date')) {
                    $query->where('loan_basic_details.created_at', '>=',
                        Carbon::parse($request->start_date, $tz)->startOfDay());
                }
                if ($request->filled('end_date')) {
                    $query->where('loan_basic_details.created_at', '<=',
                        Carbon::parse($request->end_date, $tz)->endOfDay());
                }
            }
            // YTD: Jan 1 → current month end (or Dec 31 for past years)
            else {
                $startOfYear = Carbon::create($year, 1, 1, 0, 0, 0, $tz)->startOfDay();
                $endOfPeriod = ($year === $now->year)
                    ? $now->copy()->endOfMonth()
                    : Carbon::create($year, 12, 31, 23, 59, 59, $tz);

                $query->where('loan_basic_details.created_at', '>=', $startOfYear)
                      ->where('loan_basic_details.created_at', '<=', $endOfPeriod);
            }
        } else {
            if ($request->filled('start_date') || $request->filled('end_date')) {
                if ($request->filled('start_date')) {
                    $query->where('loan_basic_details.created_at', '>=',
                        Carbon::parse($request->start_date, $tz)->startOfDay());
                }
                if ($request->filled('end_date')) {
                    $query->where('loan_basic_details.created_at', '<=',
                        Carbon::parse($request->end_date, $tz)->endOfDay());
                }
            }
        }

        // ── Lender filter (direct column) ─────────────────────────────────────
        if ($request->filled('lender_id')) {
            $query->where('loan_basic_details.lender_id', $request->lender_id);   // ✅ direct
        }

        // ── LSP filter ────────────────────────────────────────────────────────
        if ($request->filled('lsp_id')) {
            $execIds = LspExecutiveDetails::where('lsp_id', $request->lsp_id)->pluck('id');
            if ($execIds->isNotEmpty()) {
                $query->whereIn('loan_basic_details.executive_id', $execIds);
            }
        }

        // ── Dealer filter ─────────────────────────────────────────────────────
        if ($request->filled('dealer_id')) {
            $dealer = Dealer::find($request->dealer_id);
            if ($dealer) {
                $query->where('loan_basic_details.dealer_id', $dealer->dealer_unq);
            }
        }

        // ── Status filter ─────────────────────────────────────────────────────
        if ($request->filled('status')) {
            $query->where('loan_basic_details.status', $request->status);
        }
    }

    // =========================================================================
    // Resolve period end
    // =========================================================================
    private function resolvePeriodEnd(Request $request): Carbon
    {
        $tz = 'Asia/Kolkata';

        if ($request->filled('end_date')) {
            return Carbon::parse($request->end_date, $tz)->endOfDay();
        }

        if ($request->filled('month') && $request->filled('year')) {
            $monthNum = is_numeric($request->month)
                ? (int) $request->month
                : Carbon::parse("1 {$request->month} {$request->year}")->month;

            return Carbon::create((int) $request->year, $monthNum, 1, 0, 0, 0, $tz)->endOfMonth();
        }

        if ($request->filled('year')) {
            $year = (int) $request->year;
            $now  = Carbon::now($tz);

            return ($year === $now->year)
                ? $now->copy()->endOfMonth()
                : Carbon::create($year, 12, 31, 23, 59, 59, $tz);
        }

        return Carbon::now($tz)->endOfMonth();
    }

    // =========================================================================
    // SHEET 3 — Repayment Transactions (safe with fallback)
    // =========================================================================
    private function fetchRepaymentTxns(array $loanIds): \Illuminate\Support\Collection
    {
        try {
            return DB::table('borrower_repayment_schedules')
                ->whereIn('borrower_id', $loanIds)
                ->orderBy('borrower_id')
                ->orderBy('due_date')
                ->get([
                    'borrower_id',
                    'due_date',
                    'emi_amount',
                    'payment_date',
                    'payment_status',
                    'principal_amount',
                    'interest_amount',
                    'penalty_amount',
                    'payment_mode',
                ]);
        } catch (\Throwable $e) {
            // Fallback: jodi upore mention kora column na thake
            // \Log::warning('fetchRepaymentTxns fallback: ' . $e->getMessage());

            return DB::table('borrower_repayment_schedules')
                ->whereIn('borrower_id', $loanIds)
                ->orderBy('borrower_id')
                ->orderBy('due_date')
                ->get([
                    'borrower_id',
                    'due_date',
                    'emi_amount',
                    'payment_date',
                    'payment_status',
                ]);
        }
    }

    // =========================================================================
    // SHEET 2 — Monthly Performance (derived)
    // =========================================================================
    private function buildMonthlyPerformance($loans, $repaymentTxns, Carbon $periodEnd): array
    {
        $tz = 'Asia/Kolkata';
        $schedulesByLoan = $repaymentTxns->groupBy('borrower_id');
        $rows = [];

        foreach ($loans as $loan) {
            $disbDate = $loan->latestDisbursement?->disbursement_date
                ? Carbon::parse($loan->latestDisbursement->disbursement_date, $tz)
                : Carbon::parse($loan->created_at, $tz);

            $disbMonth = $disbDate->copy()->startOfMonth();
            $lastEmi   = $loan->latestRepaymentSchedule?->due_date
                ? Carbon::parse($loan->latestRepaymentSchedule->due_date, $tz)
                : null;

            $loanAmount = (float) $loan->loan_amount + (float) ($loan->latestLoanScheme?->iot ?? 0);
            $tenure     = (int) $loan->loan_term;
            $schedules  = $schedulesByLoan->get($loan->id, collect());

            // End month = min(last EMI month, period end month)
            $endMonth = match ($loan->status) {
                'CLOSED', 'DISBURSED' => $lastEmi
                    ? $lastEmi->copy()->startOfMonth()
                    : $periodEnd->copy()->startOfMonth(),
                default => $periodEnd->copy()->startOfMonth(),
            };
            if ($endMonth->gt($periodEnd)) {
                $endMonth = $periodEnd->copy()->startOfMonth();
            }

            $cursor       = $disbMonth->copy();
            $mob          = 0;
            $cumCollected = 0;

            while ($cursor->lte($endMonth)) {
                $monthStart = $cursor->copy()->startOfMonth();
                $monthEnd   = $cursor->copy()->endOfMonth();
                $mob++;

                $monthSched = $schedules->filter(function ($s) use ($monthStart, $monthEnd) {
                    $due = Carbon::parse($s->due_date);
                    return $due->gte($monthStart) && $due->lte($monthEnd);
                });

                $amountDue  = $monthSched->sum(fn($s) => (float) $s->emi_amount);
                $amountPaid = $monthSched->filter(fn($s) => $s->payment_status === 'PAID')
                                        ->sum(fn($s) => (float) $s->emi_amount);

                $cumCollected += $amountPaid;
                $overdue      = max($amountDue - $amountPaid, 0);

                $dpd = 0;
                foreach ($monthSched as $s) {
                    if ($s->payment_status !== 'PAID') {
                        $due = Carbon::parse($s->due_date);
                        if ($due->lt($monthEnd)) {
                            $days = (int) $due->diffInDays($monthEnd);
                            $dpd  = max($dpd, $days);
                        }
                    } else {
                        $payDate = $s->payment_date ? Carbon::parse($s->payment_date) : null;
                        if ($payDate && $payDate->gt(Carbon::parse($s->due_date))) {
                            $days = (int) Carbon::parse($s->due_date)->diffInDays($payDate);
                            $dpd  = max($dpd, $days);
                        }
                    }
                }

                $paidCount   = $schedules->filter(fn($s) =>
                    $s->payment_status === 'PAID'
                    && Carbon::parse($s->due_date)->lte($monthEnd))->count();
                $remaining   = max($tenure - $paidCount, 0);
                $principalOs = $remaining * ($tenure > 0 ? $loanAmount / $tenure : 0);

                $bucket = match (true) {
                    $dpd === 0 => '0',
                    $dpd <= 30 => '1-30',
                    $dpd <= 60 => '31-60',
                    $dpd <= 90 => '61-90',
                    default    => '90+',
                };

                $assetClass = match (true) {
                    $loan->status === 'CLOSED' => 'Closed',
                    $dpd === 0                 => 'Standard',
                    $dpd <= 30                 => 'SMA-0',
                    $dpd <= 60                 => 'SMA-1',
                    $dpd <= 90                 => 'SMA-2',
                    default                    => 'NPA',
                };

                $rows[] = [
                    'Loan_ID'               => $loan->borrower_id,
                    'Snapshot_Month'        => $cursor->format('Y-m'),
                    'MOB'                   => $mob,
                    'Principal_Outstanding' => round($principalOs, 2),
                    'Interest_Outstanding'  => round($overdue * 0.15, 2),
                    'Charges_Outstanding'   => 0,
                    'Total_Outstanding'     => round($principalOs + $overdue, 2),
                    'Amount_Due_Month'      => round($amountDue, 2),
                    'Amount_Paid_Month'     => round($amountPaid, 2),
                    'Overdue_Amount'        => round($overdue, 2),
                    'DPD'                   => $dpd,
                    'DPD_Bucket'            => $bucket,
                    'Asset_Classification'  => $assetClass,
                    'Loan_Status'           => $loan->status,
                    'Cumulative_Collected'  => round($cumCollected, 2),
                    'Bounce_Count_Month'    => 0,
                    'Write_Off_Amount'      => 0,
                    'DLG_Invoked_Amount'    => 0,
                    'Recovery_After_WO'     => 0,
                ];

                $cursor->addMonth();
            }
        }

        return $rows;
    }

    // =========================================================================
    // SHEET 4 — Application Funnel
    // =========================================================================
    private function buildApplicationFunnel($loans): array
    {
        $tz   = 'Asia/Kolkata';
        $rows = [];

        foreach ($loans as $loan) {
            $dealer = $loan->dealer_detail;
            $occ    = $loan->latestBorrowerOccupation;
            $addr   = $loan->latestBorrowerAddress;
            $disb   = $loan->latestDisbursement;

            $appDate  = $loan->created_at ? Carbon::parse($loan->created_at, $tz) : null;
            $disbDate = $disb?->disbursement_date ? Carbon::parse($disb->disbursement_date, $tz) : null;

            $tatHours = ($appDate && $disbDate) ? (int) $appDate->diffInHours($disbDate) : '';

            $stage = match ($loan->status) {
                'NEW'       => 'Login',
                'APPROVED'  => 'Sanction',
                'DISBURSED' => 'Disbursed',
                'REJECTED'  => 'Rejected',
                'HOLD'      => 'Underwriting',
                'CANCELLED' => 'Dropped',
                default     => $loan->status,
            };

            $decision = match ($loan->status) {
                'APPROVED', 'DISBURSED' => 'Approved',
                'REJECTED', 'CANCELLED' => 'Rejected',
                default                 => 'Pending',
            };

            $rows[] = [
                'Application_ID'      => $loan->borrower_id,
                'Loan_ID'             => in_array($loan->status, ['APPROVED', 'DISBURSED']) ? $loan->borrower_id : '',
                'Application_Date'    => $appDate?->format('d-m-Y') ?? '',
                'Sourcing_Channel'    => $loan->executive_id ? 'DLA' : 'Direct',
                'DLA_Name'            => $dealer->name ?? '',
                'Product_Type'        => $this->productTypeLabel($loan->product_id),
                'Requested_Amount'    => $loan->loan_amount,
                'State'               => $this->extractState($addr?->current_address ?? ''),
                'District'            => '',
                'City'                => '',
                'PIN_Code'            => $this->extractPincode($addr?->current_address ?? ''),
                'Occupation_Type'     => $occ?->current_occupation ?? '',
                'Monthly_Income'      => $occ?->monthly_earning ?? '',
                'Bureau_Score'        => '',
                'Internal_Risk_Grade' => '',
                'Stage_Reached'       => $stage,
                'Decision'            => $decision,
                'Rejection_Reason'    => $loan->status === 'REJECTED' ? 'Policy' : '',
                'Decision_By'         => 'System',
                'Decision_Date'       => $disbDate?->format('d-m-Y') ?? '',
                'Disbursal_TAT_Hours' => $tatHours,
            ];
        }

        return $rows;
    }

    // =========================================================================
    // BUILD WORKBOOK
    // =========================================================================
    private function buildWorkbook($loans, array $monthlyPerformance, $repaymentTxns, array $applicationFunnel, Request $request)
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        // ── Sheet 1: Loan Master ──────────────────────────────────────────────
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('1_Loan_Master');
        $this->writeSheet($sheet1, $this->loanMasterHeaders(), $this->loanMasterRows($loans));

        // ── Sheet 2: Monthly Performance ──────────────────────────────────────
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('2_Monthly_Performance');
        $this->writeSheet($sheet2, $this->monthlyPerformanceHeaders(), array_map('array_values', $monthlyPerformance));

        // ── Sheet 3: Repayment Txns ───────────────────────────────────────────
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('3_Repayment_Txns');
        $this->writeSheet($sheet3, $this->repaymentTxnHeaders(), $this->repaymentTxnRows($repaymentTxns, $loans));

        // ── Sheet 4: Application Funnel ───────────────────────────────────────
        $sheet4 = $spreadsheet->createSheet();
        $sheet4->setTitle('4_Application_Funnel');
        $this->writeSheet($sheet4, $this->applicationFunnelHeaders(), array_map('array_values', $applicationFunnel));

        $spreadsheet->setActiveSheetIndex(0);

        $filename = $this->buildFilename($request);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    // =========================================================================
    // Filename builder — YTD aware
    // =========================================================================
    private function buildFilename(Request $request): string
    {
        $tz     = 'Asia/Kolkata';
        $now    = Carbon::now($tz);
        $year   = $request->filled('year') ? (int) $request->year : (int) $now->year;
        $suffix = (string) $year;

        if ($request->filled('month')) {
            $suffix .= '_' . ucfirst(strtolower(trim($request->month)));
        } elseif ($request->filled('start_date') || $request->filled('end_date')) {
            $start = $request->filled('start_date') ? Carbon::parse($request->start_date)->format('M') : 'Jan';
            $end   = $request->filled('end_date')   ? Carbon::parse($request->end_date)->format('M')   : $now->format('M');
            $suffix .= "_{$start}-{$end}";
        } else {
            $currentMonth = ($year === (int) $now->year) ? $now->format('M') : 'Dec';
            $suffix .= "_YTD_Jan-{$currentMonth}";
        }

        return "Loan_Master_Report_{$suffix}_" . date('Ymd_His') . '.xlsx';
    }

    // =========================================================================
    // Generic sheet writer
    // =========================================================================
    private function writeSheet($sheet, array $headers, array $rows): void
    {
        $colCount = count($headers);
        $lastCol  = Coordinate::stringFromColumnIndex($colCount);

        // Header
        $sheet->fromArray($headers, null, 'A1', true);

        for ($i = 1; $i <= $colCount; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setWidth(
                max(18, min(45, strlen($headers[$i - 1]) + 6))
            );
        }

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 10],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F4E79']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);

        // Data
        $rowIdx = 2;
        foreach ($rows as $row) {
            $sheet->fromArray(array_values($row), null, "A{$rowIdx}", true);
            if ($rowIdx % 2 === 0) {
                $sheet->getStyle("A{$rowIdx}:{$lastCol}{$rowIdx}")
                      ->getFill()->setFillType(Fill::FILL_SOLID)
                      ->getStartColor()->setARGB('FFF0F4FA');
            }
            $rowIdx++;
        }

        if ($rowIdx > 2) {
            $sheet->getStyle("A1:{$lastCol}" . ($rowIdx - 1))
                  ->getBorders()->getAllBorders()
                  ->setBorderStyle(Border::BORDER_THIN)
                  ->getColor()->setRGB('BDD7EE');
            $sheet->setAutoFilter("A1:{$lastCol}1");
        }

        $sheet->freezePane('A2');
    }

    // =========================================================================
    // Header definitions
    // =========================================================================
    private function loanMasterHeaders(): array
    {
        return [
            'Loan_ID', 'LSP_Application_ID', 'Lender_Name', 'LSP_Name', 'DLA_Name',
            'Product_Type', 'Secured_Flag', 'Sourcing_Channel', 'Customer_ID',
            'Repeat_Borrower_Flag', 'Age_at_Sanction', 'Gender', 'Occupation_Type',
            'Monthly_Income', 'Income_Verified_Flag', 'State', 'District', 'City',
            'PIN_Code', 'Bureau_Name', 'Bureau_Score', 'New_To_Credit_Flag',
            'Internal_Risk_Grade', 'KYC_Mode', 'Application_Date', 'Sanction_Date',
            'Disbursement_Date', 'Sanctioned_Amount', 'Disbursed_Amount',
            'Net_Disbursed_Amount', 'Interest_Rate', 'Interest_Type', 'APR',
            'Processing_Fee', 'Other_Charges', 'GST_on_Fees', 'Tenure_Months',
            'Repayment_Frequency', 'EMI_Amount', 'First_EMI_Date', 'Maturity_Date',
            'Repayment_Mode', 'KFS_Issued_Flag', 'Cooling_Off_Exit_Flag',
            'DLG_Covered_Flag', 'LSP_Commission',
        ];
    }

    private function monthlyPerformanceHeaders(): array
    {
        return [
            'Loan_ID', 'Snapshot_Month', 'MOB', 'Principal_Outstanding',
            'Interest_Outstanding', 'Charges_Outstanding', 'Total_Outstanding',
            'Amount_Due_Month', 'Amount_Paid_Month', 'Overdue_Amount', 'DPD',
            'DPD_Bucket', 'Asset_Classification', 'Loan_Status',
            'Cumulative_Collected', 'Bounce_Count_Month', 'Write_Off_Amount',
            'DLG_Invoked_Amount', 'Recovery_After_WO',
        ];
    }

    private function repaymentTxnHeaders(): array
    {
        return [
            'Loan_ID', 'Instalment_No', 'Due_Date', 'Amount_Due', 'Payment_Date',
            'Amount_Paid', 'Principal_Component', 'Interest_Component',
            'Penal_Charges_Paid', 'Days_Late', 'Payment_Mode', 'Bounce_Flag',
            'Bounce_Reason', 'Collected_By',
        ];
    }

    private function applicationFunnelHeaders(): array
    {
        return [
            'Application_ID', 'Loan_ID', 'Application_Date', 'Sourcing_Channel',
            'DLA_Name', 'Product_Type', 'Requested_Amount', 'State', 'District',
            'City', 'PIN_Code', 'Occupation_Type', 'Monthly_Income', 'Bureau_Score',
            'Internal_Risk_Grade', 'Stage_Reached', 'Decision', 'Rejection_Reason',
            'Decision_By', 'Decision_Date', 'Disbursal_TAT_Hours',
        ];
    }

    // =========================================================================
    // Row builders
    // =========================================================================
    private function loanMasterRows($loans): array
    {
        $tz  = 'Asia/Kolkata';
        $out = [];

        foreach ($loans as $loan) {
            $kyc    = $loan->latestBorrowerKyc;
            $dealer = $loan->dealer_detail;
            $occ    = $loan->latestBorrowerOccupation;
            $addr   = $loan->latestBorrowerAddress;
            $scheme = $loan->latestLoanScheme;
            $lender = $loan->lender;                                  // ✅ direct
            $disb   = $loan->latestDisbursement;
            $first  = $loan->firstRepaymentSchedule2;
            $last   = $loan->latestRepaymentSchedule;

            $emiAmount  = $first?->emi_amount ?? 0;
            $disbAmount = $disb?->disbursement_amount ?? 0;
            $pf         = (float) ($scheme?->processing_fee ?? 0);
            $netDisb    = $disbAmount - $pf;

            $age = $kyc?->dob_date ? Carbon::parse($kyc->dob_date)->age : '';

            $appDate  = $loan->created_at ? Carbon::parse($loan->created_at, $tz) : null;
            $disbDate = $disb?->disbursement_date ? Carbon::parse($disb->disbursement_date, $tz) : null;

            $out[] = [
                'Loan_ID'               => $loan->borrower_id ?? '',
                'LSP_Application_ID'    => $loan->borrower_id ?? '',
                'Lender_Name'           => $lender->name ?? '',
                'LSP_Name'              => '',
                'DLA_Name'              => $dealer->name ?? '',
                'Product_Type'          => $this->productTypeLabel($loan->product_id),
                'Secured_Flag'          => 'Yes',
                'Sourcing_Channel'      => $loan->executive_id ? 'DLA' : 'Direct',
                // 'Customer_ID'           => $loan->user_id ?? '',
                'Customer_ID'           => $loan->borrower_id  ?? '',
                'Repeat_Borrower_Flag'  => 'No',
                'Age_at_Sanction'       => $age,
                'Gender'                => $kyc->gender ?? '',
                'Occupation_Type'       => $occ?->current_occupation ?? '',
                'Monthly_Income'        => $occ?->monthly_earning ?? '',
                'Income_Verified_Flag'  => $occ?->total_income ? 'Yes' : 'No',
                'State'                 => $this->extractState($addr?->current_address ?? ''),
                'District'              => '',
                'City'                  => '',
                'PIN_Code'              => $this->extractPincode($addr?->current_address ?? ''),
                'Bureau_Name'           => '',
                'Bureau_Score'          => '',
                'New_To_Credit_Flag'    => '',
                'Internal_Risk_Grade'   => '',
                'KYC_Mode'              => 'Aadhaar',
                'Application_Date'      => $appDate?->format('d-m-Y') ?? '',
                'Sanction_Date'         => $appDate?->format('d-m-Y') ?? '',
                'Disbursement_Date'     => $disbDate?->format('d-m-Y') ?? '',
                'Sanctioned_Amount'     => $loan->loan_amount,
                'Disbursed_Amount'      => $disbAmount,
                'Net_Disbursed_Amount'  => $disbAmount,
                'Interest_Rate'         => $scheme?->interest ?? '',
                'Interest_Type'         => 'Reducing',
                'APR'                   => '',
                'Processing_Fee'        => $pf,
                'Other_Charges'         => 0,
                'GST_on_Fees'           => round($pf * 0.18, 2),
                'Tenure_Months'         => $loan->loan_term ?? '',
                'Repayment_Frequency'   => 'Monthly',
                'EMI_Amount'            => $emiAmount,
                'First_EMI_Date'        => $first?->due_date
                                                ? Carbon::parse($first->due_date)->format('d-m-Y')
                                                : '',
                'Maturity_Date'         => $last?->due_date
                                                ? Carbon::parse($last->due_date)->format('d-m-Y')
                                                : '',
                'Repayment_Mode'        => 'NACH',
                'KFS_Issued_Flag'       => 'Yes',
                'Cooling_Off_Exit_Flag' => 'No',
                'DLG_Covered_Flag'      => 'No',
                'LSP_Commission'        => 0,
            ];
        }

        return $out;
    }

    private function repaymentTxnRows($repaymentTxns, $loans): array
    {
        $loanMap = $loans->keyBy('id');
        $out     = [];
        $counter = [];

        foreach ($repaymentTxns as $txn) {
            $loan = $loanMap->get($txn->borrower_id);
            if (!$loan) continue;

            $counter[$txn->borrower_id] = ($counter[$txn->borrower_id] ?? 0) + 1;

            $dueDate  = Carbon::parse($txn->due_date);
            $payDate  = $txn->payment_date ? Carbon::parse($txn->payment_date) : null;
            $daysLate = $payDate ? max((int) $dueDate->diffInDays($payDate, false), 0) : 0;

            $out[] = [
                'Loan_ID'             => $loan->borrower_id,
                'Instalment_No'       => $counter[$txn->borrower_id],
                'Due_Date'            => $dueDate->format('d-m-Y'),
                'Amount_Due'          => $txn->emi_amount,
                'Payment_Date'        => $payDate?->format('d-m-Y') ?? '',
                'Amount_Paid'         => $txn->payment_status === 'PAID' ? $txn->emi_amount : 0,
                'Principal_Component' => $txn->principal_amount ?? '',
                'Interest_Component'  => $txn->interest_amount  ?? '',
                'Penal_Charges_Paid'  => $txn->penalty_amount   ?? 0,
                'Days_Late'           => $daysLate,
                'Payment_Mode'        => $txn->payment_mode     ?? 'NACH',
                'Bounce_Flag'         => ($txn->payment_status !== 'PAID' && $payDate) ? 'Yes' : 'No',
                'Bounce_Reason'       => '',
                'Collected_By'        => 'INNACH',
            ];
        }

        return $out;
    }


    private function resolveAddressFull($addressMap, $borrowerId): array
    {
        $addressData = (is_array($addressMap) && isset($addressMap[$borrowerId]))
            ? $addressMap[$borrowerId]
            : $this->helper_controller->user_address_details($borrowerId, 'BORROWER');

        return $addressData['data']['full_address'] ?? [];
    }

    // =========================================================================
    // Helpers
    // =========================================================================
    private function productTypeLabel($productId): string
    {
        return match ((string) $productId) {
            '1' => 'E-Rickshaw (L3)',
            '2' => 'E-Auto (L5)',
            '3' => 'E-Two Wheeler',
            '4' => 'Battery',
            default => 'EV',
        };
    }

    /**
     * Extract state name from a raw address string.
     */
    private function extractState(string $address): string
    {
        if (empty($address)) return '';

        $clean = preg_replace('/\b\d{6}\b/', '', $address);
        $clean = preg_replace('/\s+/', ' ', $clean);

        $parts = array_values(array_filter(
            array_map('trim', preg_split('/[,\n]/', $clean))
        ));

        if (empty($parts)) return '';

        $state = end($parts);
        $state = preg_replace('/[0-9]/', '', $state);
        $state = trim($state, " \t\n\r\0\x0B-.");

        return ucwords(strtolower($state));
    }

    /**
     * Extract 6-digit PIN code from a raw address string.
     */
    private function extractPincode(string $address): string
    {
        if (empty($address)) return '';

        if (preg_match('/\b(\d{6})\b/', $address, $m)) {
            return $m[1];
        }

        return '';
    }
}