<?php

namespace App\Http\Controllers;

use App\Models\BorrowerIdentifier;
use App\Models\BorrowerIdentifierMapping;
use App\Models\Dealer;
use App\Models\LoanBasicDetails;
use App\Models\LoanServiceProvider;
use App\Models\LspExecutiveDetails;
use App\Models\Manufacturers;
use App\Models\ProductModel;
use App\Models\VendorServiceApi;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\BorrowerVisibilityService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class LoanApplicationReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly HelperController          $helper_controller,
        private readonly BorrowerVisibilityService $visibilityService,
    ) {}

    // =========================================================================
    // Access control — duplicated from ReportsController intentionally
    // =========================================================================
    private function resolveBaseQuery(int $authId): array
    {
        $base = LoanBasicDetails::query();
            return [$base, true, false];

        $lsp = LoanServiceProvider::where('lsp_auth_id', $authId)->first();
        if ($lsp) {
            $execIds = LspExecutiveDetails::where('lsp_id', $lsp->id)->pluck('id');
            if ($execIds->isEmpty()) {
                return [null, false, true];
            }
            $base->whereIn('executive_id', $execIds);
            return [$base, false, false];
        }

        $dealerAuth = Dealer::where('dealer_auth_id', $authId)->first();
        if ($dealerAuth) {
            $base->where('dealer_id', $dealerAuth->dealer_unq);
            return [$base, false, false];
        }

        $user = auth()->user();

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
    // Main entry point — JSON list + Excel export
    // =========================================================================
    public function exportLoanApplicationReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'is_excel' => 'nullable|boolean',
            'page'     => 'nullable|integer|min:1',
            'limit'    => 'nullable|integer|min:1',
            'year'     => 'nullable|integer',
            'month'    => 'nullable|string',
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
        $year        = (int) ($request->input('year') ?: date('Y'));
        $monthNumber = null;

        // $baseQuery->whereYear('loan_basic_details.created_at', $year);

        // if ($request->filled('month')) {
        //     $monthInput  = ucfirst(strtolower(trim($request->input('month'))));
        //     $parsed      = DateTime::createFromFormat('j F Y', "1 {$monthInput} {$year}");
        //     if (!$parsed) {
        //         return $this->send_response('failed', 'Invalid month provided', 422);
        //     }
        //     $monthNumber = (int) $parsed->format('n');
        //     $baseQuery->whereMonth('loan_basic_details.created_at', $monthNumber);
        // }

        $monthInput  = $request->filled('month')
            ? ucfirst(strtolower(trim($request->input('month'))))
            : date('F');

        $parsed = DateTime::createFromFormat('j F Y', "1 {$monthInput} {$year}");
        if (!$parsed) {
            return $this->send_response('failed', 'Invalid month provided', 422);
        }
        $monthNumber = (int) $parsed->format('n');

        // $baseQuery->whereHas('latestDisbursement', function ($q) use ($year, $monthNumber) {
        //     $q->whereYear('disbursement_date', $year)
        //       ->whereMonth('disbursement_date', $monthNumber);
        // });

        // ── JSON — paginated minimal listing ──────────────────────────────────
        if (!$isExcel) {
            $baseQuery
                ->with(['latestBorrowerKyc:id,borrower_id,name,mobile,email'])
                ->select([
                    'loan_basic_details.id',
                    'loan_basic_details.borrower_id',
                    'loan_basic_details.status',
                    'loan_basic_details.created_at',
                ])
                ->orderBy('loan_basic_details.created_at', 'desc');

            $perPage   = (int) $request->input('limit', 10);
            $page      = (int) $request->input('page', 1);
            $paginated = $baseQuery->paginate($perPage, ['*'], 'page', $page);

            if ($paginated->isEmpty()) {
                return $this->send_response('success', 'No records found', 200, []);
            }

            $paginated->getCollection()->transform(function ($row) {
                $kyc = $row->latestBorrowerKyc;
                return [
                    'borrower_id' => $row->borrower_id ?? '',
                    'name'        => $kyc->name         ?? '',
                    'mobile'      => $kyc->mobile        ?? '',
                    'email'       => $kyc->email         ?? '',
                    'status'      => $row->status         ?? '',
                ];
            });

            return $this->send_response('success', 'Loan application report fetched', 200, $paginated);
        }

        // ── Excel — full 656-column export ────────────────────────────────────
        return $this->buildExcelExport($baseQuery, $year, $monthNumber);
    }

    // =========================================================================
    // Excel builder
    // =========================================================================
    private function buildExcelExport($baseQuery, int $year, ?int $monthNumber)
    {
        // 656 columns × N rows — bump memory before anything else
        ini_set('memory_limit', '512M');

        $baseQuery
            ->with([
                'dealer_detail:id,dealer_unq,name',
                'latestBorrowerKyc:id,borrower_id,name,mobile,pan,alternate_number1,email,dob_date,gender,marital_status,father_name,chasis_number',
                'latestBorrowerAddress:id,borrower_id,current_address,permanent_address,family_members',
                'latestBorrowerOccupation:id,borrower_id,current_occupation,total_income',
                'latestCoapplicantKyc:id,borrower_id,name,mobile,pan,email,dob_date,gender,father_name,relation_with_borrower',
                'latestCoapplicantOccupation:id,borrower_id,current_occupation,total_income,current_address,permanent_address',
                'latestGuarantor:id,borrower_id,name,mobile,pan,email,dob_date,gender,father_name,relation_with_borrower',
                'latestGuarantorOccupation:id,borrower_id,current_occupation,total_income,current_address,permanent_address',
                'latestLoanScheme:id,borrower_id,iot,interest,ex_showroom_price,processing_fee',
                'lenderGroup:id,borrower_id,lender_id',
                'lenderGroup.lender:id,name',
                
                'latestBorrowerReferenceDetails:id,borrower_id,reference_1,mobile_1,address_1,relation_1,reference_2,mobile_2,address_2,relation_2',
                'latestDisbursement:id,disbursement_date',
            ])
            ->with(['firstRepaymentSchedule' => function ($query) {
                $query->select(
                    'borrower_repayment_schedules.id',
                    'borrower_repayment_schedules.borrower_id',
                    'borrower_repayment_schedules.due_date',
                    'borrower_repayment_schedules.emi_amount'
                );
            }])
            ->select([
                'loan_basic_details.id',
                'loan_basic_details.borrower_id',
                'loan_basic_details.loan_amount',
                'loan_basic_details.loan_term',
                'loan_basic_details.dealer_id',
                'loan_basic_details.product_id',
                'loan_basic_details.model_id',
                'loan_basic_details.model',
                'loan_basic_details.created_at',
                'loan_basic_details.status',
            ])
            // ->whereIn('loan_basic_details.id', [1394,1402])
            ->orderBy('loan_basic_details.created_at', 'desc');

        $rows = $baseQuery->get();

        if ($rows->isEmpty()) {
            return $this->send_response('success', 'No records found for the selected period', 200, []);
        }

        $borrowerIds = $rows->pluck('id')->all();

        // ── Bulk load Aadhaar OCR (3 queries total) ───────────────────────────
        $aadharMap = $this->bulkLoadAadharOcr($borrowerIds);

        // ── Bulk load product models ──────────────────────────────────────────
        $modelUnqs     = $rows->pluck('model_id')->filter()->unique()->values();
        $productModels = ProductModel::whereIn('model_unq', $modelUnqs)
            ->get(['model_unq', 'name', 'manufacture_name', 'product_type'])
            ->keyBy('model_unq');

        // ── Spreadsheet setup ─────────────────────────────────────────────────
        $spreadsheet = new Spreadsheet();

        // Default font once — never set per row or per cell
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(9);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Loan Application Report');
        $sheet->getSheetView()->setZoomScale(80); // wide sheets are easier at 80%

        $headers       = $this->getColumnHeaders();
        $headerCount   = count($headers);
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($headerCount);

        // ── Write headers via fromArray (single call) ─────────────────────────
        $sheet->fromArray($headers, null, 'A1', true);

        // Fixed width 20 for every column — no per-cell strlen calculation
        for ($i = 1; $i <= $headerCount; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setWidth(20);
        }

        // Header style — one range call
        $sheet->getStyle("A1:{$lastColLetter}1")->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['argb' => 'FFFFFFFF'],
                'name'  => 'Arial',
                'size'  => 10,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF1F4E79'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => false, // wrapText on 656 cols adds overhead
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        // ── Data rows ─────────────────────────────────────────────────────────
        $rowIndex = 2;

        foreach ($rows as $borrower) {
            $rowData = $this->buildRowData($borrower, $aadharMap, $productModels);

            // fromArray writes all 656 cells in one call
            $sheet->fromArray($rowData, null, "A{$rowIndex}", true);

            $rowIndex++;

            // Free each borrower model immediately after use
            $borrower->setRelations([]);
        }

        // Free data before building the xlsx in memory
        unset($rows, $aadharMap, $productModels);
        gc_collect_cycles();

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColLetter}1");

        $monthLabel = $monthNumber ? date('M', mktime(0, 0, 0, $monthNumber, 1)) : 'All';
        $filename   = "Loan_Application_Report_{$monthLabel}_{$year}_" . date('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            // Explicitly destroy after streaming
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    // =========================================================================
    // Bulk load Aadhaar OCR back — 1 query per applicant type (3 total)
    // Returns: $aadharMap[$borrowerId][$applicantType] = full_address array
    // =========================================================================
    private function bulkLoadAadharOcr(array $borrowerIds): array
    {
        $map   = [];
        $types = ['BORROWER', 'COAPPLICANT', 'GUARANTOR'];

        foreach ($types as $type) {
            // DISTINCT ON picks latest record per borrower_id
            $records = DB::table('vendor_service_apis')
                ->select(DB::raw('DISTINCT ON (borrower_id) borrower_id, response'))
                ->whereIn('borrower_id', $borrowerIds)
                ->where('api_flag', 'AADHAR_CARD_OCR_BACK')
                ->where('status', 'VALID')
                ->where('applicant_type', $type)
                ->orderByRaw('borrower_id, created_at DESC')
                ->get();

            foreach ($records as $record) {
                try {
                    $decoded = json_decode(
                        unserialize(base64_decode($record->response)),
                        true
                    );
                    $address = $decoded['result'][0]['details']['address'] ?? [];
                    $map[$record->borrower_id][$type] = $address;
                } catch (\Throwable $e) {
                    $map[$record->borrower_id][$type] = [];
                }
            }
        }

        return $map;
    }

    // =========================================================================
    // Build a single row (656 values, indexed 0–655)
    // =========================================================================
    private function buildRowData($borrower, array $aadharMap, $productModels): array
    {
        $row = array_fill(0, 656, '');

        $kyc        = $borrower->latestBorrowerKyc;
        $address    = $borrower->latestBorrowerAddress;
        $occupation = $borrower->latestBorrowerOccupation;
        $dealer     = $borrower->dealer_detail;
        $scheme     = $borrower->latestLoanScheme;
        $lender     = $borrower->lenderGroup?->lender;
        $firstSched = $borrower->firstRepaymentSchedule;
        $coKyc      = $borrower->latestCoapplicantKyc;
        $coOcc      = $borrower->latestCoapplicantOccupation;
        $guarantor  = $borrower->latestGuarantor;
        $guarOcc    = $borrower->latestGuarantorOccupation;
        $ref        = $borrower->latestBorrowerReferenceDetails;
        $disbursement        = $borrower->latestDisbursement;

        $borrowerAadhar    = $aadharMap[$borrower->id]['BORROWER']     ?? [];
        $coapplicantAadhar = $aadharMap[$borrower->id]['COAPPLICANT']  ?? [];
        $guarantorAadhar   = $aadharMap[$borrower->id]['GUARANTOR']    ?? [];

        $productDetail = $productModels->get($borrower->model_id);

        $interest = $scheme?->interest ?? 0;
        $loan_term = $borrower->loan_term;
        // Loan amount = loan_amount + iot (if iot exists)
        $loanAmount = ($scheme?->iot)
            ? $borrower->loan_amount + $scheme->iot
            : $borrower->loan_amount;
        $repayment_schedule_calculation = $this->helper_controller->generateReducingInterestSchedule($loanAmount, $interest, $loan_term);

        $emi = $repayment_schedule_calculation['emi_amount'];
        $total_interest = $repayment_schedule_calculation['total_interest'];
        $total_loan_amount = $repayment_schedule_calculation['total_loan_amount'];


        // First EMI date
        $emiStartDate = $firstSched?->due_date
            ? date('d.m.Y', strtotime($firstSched->due_date))
            : '';

        // Lender name
        $lenderName = $lender?->name ?? '';

        // ── Cols 0–7: Loan header ─────────────────────────────────────────────
        $row[0] = 'Secured';                                          // Loan Type
        $row[1] = 'Electric Vehicle';                                 // Product Type
        $row[2] = 'Additional Asset';                                 // Purpose
        $row[3] = 'ELECTRIC VEHICLE';                                 // Product
        $row[4] = '1';                                                // No. Of Assets
        $row[5] = $borrower->created_at                               // Loan Date
            ? date('d.m.Y', strtotime($borrower->created_at))
            : '';
        // Col 6: Payment_bank/cash_Code — blank
        $row[6] = 'AFTBA-0001';                                       // Payment_bank/cash_Code
        $row[7] = 'Ev-Three Wheeler';                                 // Asset Type

        // Col 8: Dealer
        $row[8] = $dealer?->name ?? '';

        // Col 9: Required Date — blank

        // Cols 10–12: Branch, Location, Sub Location
        $row[10] = 'Delhi Branch';
        $row[11] = 'Delhi Branch';
        $row[12] = 'Delhi Branch';

        // Col 13: Borrower_Aadhar — blank
        // Col 14: Borrower_Pan Card
        $row[14] = $kyc?->pan ?? '';

        // Col 15: Borrower_Mobile1
        $row[15] = $kyc?->mobile ?? '';

        // Col 16: Borrower_Mobile2
        $row[16] = $kyc?->alternate_number1 ?? '';

        // Col 17: Borrower_Whatsapp Number
        $row[17] = $kyc?->mobile ?? '';

        // Col 18: Borrower_Email1
        $row[18] = $kyc?->email ?? '';

        // ── Pre-resolve addresses (needed for cols 41, 50–85) ────────────────
        $presAddr = $this->resolvePresAddress(
            $address?->current_address ?? '',
            $address?->permanent_address ?? '',
            $borrowerAadhar
        );
        $permAddr = $this->mapAadharToAddress($borrowerAadhar);

        // Col 19: Borrower_Email2 — blank

        // Col 20: Borrower_Prefix — Mr./Ms. based on gender
        $row[20] = $this->genderToPrefix($kyc?->gender ?? '');

        // Cols 21–23: Borrower name split
        [$fName, $mName, $lName] = $this->splitName($kyc?->name ?? '');
        $row[21] = $fName;
        $row[22] = $mName;
        $row[23] = $lName;

        // Col 24: Borrower_Relation
        $row[24] = 'S/o';

        // Col 25: Borrower_Rel. Prefix
        $row[25] = 'Mr.';

        // Cols 26–28: Father name split
        [$ffName, $fmName, $flName] = $this->splitName($kyc?->father_name ?? '');
        $row[26] = $ffName;
        $row[27] = $fmName;
        $row[28] = $flName;

        // Col 29: Borrower_Source — blank

        // Col 30: Borrower_DOB
        $row[30] = $kyc?->dob_date
            ? date('d.m.Y', strtotime($kyc->dob_date))
            : '';

        // Col 31: Borrower_Age
        $row[31] = $kyc?->dob_date
            ? Carbon::parse($kyc->dob_date)->age
            : '';

        // Col 32: Borrower_Gender
        $row[32] = $kyc?->gender ?? '';

        // Col 33: Borrower_Marital Status
        $row[33] = $kyc?->marital_status ?? '';

        // Col 34: Borrower_Dependents
        $row[34] = $address?->family_members ?? '';

        // Cols 35–36: Qualification — blank, Business/Sal
        $row[36] = 'Business';
        // Cols 37–39: Organization, Nature, Org.age — blank

        // Col 40: Borrower_Income Slab
        $row[40] = $occupation?->total_income ?? '';

        // Col 41: Borrower_State — from present address
        $row[41] = 'Delhi'; // $presAddr['state'] ?? '';

        // Col 42: Borrower_Segment — same as occupation type
        $row[42] = $occupation?->current_occupation ?? '';

        // Col 43: Borrower_Occupation
        $row[43] = $occupation?->current_occupation ?? '';

        // Col 44: Borrower_Status Type
        $row[44] = 'Open';
        // Cols 45–48: Allocate To, Un-allocated, Caste, Religion — blank
        // Col 49: Borrower_Remarks
        $row[49] = $borrower->borrower_id ?? '';

        // ── Borrower Present Address (cols 50–67) ────────────────────────────
        // presAddr already resolved above
        $row[50] = 'India';                         // Country
        $row[51] = 'Urban Independent';             // Location Type
        $row[52] = $presAddr['house']    ?? '';     // No.
        $row[53] = '';                              // Building
        $row[54] = $presAddr['street']   ?? '';     // Sector/Street
        $row[55] = $presAddr['locality'] ?? '';     // Colony/Area
        $row[56] = $presAddr['landmark'] ?? '';     // Land Mark
        $row[57] = $presAddr['city']     ?? '';     // City/Village
        $row[58] = $presAddr['state']    ?? '';     // State
        $row[59] = $presAddr['dist']     ?? '';     // District
        $row[60] = $presAddr['subdist']  ?? '';     // Tehsil — if available
        $row[61] = $presAddr['pincode']  ?? '';     // Pin Code
        $row[62] = '';                              // Residency
        // 63–67: STD Code, Phone-1, Phone-2, Fax, Mobile — blank

        // ── Borrower Permanent Address (cols 68–85) ──────────────────────────
        // permAddr already resolved above
        $row[68] = 'India';
        $row[69] = '';
        $row[70] = $permAddr['house']    ?? '';
        $row[71] = '';
        $row[72] = $permAddr['street']   ?? '';
        $row[73] = $permAddr['locality'] ?? '';
        $row[74] = $permAddr['landmark'] ?? '';
        $row[75] = $permAddr['city']     ?? '';
        $row[76] = $permAddr['state']    ?? '';
        $row[77] = $permAddr['dist']     ?? '';
        $row[78] = $permAddr['subdist']  ?? '';
        $row[79] = $permAddr['pincode']  ?? '';
        $row[80] = '';
        // 81–85: STD Code, Phone-1, Phone-2, Fax, Mobile — blank

        // ── Borrower Office Address (cols 86–103) — all blank ────────────────
        // Already blank from array_fill

        // ── Loan Details (cols 104–151) ───────────────────────────────────────
        // Col 104: Loan Amount
        $row[104] = $loanAmount;

        // Col 105: Tenure
        $row[105] = $borrower->loan_term ?? '';

        // Col 106: Instalments
        $row[106] = $borrower->loan_term ?? '';

        // Col 107: Rate Input Method
        $row[107] = 'EMI';

        // Col 108: Final Rate — from scheme interest
        $row[108] = $scheme?->interest ?? '';

        // Col 109: Interest Amount — blank
        $row[109] = $total_interest ?? '';
        // Col 110: Agreed Amount — blank
        $row[110] = $total_loan_amount ?? '';

        // Col 111: Adv Instalments — blank

        // Col 112: Repayment Type
        $row[112] = 'Instalments';

        // Col 113: Frequency
        $row[113] = 'Monthly';

        // Col 114: Vehicle Type
        $row[114] = ($borrower->product_id == 4) ? '' : 'Vehicle';

        // Col 115: Vehicle Categ.
        $row[115] = 'Ev Rickshaw';

        // Col 116: Vehicle Mfr.
        $row[116] = ''; // $productDetail?->manufacture_name ?? '';

        // Col 117: Veh Name
        $row[117] = ''; //$productDetail?->name ?? '';

        // Col 118: Vehicle Variant — blank

        // Col 119: Asset Cost
        $row[119] = $scheme?->ex_showroom_price ?? '';

        // Col 120: Margin Amount — blank
        // Col 121: Accounting_Category_Code — blank
        $row[121] = 'Vehicle Loan';

        // Col 122: First_Emi_Date
        $row[122] = $emiStartDate;

        // Col 123: Company
        $row[123] = ($lenderName == 'Akme  Fintrade India limited') ? 'Akme Fintrade (India) Limited' : '';

        // Cols 124–125: Sales Executive, Branch Manager — blank
        $row[124] = 'accounts';
        $row[125] = 'accounts';
        // Col 126: Nature — blank
        $row[126] = 'New';
        // Col 127: Channel — blank

        // Col 128: Instalment Type
        $row[128] = 'EMI';

        // Col 129: Engine No — blank

        // Col 130: Chassis No
        $row[130] = $kyc?->chasis_number ?? '';

        // Col 131: Purchase date — blank
        $row[131] = isset($disbursement->disbursement_date) ? date('d-m-Y', strtotime($disbursement->disbursement_date)) : '';


        // Col 132: Fuel Type
        $row[132] = 'Electric';

        // Col 133: Model Year
        $row[133] = date('Y');

        // Col 134: Order Number — blank

        // Col 135: Repayment Mode
        $row[135] = 'NACH';

        // Cols 136–140: Cibil Type, Source Group, DMA/DSA, App No, Loan No — blank
        $row[136] = 'AUTO LOAN';
        $row[137] = 'Dealer';

        // Col 141: Deduct Emi — blank
        $row[141] = 'FALSE';

        // Deductions (code + amount pairs, cols 142–151)
        $row[142] = 'Management Fee A/C'; // Deduction1
        $row[143] = $scheme?->processing_fee ?? ''; // Deduction1 Amount — blank
        $row[144] = 'Inter State Gst';    // Deduction2
        $row[145] = '';                   // Deduction2 Amount — blank
        $row[146] = 'Iot Charges';        // Deduction3
        $row[147] = $scheme?->iot ?? '';;                   // Deduction3 Amount — blank
        $row[148] = 'Pre-EMI Dues';       // Deduction4
        $row[149] = '';                   // Deduction4 Amount — blank
        // Deduction5 — blank

        // ── CoApplicant1 (cols 152–245) ───────────────────────────────────────
        if ($coKyc) {
            $row[152] = $coKyc->relation_with_borrower ?? '';
            // Cols 153–154: Location, Sub Location — blank
            $row[153] = 'Delhi Branch';
            $row[154] = 'Delhi Branch';
            // Col 155: Aadhar — blank
            $row[156] = $coKyc->pan    ?? '';
            $row[157] = $coKyc->mobile ?? '';
            // Col 158: Mobile2 — blank
            $row[159] = $coKyc->mobile ?? ''; // WhatsApp
            $row[160] = $coKyc->email  ?? '';
            // Col 161: Email2 — blank
            // Col 162: Prefix — blank
            [$cfName, $cmName, $clName] = $this->splitName($coKyc->name ?? '');
            $row[163] = $cfName;
            $row[164] = $cmName;
            $row[165] = $clName;
            // Col 166: Relation — blank (marital_status)
            // Col 167: Rel. Prefix — blank
            [$cffName, $cfmName, $cflName] = $this->splitName($coKyc->father_name ?? '');
            $row[168] = $cffName;
            $row[169] = $cfmName;
            $row[170] = $cflName;
            // Col 171: Source — blank
            $row[172] = $coKyc->dob_date
                ? date('d.m.Y', strtotime($coKyc->dob_date))
                : '';
            $row[173] = $coKyc->dob_date ? Carbon::parse($coKyc->dob_date)->age : '';
            $row[174] = $coKyc->gender ?? '';
            // Col 175: Marital Status — blank
            // Cols 176–177: Dependents, Qualification — blank
            // Cols 178–182: Business/Sal, Organization, Nature, Org.age — blank
            $row[182] = $coOcc?->total_income ?? '';       // Income Slab
            // Col 183: State — blank
            // Col 184: Segment — blank
            $row[185] = $coOcc?->current_occupation ?? ''; // Occupation
            // Cols 186–191: Status Type … Remarks — blank

            // CoApplicant1 Present Address (cols 192–209)
            $coPresAddr = $this->resolvePresAddress(
                $coOcc?->current_address   ?? '',
                $coOcc?->permanent_address ?? '',
                $coapplicantAadhar
            );
            $row[192] = 'India';
            $row[193] = 'Urban Independent';
            $row[194] = $coPresAddr['house']    ?? '';
            $row[195] = '';
            $row[196] = $coPresAddr['street']   ?? '';
            $row[197] = $coPresAddr['locality'] ?? '';
            $row[198] = $coPresAddr['landmark'] ?? '';
            $row[199] = $coPresAddr['city']     ?? '';
            $row[200] = $coPresAddr['state']    ?? '';
            $row[201] = $coPresAddr['dist']     ?? '';
            $row[202] = $coPresAddr['subdist']  ?? '';
            $row[203] = $coPresAddr['pincode']  ?? '';
            // 204–209: blank

            // CoApplicant1 Permanent Address (cols 210–227)
            $coPermAddr = $this->mapAadharToAddress($coapplicantAadhar);
            $row[210] = 'India';
            $row[211] = '';
            $row[212] = $coPermAddr['house']    ?? '';
            $row[213] = '';
            $row[214] = $coPermAddr['street']   ?? '';
            $row[215] = $coPermAddr['locality'] ?? '';
            $row[216] = $coPermAddr['landmark'] ?? '';
            $row[217] = $coPermAddr['city']     ?? '';
            $row[218] = $coPermAddr['state']    ?? '';
            $row[219] = $coPermAddr['dist']     ?? '';
            $row[220] = $coPermAddr['subdist']  ?? '';
            $row[221] = $coPermAddr['pincode']  ?? '';
            // 222–245: Office address (blank) + remainder
        }

        // ── CoApplicant2 (cols 246–339) — all blank ───────────────────────────
        // Already blank from array_fill

        // ── Guarantor1 (cols 340–433) ─────────────────────────────────────────
        if ($guarantor) {
            $row[340] = $guarantor->relation_with_borrower ?? ''; // Relation With Borrower
            $row[341] = 'Delhi Branch'; // Location
            $row[342] = 'Delhi Branch'; // Sub Location
            // Col 343: Aadhar — blank
            $row[344] = $guarantor->pan    ?? '';
            $row[345] = $guarantor->mobile ?? '';
            // Col 346: Mobile2 — blank
            $row[347] = $guarantor->mobile ?? ''; // WhatsApp
            $row[348] = $guarantor->email  ?? '';
            // Col 349: Email2 — blank
            // Col 350: Prefix — Mr./Ms. based on gender
            $row[350] = $this->genderToPrefix($guarantor->gender ?? '');
            [$gfName, $gmName, $glName] = $this->splitName($guarantor->name ?? '');
            $row[351] = $gfName;
            $row[352] = $gmName;
            $row[353] = $glName;
            // Col 354: Relation
            $row[354] = 'S/o';
            // Col 355: Rel. Prefix
            $row[355] = 'Mr.';
            [$gffName, $gfmName, $gflName] = $this->splitName($guarantor->father_name ?? '');
            $row[356] = $gffName;
            $row[357] = $gfmName;
            $row[358] = $gflName;
            // Col 359: Source — blank
            $row[360] = $guarantor->dob_date
                ? date('d.m.Y', strtotime($guarantor->dob_date))
                : '';
            $row[361] = $guarantor->dob_date ? Carbon::parse($guarantor->dob_date)->age : '';
            $row[362] = $guarantor->gender ?? '';
            // Col 363: Marital Status — blank
            // Cols 364–369: Dependents, Qualification — blank
            // Col 366: Business/Sal
            $row[366] = 'Business';
            // Cols 367–369: Organization, Nature, Org.age — blank
            $row[370] = $guarOcc?->total_income ?? '';       // Income Slab
            // Col 371: State — blank
            // Col 372: Segment — blank
            $row[373] = $guarOcc?->current_occupation ?? ''; // Occupation
            // Col 374: Status Type
            $row[374] = 'Open';
            // Cols 375–379: Allocate To … Remarks — blank

            // Guarantor1 Present Address (cols 380–397)
            $gPresAddr = $this->resolvePresAddress(
                $guarOcc?->current_address   ?? '',
                $guarOcc?->permanent_address ?? '',
                $guarantorAadhar
            );
            $row[380] = 'India';
            $row[381] = 'Urban Independent';
            $row[382] = $gPresAddr['house']    ?? '';
            $row[383] = '';
            $row[384] = $gPresAddr['street']   ?? '';
            $row[385] = $gPresAddr['locality'] ?? '';
            $row[386] = $gPresAddr['landmark'] ?? '';
            $row[387] = $gPresAddr['city']     ?? '';
            $row[388] = $gPresAddr['state']    ?? '';
            $row[389] = $gPresAddr['dist']     ?? '';
            $row[390] = $gPresAddr['subdist']  ?? ''; // Tehsil — if available
            $row[391] = $gPresAddr['pincode']  ?? '';
            // 392–397: blank

            // Guarantor1 Permanent Address (cols 398–415)
            $gPermAddr = $this->mapAadharToAddress($guarantorAadhar);
            $row[398] = 'India';
            $row[399] = '';
            $row[400] = $gPermAddr['house']    ?? '';
            $row[401] = '';
            $row[402] = $gPermAddr['street']   ?? '';
            $row[403] = $gPermAddr['locality'] ?? '';
            $row[404] = $gPermAddr['landmark'] ?? '';
            $row[405] = $gPermAddr['city']     ?? '';
            $row[406] = $gPermAddr['state']    ?? '';
            $row[407] = $gPermAddr['dist']     ?? '';
            $row[408] = $gPermAddr['subdist']  ?? '';
            $row[409] = $gPermAddr['pincode']  ?? '';
            // 410–433: Office address + remainder — blank
        }

        // ── Guarantor2 (cols 434–527) — all blank ────────────────────────────

        // ── References (cols 528–537) ─────────────────────────────────────────
        $row[528] = $ref?->reference_1 ?? '';
        $row[529] = $ref?->mobile_1    ?? '';
        $row[530] = '';                             // Email1 — blank
        $row[531] = $ref?->address_1   ?? '';
        $row[532] = $ref?->relation_1  ?? '';
        $row[533] = $ref?->reference_2 ?? '';
        $row[534] = $ref?->mobile_2    ?? '';
        $row[535] = '';                             // Email2 — blank
        $row[536] = $ref?->address_2   ?? '';
        $row[537] = $ref?->relation_2  ?? '';

        // Cols 538–655: Add-to-loan, Insurance, Instalments, Partner, Share, Reg — all blank

        return $row;
    }

    // =========================================================================
    // Address Helpers
    // =========================================================================

    /**
     * Resolve present address:
     * If current_address == permanent_address → use Aadhaar OCR
     * Else → parseAddress(current_address)
     */
    private function resolvePresAddress(
        string $currentAddress,
        string $permanentAddress,
        array  $aadharAddress
    ): array {
        if (trim($currentAddress) === trim($permanentAddress) || empty($currentAddress)) {
            return $this->mapAadharToAddress($aadharAddress);
        }

        return $this->parseAddress($currentAddress);
    }

    /**
     * Map Aadhaar OCR full_address fields → our unified address keys
     */
    private function mapAadharToAddress(array $a): array
    {
        return [
            'house'    => $a['house_number'] ?? $a['house'] ?? '',
            'street'   => $a['street']       ?? '',
            'locality' => $a['loc']          ?? $a['locality'] ?? '',
            'landmark' => $a['landmark']     ?? '',
            'city'     => $a['vtc']          ?? $a['city'] ?? '',
            'dist'     => $a['dist']         ?? $a['district'] ?? '',
            'subdist'  => $a['subdist']      ?? '',
            'state'    => $a['state']        ?? '',
            'pincode'  => $a['pc']           ?? $a['pin'] ?? '',
        ];
    }

    /**
     * Parse a plain text address string into structured fields
     */
    private function parseAddress(string $address): array
    {
        if (empty($address)) {
            return [
                'house' => '', 'street' => '', 'locality' => '',
                'landmark' => '', 'city' => '', 'dist' => '',
                'subdist' => '', 'state' => '', 'pincode' => '',
            ];
        }

        // Extract 6-digit pincode
        preg_match('/\b\d{6}\b/', $address, $pincodeMatch);
        $pincode = $pincodeMatch[0] ?? '';

        $addressWithoutPincode = preg_replace('/\b\d{6}\b/', '', $address);
        $parts  = preg_split('/[,\n]/', $addressWithoutPincode);
        $parts  = array_values(array_filter(array_map('trim', $parts)));
        $count  = count($parts);

        return [
            'house'    => $parts[0]           ?? '',
            'street'   => $parts[1]           ?? '',
            'locality' => $parts[2]           ?? '',
            'landmark' => '',
            'city'     => $parts[$count - 2]  ?? '',
            'dist'     => '',
            'subdist'  => '',
            'state'    => $parts[$count - 1]  ?? '',
            'pincode'  => $pincode,
        ];
    }

    /**
     * Convert gender string to Mr./Ms. prefix
     */
    private function genderToPrefix(string $gender): string
    {
        return (strtoupper(trim($gender)) === 'FEMALE') ? 'Ms.' : 'Mr.';
    }

    /**
     * Split a full name into [firstName, middleName, lastName]
     * Single word  → [word, '', '']
     * Two words    → [first, '', last]
     * Three+ words → [first, middle(s), last]
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $count = count($parts);

        if ($count === 0) return ['', '', ''];
        if ($count === 1) return [$parts[0], '', ''];
        if ($count === 2) return [$parts[0], '', $parts[1]];

        $first  = array_shift($parts);
        $last   = array_pop($parts);
        $middle = implode(' ', $parts);

        return [$first, $middle, $last];
    }

    // =========================================================================
    // Column headers — exactly matching the uploaded template (656 columns)
    // =========================================================================
    private function getColumnHeaders(): array
    {
        return [
            // 0–7
            'Loan Type(Mandatory)', 'Product Type(Mandatory)', 'Purpose(Mandatory)',
            'Product(Mandatory)', 'No. Of Assets(Mandatory)', 'Loan Date(Mandatory)(dd.MM.yyyy)',
            'Payment_bank/cash_Code', 'Asset Type',
            // 8–12
            'Dealer', 'Required Date(dd.MM.yyyy)', 'Borrower_Branch(Mandatory)',
            'Borrower_Location(Mandatory)', 'Borrower_Sub Location(Mandatory)',
            // 13–19
            'Borrower_Aadhar', 'Borrower_Pan Card', 'Borrower_Mobile1(Mandatory)',
            'Borrower_Mobile2', 'Borrower_Whatsapp Number', 'Borrower_Email1', 'Borrower_Email2',
            // 20–29
            'Borrower_Prefix(Mandatory)', 'Borrower_First Name(Mandatory)',
            'Borrower_Middle Name', 'Borrower_Last Name(Mandatory)',
            'Borrower_Relation(Mandatory)', 'Borrower_Rel. Prefix(Mandatory)',
            'Borrower_Rel. First Name(Mandatory)', 'Borrower_Rel. Middle Name',
            'Borrower_Rel. Last Name(Mandatory)', 'Borrower_Source',
            // 30–49
            'Borrower_DOB(dd.MM.yyyy)', 'Borrower_Age', 'Borrower_Gender(Mandatory)',
            'Borrower_Marital Status(Mandatory)', 'Borrower_Dependents',
            'Borrower_Qualification(Mandatory)', 'Borrower_Business/Sal(Mandatory)',
            'Borrower_Organization(Mandatory)', 'Borrower_Nature of organization(Mandatory)',
            'Borrower_Org.age in yrs', 'Borrower_Income Slab(Mandatory)', 'Borrower_State',
            'Borrower_Segment(Mandatory)', 'Borrower_Occupation', 'Borrower_Status Type',
            'Borrower_Allocate To', 'Borrower_Un-allocated', 'Borrower_Caste',
            'Borrower_Religion', 'Borrower_Remarks',
            // 50–67: Borrower Present Address
            'Borrower_addrPres_Country(Mandatory)', 'Borrower_addrPres_Location Type(Mandatory)',
            'Borrower_addrPres_No.(Mandatory)', 'Borrower_addrPres_Building',
            'Borrower_addrPres_Sector/Street', 'Borrower_addrPres_Colony/Area(Mandatory)',
            'Borrower_addrPres_Land Mark', 'Borrower_addrPres_City/Village(Mandatory)',
            'Borrower_addrPres_State(Mandatory)', 'Borrower_addrPres_District(Mandatory)',
            'Borrower_addrPres_Tehsil(Mandatory)', 'Borrower_addrPres_Pin Code(Mandatory)',
            'Borrower_addrPres_Residency(Mandatory)', 'Borrower_addrPres_STD Code',
            'Borrower_addrPres_Phone-1', 'Borrower_addrPres_Phone-2',
            'Borrower_addrPres_Fax', 'Borrower_addrPres_Mobile',
            // 68–85: Borrower Permanent Address
            'Borrower_addrPerm_Country(Mandatory)', 'Borrower_addrPerm_Location Type(Mandatory)',
            'Borrower_addrPerm_No.(Mandatory)', 'Borrower_addrPerm_Building',
            'Borrower_addrPerm_Sector/Street', 'Borrower_addrPerm_Colony/Area(Mandatory)',
            'Borrower_addrPerm_Land Mark', 'Borrower_addrPerm_City/Village(Mandatory)',
            'Borrower_addrPerm_State(Mandatory)', 'Borrower_addrPerm_District(Mandatory)',
            'Borrower_addrPerm_Tehsil(Mandatory)', 'Borrower_addrPerm_Pin Code(Mandatory)',
            'Borrower_addrPerm_Residency(Mandatory)', 'Borrower_addrPerm_STD Code',
            'Borrower_addrPerm_Phone-1', 'Borrower_addrPerm_Phone-2',
            'Borrower_addrPerm_Fax', 'Borrower_addrPerm_Mobile',
            // 86–103: Borrower Office Address
            'Borrower_addrOff_Country(Mandatory)', 'Borrower_addrOff_Location Type(Mandatory)',
            'Borrower_addrOff_No.(Mandatory)', 'Borrower_addrOff_Building',
            'Borrower_addrOff_Sector/Street', 'Borrower_addrOff_Colony/Area(Mandatory)',
            'Borrower_addrOff_Land Mark', 'Borrower_addrOff_City/Village(Mandatory)',
            'Borrower_addrOff_State(Mandatory)', 'Borrower_addrOff_District(Mandatory)',
            'Borrower_addrOff_Tehsil(Mandatory)', 'Borrower_addrOff_Pin Code(Mandatory)',
            'Borrower_addrOff_Residency(Mandatory)', 'Borrower_addrOff_STD Code',
            'Borrower_addrOff_Phone-1', 'Borrower_addrOff_Phone-2',
            'Borrower_addrOff_Fax', 'Borrower_addrOff_Mobile',
            // 104–151: Loan details
            'Loan amount(Mandatory)', 'Tenure(Mandatory)', 'Instalments(Mandatory)',
            'Rate Input Method', 'Final Rate', 'Interest Amount(Mandatory)',
            'Agreed Amount(Mandatory)', 'Adv Instalments', 'Repayment Type', 'Frequency',
            'Vehicle Type', 'Vehicle Categ.', 'Vehicle Mfr.', 'Veh Name', 'Vehicle Variant',
            'Asset Cost', 'Margin Amount', 'Accounting_Category_Code',
            'First_Emi_Date(Mandatory)((dd.MM.yyyy))', 'Company(Mandatory)',
            'Sales Executive(Mandatory)', 'Branch Manager(Mandatory)', 'Nature(Mandatory)',
            'Channel', 'Instalment Type(Mandatory)', 'Engine No(Mandatory)',
            'Chassis No(Mandatory)', 'Purchase date(Mandatory)', 'Fuel Type (Mandatory)',
            'Model Year(mandatory)(yyyy)', 'Order Number', 'Repayment Mode(Mandatory)',
            'Cibil Type(Mandatory)', 'Source Group(Mandatory)', 'DMA/DSA(Optional)',
            'App No(Optional)', 'Loan No(Optional)', 'Deduct Emi(Mandatory TRUE OR FALSE)',
            'Deduction1', 'Deduction1 Amount', 'Deduction2', 'Deduction2 Amount',
            'Deduction3', 'Deduction3 Amount', 'Deduction4', 'Deduction4 Amount',
            'Deduction5', 'Deduction5 Amount',
            // 152–245: CoApplicant1
            'CoApplicant1_Relation With Borrower(Mandatory)', 'CoApplicant1_Location(Mandatory)',
            'CoApplicant1_Sub Location(Mandatory)', 'CoApplicant1_Aadhar',
            'CoApplicant1_Pan Card', 'CoApplicant1_Mobile1(Mandatory)',
            'CoApplicant1_Mobile2', 'CoApplicant1_Whatsapp Number', 'CoApplicant1_Email1',
            'CoApplicant1_Email2', 'CoApplicant1_Prefix(Mandatory)',
            'CoApplicant1_First Name(Mandatory)', 'CoApplicant1_Middle Name',
            'CoApplicant1_Last Name(Mandatory)', 'CoApplicant1_Relation(Mandatory)',
            'CoApplicant1_Rel. Prefix(Mandatory)', 'CoApplicant1_Rel. First Name(Mandatory)',
            'CoApplicant1_Rel. Middle Name', 'CoApplicant1_Rel. Last Name(Mandatory)',
            'CoApplicant1_Source', 'CoApplicant1_DOB(dd.MM.yyyy)', 'CoApplicant1_Age',
            'CoApplicant1_Gender(Mandatory)', 'CoApplicant1_Marital Status(Mandatory)',
            'CoApplicant1_Dependents', 'CoApplicant1_Qualification(Mandatory)',
            'CoApplicant1_Business/Sal(Mandatory)', 'CoApplicant1_Organization(Mandatory)',
            'CoApplicant1_Nature of organization(Mandatory)', 'CoApplicant1_Org.age in yrs',
            'CoApplicant1_Income Slab(Mandatory)', 'CoApplicant1_State',
            'CoApplicant1_Segment(Mandatory)', 'CoApplicant1_Occupation',
            'CoApplicant1_Status Type', 'CoApplicant1_Allocate To',
            'CoApplicant1_Un-allocated', 'CoApplicant1_Caste', 'CoApplicant1_Religion',
            'CoApplicant1_Remarks',
            'CoApplicant1_addrPres_Country(Mandatory)', 'CoApplicant1_addrPres_Location Type(Mandatory)',
            'CoApplicant1_addrPres_No.(Mandatory)', 'CoApplicant1_addrPres_Building',
            'CoApplicant1_addrPres_Sector/Street', 'CoApplicant1_addrPres_Colony/Area(Mandatory)',
            'CoApplicant1_addrPres_Land Mark', 'CoApplicant1_addrPres_City/Village(Mandatory)',
            'CoApplicant1_addrPres_State(Mandatory)', 'CoApplicant1_addrPres_District(Mandatory)',
            'CoApplicant1_addrPres_Tehsil(Mandatory)', 'CoApplicant1_addrPres_Pin Code(Mandatory)',
            'CoApplicant1_addrPres_Residency(Mandatory)', 'CoApplicant1_addrPres_STD Code',
            'CoApplicant1_addrPres_Phone-1', 'CoApplicant1_addrPres_Phone-2',
            'CoApplicant1_addrPres_Fax', 'CoApplicant1_addrPres_Mobile',
            'CoApplicant1_addrPerm_Country(Mandatory)', 'CoApplicant1_addrPerm_Location Type(Mandatory)',
            'CoApplicant1_addrPerm_No.(Mandatory)', 'CoApplicant1_addrPerm_Building',
            'CoApplicant1_addrPerm_Sector/Street', 'CoApplicant1_addrPerm_Colony/Area(Mandatory)',
            'CoApplicant1_addrPerm_Land Mark', 'CoApplicant1_addrPerm_City/Village(Mandatory)',
            'CoApplicant1_addrPerm_State(Mandatory)', 'CoApplicant1_addrPerm_District(Mandatory)',
            'CoApplicant1_addrPerm_Tehsil(Mandatory)', 'CoApplicant1_addrPerm_Pin Code(Mandatory)',
            'CoApplicant1_addrPerm_Residency(Mandatory)', 'CoApplicant1_addrPerm_STD Code',
            'CoApplicant1_addrPerm_Phone-1', 'CoApplicant1_addrPerm_Phone-2',
            'CoApplicant1_addrPerm_Fax', 'CoApplicant1_addrPerm_Mobile',
            'CoApplicant1_addrOff_Country(Mandatory)', 'CoApplicant1_addrOff_Location Type(Mandatory)',
            'CoApplicant1_addrOff_No.(Mandatory)', 'CoApplicant1_addrOff_Building',
            'CoApplicant1_addrOff_Sector/Street', 'CoApplicant1_addrOff_Colony/Area(Mandatory)',
            'CoApplicant1_addrOff_Land Mark', 'CoApplicant1_addrOff_City/Village(Mandatory)',
            'CoApplicant1_addrOff_State(Mandatory)', 'CoApplicant1_addrOff_District(Mandatory)',
            'CoApplicant1_addrOff_Tehsil(Mandatory)', 'CoApplicant1_addrOff_Pin Code(Mandatory)',
            'CoApplicant1_addrOff_Residency(Mandatory)', 'CoApplicant1_addrOff_STD Code',
            'CoApplicant1_addrOff_Phone-1', 'CoApplicant1_addrOff_Phone-2',
            'CoApplicant1_addrOff_Fax', 'CoApplicant1_addrOff_Mobile',
            // 246–339: CoApplicant2
            'CoApplicant2_Relation With Borrower(Mandatory)', 'CoApplicant2_Location(Mandatory)',
            'CoApplicant2_Sub Location(Mandatory)', 'CoApplicant2_Aadhar',
            'CoApplicant2_Pan Card', 'CoApplicant2_Mobile1(Mandatory)',
            'CoApplicant2_Mobile2', 'CoApplicant2_Whatsapp Number', 'CoApplicant2_Email1',
            'CoApplicant2_Email2', 'CoApplicant2_Prefix(Mandatory)',
            'CoApplicant2_First Name(Mandatory)', 'CoApplicant2_Middle Name',
            'CoApplicant2_Last Name(Mandatory)', 'CoApplicant2_Relation(Mandatory)',
            'CoApplicant2_Rel. Prefix(Mandatory)', 'CoApplicant2_Rel. First Name(Mandatory)',
            'CoApplicant2_Rel. Middle Name', 'CoApplicant2_Rel. Last Name(Mandatory)',
            'CoApplicant2_Source', 'CoApplicant2_DOB(dd.MM.yyyy)', 'CoApplicant2_Age',
            'CoApplicant2_Gender(Mandatory)', 'CoApplicant2_Marital Status(Mandatory)',
            'CoApplicant2_Dependents', 'CoApplicant2_Qualification(Mandatory)',
            'CoApplicant2_Business/Sal(Mandatory)', 'CoApplicant2_Organization(Mandatory)',
            'CoApplicant2_Nature of organization(Mandatory)', 'CoApplicant2_Org.age in yrs',
            'CoApplicant2_Income Slab(Mandatory)', 'CoApplicant2_State',
            'CoApplicant2_Segment(Mandatory)', 'CoApplicant2_Occupation',
            'CoApplicant2_Status Type', 'CoApplicant2_Allocate To',
            'CoApplicant2_Un-allocated', 'CoApplicant2_Caste', 'CoApplicant2_Religion',
            'CoApplicant2_Remarks',
            'CoApplicant2_addrPres_Country(Mandatory)', 'CoApplicant2_addrPres_Location Type(Mandatory)',
            'CoApplicant2_addrPres_No.(Mandatory)', 'CoApplicant2_addrPres_Building',
            'CoApplicant2_addrPres_Sector/Street', 'CoApplicant2_addrPres_Colony/Area(Mandatory)',
            'CoApplicant2_addrPres_Land Mark', 'CoApplicant2_addrPres_City/Village(Mandatory)',
            'CoApplicant2_addrPres_State(Mandatory)', 'CoApplicant2_addrPres_District(Mandatory)',
            'CoApplicant2_addrPres_Tehsil(Mandatory)', 'CoApplicant2_addrPres_Pin Code(Mandatory)',
            'CoApplicant2_addrPres_Residency(Mandatory)', 'CoApplicant2_addrPres_STD Code',
            'CoApplicant2_addrPres_Phone-1', 'CoApplicant2_addrPres_Phone-2',
            'CoApplicant2_addrPres_Fax', 'CoApplicant2_addrPres_Mobile',
            'CoApplicant2_addrPerm_Country(Mandatory)', 'CoApplicant2_addrPerm_Location Type(Mandatory)',
            'CoApplicant2_addrPerm_No.(Mandatory)', 'CoApplicant2_addrPerm_Building',
            'CoApplicant2_addrPerm_Sector/Street', 'CoApplicant2_addrPerm_Colony/Area(Mandatory)',
            'CoApplicant2_addrPerm_Land Mark', 'CoApplicant2_addrPerm_City/Village(Mandatory)',
            'CoApplicant2_addrPerm_State(Mandatory)', 'CoApplicant2_addrPerm_District(Mandatory)',
            'CoApplicant2_addrPerm_Tehsil(Mandatory)', 'CoApplicant2_addrPerm_Pin Code(Mandatory)',
            'CoApplicant2_addrPerm_Residency(Mandatory)', 'CoApplicant2_addrPerm_STD Code',
            'CoApplicant2_addrPerm_Phone-1', 'CoApplicant2_addrPerm_Phone-2',
            'CoApplicant2_addrPerm_Fax', 'CoApplicant2_addrPerm_Mobile',
            'CoApplicant2_addrOff_Country(Mandatory)', 'CoApplicant2_addrOff_Location Type(Mandatory)',
            'CoApplicant2_addrOff_No.(Mandatory)', 'CoApplicant2_addrOff_Building',
            'CoApplicant2_addrOff_Sector/Street', 'CoApplicant2_addrOff_Colony/Area(Mandatory)',
            'CoApplicant2_addrOff_Land Mark', 'CoApplicant2_addrOff_City/Village(Mandatory)',
            'CoApplicant2_addrOff_State(Mandatory)', 'CoApplicant2_addrOff_District(Mandatory)',
            'CoApplicant2_addrOff_Tehsil(Mandatory)', 'CoApplicant2_addrOff_Pin Code(Mandatory)',
            'CoApplicant2_addrOff_Residency(Mandatory)', 'CoApplicant2_addrOff_STD Code',
            'CoApplicant2_addrOff_Phone-1', 'CoApplicant2_addrOff_Phone-2',
            'CoApplicant2_addrOff_Fax', 'CoApplicant2_addrOff_Mobile',
            // 340–433: Guarantor1
            'Guarantor1_Relation With Borrower(Mandatory)', 'Guarantor1_Location(Mandatory)',
            'Guarantor1_Sub Location(Mandatory)', 'Guarantor1_Aadhar',
            'Guarantor1_Pan Card', 'Guarantor1_Mobile1(Mandatory)',
            'Guarantor1_Mobile2', 'Guarantor1_Whatsapp Number', 'Guarantor1_Email1',
            'Guarantor1_Email2', 'Guarantor1_Prefix(Mandatory)',
            'Guarantor1_First Name(Mandatory)', 'Guarantor1_Middle Name',
            'Guarantor1_Last Name(Mandatory)', 'Guarantor1_Relation(Mandatory)',
            'Guarantor1_Rel. Prefix(Mandatory)', 'Guarantor1_Rel. First Name(Mandatory)',
            'Guarantor1_Rel. Middle Name', 'Guarantor1_Rel. Last Name(Mandatory)',
            'Guarantor1_Source', 'Guarantor1_DOB(dd.MM.yyyy)', 'Guarantor1_Age',
            'Guarantor1_Gender(Mandatory)', 'Guarantor1_Marital Status(Mandatory)',
            'Guarantor1_Dependents', 'Guarantor1_Qualification(Mandatory)',
            'Guarantor1_Business/Sal(Mandatory)', 'Guarantor1_Organization(Mandatory)',
            'Guarantor1_Nature of organization(Mandatory)', 'Guarantor1_Org.age in yrs',
            'Guarantor1_Income Slab(Mandatory)', 'Guarantor1_State',
            'Guarantor1_Segment(Mandatory)', 'Guarantor1_Occupation',
            'Guarantor1_Status Type', 'Guarantor1_Allocate To',
            'Guarantor1_Un-allocated', 'Guarantor1_Caste', 'Guarantor1_Religion',
            'Guarantor1_Remarks',
            'Guarantor1_addrPres_Country(Mandatory)', 'Guarantor1_addrPres_Location Type(Mandatory)',
            'Guarantor1_addrPres_No.(Mandatory)', 'Guarantor1_addrPres_Building',
            'Guarantor1_addrPres_Sector/Street', 'Guarantor1_addrPres_Colony/Area(Mandatory)',
            'Guarantor1_addrPres_Land Mark', 'Guarantor1_addrPres_City/Village(Mandatory)',
            'Guarantor1_addrPres_State(Mandatory)', 'Guarantor1_addrPres_District(Mandatory)',
            'Guarantor1_addrPres_Tehsil(Mandatory)', 'Guarantor1_addrPres_Pin Code(Mandatory)',
            'Guarantor1_addrPres_Residency(Mandatory)', 'Guarantor1_addrPres_STD Code',
            'Guarantor1_addrPres_Phone-1', 'Guarantor1_addrPres_Phone-2',
            'Guarantor1_addrPres_Fax', 'Guarantor1_addrPres_Mobile',
            'Guarantor1_addrPerm_Country(Mandatory)', 'Guarantor1_addrPerm_Location Type(Mandatory)',
            'Guarantor1_addrPerm_No.(Mandatory)', 'Guarantor1_addrPerm_Building',
            'Guarantor1_addrPerm_Sector/Street', 'Guarantor1_addrPerm_Colony/Area(Mandatory)',
            'Guarantor1_addrPerm_Land Mark', 'Guarantor1_addrPerm_City/Village(Mandatory)',
            'Guarantor1_addrPerm_State(Mandatory)', 'Guarantor1_addrPerm_District(Mandatory)',
            'Guarantor1_addrPerm_Tehsil(Mandatory)', 'Guarantor1_addrPerm_Pin Code(Mandatory)',
            'Guarantor1_addrPerm_Residency(Mandatory)', 'Guarantor1_addrPerm_STD Code',
            'Guarantor1_addrPerm_Phone-1', 'Guarantor1_addrPerm_Phone-2',
            'Guarantor1_addrPerm_Fax', 'Guarantor1_addrPerm_Mobile',
            'Guarantor1_addrOff_Country(Mandatory)', 'Guarantor1_addrOff_Location Type(Mandatory)',
            'Guarantor1_addrOff_No.(Mandatory)', 'Guarantor1_addrOff_Building',
            'Guarantor1_addrOff_Sector/Street', 'Guarantor1_addrOff_Colony/Area(Mandatory)',
            'Guarantor1_addrOff_Land Mark', 'Guarantor1_addrOff_City/Village(Mandatory)',
            'Guarantor1_addrOff_State(Mandatory)', 'Guarantor1_addrOff_District(Mandatory)',
            'Guarantor1_addrOff_Tehsil(Mandatory)', 'Guarantor1_addrOff_Pin Code(Mandatory)',
            'Guarantor1_addrOff_Residency(Mandatory)', 'Guarantor1_addrOff_STD Code',
            'Guarantor1_addrOff_Phone-1', 'Guarantor1_addrOff_Phone-2',
            'Guarantor1_addrOff_Fax', 'Guarantor1_addrOff_Mobile',
            // 434–527: Guarantor2
            'Guarantor2_Relation With Borrower(Mandatory)', 'Guarantor2_Location(Mandatory)',
            'Guarantor2_Sub Location(Mandatory)', 'Guarantor2_Aadhar',
            'Guarantor2_Pan Card', 'Guarantor2_Mobile1(Mandatory)',
            'Guarantor2_Mobile2', 'Guarantor2_Whatsapp Number', 'Guarantor2_Email1',
            'Guarantor2_Email2', 'Guarantor2_Prefix(Mandatory)',
            'Guarantor2_First Name(Mandatory)', 'Guarantor2_Middle Name',
            'Guarantor2_Last Name(Mandatory)', 'Guarantor2_Relation(Mandatory)',
            'Guarantor2_Rel. Prefix(Mandatory)', 'Guarantor2_Rel. First Name(Mandatory)',
            'Guarantor2_Rel. Middle Name', 'Guarantor2_Rel. Last Name(Mandatory)',
            'Guarantor2_Source', 'Guarantor2_DOB(dd.MM.yyyy)', 'Guarantor2_Age',
            'Guarantor2_Gender(Mandatory)', 'Guarantor2_Marital Status(Mandatory)',
            'Guarantor2_Dependents', 'Guarantor2_Qualification(Mandatory)',
            'Guarantor2_Business/Sal(Mandatory)', 'Guarantor2_Organization(Mandatory)',
            'Guarantor2_Nature of organization(Mandatory)', 'Guarantor2_Org.age in yrs',
            'Guarantor2_Income Slab(Mandatory)', 'Guarantor2_State',
            'Guarantor2_Segment(Mandatory)', 'Guarantor2_Occupation',
            'Guarantor2_Status Type', 'Guarantor2_Allocate To',
            'Guarantor2_Un-allocated', 'Guarantor2_Caste', 'Guarantor2_Religion',
            'Guarantor2_Remarks',
            'Guarantor2_addrPres_Country(Mandatory)', 'Guarantor2_addrPres_Location Type(Mandatory)',
            'Guarantor2_addrPres_No.(Mandatory)', 'Guarantor2_addrPres_Building',
            'Guarantor2_addrPres_Sector/Street', 'Guarantor2_addrPres_Colony/Area(Mandatory)',
            'Guarantor2_addrPres_Land Mark', 'Guarantor2_addrPres_City/Village(Mandatory)',
            'Guarantor2_addrPres_State(Mandatory)', 'Guarantor2_addrPres_District(Mandatory)',
            'Guarantor2_addrPres_Tehsil(Mandatory)', 'Guarantor2_addrPres_Pin Code(Mandatory)',
            'Guarantor2_addrPres_Residency(Mandatory)', 'Guarantor2_addrPres_STD Code',
            'Guarantor2_addrPres_Phone-1', 'Guarantor2_addrPres_Phone-2',
            'Guarantor2_addrPres_Fax', 'Guarantor2_addrPres_Mobile',
            'Guarantor2_addrPerm_Country(Mandatory)', 'Guarantor2_addrPerm_Location Type(Mandatory)',
            'Guarantor2_addrPerm_No.(Mandatory)', 'Guarantor2_addrPerm_Building',
            'Guarantor2_addrPerm_Sector/Street', 'Guarantor2_addrPerm_Colony/Area(Mandatory)',
            'Guarantor2_addrPerm_Land Mark', 'Guarantor2_addrPerm_City/Village(Mandatory)',
            'Guarantor2_addrPerm_State(Mandatory)', 'Guarantor2_addrPerm_District(Mandatory)',
            'Guarantor2_addrPerm_Tehsil(Mandatory)', 'Guarantor2_addrPerm_Pin Code(Mandatory)',
            'Guarantor2_addrPerm_Residency(Mandatory)', 'Guarantor2_addrPerm_STD Code',
            'Guarantor2_addrPerm_Phone-1', 'Guarantor2_addrPerm_Phone-2',
            'Guarantor2_addrPerm_Fax', 'Guarantor2_addrPerm_Mobile',
            'Guarantor2_addrOff_Country(Mandatory)', 'Guarantor2_addrOff_Location Type(Mandatory)',
            'Guarantor2_addrOff_No.(Mandatory)', 'Guarantor2_addrOff_Building',
            'Guarantor2_addrOff_Sector/Street', 'Guarantor2_addrOff_Colony/Area(Mandatory)',
            'Guarantor2_addrOff_Land Mark', 'Guarantor2_addrOff_City/Village(Mandatory)',
            'Guarantor2_addrOff_State(Mandatory)', 'Guarantor2_addrOff_District(Mandatory)',
            'Guarantor2_addrOff_Tehsil(Mandatory)', 'Guarantor2_addrOff_Pin Code(Mandatory)',
            'Guarantor2_addrOff_Residency(Mandatory)', 'Guarantor2_addrOff_STD Code',
            'Guarantor2_addrOff_Phone-1', 'Guarantor2_addrOff_Phone-2',
            'Guarantor2_addrOff_Fax', 'Guarantor2_addrOff_Mobile',
            // 528–537: References
            'Reference1_Name', 'Reference1_Mobile', 'Reference1_Email',
            'Reference1_Address', 'Reference1_Relation',
            'Reference2_Name', 'Reference2_Mobile', 'Reference2_Email',
            'Reference2_Address', 'Reference2_Relation',
            // 538–547: Add to loan
            'Add_to_loan_Code1', 'Add_to_loan_Amount1', 'Add_to_loan_Code2', 'Add_to_loan_Amount2',
            'Add_to_loan_Code3', 'Add_to_loan_Amount3', 'Add_to_loan_Code4', 'Add_to_loan_Amount4',
            'Add_to_loan_Code5', 'Add_to_loan_Amount5',
            // 548–562: Insurance
            'InsuAmt11', 'InsuAmt12', 'InsuAmt1Amount',
            'InsuAmt21', 'InsuAmt22', 'InsuAmt2Amount',
            'InsuAmt31', 'InsuAmt32', 'InsuAmt3Amount',
            'InsuAmt41', 'InsuAmt42', 'InsuAmt4Amount',
            'InsuAmt51', 'InsuAmt52', 'InsuAmt5Amount',
            // 563–582: Instalments
            'Inst1', 'InstAmt1', 'Inst2', 'InstAmt2', 'Inst3', 'InstAmt3',
            'Inst4', 'InstAmt4', 'Inst5', 'InstAmt5', 'Inst6', 'InstAmt6',
            'Inst7', 'InstAmt7', 'Inst8', 'InstAmt8', 'Inst9', 'InstAmt9',
            'Inst10', 'InstAmt10',
            // 583–616: Partner Loan
            'Partner LAV', 'Partner Loan Date', 'Channel Partner', 'Partner First Emi',
            'Partner Next Emi', 'Partner Loan Amount', 'Partner Rate Input Method',
            'Partner Rate (Instalment)', 'Partner Tenure', 'Partner Interest Amount',
            'Partner Irr', 'Partner Agreed Amount', 'Partner Instalments',
            'Partner AdvInstalments',
            'PartnerInst1', 'PartnerInstAmt1', 'PartnerInst2', 'PartnerInstAmt2',
            'PartnerInst3', 'PartnerInstAmt3', 'PartnerInst4', 'PartnerInstAmt4',
            'PartnerInst5', 'PartnerInstAmt5', 'PartnerInst6', 'PartnerInstAmt6',
            'PartnerInst7', 'PartnerInstAmt7', 'PartnerInst8', 'PartnerInstAmt8',
            'PartnerInst9', 'PartnerInstAmt9', 'PartnerInst10', 'PartnerInstAmt10',
            // 617–648: Share Loan
            'Share Loan Date', 'Share First Emi', 'Share Next Emi', 'Share Loan Amount',
            'Share Rate Input Method', 'Share Rate (Instalment)', 'Share Tenure',
            'Share Interest Amount', 'Share Irr', 'Share Agreed Amount',
            'Share Instalments', 'Share AdvInstalments',
            'ShareInst1', 'ShareInstAmt1', 'ShareInst2', 'ShareInstAmt2',
            'ShareInst3', 'ShareInstAmt3', 'ShareInst4', 'ShareInstAmt4',
            'ShareInst5', 'ShareInstAmt5', 'ShareInst6', 'ShareInstAmt6',
            'ShareInst7', 'ShareInstAmt7', 'ShareInst8', 'ShareInstAmt8',
            'ShareInst9', 'ShareInstAmt9', 'ShareInst10', 'ShareInstAmt10',
            // 649–655: Misc
            'Regn_No_Pattern', 'Regn_No', 'Margin_Amount2',
            'Upfront_Account_Codes', '', '', '',
        ];
    }
}