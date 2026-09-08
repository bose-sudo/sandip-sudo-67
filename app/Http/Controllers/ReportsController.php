<?php

namespace App\Http\Controllers;

use App\Models\BorrowerIdentifier;
use App\Models\BorrowerIdentifierMapping;
use App\Models\BorrowerLoanScheme;
use App\Models\Dealer;
use App\Models\LoanBasicDetails;
use App\Models\LoanServiceProvider;
use App\Models\LspExecutiveDetails;
use App\Models\Manufacturers;
use App\Models\ProductModel;
use App\Models\TeleduceMailLog;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Services\BorrowerVisibilityService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ReportsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly HelperController          $helper_controller,
        private readonly BorrowerVisibilityService $visibilityService,
    ) {}

    // =========================================================================
    // Shared: resolve the base loan query scope for the current user
    // =========================================================================
    private function resolveBaseQuery(int $authId): array
    {
        $base = LoanBasicDetails::query();

        // ── 1. LSP ────────────────────────────────────────────────────────────
        $lsp = LoanServiceProvider::where('lsp_auth_id', $authId)->first();
        if ($lsp) {
            $execIds = LspExecutiveDetails::where('lsp_id', $lsp->id)->pluck('id');
            if ($execIds->isEmpty()) {
                return [null, false, true];
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

        $user = auth()->user();

        // ── 3. OEM ────────────────────────────────────────────────────────────
        $manufacturer = Manufacturers::where('oem_auth_id', $authId)->first();
        if ($manufacturer) {
            $dealerUnqs = ProductModel::whereRaw(
                'LOWER(manufacture_name) LIKE ?',
                ['%' . strtolower($manufacturer->manufacturer) . '%']
            )
                ->distinct()
                ->pluck('dealer_id')
                ->pipe(fn($ids) => Dealer::whereIn('id', $ids)->pluck('dealer_unq'));

            if ($dealerUnqs->isEmpty()) {
                return [null, false, true];
            }
            $base->whereIn('dealer_id', $dealerUnqs);
            return [$base, false, false];
        }

        // ── 4. user_type === 36 ───────────────────────────────────────────────
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

        // ── 5 & 6. Admin — full access or hierarchy ───────────────────────────
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
    // LMR Report — unified JSON list + Excel export
    //
    // is_excel = false (default) → paginated JSON, all filters apply
    // is_excel = true            → stream .xlsx, scoped to month+year only
    // =========================================================================
    public function exportLmrReport(Request $request)
    {
        // ── Validate ──────────────────────────────────────────────────────────
        $validator = Validator::make($request->all(), [
            'is_excel'          => 'nullable|boolean',
            'page'              => 'integer|min:1',
            'limit' => 'integer|min:1',
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

        $isExcel = filter_var($request->input('is_excel', false), FILTER_VALIDATE_BOOLEAN);
        $authId  = auth()->id();

        [$baseQuery, , $empty] = $this->resolveBaseQuery($authId);

        if ($empty) {
            return $this->send_response('success', 'No records found', 200, []);
        }

        // ── Month / Year filter ───────────────────────────────────────────────
        // Excel  → always scoped to month+year (defaults to current if omitted)
        // JSON   → only applied when explicitly passed
        $monthNumber = null;
        $year        = null;

        if ($isExcel) {
            $year = (int) !empty($request->input('year')) ? $request->year : date('Y');
            $baseQuery->whereYear('loan_basic_details.created_at',  $year);

            if($request->filled('month')) {
                $monthInput  = $request->input('month');
                $monthNumber = Carbon::parse("1 {$monthInput} {$year}")->month;
                $baseQuery->whereMonth('loan_basic_details.created_at', $monthNumber);
            }
            // if ($request->filled('month')) {
            //     $monthInput = ucfirst(strtolower(trim($request->input('month'))));
            //     $parsed     = DateTime::createFromFormat('j F Y', "1 {$monthInput} {$year}");

            //     if (!$parsed) {
            //         return $this->send_response('failed', 'Invalid month provided', 422);
            //     }

            //     $monthNumber = (int) $parsed->format('n');
            //     $baseQuery->whereMonth('loan_basic_details.created_at', $monthNumber);
            // }
        } else {
            $year = (int) !empty($request->input('year')) ? $request->year : date('Y');
            if ($request->filled('month')) {
                $monthInput = ucfirst(strtolower(trim($request->input('month'))));
                $parsed     = DateTime::createFromFormat('j F Y', "1 {$monthInput} {$year}");

                if (!$parsed) {
                    return $this->send_response('failed', 'Invalid month provided', 422);
                }

                $monthNumber = (int) $parsed->format('n');
                // $monthNumber = Carbon::parse("1 {$monthInput} {$year}")->month;
                $baseQuery->whereYear('loan_basic_details.created_at',  $year)
                          ->whereMonth('loan_basic_details.created_at', $monthNumber);
            }

            // Date range filters — JSON only
            if ($request->filled('start_date')) {
                $baseQuery->where('loan_basic_details.created_at', '>=', Carbon::parse($request->start_date)->startOfDay());
            }
            if ($request->filled('end_date')) {
                $baseQuery->where('loan_basic_details.created_at', '<=', Carbon::parse($request->end_date)->endOfDay());
            }
            if ($request->filled('application_date')) {
                $baseQuery->whereDate('loan_basic_details.created_at', $request->application_date);
            }
        }

        // ── Admin-only filters ────────────────────────────────────────────────
        $user       = auth()->user();
        $lsp        = LoanServiceProvider::where('lsp_auth_id', $authId)->first();
        $dealerAuth = Dealer::where('dealer_auth_id', $authId)->first();
        $isAdmin    = !$lsp && !$dealerAuth && $user->user_type !== 36;

        if ($isAdmin) {
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

        // ── Common filters ────────────────────────────────────────────────────
        if ($request->filled('category')) {
            $request->category === 'B2B'
                ? $baseQuery->whereNotNull('executive_id')
                : $baseQuery->whereNull('executive_id');
        }
        if ($request->filled('product_type_id')) {
            $baseQuery->where('product_id', $request->product_type_id);
        }
        if ($request->filled('status')) {
            $baseQuery->where('status', $request->status);
        }

        // ── KYC filters ───────────────────────────────────────────────────────
        if ($request->filled('borrower_name')) {
            $name = strtolower($request->borrower_name);
            $baseQuery->where(function ($q) use ($name) {
                $q->whereHas('latestBorrowerKyc', fn($k) => $k->whereRaw('LOWER(name) LIKE ?', ["%{$name}%"]))
                  ->orWhereHas('user_detail',     fn($u) => $u->whereRaw('LOWER(name) LIKE ?', ["%{$name}%"]));
            });
        }
        if ($request->filled('borrower_id')) {
            $baseQuery->whereRaw('LOWER(borrower_id) LIKE ?', ['%' . strtolower($request->borrower_id) . '%']);
        }
        if ($request->filled('mobile_no')) {
            $mobile = $request->mobile_no;
            $baseQuery->where(function ($q) use ($mobile) {
                $q->whereHas('latestBorrowerKyc', fn($k) => $k->where('mobile', 'like', "%{$mobile}%"))
                  ->orWhereHas('user_detail',     fn($u) => $u->where('mobile', 'like', "%{$mobile}%"));
            });
        }
        foreach (['pan_no' => 'pan', 'aadhar_no' => 'aadhar', 'dob' => 'dob_date'] as $req => $col) {
            if ($request->filled($req)) {
                $baseQuery->whereHas('latestBorrowerKyc', fn($k) =>
                    $col === 'dob_date'
                        ? $k->whereDate($col, $request->$req)
                        : $k->where($col, 'like', '%' . $request->$req . '%')
                );
            }
        }
        if ($request->filled('step')) {
            $step = (int) $request->step;
            $baseQuery->whereRaw("
                CASE
                    WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int >= 27            AND ? = 4 THEN true
                    WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 21 AND 26 AND ? = 3 THEN true
                    WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 8  AND 20 AND ? = 2 THEN true
                    WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 1  AND 7  AND ? = 1 THEN true
                    ELSE false
                END
            ", [$step, $step, $step, $step]);
        }

        // ── Shared eager loads + select ───────────────────────────────────────
        $baseQuery
            ->with([
                'dealer_detail:id,dealer_unq,name',
                'user_detail:id,mobile,name',
                'latestBorrowerKyc:id,borrower_id,name,mobile,gender',
                'latestLoanScheme:id,borrower_id,iot,interest',
                'lenderGroup:id,borrower_id,lender_id',
                'lenderGroup.lender:id,name,lender_unq',
                'sanctionMail:id,reference,created_at',
            ])
            ->select([
                'id',
                'borrower_id',
                'status',
                'user_id',
                'loan_amount',
                'model',
                'model_id',
                'loan_term',
                'created_at',
                'dealer_id',
                'down_payment',
                'executive_id',
                'product_id',
            ])
            ->orderBy('loan_basic_details.created_at', 'desc');

        $loanTypeMap = [
            '1' => 'E-Rickshaw (L3)',
            '2' => 'E-Auto (L5)',
            '3' => 'E-Two Wheeler',
            '4' => 'Battery',
        ];

        // =========================================================================
        // JSON — paginated, per-row address call (acceptable at page size ≤ 50)
        // =========================================================================
        if (!$isExcel) {
            $numberOfRecords = (int) $request->input('limit', 10);
            $page            = (int) $request->input('page', 1);
            $paginated       = $baseQuery->paginate($numberOfRecords, ['*'], 'page', $page);

            if ($paginated->isEmpty()) {
                return $this->send_response('success', 'No records found', 200, []);
            }

            $paginated->getCollection()->transform(function ($borrower) use ($loanTypeMap) {
                $kyc    = $borrower->latestBorrowerKyc;
                $user   = $borrower->user_detail;
                $dealer = $borrower->dealer_detail;
                $scheme = $borrower->latestLoanScheme;
                $lender = $borrower->lenderGroup?->lender;
                $sanctionMail = $borrower->sanctionMail;

                $netAmount = $this->calculateNetLoanAmount(
                    $borrower->loan_amount,
                    $borrower->loan_term,
                    $scheme?->iot ?? 0
                );

                $emi = '';
                if ($scheme && $borrower->loan_term) {
                    $emiData = $this->helper_controller->generateReducingInterestSchedule(
                        (float) $netAmount, (float) $scheme->interest, (int) $borrower->loan_term
                    );
                    $emi = $emiData['emi_amount'] ?? '';
                }

                $addressData = $this->helper_controller->user_address_details($borrower->id, 'BORROWER');
                $addressFull = $addressData['data']['full_address'] ?? [];

                $lenderName   = $lender
                    ? $lender->name . ($lender->lender_unq ? ' (' . $lender->lender_unq . ')' : '')
                    : '';
                $productModel = ProductModel::where('model_unq', $borrower->model_id)->first();
                $createdAt    = $borrower->created_at;

                // $referenceMail = 'sanction-borrower-'.$borrower->borrower_id;
                // $sactionMail = TeleduceMailLog::where('reference', $referenceMail)->latest()->first();

                return [
                    'ApplicationId'   => $borrower->borrower_id ?? '',
                    'LoanId'          => '',
                    'Name'            => $kyc->name ?? $user->name ?? '',
                    'ContactNo'       => $kyc->mobile ?? $user->mobile ?? '',
                    'ApplicationDate' => $createdAt ? date('d-m-Y', strtotime($createdAt)) : '',
                    'AppMonthYear'    => $createdAt ? date('M-Y',   strtotime($createdAt)) : '',
                    'Term'            => $borrower->loan_term ?? '',
                    'Amount'          => $netAmount,
                    'Premium'         => $emi,
                    'PartnerCode'     => $dealer->dealer_unq ?? '',
                    'State'           => $addressFull['state'] ?? '',
                    'City'            => $addressFull['dist']  ?? '',
                    'LoanType'        => $loanTypeMap[(string) $borrower->product_id] ?? '',
                    'LoanProduct'     => $borrower->model ?? '',
                    'OEMName'         => $productModel?->manufacture_name ?? '',
                    'Status'          => $borrower->status ?? '',
                    'DOStatus'        => $sanctionMail ? 'Generated' : '', 
                    'DOSentTime'      => $sanctionMail ? date('d-m-Y H:i:s', strtotime($sanctionMail->created_at)) : '',
                    'SubService'      => '',
                    'ApprovedDate'    => $sanctionMail ? date('d-m-Y', strtotime($sanctionMail->created_at)) : '',
                    'Gender'          => $kyc->gender ?? '',
                    'BookType'        => '',
                    'LenderName'      => $lenderName,
                ];
            });

            return $this->send_response('success', 'LMR report fetched', 200, $paginated);
        }

        // =========================================================================
        // Excel — full month dataset, bulk address loading, EMI cache
        // =========================================================================
        $rows = $baseQuery->get();

        if ($rows->isEmpty()) {
            return $this->send_response('success', 'No records found for the selected period', 200, []);
        }

        $borrowerIds   = $rows->pluck('id')->all();
        $addressMap    = $this->helper_controller->user_address_details_bulk($borrowerIds, 'BORROWER');

        $modelUnqs     = $rows->pluck('model_id')->filter()->unique()->values();
        $productModels = ProductModel::whereIn('model_unq', $modelUnqs)
            ->get(['model_unq', 'manufacture_name'])
            ->keyBy('model_unq');

        $emiCache = [];
        $calcEmi  = function (float $netAmount, float $interest, int $term) use (&$emiCache): int {
            $key = "{$netAmount}|{$interest}|{$term}";
            if (!isset($emiCache[$key])) {
                $result         = $this->helper_controller->generateReducingInterestSchedule($netAmount, $interest, $term);
                $emiCache[$key] = $result['emi_amount'] ?? 0;
            }
            return $emiCache[$key];
        };

        $data = $rows->map(function ($borrower) use ($addressMap, $productModels, $calcEmi, $loanTypeMap) {
            $kyc    = $borrower->latestBorrowerKyc;
            $user   = $borrower->user_detail;
            $dealer = $borrower->dealer_detail;
            $scheme = $borrower->latestLoanScheme;
            $lender = $borrower->lenderGroup?->lender;
            $sanctionMail = $borrower->sanctionMail;

            $netAmount = $this->calculateNetLoanAmount(
                $borrower->loan_amount,
                $borrower->loan_term,
                $scheme?->iot ?? 0
            );

            $emi = ($scheme && $borrower->loan_term)
                ? $calcEmi((float) $netAmount, (float) $scheme->interest, (int) $borrower->loan_term)
                : '';

            $addressData = (is_array($addressMap) && isset($addressMap[$borrower->id]))
                ? $addressMap[$borrower->id]
                : $this->helper_controller->user_address_details($borrower->id, 'BORROWER');
            $addressFull = $addressData['data']['full_address'] ?? [];

            $lenderName   = $lender
                ? $lender->name . ($lender->lender_unq ? ' (' . $lender->lender_unq . ')' : '')
                : '';
            $productModel = $productModels->get($borrower->model_id);
            $createdAt    = $borrower->created_at;

            return [
                'ApplicationId'   => $borrower->borrower_id ?? '',
                'LoanId'          => '',
                'Name'            => $kyc->name ?? $user->name ?? '',
                'ContactNo'       => $kyc->mobile ?? $user->mobile ?? '',
                'ApplicationDate' => $createdAt ? date('d-m-Y', strtotime($createdAt)) : '',
                'AppMonthYear'    => $createdAt ? date('M-Y',   strtotime($createdAt)) : '',
                'Term'            => $borrower->loan_term ?? '',
                'Amount'          => $netAmount,
                'Premium'         => $emi,
                'PartnerCode'     => $dealer->dealer_unq ?? '',
                'State'           => $addressFull['state'] ?? '',
                'City'            => $addressFull['dist']  ?? '',
                'LoanType'        => $loanTypeMap[(string) $borrower->product_id] ?? '',
                'LoanProduct'     => $borrower->model ?? '',
                'OEMName'         => $productModel?->manufacture_name ?? '',
                'Status'          => $borrower->status ?? '',
                'DOStatus'        => $sanctionMail ? 'Generated' : '',
                'DOSentTime'      => $sanctionMail ? date('d-m-Y H:i:s', strtotime($sanctionMail->created_at)) : '',
                'SubService'      => '',
                'ApprovedDate'    => $sanctionMail ? date('d-m-Y', strtotime($sanctionMail->created_at)) : '',
                'Gender'          => $kyc->gender ?? '',
                'BookType'        => '',
                'LenderName'      => $lenderName,
            ];
        });

        // ── Build spreadsheet ─────────────────────────────────────────────────
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('LMR Report');

        $columns = [
            'A' => ['header' => 'ApplicationId',          'key' => 'ApplicationId'],
            'B' => ['header' => 'LoanId',                 'key' => 'LoanId'],
            'C' => ['header' => 'Name',                   'key' => 'Name'],
            'D' => ['header' => 'Contact No',             'key' => 'ContactNo'],
            'E' => ['header' => 'Application Date',       'key' => 'ApplicationDate'],
            'F' => ['header' => 'Application Month Year', 'key' => 'AppMonthYear'],
            'G' => ['header' => 'Term',                   'key' => 'Term'],
            'H' => ['header' => 'Amount',                 'key' => 'Amount'],
            'I' => ['header' => 'Premium',                'key' => 'Premium'],
            'J' => ['header' => 'Partner Code',           'key' => 'PartnerCode'],
            'K' => ['header' => 'State',                  'key' => 'State'],
            'L' => ['header' => 'City',                   'key' => 'City'],
            'M' => ['header' => 'Loan Type',              'key' => 'LoanType'],
            'N' => ['header' => 'Loan Product',           'key' => 'LoanProduct'],
            'O' => ['header' => 'OEM name',               'key' => 'OEMName'],
            'P' => ['header' => 'Status',                 'key' => 'Status'],
            'Q' => ['header' => 'D.O Status',             'key' => 'DOStatus'],
            'R' => ['header' => 'D.O sent time',          'key' => 'DOSentTime'],
            'S' => ['header' => 'Sub service',            'key' => 'SubService'],
            'T' => ['header' => 'ApprovedDate',           'key' => 'ApprovedDate'],
            'U' => ['header' => 'Gender',                 'key' => 'Gender'],
            'V' => ['header' => 'Book Type',              'key' => 'BookType'],
            'W' => ['header' => 'Lender Name',            'key' => 'LenderName'],
        ];

        $lastCol = array_key_last($columns);

        foreach ($columns as $col => $def) {
            $sheet->setCellValue($col . '1', $def['header']);
            $sheet->getColumnDimension($col)->setWidth(max(18, strlen($def['header']) + 4));
        }

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Arial', 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F4E79']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        $rowIndex = 2;
        foreach ($data as $record) {
            foreach ($columns as $col => $def) {
                $sheet->setCellValue($col . $rowIndex, $record[$def['key']] ?? '');
            }
            if ($rowIndex % 2 === 0) {
                $sheet->getStyle("A{$rowIndex}:{$lastCol}{$rowIndex}")
                      ->getFill()->setFillType(Fill::FILL_SOLID)
                      ->getStartColor()->setARGB('FFF0F4FA');
            }
            $rowIndex++;
        }

        if ($rowIndex > 2) {
            $sheet->getStyle("A2:{$lastCol}" . ($rowIndex - 1))->getFont()->setName('Arial')->setSize(10);
        }

        $sheet->freezePane('A2');

        // $monthLabel = $monthNumber ? date('M', mktime(0, 0, 0, $monthNumber, 1)) : date('M');
        // $yearLabel  = $year ?? date('Y');
        // $filename   = "LMR_Report_{$monthLabel}{$yearLabel}_" . date('Ymd_His') . '.xlsx';
        $monthLabel = $monthNumber ? date('M', mktime(0, 0, 0, $monthNumber, 1)) : 'All';
        $yearLabel  = $year;
        $filename   = "LMR_Report_{$monthLabel}_{$yearLabel}_" . date('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }


    // =========================================================================
    // LAR Report — unified JSON list + Excel export
    //
    // is_excel = false (default) → paginated JSON, all filters apply
    // is_excel = true            → stream .xlsx, scoped to month+year only
    // =========================================================================
    public function exportLarReport(Request $request)
    {
        // ── Validate ──────────────────────────────────────────────────────────
        $validator = Validator::make($request->all(), [
            'is_excel'          => 'nullable|boolean',
            'page'              => 'integer|min:1',
            'limit' => 'integer|min:1',
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

        $isExcel = filter_var($request->input('is_excel', false), FILTER_VALIDATE_BOOLEAN);
        $authId  = auth()->id();

        [$baseQuery, , $empty] = $this->resolveBaseQuery($authId);

        if ($empty) {
            return $this->send_response('success', 'No records found', 200, []);
        }

        // ── Month / Year filter ───────────────────────────────────────────────
        // Excel  → always scoped to month+year (defaults to current if omitted)
        // JSON   → only applied when explicitly passed
        $monthNumber = null;
        $year        = null;

        if ($isExcel) {
            $year = (int) !empty($request->input('year')) ? $request->year : date('Y');
            $baseQuery->whereYear('loan_basic_details.created_at',  $year);

            if($request->filled('month')) {
                $monthInput  = $request->input('month');
                $monthNumber = Carbon::parse("1 {$monthInput} {$year}")->month;
                $baseQuery->whereMonth('loan_basic_details.created_at', $monthNumber);
            }
            // if ($request->filled('month')) {
            //     $monthInput = ucfirst(strtolower(trim($request->input('month'))));
            //     $parsed     = DateTime::createFromFormat('j F Y', "1 {$monthInput} {$year}");

            //     if (!$parsed) {
            //         return $this->send_response('failed', 'Invalid month provided', 422);
            //     }

            //     $monthNumber = (int) $parsed->format('n');
            //     $baseQuery->whereMonth('loan_basic_details.created_at', $monthNumber);
            // }
        } else {
            $year = (int) !empty($request->input('year')) ? $request->year : date('Y');
            if ($request->filled('month')) {
                $monthInput = ucfirst(strtolower(trim($request->input('month'))));
                $parsed     = DateTime::createFromFormat('j F Y', "1 {$monthInput} {$year}");

                if (!$parsed) {
                    return $this->send_response('failed', 'Invalid month provided', 422);
                }

                $monthNumber = (int) $parsed->format('n');
                // $monthNumber = Carbon::parse("1 {$monthInput} {$year}")->month;
                $baseQuery->whereYear('loan_basic_details.created_at',  $year)
                          ->whereMonth('loan_basic_details.created_at', $monthNumber);
            }

            // Date range filters — JSON only
            if ($request->filled('start_date')) {
                $baseQuery->where('loan_basic_details.created_at', '>=', Carbon::parse($request->start_date)->startOfDay());
            }
            if ($request->filled('end_date')) {
                $baseQuery->where('loan_basic_details.created_at', '<=', Carbon::parse($request->end_date)->endOfDay());
            }
            if ($request->filled('application_date')) {
                $baseQuery->whereDate('loan_basic_details.created_at', $request->application_date);
            }
        }

        // ── Admin-only filters ────────────────────────────────────────────────
        $user       = auth()->user();
        $lsp        = LoanServiceProvider::where('lsp_auth_id', $authId)->first();
        $dealerAuth = Dealer::where('dealer_auth_id', $authId)->first();
        $isAdmin    = !$lsp && !$dealerAuth && $user->user_type !== 36;

        if ($isAdmin) {
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

        // ── Common filters ────────────────────────────────────────────────────
        if ($request->filled('category')) {
            $request->category === 'B2B'
                ? $baseQuery->whereNotNull('executive_id')
                : $baseQuery->whereNull('executive_id');
        }
        if ($request->filled('product_type_id')) {
            $baseQuery->where('product_id', $request->product_type_id);
        }
        if ($request->filled('status')) {
            $baseQuery->where('status', $request->status);
        }

        // ── KYC filters ───────────────────────────────────────────────────────
        if ($request->filled('borrower_name')) {
            $name = strtolower($request->borrower_name);
            $baseQuery->where(function ($q) use ($name) {
                $q->whereHas('latestBorrowerKyc', fn($k) => $k->whereRaw('LOWER(name) LIKE ?', ["%{$name}%"]))
                  ->orWhereHas('user_detail',     fn($u) => $u->whereRaw('LOWER(name) LIKE ?', ["%{$name}%"]));
            });
        }
        if ($request->filled('borrower_id')) {
            $baseQuery->whereRaw('LOWER(borrower_id) LIKE ?', ['%' . strtolower($request->borrower_id) . '%']);
        }
        if ($request->filled('mobile_no')) {
            $mobile = $request->mobile_no;
            $baseQuery->where(function ($q) use ($mobile) {
                $q->whereHas('latestBorrowerKyc', fn($k) => $k->where('mobile', 'like', "%{$mobile}%"))
                  ->orWhereHas('user_detail',     fn($u) => $u->where('mobile', 'like', "%{$mobile}%"));
            });
        }
        foreach (['pan_no' => 'pan', 'aadhar_no' => 'aadhar', 'dob' => 'dob_date'] as $req => $col) {
            if ($request->filled($req)) {
                $baseQuery->whereHas('latestBorrowerKyc', fn($k) =>
                    $col === 'dob_date'
                        ? $k->whereDate($col, $request->$req)
                        : $k->where($col, 'like', '%' . $request->$req . '%')
                );
            }
        }
        if ($request->filled('step')) {
            $step = (int) $request->step;
            $baseQuery->whereRaw("
                CASE
                    WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int >= 27            AND ? = 4 THEN true
                    WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 21 AND 26 AND ? = 3 THEN true
                    WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 8  AND 20 AND ? = 2 THEN true
                    WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 1  AND 7  AND ? = 1 THEN true
                    ELSE false
                END
            ", [$step, $step, $step, $step]);
        }

        // ── Shared eager loads + select ───────────────────────────────────────
        $baseQuery
            ->with([
                'dealer_detail:id,dealer_unq,name',
                'user_detail:id,mobile,name',
                'latestBorrowerKyc:id,borrower_id,name,mobile,gender',
                'latestLoanScheme:id,borrower_id,iot,interest',
                'lenderGroup:id,borrower_id,lender_id',
                'lenderGroup.lender:id,name,lender_unq',
                'sanctionMail:id,reference,created_at',
            ])
            ->select([
                'id',
                'borrower_id',
                'status',
                'user_id',
                'loan_amount',
                'model',
                'model_id',
                'loan_term',
                'created_at',
                'dealer_id',
                'down_payment',
                'executive_id',
                'product_id',
            ])
            ->orderBy('loan_basic_details.created_at', 'desc');

        $loanTypeMap = [
            '1' => 'E-Rickshaw (L3)',
            '2' => 'E-Auto (L5)',
            '3' => 'E-Two Wheeler',
            '4' => 'Battery',
        ];

        // =========================================================================
        // JSON — paginated, per-row address call (acceptable at page size ≤ 50)
        // =========================================================================
        if (!$isExcel) {
            $numberOfRecords = (int) $request->input('limit', 10);
            $page            = (int) $request->input('page', 1);
            $paginated       = $baseQuery->paginate($numberOfRecords, ['*'], 'page', $page);

            if ($paginated->isEmpty()) {
                return $this->send_response('success', 'No records found', 200, []);
            }

            $paginated->getCollection()->transform(function ($borrower) use ($loanTypeMap) {
                $kyc    = $borrower->latestBorrowerKyc;
                $user   = $borrower->user_detail;
                $dealer = $borrower->dealer_detail;
                $scheme = $borrower->latestLoanScheme;
                $lender = $borrower->lenderGroup?->lender;
                $sanctionMail = $borrower->sanctionMail;

                $netAmount = $this->calculateNetLoanAmount(
                    $borrower->loan_amount,
                    $borrower->loan_term,
                    $scheme?->iot ?? 0
                );

                $emi = '';
                if ($scheme && $borrower->loan_term) {
                    $emiData = $this->helper_controller->generateReducingInterestSchedule(
                        (float) $netAmount, (float) $scheme->interest, (int) $borrower->loan_term
                    );
                    $emi = $emiData['emi_amount'] ?? '';
                }

                $addressData = $this->helper_controller->user_address_details($borrower->id, 'BORROWER');
                $addressFull = $addressData['data']['full_address'] ?? [];

                $lenderName   = $lender
                    ? $lender->name . ($lender->lender_unq ? ' (' . $lender->lender_unq . ')' : '')
                    : '';
                $productModel = ProductModel::where('model_unq', $borrower->model_id)->first();
                $createdAt    = $borrower->created_at;

                // $referenceMail = 'sanction-borrower-'.$borrower->borrower_id;
                // $sactionMail = TeleduceMailLog::where('reference', $referenceMail)->latest()->first();

                return [
                    'ApplicationId'   => $borrower->borrower_id ?? '',
                    'LoanId'          => '',
                    'Name'            => $kyc->name ?? $user->name ?? '',
                    'ContactNo'       => $kyc->mobile ?? $user->mobile ?? '',
                    'ApplicationDate' => $createdAt ? date('d-m-Y', strtotime($createdAt)) : '',
                    'AppMonthYear'    => $createdAt ? date('M-Y',   strtotime($createdAt)) : '',
                    'Term'            => $borrower->loan_term ?? '',
                    'Amount'          => $netAmount,
                    'Premium'         => $emi,
                    'PartnerCode'     => $dealer->dealer_unq ?? '',
                    'State'           => $addressFull['state'] ?? '',
                    'City'            => $addressFull['dist']  ?? '',
                    'LoanType'        => $loanTypeMap[(string) $borrower->product_id] ?? '',
                    'LoanProduct'     => $borrower->model ?? '',
                    'OEMName'         => $productModel?->manufacture_name ?? '',
                    'Status'          => $borrower->status ?? '',
                    'DOStatus'        => $sanctionMail ? 'Generated' : '', 
                    'DOSentTime'      => $sanctionMail ? date('d-m-Y H:i:s', strtotime($sanctionMail->created_at)) : '',
                    'SubService'      => '',
                    'ApprovedDate'    => $sanctionMail ? date('d-m-Y', strtotime($sanctionMail->created_at)) : '',
                    'Gender'          => $kyc->gender ?? '',
                    'BookType'        => '',
                    'LenderName'      => $lenderName,
                ];
            });

            return $this->send_response('success', 'LAR report fetched', 200, $paginated);
        }

        // =========================================================================
        // Excel — full month dataset, bulk address loading, EMI cache
        // =========================================================================
        $rows = $baseQuery->get();

        if ($rows->isEmpty()) {
            return $this->send_response('success', 'No records found for the selected period', 200, []);
        }

        $borrowerIds   = $rows->pluck('id')->all();
        $addressMap    = $this->helper_controller->user_address_details_bulk($borrowerIds, 'BORROWER');

        $modelUnqs     = $rows->pluck('model_id')->filter()->unique()->values();
        $productModels = ProductModel::whereIn('model_unq', $modelUnqs)
            ->get(['model_unq', 'manufacture_name'])
            ->keyBy('model_unq');

        $emiCache = [];
        $calcEmi  = function (float $netAmount, float $interest, int $term) use (&$emiCache): int {
            $key = "{$netAmount}|{$interest}|{$term}";
            if (!isset($emiCache[$key])) {
                $result         = $this->helper_controller->generateReducingInterestSchedule($netAmount, $interest, $term);
                $emiCache[$key] = $result['emi_amount'] ?? 0;
            }
            return $emiCache[$key];
        };

        $data = $rows->map(function ($borrower) use ($addressMap, $productModels, $calcEmi, $loanTypeMap) {
            $kyc    = $borrower->latestBorrowerKyc;
            $user   = $borrower->user_detail;
            $dealer = $borrower->dealer_detail;
            $scheme = $borrower->latestLoanScheme;
            $lender = $borrower->lenderGroup?->lender;
            $sanctionMail = $borrower->sanctionMail;

            $netAmount = $this->calculateNetLoanAmount(
                $borrower->loan_amount,
                $borrower->loan_term,
                $scheme?->iot ?? 0
            );

            $emi = ($scheme && $borrower->loan_term)
                ? $calcEmi((float) $netAmount, (float) $scheme->interest, (int) $borrower->loan_term)
                : '';

            $addressData = (is_array($addressMap) && isset($addressMap[$borrower->id]))
                ? $addressMap[$borrower->id]
                : $this->helper_controller->user_address_details($borrower->id, 'BORROWER');
            $addressFull = $addressData['data']['full_address'] ?? [];

            $lenderName   = $lender
                ? $lender->name . ($lender->lender_unq ? ' (' . $lender->lender_unq . ')' : '')
                : '';
            $productModel = $productModels->get($borrower->model_id);
            $createdAt    = $borrower->created_at;

            return [
                'ApplicationId'   => $borrower->borrower_id ?? '',
                'LoanId'          => '',
                'Name'            => $kyc->name ?? $user->name ?? '',
                'ContactNo'       => $kyc->mobile ?? $user->mobile ?? '',
                'ApplicationDate' => $createdAt ? date('d-m-Y', strtotime($createdAt)) : '',
                'AppMonthYear'    => $createdAt ? date('M-Y',   strtotime($createdAt)) : '',
                'Term'            => $borrower->loan_term ?? '',
                'Amount'          => $netAmount,
                'Premium'         => $emi,
                'PartnerCode'     => $dealer->dealer_unq ?? '',
                'State'           => $addressFull['state'] ?? '',
                'City'            => $addressFull['dist']  ?? '',
                'LoanType'        => $loanTypeMap[(string) $borrower->product_id] ?? '',
                'LoanProduct'     => $borrower->model ?? '',
                'OEMName'         => $productModel?->manufacture_name ?? '',
                'Status'          => $borrower->status ?? '',
                'DOStatus'        => $sanctionMail ? 'Generated' : '',
                'DOSentTime'      => $sanctionMail ? date('d-m-Y H:i:s', strtotime($sanctionMail->created_at)) : '',
                'SubService'      => '',
                'ApprovedDate'    => $sanctionMail ? date('d-m-Y', strtotime($sanctionMail->created_at)) : '',
                'Gender'          => $kyc->gender ?? '',
                'BookType'        => '',
                'LenderName'      => $lenderName,
            ];
        });

        // ── Build spreadsheet ─────────────────────────────────────────────────
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('LAR Report');

        $columns = [
            'A' => ['header' => 'ApplicationId',          'key' => 'ApplicationId'],
            'B' => ['header' => 'LoanId',                 'key' => 'LoanId'],
            'C' => ['header' => 'Name',                   'key' => 'Name'],
            'D' => ['header' => 'Contact No',             'key' => 'ContactNo'],
            'E' => ['header' => 'Application Date',       'key' => 'ApplicationDate'],
            'F' => ['header' => 'Application Month Year', 'key' => 'AppMonthYear'],
            'G' => ['header' => 'Term',                   'key' => 'Term'],
            'H' => ['header' => 'Amount',                 'key' => 'Amount'],
            'I' => ['header' => 'Premium',                'key' => 'Premium'],
            'J' => ['header' => 'Partner Code',           'key' => 'PartnerCode'],
            'K' => ['header' => 'State',                  'key' => 'State'],
            'L' => ['header' => 'City',                   'key' => 'City'],
            'M' => ['header' => 'Loan Type',              'key' => 'LoanType'],
            'N' => ['header' => 'Loan Product',           'key' => 'LoanProduct'],
            'O' => ['header' => 'OEM name',               'key' => 'OEMName'],
            'P' => ['header' => 'Status',                 'key' => 'Status'],
            'Q' => ['header' => 'D.O Status',             'key' => 'DOStatus'],
            'R' => ['header' => 'D.O sent time',          'key' => 'DOSentTime'],
            'S' => ['header' => 'Sub service',            'key' => 'SubService'],
            'T' => ['header' => 'ApprovedDate',           'key' => 'ApprovedDate'],
            'U' => ['header' => 'Gender',                 'key' => 'Gender'],
            'V' => ['header' => 'Book Type',              'key' => 'BookType'],
            'W' => ['header' => 'Lender Name',            'key' => 'LenderName'],
        ];

        $lastCol = array_key_last($columns);

        foreach ($columns as $col => $def) {
            $sheet->setCellValue($col . '1', $def['header']);
            $sheet->getColumnDimension($col)->setWidth(max(18, strlen($def['header']) + 4));
        }

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Arial', 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F4E79']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        $rowIndex = 2;
        foreach ($data as $record) {
            foreach ($columns as $col => $def) {
                $sheet->setCellValue($col . $rowIndex, $record[$def['key']] ?? '');
            }
            if ($rowIndex % 2 === 0) {
                $sheet->getStyle("A{$rowIndex}:{$lastCol}{$rowIndex}")
                      ->getFill()->setFillType(Fill::FILL_SOLID)
                      ->getStartColor()->setARGB('FFF0F4FA');
            }
            $rowIndex++;
        }

        if ($rowIndex > 2) {
            $sheet->getStyle("A2:{$lastCol}" . ($rowIndex - 1))->getFont()->setName('Arial')->setSize(10);
        }

        $sheet->freezePane('A2');

        // $monthLabel = $monthNumber ? date('M', mktime(0, 0, 0, $monthNumber, 1)) : date('M');
        // $yearLabel  = $year ?? date('Y');
        // $filename   = "LAR_Report_{$monthLabel}{$yearLabel}_" . date('Ymd_His') . '.xlsx';
        $monthLabel = $monthNumber ? date('M', mktime(0, 0, 0, $monthNumber, 1)) : 'All';
        $yearLabel  = $year;
        $filename   = "LAR_Report_{$monthLabel}_{$yearLabel}_" . date('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================
    private function calculateNetLoanAmount($loan_amount, $loan_term, $iot_amount = 0): float|int
    {
        $second_year_insurance = ($loan_term > 15 && $loan_term <= 24) ? 0 : 0;
        return $loan_amount + $iot_amount + $second_year_insurance;
    }


    /**
     * Column definitions: [header, data_key, date_format]
     * date_format: 'datetime' => d-m-Y H:i:s | 'date' => d-m-Y | null => raw
     */
    private const COLUMNS = [
        ['Borrower ID',                           'borrower_id',                  null],
        ['Application Status',                    'application_status',           null],
        ['Name',                                  'name',                         null],
        ['Mobile',                                'mobile',                       null],
        ['User Registered On',                    'user_registered_on',           'datetime'],
        ['Basic Details On',                      'basic_details_on',             'datetime'],
        ['Borrower Details On',                   'borrower_details_on',          'datetime'],
        ['Borrower Family Details On',            'borrower_family_details_on',   'datetime'],
        ['Borrower Occupation On',                'borrower_occupation_on',       'datetime'],
        ['Co-Applicant Details On',               'coapplicant_details_on',       'datetime'],
        ['Co-Applicant Occupation On',            'coapplicant_occupation_on',    'datetime'],
        ['Guarantor Details On',                  'guarantor_details_on',         'datetime'],
        ['Guarantor Occupation On',               'guarantor_occupation_on',      'datetime'],
        ['Reference Verification On',             'reference_verification_on',    'datetime'],
        ['Field Inspection On',                   'field_inspection_on',          'datetime'],
        ['VKYC Generated On',                     'vkyc_generated_on',            'datetime'],
        ['EMI Debit Consent (Acc Details) On',    'emi_debit_consent_on',         'datetime'],
        ['Borrower E-Sign Generated On',          'borrower_esign_on',            'datetime'],
        ['Co-Applicant E-Sign Generated On',      'coapplicant_esign_on',         'datetime'],
        ['Guarantor E-Sign Generated On',         'guarantor_esign_on',           'datetime'],
        ['E-NACH Registration On',                'enach_registration_on',        'datetime'],
        ['Disbursement On',                       'disbursement_on',              'date'],
        ['Time Taken (Application->Disbursement)','time_taken',                   null],
    ];
 
    // -------------------------------------------------------------------------
 
    public function export(Request $request)
    {
        $tz       = 'Asia/Kolkata';
        $year     = (int) ($request->input('year') ?: now($tz)->year);
        $month    = $request->filled('month') ? (int) $request->input('month') : null;
        $lenderId = $request->input('lender_id');
 
        $rows = $this->fetchData($year, $month, $lenderId, $tz);
 
        return $this->buildExcel($rows, $tz, $year, $month);
    }
 
    // -------------------------------------------------------------------------
    // Data layer
    // -------------------------------------------------------------------------
 
    private function fetchData(int $year, ?int $month, $lenderId, string $tz): \Illuminate\Support\Collection
    {
        /*
         * borrower_kycs needs name + mobile alongside created_at.
         * GROUP BY borrower_id + MAX(created_at) won't work in Postgres unless
         * every selected column is in GROUP BY or an aggregate.
         * Solution: DISTINCT ON (borrower_id) ORDER BY borrower_id, created_at DESC
         * picks the latest row per borrower without needing aggregates.
         *
         * All other subqueries only need created_at so MAX(...) GROUP BY is fine.
         */
 
        $query = DB::table('loan_basic_details as lbd')
 
            // -- Users --------------------------------------------------------
            ->leftJoin('users as u', 'u.id', '=', 'lbd.user_id')
 
            // -- Borrower KYC (latest) - DISTINCT ON to carry name + mobile ---
            ->leftJoinSub(
                DB::table('borrower_kycs')
                    ->select(DB::raw('DISTINCT ON (borrower_id) borrower_id, name, mobile, created_at'))
                    ->orderByRaw('borrower_id, created_at DESC'),
                'bk', 'bk.borrower_id', '=', 'lbd.id'
            )
 
            // -- Borrower Address (latest) ------------------------------------
            ->leftJoinSub(
                DB::table('borrower_addresses')
                    ->select('borrower_id', DB::raw('MAX(created_at) as created_at'))
                    ->groupBy('borrower_id'),
                'ba', 'ba.borrower_id', '=', 'lbd.id'
            )
 
            // -- Borrower Occupation (latest) ---------------------------------
            ->leftJoinSub(
                DB::table('borrower_occupations')
                    ->select('borrower_id', DB::raw('MAX(created_at) as created_at'))
                    ->groupBy('borrower_id'),
                'bo', 'bo.borrower_id', '=', 'lbd.id'
            )
 
            // -- Co-Applicant KYC (latest) -------------------------------------
            ->leftJoinSub(
                DB::table('coapplicant_kycs')
                    ->select('borrower_id', DB::raw('MAX(created_at) as created_at'))
                    ->groupBy('borrower_id'),
                'ck', 'ck.borrower_id', '=', 'lbd.id'
            )
 
            // -- Co-Applicant Occupation (latest) ------------------------------
            ->leftJoinSub(
                DB::table('coapplicant_occupations')
                    ->select('borrower_id', DB::raw('MAX(created_at) as created_at'))
                    ->groupBy('borrower_id'),
                'co', 'co.borrower_id', '=', 'lbd.id'
            )
 
            // -- Guarantor (latest) -------------------------------------------
            ->leftJoinSub(
                DB::table('guarantors')
                    ->select('borrower_id', DB::raw('MAX(created_at) as created_at'))
                    ->groupBy('borrower_id'),
                'g', 'g.borrower_id', '=', 'lbd.id'
            )
 
            // -- Guarantor Occupation (latest) --------------------------------
            ->leftJoinSub(
                DB::table('guarantor_occupations')
                    ->select('borrower_id', DB::raw('MAX(created_at) as created_at'))
                    ->groupBy('borrower_id'),
                'go', 'go.borrower_id', '=', 'lbd.id'
            )
 
            // -- Reference Verification (latest) ------------------------------
            ->leftJoinSub(
                DB::table('borrower_reference_details')
                    ->select('borrower_id', DB::raw('MAX(created_at) as created_at'))
                    ->groupBy('borrower_id'),
                'rd', 'rd.borrower_id', '=', 'lbd.id'
            )
 
            // -- Field Inspection (latest) -------------------------------------
            ->leftJoinSub(
                DB::table('field_inspection_details')
                    ->select('borrower_id', DB::raw('MAX(verification_time) as verification_time'))
                    ->groupBy('borrower_id'),
                'fi', 'fi.borrower_id', '=', 'lbd.id'
            )
 
            // -- VKYC (latest) ------------------------------------------------
            ->leftJoinSub(
                DB::table('vkyc_api_req_details')
                    ->select('borrower_id', DB::raw('MAX(created_at) as created_at'))
                    ->groupBy('borrower_id'),
                'vk', 'vk.borrower_id', '=', 'lbd.id'
            )
 
            // -- Borrower Account Details / EMI Debit Consent (latest) --------
            ->leftJoinSub(
                DB::table('borrower_account_details')
                    ->select('borrower_id', DB::raw('MAX(created_at) as created_at'))
                    ->groupBy('borrower_id'),
                'bad', 'bad.borrower_id', '=', 'lbd.id'
            )
 
            // -- E-Sign: BORROWER (latest VALID GENERATE_ESIGN_URL) -----------
            ->leftJoinSub(
                DB::table('vendor_service_apis')
                    ->select('borrower_id', DB::raw('MAX(created_at) as created_at'))
                    ->where('api_flag', 'GENERATE_ESIGN_URL')
                    ->where('status', 'VALID')
                    ->where('applicant_type', 'BORROWER')
                    ->groupBy('borrower_id'),
                'esb', 'esb.borrower_id', '=', 'lbd.id'
            )
 
            // -- E-Sign: COAPPLICANT ------------------------------------------
            ->leftJoinSub(
                DB::table('vendor_service_apis')
                    ->select('borrower_id', DB::raw('MAX(created_at) as created_at'))
                    ->where('api_flag', 'GENERATE_ESIGN_URL')
                    ->where('status', 'VALID')
                    ->where('applicant_type', 'COAPPLICANT')
                    ->groupBy('borrower_id'),
                'esc', 'esc.borrower_id', '=', 'lbd.id'
            )
 
            // -- E-Sign: GUARANTOR --------------------------------------------
            ->leftJoinSub(
                DB::table('vendor_service_apis')
                    ->select('borrower_id', DB::raw('MAX(created_at) as created_at'))
                    ->where('api_flag', 'GENERATE_ESIGN_URL')
                    ->where('status', 'VALID')
                    ->where('applicant_type', 'GUARANTOR')
                    ->groupBy('borrower_id'),
                'esg', 'esg.borrower_id', '=', 'lbd.id'
            )
 
            // -- E-NACH (latest) ----------------------------------------------
            ->leftJoinSub(
                DB::table('enach_requests')
                    ->select('borrower_id', DB::raw('MAX(created_at) as created_at'))
                    ->groupBy('borrower_id'),
                'en', 'en.borrower_id', '=', 'lbd.id'
            )
 
            // -- Disbursement (latest) ----------------------------------------
            ->leftJoinSub(
                DB::table('disbursement_details')
                    ->select('borrower_id', DB::raw('MAX(disbursement_date) as disbursement_date'))
                    ->groupBy('borrower_id'),
                'dd', 'dd.borrower_id', '=', 'lbd.id'
            )
 
            ->select([
                'lbd.borrower_id',
                DB::raw('COALESCE(bk.name,   u.name)   as name'),
                DB::raw('COALESCE(bk.mobile, u.mobile) as mobile'),
                'u.created_at         as user_registered_on',
                'lbd.created_at       as basic_details_on',
                'lbd.status           as application_status',
                'bk.created_at        as borrower_details_on',
                'ba.created_at        as borrower_family_details_on',
                'bo.created_at        as borrower_occupation_on',
                'ck.created_at        as coapplicant_details_on',
                'co.created_at        as coapplicant_occupation_on',
                'g.created_at         as guarantor_details_on',
                'go.created_at        as guarantor_occupation_on',
                'rd.created_at        as reference_verification_on',
                'fi.verification_time as field_inspection_on',
                'vk.created_at        as vkyc_generated_on',
                'bad.created_at       as emi_debit_consent_on',
                'esb.created_at       as borrower_esign_on',
                'esc.created_at       as coapplicant_esign_on',
                'esg.created_at       as guarantor_esign_on',
                'en.created_at        as enach_registration_on',
                'dd.disbursement_date as disbursement_on',
            ]);
 
        // -- Filters ----------------------------------------------------------
        // Year always applied (defaults to current year when not passed)
        $query->whereRaw(
            "EXTRACT(YEAR FROM lbd.created_at AT TIME ZONE 'UTC' AT TIME ZONE ?) = ?",
            [$tz, $year]
        );
 
        // Month only applied when explicitly provided
        if ($month !== null) {
            $query->whereRaw(
                "EXTRACT(MONTH FROM lbd.created_at AT TIME ZONE 'UTC' AT TIME ZONE ?) = ?",
                [$tz, $month]
            );
        }
 
        if ($lenderId) {
            $query->where('lbd.lender_id', $lenderId);
        }
 
        return $query->orderBy('lbd.created_at', 'asc')->get();
    }
 
    // -------------------------------------------------------------------------
    // Excel builder
    // -------------------------------------------------------------------------
 
    private function buildExcel(\Illuminate\Support\Collection $rows, string $tz, int $year = 0, ?int $month = null)
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Borrower Timeline');
 
        $headerBgHex   = '1F4E79';
        $headerFontHex = 'FFFFFF';
        $altRowHex     = 'EBF3FB';
        $borderColor   = 'BDD7EE';
 
        // -- Headers ----------------------------------------------------------
        foreach (self::COLUMNS as $colIdx => [$header]) {
            $sheet->getCellByColumnAndRow($colIdx + 1, 1)->setValue($header);
        }
 
        $lastCol    = count(self::COLUMNS);
        $lastLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastCol);
 
        $sheet->getStyle("A1:{$lastLetter}1")->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => $headerFontHex],
                'size'  => 10,
                'name'  => 'Arial',
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $headerBgHex],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
        ]);
 
        $sheet->getRowDimension(1)->setRowHeight(30);
 
        // -- Data rows --------------------------------------------------------
        $excelRow = 2;
 
        foreach ($rows as $row) {
            $dataMap = [
                'borrower_id'                => $row->borrower_id,
                'application_status'         => $row->application_status,
                'name'                       => $row->name,
                'mobile'                     => $row->mobile,
                'user_registered_on'         => $row->user_registered_on,
                'basic_details_on'           => $row->basic_details_on,
                'borrower_details_on'        => $row->borrower_details_on,
                'borrower_family_details_on' => $row->borrower_family_details_on,
                'borrower_occupation_on'     => $row->borrower_occupation_on,
                'coapplicant_details_on'     => $row->coapplicant_details_on,
                'coapplicant_occupation_on'  => $row->coapplicant_occupation_on,
                'guarantor_details_on'       => $row->guarantor_details_on,
                'guarantor_occupation_on'    => $row->guarantor_occupation_on,
                'reference_verification_on'  => $row->reference_verification_on,
                'field_inspection_on'        => $row->field_inspection_on,
                'vkyc_generated_on'          => $row->vkyc_generated_on,
                'emi_debit_consent_on'       => $row->emi_debit_consent_on,
                'borrower_esign_on'          => $row->borrower_esign_on,
                'coapplicant_esign_on'       => $row->coapplicant_esign_on,
                'guarantor_esign_on'         => $row->guarantor_esign_on,
                'enach_registration_on'      => $row->enach_registration_on,
                'disbursement_on'            => $row->disbursement_on,
                'time_taken'                 => $this->calcTimeTaken($row->basic_details_on, $row->disbursement_on),
            ];
 
            foreach (self::COLUMNS as $colIdx => [, $key, $fmt]) {
                $sheet->getCellByColumnAndRow($colIdx + 1, $excelRow)
                    ->setValueExplicit(
                        $this->formatValue($dataMap[$key] ?? null, $fmt, $tz),
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                    );
            }
 
            $rowStyle = $sheet->getStyle("A{$excelRow}:{$lastLetter}{$excelRow}");
 
            if ($excelRow % 2 === 0) {
                $rowStyle->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($altRowHex);
            }
 
            $rowStyle->getFont()->setName('Arial')->setSize(9);
            $rowStyle->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setVertical(Alignment::VERTICAL_CENTER);
 
            $excelRow++;
        }
 
        // -- Column widths ----------------------------------------------------
        $widths = [
            1 => 14, 2 => 22, 3 => 14, 4 => 22, 5 => 22,
            6 => 22, 7 => 24, 8 => 24, 9 => 26, 10 => 26,
            11 => 22, 12 => 26, 13 => 26, 14 => 22, 15 => 22,
            16 => 28, 17 => 28, 18 => 30, 19 => 28, 20 => 26,
            21 => 18, 22 => 32,
        ];
 
        foreach ($widths as $colIdx => $width) {
            $sheet->getColumnDimension(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx)
            )->setWidth($width);
        }
 
        // -- Border + freeze + autofilter -------------------------------------
        if ($excelRow > 2) {
            $sheet->getStyle("A1:{$lastLetter}" . ($excelRow - 1))
                ->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setRGB($borderColor);
 
            $sheet->setAutoFilter("A1:{$lastLetter}1");
        }
 
        $sheet->freezePane('A2');
 
        // -- Stream -----------------------------------------------------------
        $periodSuffix = $month
            ? sprintf('%04d_%02d', $year, $month)
            : (string) $year;
 
        $filename = 'borrower_timeline_' . $periodSuffix . '_' . now($tz)->format('His') . '.xlsx';
        $writer   = new Xlsx($spreadsheet);
 
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }
 
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
 
    private function formatValue($value, ?string $fmt, string $tz): string
    {
        if ($value === null || $value === '') {
            return '';
        }
 
        if ($fmt === 'datetime') {
            try {
                return Carbon::parse($value, 'UTC')->setTimezone($tz)->format('d-m-Y H:i:s');
            } catch (\Exception) {
                return (string) $value;
            }
        }
 
        if ($fmt === 'date') {
            try {
                return Carbon::parse($value)->format('d-m-Y');
            } catch (\Exception) {
                return (string) $value;
            }
        }
 
        return (string) $value;
    }
 
    private function calcTimeTaken($startRaw, $endRaw): string
    {
        if (!$startRaw || !$endRaw) {
            return '';
        }
 
        try {
            $start = Carbon::parse($startRaw, 'UTC');
            $end   = Carbon::parse($endRaw);
 
            if ($end->lt($start)) {
                return '';
            }
 
            $days = (int) $start->diffInDays($end);
 
            return $days . ' day' . ($days !== 1 ? 's' : '');
        } catch (\Exception) {
            return '';
        }
    }

    // =========================================================================
    // Disbursement Report — unified JSON list + Excel export
    //
    // Filters apply to disbursement_date (not created_at)
    // Only DISBURSED loans are included
    // is_excel = false → paginated JSON
    // is_excel = true  → stream .xlsx
    // =========================================================================
    public function exportDisbursementReport(Request $request)
    {
        // ── Validate ──────────────────────────────────────────────────────────
        $validator = Validator::make($request->all(), [
            'is_excel'               => 'nullable|boolean',
            'page'                   => 'integer|min:1',
            'limit'                  => 'integer|min:1',
            'year'                   => 'nullable|integer',
            'month'                  => 'nullable|string',
            'start_date'             => 'nullable|date',
            'end_date'               => 'nullable|date|after_or_equal:start_date',
            'disbursement_start_date'=> 'nullable|date',
            'disbursement_end_date'  => 'nullable|date|after_or_equal:disbursement_start_date',
            'borrower_id'            => 'nullable|string',
            'borrower_name'          => 'nullable|string|regex:/^[a-zA-Z\s]+$/',
            'mobile_no'              => 'nullable|digits:10',
            'dealer_id'              => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $isExcel = filter_var($request->input('is_excel', false), FILTER_VALIDATE_BOOLEAN);
        $authId  = auth()->id();

        [$baseQuery, , $empty] = $this->resolveBaseQuery($authId);

        if ($empty) {
            return $this->send_response('success', 'No records found', 200, []);
        }

        // ── Only DISBURSED loans ──────────────────────────────────────────────
        $baseQuery->where('loan_basic_details.status', 'DISBURSED');

        // ── Join disbursement_details (always needed) ─────────────────────────
        $baseQuery->join(
            'disbursement_details as dd',
            'dd.borrower_id', '=', 'loan_basic_details.id'
        );

        // ── Year filter (always applied, defaults to current year) ────────────
        $year = (int) ($request->input('year') ?: date('Y'));
        $baseQuery->whereYear('dd.disbursement_date', $year);

        // ── Month filter (only when explicitly provided) ───────────────────────
        if ($request->filled('month')) {
            $monthInput  = ucfirst(strtolower(trim($request->input('month'))));
            $parsed      = DateTime::createFromFormat('j F Y', "1 {$monthInput} {$year}");

            if (!$parsed) {
                return $this->send_response('failed', 'Invalid month provided', 422);
            }

            $monthNumber = (int) $parsed->format('n');
            $baseQuery->whereMonth('dd.disbursement_date', $monthNumber);
        }

        // ── Application date range (loan_basic_details.created_at) ───────────
        if ($request->filled('start_date')) {
            $baseQuery->where('loan_basic_details.created_at', '>=', Carbon::parse($request->start_date)->startOfDay());
        }
        if ($request->filled('end_date')) {
            $baseQuery->where('loan_basic_details.created_at', '<=', Carbon::parse($request->end_date)->endOfDay());
        }

        // ── Disbursement date range ───────────────────────────────────────────
        if ($request->filled('disbursement_start_date')) {
            $baseQuery->where('dd.disbursement_date', '>=', Carbon::parse($request->disbursement_start_date)->startOfDay());
        }
        if ($request->filled('disbursement_end_date')) {
            $baseQuery->where('dd.disbursement_date', '<=', Carbon::parse($request->disbursement_end_date)->endOfDay());
        }

        // ── Borrower ID filter ────────────────────────────────────────────────
        // numeric → search loan_basic_details.id, else → search borrower_id string
        if ($request->filled('borrower_id')) {
            $bid = trim($request->borrower_id);
            if (ctype_digit($bid)) {
                $baseQuery->where('loan_basic_details.id', (int) $bid);
            } else {
                $baseQuery->whereRaw('LOWER(loan_basic_details.borrower_id) LIKE ?', ['%' . strtolower($bid) . '%']);
            }
        }

        // ── Borrower name filter ──────────────────────────────────────────────
        if ($request->filled('borrower_name')) {
            $name = strtolower($request->borrower_name);
            $baseQuery->where(function ($q) use ($name) {
                $q->whereHas('latestBorrowerKyc', fn($k) => $k->whereRaw('LOWER(name) LIKE ?', ["%{$name}%"]))
                  ->orWhereHas('user_detail',     fn($u) => $u->whereRaw('LOWER(name) LIKE ?', ["%{$name}%"]));
            });
        }

        // ── Mobile filter ─────────────────────────────────────────────────────
        if ($request->filled('mobile_no')) {
            $mobile = $request->mobile_no;
            $baseQuery->where(function ($q) use ($mobile) {
                $q->whereHas('latestBorrowerKyc', fn($k) => $k->where('mobile', 'like', "%{$mobile}%"))
                  ->orWhereHas('user_detail',     fn($u) => $u->where('mobile', 'like', "%{$mobile}%"));
            });
        }

        // ── Admin-only dealer filter ──────────────────────────────────────────
        $lsp        = LoanServiceProvider::where('lsp_auth_id', $authId)->first();
        $dealerAuth = Dealer::where('dealer_auth_id', $authId)->first();
        $user       = auth()->user();
        $isAdmin    = !$lsp && !$dealerAuth && $user->user_type !== 36;

        if ($isAdmin && $request->filled('dealer_id')) {
            $dealer = Dealer::find($request->dealer_id);
            if ($dealer) {
                $baseQuery->where('loan_basic_details.dealer_id', $dealer->dealer_unq);
            }
        }

        // ── Eager loads + select ──────────────────────────────────────────────
        $baseQuery
            ->with([
                'dealer_detail:id,dealer_unq,name',
                'user_detail:id,mobile,name,email',
                'latestBorrowerKyc:id,borrower_id,name,mobile,email',
                'latestLoanScheme:id,borrower_id,iot,interest,loan_emi',
                'lenderGroup:id,borrower_id,lender_id',
                'lenderGroup.lender:id,name,lender_unq',
                'repaymentSchedules:id,borrower_id,emi_amount,due_date',
            ])
            ->select([
                'loan_basic_details.id',
                'loan_basic_details.borrower_id',
                'loan_basic_details.user_id',
                'loan_basic_details.loan_amount',
                'loan_basic_details.loan_term',
                'loan_basic_details.created_at',
                'loan_basic_details.dealer_id',
                'loan_basic_details.executive_id',
                'loan_basic_details.product_id',
                // disbursement columns pulled directly via join
                'dd.disbursement_amount',
                'dd.disbursement_date',
                'dd.transaction_id',
                'dd.utr_number',
            ])
            ->orderBy('dd.disbursement_date', 'desc');

        // ── Row transformer (shared between JSON and Excel) ───────────────────
        $transform = function ($borrower) {
            $kyc    = $borrower->latestBorrowerKyc;
            $user   = $borrower->user_detail;
            $dealer = $borrower->dealer_detail;
            $scheme = $borrower->latestLoanScheme;
            $lender = $borrower->lenderGroup?->lender;
            $schedules  = $borrower->repaymentSchedules;

            $firstSched = $schedules->first();
            $lastSched  = $schedules->last();

            $emi          = $firstSched?->emi_amount  ?? '';
            $emiStartDate = $firstSched?->due_date
                                ? date('d-m-Y', strtotime($firstSched->due_date))
                                : '';
            $emiEndDate   = $lastSched?->due_date
                                ? date('d-m-Y', strtotime($lastSched->due_date))
                                : '';

            $loanAmount = ($scheme && $scheme->iot)
                ? $borrower->loan_amount + $scheme->iot
                : $borrower->loan_amount;

            $lenderName = $lender ? $lender->name : '';

            return [
                'borrower_id'           => $borrower->borrower_id           ?? '',
                'borrower_name'         => $kyc->name   ?? $user->name      ?? '',
                'mobile'                => $kyc->mobile ?? $user->mobile    ?? '',
                'email'                 => $kyc->email  ?? $user->email     ?? '',
                'application_date'      => $borrower->created_at
                                            ? date('d-m-Y', strtotime($borrower->created_at))
                                            : '',
                'loan_amount'           => $loanAmount,
                'tenure'                => $borrower->loan_term             ?? '',
                'emi_amount'            => $emi,
                'emi_start_date'        => $emiStartDate,
                'emi_end_date'          => $emiEndDate,
                'disbursement_amount'   => $borrower->disbursement_amount   ?? '',
                'disbursement_date'     => $borrower->disbursement_date
                                            ? date('d-m-Y', strtotime($borrower->disbursement_date))
                                            : '',
                'transaction_id'        => $borrower->transaction_id        ?? '',
                'utr_number'            => $borrower->utr_number            ?? '',
                'dealer_id'             => $dealer->dealer_unq              ?? '',
                'dealer_name'           => $dealer->name                    ?? '',
                'lender_name'           => $lenderName,
            ];
        };

        // =========================================================================
        // JSON — paginated
        // =========================================================================
        if (!$isExcel) {
            $numberOfRecords = (int) $request->input('limit', 10);
            $page            = (int) $request->input('page', 1);
            $paginated       = $baseQuery->paginate($numberOfRecords, ['*'], 'page', $page);

            if ($paginated->isEmpty()) {
                return $this->send_response('success', 'No records found', 200, []);
            }

            $paginated->getCollection()->transform($transform);

            return $this->send_response('success', 'Disbursement report fetched', 200, $paginated);
        }

        // =========================================================================
        // Excel — full dataset for the period
        // =========================================================================
        $rows = $baseQuery->get();

        if ($rows->isEmpty()) {
            return $this->send_response('success', 'No records found for the selected period', 200, []);
        }

        $data = $rows->map($transform);

        // ── Column definitions ────────────────────────────────────────────────
        $columns = [
            'A' => ['header' => 'Borrower ID',           'key' => 'borrower_id'],
            'B' => ['header' => 'Borrower Name',         'key' => 'borrower_name'],
            'C' => ['header' => 'Mobile',                'key' => 'mobile'],
            'D' => ['header' => 'Email',                 'key' => 'email'],
            'E' => ['header' => 'Application Date',      'key' => 'application_date'],
            'F' => ['header' => 'Loan Amount',           'key' => 'loan_amount'],
            'G' => ['header' => 'Tenure',                'key' => 'tenure'],
            'H' => ['header' => 'EMI Amount',            'key' => 'emi_amount'],
            'I' => ['header' => 'EMI Start Date',        'key' => 'emi_start_date'],
            'J' => ['header' => 'EMI End Date',          'key' => 'emi_end_date'],
            'K' => ['header' => 'Disbursement Amount',   'key' => 'disbursement_amount'],
            'L' => ['header' => 'Disbursement Date',     'key' => 'disbursement_date'],
            'M' => ['header' => 'Transaction ID',        'key' => 'transaction_id'],
            'N' => ['header' => 'UTR Number',            'key' => 'utr_number'],
            'O' => ['header' => 'Dealer ID',             'key' => 'dealer_id'],
            'P' => ['header' => 'Dealer Name',           'key' => 'dealer_name'],
            'Q' => ['header' => 'Lender Name',           'key' => 'lender_name'],
        ];

        $lastCol = array_key_last($columns); // 'P'

        // ── Build spreadsheet ─────────────────────────────────────────────────
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Disbursement Report');

        // Headers
        foreach ($columns as $col => $def) {
            $sheet->setCellValue($col . '1', $def['header']);
            $sheet->getColumnDimension($col)->setWidth(max(18, strlen($def['header']) + 4));
        }

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Arial', 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F4E79']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        // Data rows
        $rowIndex = 2;
        foreach ($data as $record) {
            foreach ($columns as $col => $def) {
                $sheet->setCellValue($col . $rowIndex, $record[$def['key']] ?? '');
            }

            if ($rowIndex % 2 === 0) {
                $sheet->getStyle("A{$rowIndex}:{$lastCol}{$rowIndex}")
                      ->getFill()
                      ->setFillType(Fill::FILL_SOLID)
                      ->getStartColor()->setARGB('FFF0F4FA');
            }

            $sheet->getStyle("A{$rowIndex}:{$lastCol}{$rowIndex}")
                  ->getFont()->setName('Arial')->setSize(10);

            $rowIndex++;
        }

        $sheet->freezePane('A2');

        if ($rowIndex > 2) {
            $sheet->setAutoFilter("A1:{$lastCol}1");
        }

        // ── Filename ──────────────────────────────────────────────────────────
        $monthLabel = $request->filled('month')
            ? ucfirst(strtolower(trim($request->month)))
            : 'All';
        $filename   = "Disbursement_Report_{$monthLabel}_{$year}_" . date('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    // =========================================================================
    // DPD & NPA Report
    // =========================================================================
    public function exportDpdNpaReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'is_excel'      => 'nullable|boolean',
            'page'          => 'nullable|integer|min:1',
            'limit'         => 'nullable|integer|min:1',
            'year'          => 'nullable|integer|min:2000|max:2100',
            'month'         => 'nullable|integer|min:1|max:12',
            'borrower_id'   => 'nullable|string',
            'borrower_name' => 'nullable|string|regex:/^[a-zA-Z\s]+$/',
            'mobile_no'     => 'nullable|digits:10',
            'dealer_id'     => 'nullable|integer',
            'lsp_id'        => 'nullable|integer',
            'dpd_filter' => 'nullable|string|in:DPD30,DPD60,DPD90,NPA',
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $isExcel   = filter_var($request->input('is_excel', false), FILTER_VALIDATE_BOOLEAN);
        $authId    = auth()->id();
        $tz        = 'Asia/Kolkata';
        $year      = (int) ($request->input('year') ?: now($tz)->year);
        $month     = $request->filled('month') ? (int) $request->input('month') : null;
        $jsonMonth = $month ?? (int) now($tz)->month;

        [$baseQuery, , $empty] = $this->resolveBaseQuery($authId);

        if ($empty) {
            return $this->send_response('success', 'No records found', 200, []);
        }

        $baseQuery->where('loan_basic_details.status', 'DISBURSED');
        $baseQuery->join('disbursement_details as dd', 'dd.borrower_id', '=', 'loan_basic_details.id');

        if ($request->filled('borrower_id')) {
            $bid = trim($request->borrower_id);
            if (ctype_digit($bid)) {
                $baseQuery->where('loan_basic_details.id', (int) $bid);
            } else {
                $baseQuery->whereRaw(
                    'LOWER(loan_basic_details.borrower_id) LIKE ?',
                    ['%' . strtolower($bid) . '%']
                );
            }
        }

        if ($request->filled('borrower_name')) {
            $name = strtolower($request->borrower_name);
            $baseQuery->where(function ($q) use ($name) {
                $q->whereHas('latestBorrowerKyc', fn($k) => $k->whereRaw('LOWER(name) LIKE ?', ["%{$name}%"]))
                  ->orWhereHas('user_detail',     fn($u) => $u->whereRaw('LOWER(name) LIKE ?', ["%{$name}%"]));
            });
        }

        if ($request->filled('mobile_no')) {
            $mobile = $request->mobile_no;
            $baseQuery->where(function ($q) use ($mobile) {
                $q->whereHas('latestBorrowerKyc', fn($k) => $k->where('mobile', 'like', "%{$mobile}%"))
                  ->orWhereHas('user_detail',     fn($u) => $u->where('mobile', 'like', "%{$mobile}%"));
            });
        }

        $lsp        = LoanServiceProvider::where('lsp_auth_id', $authId)->first();
        $dealerAuth = Dealer::where('dealer_auth_id', $authId)->first();
        $user       = auth()->user();
        $isAdmin    = !$lsp && !$dealerAuth && $user->user_type !== 36;

        if ($isAdmin) {
            if ($request->filled('dealer_id')) {
                $dealer = Dealer::find($request->dealer_id);
                if ($dealer) {
                    $baseQuery->where('loan_basic_details.dealer_id', $dealer->dealer_unq);
                }
            }
            if ($request->filled('lsp_id')) {
                $execIds = LspExecutiveDetails::where('lsp_id', $request->lsp_id)->pluck('id');
                if ($execIds->isNotEmpty()) {
                    $baseQuery->whereIn('loan_basic_details.executive_id', $execIds);
                }
            }
        }

        $baseQuery
            ->with([
                'user_detail:id,mobile,name,email',
                'latestBorrowerKyc:id,borrower_id,name,mobile,email',
                'latestLoanScheme:id,borrower_id,iot',
            ])
            ->select([
                'loan_basic_details.id',
                'loan_basic_details.borrower_id',
                'loan_basic_details.user_id',
                'loan_basic_details.loan_amount',
                'loan_basic_details.loan_term',
                'loan_basic_details.created_at',
                'dd.disbursement_date',
            ])
            ->orderBy('loan_basic_details.created_at', 'desc');

        // =========================================================================
        // JSON
        // =========================================================================
        if (!$isExcel) {
            $perPage   = (int) $request->input('limit', 10);
            $page      = (int) $request->input('page', 1);
            $paginated = $baseQuery->paginate($perPage, ['*'], 'page', $page);

            if ($paginated->isEmpty()) {
                return $this->send_response('success', 'No records found', 200, []);
            }

            $pageIds = $paginated->pluck('id')->all();

            // Period-scoped: EMIs due up to end of selected month
            // Used for DPD30/60/90 bucket counts for the period
            $periodEnd = Carbon::create($year, $jsonMonth, 1, 0, 0, 0, $tz)
                ->endOfMonth()
                ->utc();

            $schedulesForPeriod = DB::table('borrower_repayment_schedules')
                ->whereIn('borrower_id', $pageIds)
                ->where('due_date', '<=', $periodEnd)
                ->orderBy('borrower_id')
                ->orderBy('due_date', 'asc')
                ->get(['borrower_id', 'due_date', 'payment_date', 'payment_status', 'emi_amount']);

            // All-time: for MaxDPD, CurrentDPD, IsNPA — true loan history
            $schedulesAllTime = DB::table('borrower_repayment_schedules')
                ->whereIn('borrower_id', $pageIds)
                ->orderBy('borrower_id')
                ->orderBy('due_date', 'asc')
                ->get(['borrower_id', 'due_date', 'payment_date', 'payment_status', 'emi_amount']);

            // EMI amount = first installment (earliest due_date) per borrower
            $emiMap = $schedulesAllTime
                ->groupBy('borrower_id')
                ->map(fn($rows) => $rows->first()->emi_amount);

            $scheduleMapPeriod  = $schedulesForPeriod->groupBy('borrower_id');
            $scheduleMapAllTime = $schedulesAllTime->groupBy('borrower_id');

            $dpdFilter = $request->input('dpd_filter') ?? null;
            if (!empty($dpdFilter)) {
                $paginated->setCollection(
                    $paginated->getCollection()->filter(function ($borrower) use (
                        $scheduleMapAllTime, $tz, $dpdFilter
                    ) {
                        $allTimeSchedules = $scheduleMapAllTime->get($borrower->id, collect());
                        $dpdAllTime       = $this->calculateDpdSummary($allTimeSchedules, $tz);

                        return match($dpdFilter) {
                            'DPD30' => $dpdAllTime['maxDpd'] >= 1  && $dpdAllTime['maxDpd'] <= 30,
                            'DPD60' => $dpdAllTime['maxDpd'] >= 31 && $dpdAllTime['maxDpd'] <= 60,
                            'DPD90' => $dpdAllTime['maxDpd'] >= 61 && $dpdAllTime['maxDpd'] <= 90,
                            'NPA'   => $dpdAllTime['isNpa'],
                            default => true,
                        };
                    })->values()
                );

                if ($paginated->getCollection()->isEmpty()) {
                    return $this->send_response('success', 'No records found', 200, []);
                }
            }

            $paginated->getCollection()->transform(function ($borrower) use (
                $scheduleMapPeriod,
                $scheduleMapAllTime,
                $emiMap,
                $tz
            ) {
                $kyc    = $borrower->latestBorrowerKyc;
                $user   = $borrower->user_detail;
                $scheme = $borrower->latestLoanScheme;

                $loanAmount = ($scheme?->iot)
                    ? $borrower->loan_amount + $scheme->iot
                    : $borrower->loan_amount;

                $emi = $emiMap[$borrower->id] ?? '';

                $periodSchedules  = $scheduleMapPeriod->get($borrower->id,  collect());
                $allTimeSchedules = $scheduleMapAllTime->get($borrower->id, collect());

                // Period summary → bucket counts for selected month
                $dpdPeriod  = $this->calculateDpdSummary($periodSchedules,  $tz);
                // All-time summary → MaxDPD, CurrentDPD, IsNPA
                $dpdAllTime = $this->calculateDpdSummary($allTimeSchedules, $tz);

                return [
                    'BorrowerId'       => $borrower->borrower_id        ?? '',
                    'BorrowerName'     => $kyc->name   ?? $user->name   ?? '',
                    'BorrowerMobile'   => $kyc->mobile ?? $user->mobile ?? '',
                    'BorrowerEmail'    => $kyc->email  ?? $user->email  ?? '',
                    'LoanAmount'       => $loanAmount,
                    'Tenure'           => $borrower->loan_term           ?? '',
                    'EmiAmount'        => $emi,
                    'DisbursementDate' => $borrower->disbursement_date
                                            ? date('d-m-Y', strtotime($borrower->disbursement_date))
                                            : '',
                    'DPD30Count'   => $dpdPeriod['dpd30'],
                    'DPD60Count'   => $dpdPeriod['dpd60'],
                    'DPD90Count'   => $dpdPeriod['dpd90'],
                    'MaxDPD'       => $dpdAllTime['maxDpd'],
                    'CurrentDPD'   => $dpdAllTime['currentDpd'],
                    'IsNPA'        => $dpdAllTime['isNpa'],
                    'NpaLabel'     => $dpdAllTime['isNpa']
                                        ? 'NPA'
                                        : ($dpdAllTime['maxDpd'] > 0 ? 'DPD' : 'STANDARD'),
                ];
            });

            return $this->send_response('success', 'DPD/NPA report fetched', 200, $paginated);
        }

        // =========================================================================
        // Excel
        // =========================================================================
        $rows = $baseQuery->get();

        if ($rows->isEmpty()) {
            return $this->send_response('success', 'No records found for the selected period', 200, []);
        }

        $allIds = $rows->pluck('id')->all();

        if ($month !== null) {
            $monthColumns = [Carbon::create($year, $month, 1)];
        } else {
            $currentYear  = (int) now($tz)->year;
            $upToMonth    = ($year === $currentYear) ? (int) now($tz)->month : 12;
            $monthColumns = [];
            for ($m = 1; $m <= $upToMonth; $m++) {
                $monthColumns[] = Carbon::create($year, $m, 1);
            }
        }

        // Year-scoped: for the month grid columns
        $yearStart = Carbon::create($year, 1, 1, 0, 0, 0, $tz)->startOfYear()->utc();
        $yearEnd   = Carbon::create($year, 12, 31, 23, 59, 59, $tz)->endOfYear()->utc();

        $allSchedules = DB::table('borrower_repayment_schedules')
            ->whereIn('borrower_id', $allIds)
            ->whereBetween('due_date', [$yearStart, $yearEnd])
            ->orderBy('borrower_id')
            ->orderBy('due_date', 'asc')
            ->get(['borrower_id', 'due_date', 'payment_date', 'payment_status', 'emi_amount']);

        // All-time: for MaxDPD + NPA fixed columns + EMI amount
        $allSchedulesAllTime = DB::table('borrower_repayment_schedules')
            ->whereIn('borrower_id', $allIds)
            ->orderBy('borrower_id')
            ->orderBy('due_date', 'asc')
            ->get(['borrower_id', 'due_date', 'payment_date', 'payment_status', 'emi_amount']);

        $emiMap = $allSchedulesAllTime
            ->groupBy('borrower_id')
            ->map(fn($rows) => $rows->first()->emi_amount);

        $allTimeSummaryMap = $allSchedulesAllTime
            ->groupBy('borrower_id')
            ->map(fn($rows) => $this->calculateDpdSummary($rows, $tz));

        if (!empty($dpdFilter)) {
            $rows = $rows->filter(function ($borrower) use ($allTimeSummaryMap, $dpdFilter) {
                $dpd = $allTimeSummaryMap[$borrower->id] ?? null;

                if (!$dpd) return false;

                return match($dpdFilter) {
                    'DPD30' => $dpd['maxDpd'] >= 1  && $dpd['maxDpd'] <= 30,
                    'DPD60' => $dpd['maxDpd'] >= 31 && $dpd['maxDpd'] <= 60,
                    'DPD90' => $dpd['maxDpd'] >= 61 && $dpd['maxDpd'] <= 90,
                    'NPA'   => $dpd['isNpa'],
                    default => true,
                };
            })->values();

            if ($rows->isEmpty()) {
                return $this->send_response('success', 'No records found for the selected period', 200, []);
            }
        }

        // Grid: borrower_id → 'Y-m' → earliest EMI row for that month
        $scheduleGrid = [];
        foreach ($allSchedules as $sched) {
            $mk = Carbon::parse($sched->due_date)->format('Y-m');
            if (!isset($scheduleGrid[$sched->borrower_id][$mk])) {
                $scheduleGrid[$sched->borrower_id][$mk] = $sched;
            }
        }

        // ── Spreadsheet ───────────────────────────────────────────────────────
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('DPD NPA Report');

        $fixedColumns = [
            ['header' => 'Borrower ID',       'key' => 'borrower_id'],
            ['header' => 'Borrower Name',     'key' => 'name'],
            ['header' => 'Mobile',            'key' => 'mobile'],
            ['header' => 'Email',             'key' => 'email'],
            ['header' => 'Loan Amount',       'key' => 'loan_amount'],
            ['header' => 'Tenure',            'key' => 'loan_term'],
            ['header' => 'EMI Amount',        'key' => 'emi_amount'],
            ['header' => 'Disbursement Date', 'key' => 'disbursement_date'],
            ['header' => 'Max DPD',           'key' => 'max_dpd'],
            ['header' => 'NPA',               'key' => 'is_npa'],
        ];

        $fixedCount = count($fixedColumns); // 10

        foreach ($fixedColumns as $i => $def) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $def['header']);
            $sheet->getColumnDimension($col)->setWidth(20);
        }

        foreach ($monthColumns as $mi => $monthDate) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($fixedCount + $mi + 1);
            $sheet->setCellValue("{$col}1", $monthDate->format('M-y'));
            $sheet->getColumnDimension($col)->setWidth(11);
        }

        $lastColIdx    = $fixedCount + count($monthColumns);
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIdx);

        $sheet->getStyle("A1:{$lastColLetter}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Arial', 'size' => 10],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F4E79']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $rowIndex = 2;

        foreach ($rows as $borrower) {
            $kyc    = $borrower->latestBorrowerKyc;
            $user   = $borrower->user_detail;
            $scheme = $borrower->latestLoanScheme;

            $loanAmount = ($scheme?->iot)
                ? $borrower->loan_amount + $scheme->iot
                : $borrower->loan_amount;

            $emi = $emiMap[$borrower->id] ?? '';

            $dpd = $allTimeSummaryMap[$borrower->id] ?? [
                'maxDpd'     => 0,
                'isNpa'      => false,
                'dpd30'      => 0,
                'dpd60'      => 0,
                'dpd90'      => 0,
                'currentDpd' => 0,
            ];

            $fixedData = [
                'borrower_id'       => $borrower->borrower_id        ?? '',
                'name'              => $kyc->name   ?? $user->name   ?? '',
                'mobile'            => $kyc->mobile ?? $user->mobile ?? '',
                'email'             => $kyc->email  ?? $user->email  ?? '',
                'loan_amount'       => $loanAmount,
                'loan_term'         => $borrower->loan_term           ?? '',
                'emi_amount'        => $emi,
                'disbursement_date' => $borrower->disbursement_date
                                        ? date('d-m-Y', strtotime($borrower->disbursement_date))
                                        : '',
                'max_dpd'           => $dpd['maxDpd'],
                'is_npa'            => $dpd['isNpa'] ? 'YES' : 'NO',
            ];

            foreach ($fixedColumns as $i => $def) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue("{$col}{$rowIndex}", $fixedData[$def['key']] ?? '');
            }

            if ($dpd['isNpa']) {
                $sheet->getStyle("A{$rowIndex}:J{$rowIndex}")
                      ->getFill()->setFillType(Fill::FILL_SOLID)
                      ->getStartColor()->setARGB('FFFCE4EC');
            } elseif ($rowIndex % 2 === 0) {
                $sheet->getStyle("A{$rowIndex}:J{$rowIndex}")
                      ->getFill()->setFillType(Fill::FILL_SOLID)
                      ->getStartColor()->setARGB('FFF0F4FA');
            }

            foreach ($monthColumns as $mi => $monthDate) {
                $col      = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($fixedCount + $mi + 1);
                $monthKey = $monthDate->format('Y-m');
                $sched    = $scheduleGrid[$borrower->id][$monthKey] ?? null;

                if (!$sched) {
                    $sheet->setCellValue("{$col}{$rowIndex}", '—');
                    $sheet->getStyle("{$col}{$rowIndex}")
                          ->getFont()->getColor()->setARGB('FFBDBDBD');
                } else {
                    $dpdVal   = $this->computeSingleDpd($sched, $tz);
                    $isPaid   = $sched->payment_status === 'PAID';
                    $isNpaEmi = !$isPaid && $dpdVal > 90;

                    if ($isNpaEmi) {
                        $cellVal = 'NPA';
                    } elseif ($dpdVal === 0 && $isPaid) {
                        $cellVal = 'OK';
                    } elseif ($dpdVal === 0 && !$isPaid) {
                        $cellVal = 'DUE';
                    } else {
                        $cellVal = (string) $dpdVal;
                    }

                    $sheet->setCellValue("{$col}{$rowIndex}", $cellVal);

                    $bgArgb = match(true) {
                        $isNpaEmi         => 'FF7F1D1D',
                        $dpdVal > 90      => 'FFDC2626',
                        $dpdVal > 60      => 'FFF97316',
                        $dpdVal > 30      => 'FFFACC15',
                        $dpdVal > 0       => 'FFFEF08A',
                        $cellVal === 'OK' => 'FFD1FAE5',
                        $cellVal === 'DUE'=> 'FFE0F2FE',
                        default           => 'FFFFFFFF',
                    };

                    $sheet->getStyle("{$col}{$rowIndex}")
                          ->getFill()->setFillType(Fill::FILL_SOLID)
                          ->getStartColor()->setARGB($bgArgb);

                    $sheet->getStyle("{$col}{$rowIndex}")
                          ->getFont()->getColor()
                          ->setARGB($isNpaEmi ? 'FFFFFFFF' : 'FF000000');
                }

                $sheet->getStyle("{$col}{$rowIndex}")
                      ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }

            $sheet->getStyle("A{$rowIndex}:{$lastColLetter}{$rowIndex}")
                  ->getFont()->setName('Arial')->setSize(9);
            $sheet->getStyle("A{$rowIndex}:{$lastColLetter}{$rowIndex}")
                  ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

            $rowIndex++;
        }

        // $sheet->freezePane('K2');
        $sheet->freezePane('C2');

        if ($rowIndex > 2) {
            $sheet->setAutoFilter("A1:{$lastColLetter}1");
            $sheet->getStyle("A1:{$lastColLetter}" . ($rowIndex - 1))
                  ->getBorders()->getAllBorders()
                  ->setBorderStyle(Border::BORDER_THIN)
                  ->getColor()->setRGB('BDD7EE');
        }

        $periodSuffix = $month
            ? date('M', mktime(0, 0, 0, $month, 1)) . "_{$year}"
            : "Full_{$year}";

        $filename = "DPD_NPA_Report_{$periodSuffix}_" . date('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    // =========================================================================
    // DPD Helpers
    // =========================================================================

    /**
     * Compute DPD for a single EMI installment.
     *
     * Examples (today = 21-May-2026):
     *   due=05-May-2026, unpaid  → 21-05 = 16 days  → DPD 16
     *   due=04-Apr-2026, unpaid  → 47 days           → DPD 47
     *   due=05-Jun-2026, unpaid  → future             → DPD 0
     *   due=05-May-2026, paid=10-May → 10-05 = 5 days late → DPD 5
     *   due=05-May-2026, paid=03-May → paid early     → DPD 0
     */
    private function computeSingleDpd(object $sched, string $tz = 'Asia/Kolkata'): int
    {
        $dueDate = Carbon::parse($sched->due_date)->startOfDay();

        if ($sched->payment_status === 'PAID' && $sched->payment_date) {
            $payDate = Carbon::parse($sched->payment_date)->startOfDay();

            // Days late = payment_date - due_date
            // Positive means late, negative/zero means on time or early
            $daysLate = $dueDate->diffInDays($payDate, false); // payDate - dueDate

            return max((int) $daysLate, 0);
        }

        // Unpaid — compare due_date against today
        $today = Carbon::today($tz);

        // Future EMI — not due yet
        if ($dueDate->gt($today)) {
            return 0;
        }

        // today - due_date = days overdue
        return (int) $dueDate->diffInDays($today);
    }

    /**
     * Aggregate DPD summary across all EMI rows for one borrower.
     *
     * dpd30      → count of EMIs with DPD 1–30   (overdue but within 30 days)
     * dpd60      → count of EMIs with DPD 31–60
     * dpd90      → count of EMIs with DPD 61–90
     * maxDpd     → single worst DPD value across all EMIs
     * currentDpd → DPD of the most recently due EMI
     * isNpa      → true if ANY unpaid EMI has DPD > 90
     */
    private function calculateDpdSummary(
        \Illuminate\Support\Collection $schedules,
        string $tz = 'Asia/Kolkata'
    ): array {
        $dpd30      = 0;
        $dpd60      = 0;
        $dpd90      = 0;
        $maxDpd     = 0;
        $isNpa      = false;
        $currentDpd = 0;
        $latestDue  = null;

        foreach ($schedules as $sched) {
            $dpd = $this->computeSingleDpd($sched, $tz);

            // Track worst DPD
            if ($dpd > $maxDpd) {
                $maxDpd = $dpd;
            }

            // Account-level NPA: any unpaid EMI overdue by more than 90 days
            if ($sched->payment_status === 'UNPAID' && $dpd > 90) {
                $isNpa = true;
            }

            // Bucket counts — only EMIs that are actually overdue (dpd > 0)
            if ($dpd >= 1  && $dpd <= 30) $dpd30++;
            if ($dpd >= 31 && $dpd <= 60) $dpd60++;
            if ($dpd >= 61 && $dpd <= 90) $dpd90++;

            // CurrentDPD = DPD of the most recently due EMI (past or today)
            $due = Carbon::parse($sched->due_date)->startOfDay();
            $today = Carbon::today($tz);

            if (!$due->gt($today)) {
                // Only consider EMIs that are already due
                if ($latestDue === null || $due->gt($latestDue)) {
                    $latestDue  = $due;
                    $currentDpd = $dpd;
                }
            }
        }

        return [
            'dpd30'      => $dpd30,
            'dpd60'      => $dpd60,
            'dpd90'      => $dpd90,
            'maxDpd'     => $maxDpd,
            'currentDpd' => $currentDpd,
            'isNpa'      => $isNpa,
        ];
    }
}