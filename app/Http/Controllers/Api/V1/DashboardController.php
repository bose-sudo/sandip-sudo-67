<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelperController;
use App\Models\BatteryTypes;
use App\Models\BorrowerAccountDetails;
use App\Models\BorrowerAddress;
use App\Models\BorrowerIotDetails;
use App\Models\BorrowerKyc;
use App\Models\BorrowerLoanProducts;
use App\Models\BorrowerLoanScheme;
use App\Models\BorrowerOccupation;
use App\Models\BorrowerReferenceDetails;
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
use App\Models\LoanServiceProvider;
use App\Models\LoanApplicationSummary;
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
use ZipArchive;

class DashboardController extends Controller
{
    private $helper_controller;
    public function __construct(HelperController $helper_controller) {
        $this->helper_controller = $helper_controller;
    }

    public function getDashboardDetails(Request $request) {
        try {
            // Default to current year and month if not provided
            $year = $request->input('year');
            $month = $request->input('month', date('F'));
        
            $year = !empty($year) ? $year : date('Y');
            // Validate input
            $request->validate([
                'month' => 'nullable|date_format:F',
                'year' => 'nullable|integer',
            ]);
    
            // Convert month name to numeric representation
            $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
    
            // Get all loan details for the specified month and year
            $loanDetailsQuery = LoanBasicDetails::whereYear('created_at', $year)
                                            ->whereMonth('created_at', $monthNumber);
            
            $logged_in_user = auth()->user()->id;
            $lsp_details = LenderServiceProvider::where('lsp_auth_id', $logged_in_user)->first();

            if ($lsp_details) {
                $lsp_executives = LspExecutiveDetails::where('lsp_id', $lsp_details->id)->pluck('id');

                if ($lsp_executives->isNotEmpty()) {  // Use isNotEmpty() instead of count()
                    $loanDetailsQuery->whereIn('executive_id', $lsp_executives);
                }
            }
            
            $dealer_auth_details = Dealer::where('dealer_auth_id', $logged_in_user)->first();
            if($dealer_auth_details){
                $loanDetailsQuery->where('dealer_id', $dealer_auth_details->dealer_unq);
            }

            $loanDetails = $loanDetailsQuery->get();
    
            // Initialize counters for steps
            $step1Count = 0;
            $step2Count = 0;
            $step3Count = 0;
            $step4Count = 0;
            $totalApplicationCount = 0;
            $totalDisbursedCount = 0;
    
            // Count steps based on the last JSON value
            foreach ($loanDetails as $loanDetail) {
                if ($loanDetail->loan_application_steps) {
                    $steps = json_decode($loanDetail->loan_application_steps, true);
                    if (is_array($steps) && !empty($steps)) {
                        $lastStep = end($steps);
                        if (isset($lastStep['step'])) {
                            $stepValue = (int)$lastStep['step'];
                            if ($stepValue >= 1 && $stepValue < 8) {
                                $step1Count++;
                            } elseif ($stepValue >= 8 && $stepValue < 26) {
                                $step2Count++;
                            } elseif ($stepValue >= 26 && $stepValue < 27) {
                                $step3Count++;
                            } elseif ($stepValue >= 27) {
                                $step4Count++;
                            }
                            $totalApplicationCount++;
                        }
                    }
                }
                if($loanDetail->status == 'DISBURSED') {
                    $totalDisbursedCount++;
                }
            }
    
            // Get total loan amount
            $totalLoanAmountQuery = LoanBasicDetails::whereYear('created_at', $year)
                                         ->whereMonth('created_at', $monthNumber);
            if ($lsp_details) {
                if ($lsp_executives->isNotEmpty()) {  // Use isNotEmpty() instead of count()
                    $totalLoanAmountQuery->whereIn('executive_id', $lsp_executives);
                }
            }   

            if($dealer_auth_details){
                $totalLoanAmountQuery->where('dealer_id', $dealer_auth_details->dealer_unq);
            }     

            $totalLoanAmount = $totalLoanAmountQuery->sum('loan_amount');
    
            // Get daily data for the month
            $dailyDataQuery = LoanBasicDetails::select(DB::raw('EXTRACT(DAY FROM created_at) as day'),
                                            DB::raw('COUNT(*) as total'),
                                            DB::raw('SUM(loan_amount) as loan_amount'));
            if ($lsp_details) {
                if ($lsp_executives->isNotEmpty()) {  // Use isNotEmpty() instead of count()
                    $dailyDataQuery->whereIn('executive_id', $lsp_executives);
                }
            }

            if($dealer_auth_details){
                $dailyDataQuery->where('dealer_id', $dealer_auth_details->dealer_unq);
            }

            $dailyData = $dailyDataQuery->whereYear('created_at', $year)
                                       ->whereMonth('created_at', $monthNumber)
                                       ->groupBy(DB::raw('EXTRACT(DAY FROM created_at)'))
                                       ->orderBy(DB::raw('EXTRACT(DAY FROM created_at)'))
                                       ->get()->toArray();
    
            // Format daily data to ensure days with no data default to 0
            $formattedDailyData = $this->formatDailyData($dailyData, $monthNumber, $year);
    
            // Count steps daywise
            $dailyStepCounts = [
                'step1' => array_fill(0, count($formattedDailyData['total']), 0),
                'step2' => array_fill(0, count($formattedDailyData['total']), 0),
                'step3' => array_fill(0, count($formattedDailyData['total']), 0),
                'step4' => array_fill(0, count($formattedDailyData['total']), 0),
                'disbursed' => array_fill(0, count($formattedDailyData['total']), 0),
            ];
    
            foreach ($loanDetails as $loanDetail) {
                $day = Carbon::parse($loanDetail->created_at)->day - 1;
                if ($loanDetail->loan_application_steps) {
                    $steps = json_decode($loanDetail->loan_application_steps, true);
                    if (is_array($steps) && !empty($steps)) {
                        $lastStep = end($steps);
                        if (isset($lastStep['step'])) {
                            $stepValue = (int)$lastStep['step'];
                            if ($stepValue >= 1 && $stepValue < 8) {
                                $dailyStepCounts['step1'][$day]++;
                            } elseif ($stepValue >= 8 && $stepValue < 26) {
                                $dailyStepCounts['step2'][$day]++;
                            } elseif ($stepValue >= 26 && $stepValue < 27) {
                                $dailyStepCounts['step3'][$day]++;
                            } elseif ($stepValue >= 27) {
                                $dailyStepCounts['step4'][$day]++;
                            }
                        }
                    }
                }
                if($loanDetail->status == 'DISBURSED') {
                    $dailyStepCounts['disbursed'][$day]++;
                }
            }
    
            return response()->json([
                'total_application' => $totalApplicationCount,
                'total_loan_amount' => $totalLoanAmount,
                'step1_count' => $step1Count,
                'step2_count' => $step2Count,
                'step3_count' => $step3Count,
                'step4_count' => $step4Count,
                'total_disbursed_application' => $totalDisbursedCount,
                'daywise_totalApplication' => $formattedDailyData['total'],
                'daywise_step1Application' => $dailyStepCounts['step1'],
                'daywise_step2Application' => $dailyStepCounts['step2'],
                'daywise_step3Application' => $dailyStepCounts['step3'],
                'daywise_step4Application' => $dailyStepCounts['step4'],
                'daywise_loanAmount' => $formattedDailyData['loan_amount'],
                'daywise_disbursed_application' => $dailyStepCounts['disbursed'],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching the dashboard details.',
                'message' => $e->getMessage()
            ], 400);
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

    private function formatDailyData(array $dailyData, int $month, int $year) {
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

    public function getYearlyCount(Request $request) {
        try {
            // Retrieve year input, default to null if not provided
            $year = $request->input('year');
    
            // Validate input
            $request->validate([
                'year' => 'nullable|integer',
            ]);
    
            // Get all loan details for the specified year or all years if no year is provided
            $loanDetailsQuery = LoanBasicDetails::query();

            $logged_in_user = auth()->user()->id;
            $lsp_details = LenderServiceProvider::where('lsp_auth_id', $logged_in_user)->first();

            if ($lsp_details) {
                $lsp_executives = LspExecutiveDetails::where('lsp_id', $lsp_details->id)->pluck('id');

                if ($lsp_executives->isNotEmpty()) {  // Use isNotEmpty() instead of count()
                    $loanDetailsQuery->whereIn('executive_id', $lsp_executives);
                } else {
                    return response()->json([
                        'total_application' => 0,
                        'total_loan_amount' => 0,
                        'step1_count' => 0,
                        'step2_count' => 0,
                        'step3_count' => 0,
                        'step4_count' => 0,
                        'loan_pipeline' => 0,
                        'rejected_application' => 0,
                        'approved_application' => 0,
                        'dusbursed_application' => 0,
                    ], 200);
                }
            }

            $dealer_auth_details = Dealer::where('dealer_auth_id', $logged_in_user)->first();
            if($dealer_auth_details){
                $loanDetailsQuery->where('dealer_id', $dealer_auth_details->dealer_unq);
            }

            if ($year) {
                $loanDetailsQuery->whereYear('created_at', $year);
            }
            $loanDetails = $loanDetailsQuery->get();
    
            // Initialize counters for steps and total applications
            $step1Count = 0;
            $step2Count = 0;
            $step3Count = 0;
            $step4Count = 0;
            $totalApplicationCount = 0;
            $totalDisbursedCount = 0;
    
            // Count steps based on the last JSON value
            foreach ($loanDetails as $loanDetail) {
                if ($loanDetail->loan_application_steps) {
                    $steps = json_decode($loanDetail->loan_application_steps, true);
                    if (is_array($steps) && !empty($steps)) {
                        $lastStep = end($steps);
                        if (isset($lastStep['step'])) {
                            $stepValue = (int)$lastStep['step'];
                            if ($stepValue >= 1 && $stepValue < 8) {
                                $step1Count++;
                            } elseif ($stepValue >= 8 && $stepValue < 26) {
                                $step2Count++;
                            } elseif ($stepValue >= 26 && $stepValue < 27) {
                                $step3Count++;
                            } elseif ($stepValue >= 27) {
                                $step4Count++;
                            }
                            $totalApplicationCount++;
                        }
                    }
                }

                if($loanDetail->status == 'DISBURSED') {
                    $totalDisbursedCount++;
                }
            }
    
            // Get total loan amount for the specified year or all years
            $totalLoanAmountQuery = LoanBasicDetails::query();
            if ($year) {
                $totalLoanAmountQuery->whereYear('created_at', $year);
            }
            if ($lsp_details) {
                $lsp_executives = LspExecutiveDetails::where('lsp_id', $lsp_details->id)->pluck('id');

                if ($lsp_executives->isNotEmpty()) {  // Use isNotEmpty() instead of count()
                    $loanDetailsQuery->whereIn('executive_id', $lsp_executives);
                }
            }

            if($dealer_auth_details){
                $totalLoanAmountQuery->where('dealer_id', $dealer_auth_details->dealer_unq);
            }
            $totalLoanAmount = $totalLoanAmountQuery->sum('loan_amount');
            $totalRejectedAppplications = $totalLoanAmountQuery->where('status', 'REJECTED')->count();
            $total_in_loan_pipeline = $totalApplicationCount - $totalRejectedAppplications;

            // $totalApprovedAppplications = LoanBasicDetails::query();
            // if ($year) {
            //     $totalApprovedAppplications->whereYear('created_at', $year);
            // }
            // $totalApprovedAppplications =  $totalApprovedAppplications->where('status', 'DISBURSED')->count();
    
            return response()->json([
                'total_application' => $totalApplicationCount,
                'total_loan_amount' => $totalLoanAmount,
                'step1_count' => $step1Count,
                'step2_count' => $step2Count,
                'step3_count' => $step3Count,
                'step4_count' => $step4Count,
                'loan_pipeline' => $total_in_loan_pipeline,
                'rejected_application' => $totalRejectedAppplications,
                'approved_application' => $totalDisbursedCount,
                'disbursed_application' => $totalDisbursedCount,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching the yearly count.',
                'message' => $e->getMessage()
            ], 400);
        }
    }
    
    public function get_dealers_list(Request $request) {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user) {
                $logged_in_user =  response()->json($user);
                $logged_in_user_id = auth()->id();

                $lender_details = Lenders::where('lender_auth_id', $logged_in_user_id)->first();
                if(!$lender_details) {
                    $lender_director_details = LenderDirectors::where('auth_id', $logged_in_user_id)->first();
                    if($lender_director_details) {
                        $lender_id = $lender_director_details->lender_id;
                    }
                } else {
                    $lender_id = $lender_details->id;
                }

                if(isset($lender_id)) {
                    $lender_dealers = LenderDealerGroup::where('lender_id', $lender_id)->pluck('dealer_id');
                }
            }

            // Define default values
            $page = $request->get('page', 1);
            $numberOfRecords = $request->get('number_of_records', 10);
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
    
            // Validation (optional, can be expanded)
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'start_date' => 'date|nullable',
                'end_date' => 'date|nullable|after_or_equal:start_date',
                'is_active' => 'boolean|nullable',
                'is_deleted' => 'boolean|nullable',
                'dealer_id' => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable',
                'dealer_name' => 'string|regex:/^[a-zA-Z0-9\- ]+$/|nullable',
                'dealer_type' => 'nullable|string|in:B2B,B2C',
                'mobile' => [
                    'string',
                    'nullable',
                    'regex:/^[6-9]\d{9}$/', // Indian mobile number validation (10 digits starting with 6-9)
                ],
                'email' => [
                    'string',
                    'nullable',
                    'email:rfc,dns', // Standard email validation
                ],
                'pan' => [
                    'string',
                    'nullable',
                    'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/' // PAN validation (5 letters, 4 digits, 1 letter)
                ],
                'aadhar' => [
                    'string',
                    'nullable',
                    'regex:/^\d{12}$/' // Aadhar validation (12 digits)
                ],
            ]);
    
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            // Build the query
            // $query = Dealer::query();
            $query = Dealer::with([
                'accountAggregator:id,entity_id,entity_type,mobile,pan_no,fip_ids,tracking_id,aa_status'
            ]);

            if(isset($lender_dealers)) {
                $query->whereIn('id', $lender_dealers);                
            }
    
            // Apply date filters if provided
            if ($request->filled('start_date')) {
                $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
                $query->where('created_at', '>=', $startDate);
            }
            if ($request->filled('end_date')) {
                $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
                $query->where('created_at', '<=', $endDate);
            }
    
            // Apply additional filters
            if ($request->filled('is_active')) {
                $query->where('is_active', $request->input('is_active'));
            }
    
            if ($request->filled('is_deleted')) {
                $query->where('is_deleted', $request->input('is_deleted'));
            } else {
                $query->where('is_deleted', false); // Default to false if not provided
            }
    
            if ($request->filled('mobile')) {
                $query->where('mobile', 'like', '%' . $request->input('mobile') . '%');
            }
    
            if ($request->filled('email')) {
                $query->where('email', 'like', '%' . $request->input('email') . '%');
                $query->whereRaw('LOWER(email) LIKE ?', ['%' . strtolower($request->email) . '%']);
            }
    
            if ($request->filled('pan')) {
                $query->where('pan', 'like', '%' . $request->input('pan') . '%');
            }
    
            if ($request->filled('aadhar')) {
                $query->where('aadhar', 'like', '%' . $request->input('aadhar') . '%');
            }
    
            // Apply year and month filters
            if ($request->filled('year')) {
                $query->whereYear('created_at', $request->input('year'));
            }
    
            if ($request->filled('month')) {
                $monthNumber = Carbon::parse($request->input('month'), 'Asia/Kolkata')->month;
                $query->whereMonth('created_at', $monthNumber);
            }

            if($request->filled('dealer_id')) {
                $query->whereRaw('LOWER(dealer_unq) LIKE ?', ['%' . strtolower($request->dealer_id) . '%']);
            }
            if($request->filled('dealer_name')) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($request->dealer_name) . '%']);
            }
            if($request->filled('dealer_type')) {
                $query->whereRaw('LOWER(dealer_type) LIKE ?', ['%' . strtolower($request->dealer_type) . '%']);
            }
    
            // Apply pagination
            $dealers = $query->with(['beneficiaryDetails' => function ($query) {
                                    $query->where('user_type', 'DEALER');
                             }])
                            ->orderBy('created_at', 'desc')
                            ->paginate($numberOfRecords, ['*'], 'page', $page);
            $dealers->getCollection()->transform(function ($dealer) {
                $beneficiaryDetails = $dealer->beneficiaryDetails;

                // Combine dealer attributes with beneficiary details
                $data = array_merge(
                    $dealer->toArray(),
                    [
                        'beneficiary_name' => $beneficiaryDetails ? $beneficiaryDetails->beneficiary_name : '',
                        'account_number' => $beneficiaryDetails ? $beneficiaryDetails->account_number : '',
                        'ifsc_code' => $beneficiaryDetails ? $beneficiaryDetails->ifsc_code : '',
                        'micr_code' => $beneficiaryDetails ? $beneficiaryDetails->micr_code : '',
                    ]
                );

                // Remove the beneficiaryDetails object
                unset($data['beneficiary_details']);
                return $data;
            });
    
            return response()->json($dealers, 200);
    
        } catch (Exception $e) {
            // Handle errors
            return response()->json([
                'error' => 'An error occurred while fetching dealer records.',
                'message' => $e->getMessage()
            ], 400);
        }
    }
    
    public function get_product_list(Request $request) {
        try {
            // Define default values
            $page = $request->get('page', 1);
            $numberOfRecords = $request->get('number_of_records', 10);
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $maxLoanAmount = $request->get('max_loan_amount');
            $minLoanTenure = $request->get('min_loan_tenure');
            $maxLoanTenure = $request->get('max_loan_tenure');
            $productType = $request->get('product_type');
            
            $dealerId = $request->get('dealer_id');
            $modelUnq = $request->get('model_unq');
            $name = $request->get('name');
            $batteryType = $request->get('battery_type');
            $exShowroomPrice = $request->get('ex_showroom_price');
            $loanToValueRatio = $request->get('loan_to_value_ratio');
            $manufactureName = $request->get('manufacture_name');
            $interest = $request->get('interest');
    
            // Validation
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'start_date' => 'date|nullable',
                'end_date' => 'date|nullable|after_or_equal:start_date',
                'max_loan_amount' => 'numeric|nullable',
                'min_loan_tenure' => 'integer|nullable',
                'max_loan_tenure' => 'integer|nullable',
                'product_type' => 'string|nullable',
                'dealer_id' => 'string|nullable',
                'model_unq' => 'string|nullable',
                'name' => 'string|nullable',
                'battery_type' => 'string|nullable',
                'ex_showroom_price' => 'numeric|nullable',
                'loan_to_value_ratio' => 'numeric|nullable',
                'manufacture_name' => 'string|nullable',
                'interest' => 'numeric|nullable',
            ]);
    
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 400);
            }
    
            // Build the query
            $query = DB::table('product_models')
                ->join('dealers', 'product_models.dealer_id', '=', 'dealers.id')
                ->where('dealers.is_deleted', false)
                ->select(
                    'product_models.id',
                    'dealers.name as dealer_name',
                    'dealers.dealer_unq',
                    'product_models.model_unq',
                    'product_models.name',
                    'product_models.battery_type',
                    'product_models.ex_showroom_price',
                    'product_models.loan_to_value_ratio',
                    'product_models.manufacture_name',
                    'product_models.min_loan_amount',
                    'product_models.max_loan_amount',
                    'product_models.min_loan_tenure',
                    'product_models.max_loan_tenure',
                    'product_models.product_type',
                    'product_models.created_at',
                    'product_models.interest'
                );
    
            // Apply filters if provided
            if ($startDate) {
                $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
                $query->where('product_models.created_at', '>=', $startDate);
            }
            if ($endDate) {
                $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
                $query->where('product_models.created_at', '<=', $endDate);
            }
            if ($maxLoanAmount) {
                $query->where('product_models.max_loan_amount', $maxLoanAmount);
            }
            if ($minLoanTenure) {
                $query->where('product_models.min_loan_tenure', $minLoanTenure);
            }
            if ($maxLoanTenure) {
                $query->where('product_models.max_loan_tenure', $maxLoanTenure);
            }
            if ($productType) {
                $query->where('product_models.product_type', $productType);
            }
    
            // Apply additional filters
            // if ($dealerId) {
            //     $query->where('product_models.dealer_id', $dealerId);
            // }
            
            if ($dealerId) {
                $query->where('dealers.dealer_unq', $dealerId);
            }
            if ($modelUnq) {
                $query->where('product_models.model_unq', $modelUnq);
            }
            if ($name) {
                $query->where('product_models.name', 'like', "%{$name}%");
            }
            if ($batteryType) {
                $query->where('product_models.battery_type', $batteryType);
            }
            if ($exShowroomPrice) {
                $query->where('product_models.ex_showroom_price', $exShowroomPrice);
            }
            if ($loanToValueRatio) {
                $query->where('product_models.loan_to_value_ratio', $loanToValueRatio);
            }
            if ($manufactureName) {
                $query->where('product_models.manufacture_name', 'like', "%{$manufactureName}%");
            }
            if ($interest) {
                $query->where('product_models.interest', $interest);
            }
    
            // Paginate the results
            $products = $query->orderBy('product_models.created_at', 'desc')
                              ->paginate($numberOfRecords, ['*'], 'page', $page);
    
            return $this->helper_controller->send_response('success', "Product list fetched", 200, $products);
            
        } catch (Exception $e) {
            // Handle errors
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    
    public function get_loan_application_list(Request $request) {
        date_default_timezone_set('Asia/Kolkata');
        try {
            // Define default values
            $page               = $request->get('page', 1);
            $numberOfRecords    = $request->get('number_of_records', 10);
            $startDate          = $request->get('start_date');
            $endDate            = $request->get('end_date');
            $borrowerId         = $request->get('borrowerId');
            $loanAmount         = $request->get('loanAmount');
            $product            = $request->get('product');

            // Validation (optional, can be expanded)
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'start_date' => 'date|nullable',
                'end_date' => 'date|nullable|after_or_equal:start_date',
                'borrower_id' => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable', // Alphanumeric and hyphens
                'loan_amount' => 'numeric|nullable',
                'dealer_id' => 'nullable|integer',
                'lsp_id' => 'nullable|integer',
                'product' => 'string|regex:/^[a-zA-Z0-9\s\-\(\)]+$/|nullable',
                'status' => 'nullable|string|in:NEW,APPROVED,DISBURSED,REJECTED,HOLD,CLOSED,CANCELLED',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 400);
            }

            $query = LoanBasicDetails::query()->select(
                'borrower_id', 'loan_application_steps', 'preferred_language', 'dealer_id', 'model', 'loan_amount', 'loan_term', 'status', 'created_at'
            );

            $logged_in_user = auth()->user()->id;
            $lsp_details = LenderServiceProvider::where('lsp_auth_id', $logged_in_user)->first();

            if ($lsp_details) {
                $lsp_executives = LspExecutiveDetails::where('lsp_id', $lsp_details->id)->pluck('id');

                if ($lsp_executives->isNotEmpty()) {  // Use isNotEmpty() instead of count()
                    $query->whereIn('executive_id', $lsp_executives);
                }
            }

            $dealer_auth_details = Dealer::where('dealer_auth_id', $logged_in_user)->first();
            if($dealer_auth_details){
                $query->where('dealer_id', $dealer_auth_details->dealer_unq);
            }

            // Apply date filters if provided
            if ($startDate && !empty($startDate)) {
                $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
                $query->where('created_at', '>=', $startDate);
            }
        
            // Filter by end date (end of the day in Asia/Kolkata timezone)
            if ($endDate && !empty($endDate)) {
                $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
                $query->where('created_at', '<=', $endDate);
            }

            if ($request->filled('dealer_id') && !empty($request->input('dealer_id'))) {
                $dealerDetails = Dealer::find($request->input('dealer_id'));
                if($dealerDetails) {
                    // $query->where('loan_basic_details.dealer_id', $dealerDetails->dealer_unq);
                    $query->where('dealer_id', $dealerDetails->dealer_unq);
                }
            }

            if ($request->filled('lsp_id') && !empty($request->input('lsp_id'))) {
                $lsp_executives_ids = LspExecutiveDetails::where('lsp_id', $request->input('lsp_id'))->pluck('id');

                if ($lsp_executives_ids->isNotEmpty()) {  // Use isNotEmpty() instead of count()
                    $query->whereIn('executive_id', $lsp_executives_ids);
                }
            }

            if ($borrowerId && !empty($borrowerId)) {
                $query->where('borrower_id', 'LIKE', '%' . $borrowerId . '%');
            }
            if ($loanAmount && !empty($loanAmount)) {
                $query->where('loan_amount', $loanAmount);
            }

            if (isset($request->status) && !empty($request->status)) {
                $query->where('status', $request->status);
            }

            if ($product && !empty($product)) {
                // $query->where('model', 'like', "%{$product}%");
                $query->whereRaw('LOWER(model) LIKE ?', ['%' . strtolower($product) . '%']);
            }

            $year = $request->input('year', date('Y'));
            $month = $request->input('month', date('F'));
            
            if(isset($request->year) && !empty($request->year)) {
                $year = $request->input('year', date('Y'));;
                $query->whereYear('created_at', $year);
            }
            
            if(isset($request->month) && !empty($request->month)) {
                $year = $request->input('year', date('Y'));
                $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
                $query->whereMonth('created_at', $monthNumber);
            }

            // Apply pagination
            $applications = $query->orderBy('created_at', 'desc')->paginate($numberOfRecords, ['*'], 'page', $page);

            // Transform the loan_application_steps field
            $applications->getCollection()->transform(function ($application) {
                $loanApplicationSteps = json_decode($application->loan_application_steps, true);
                $lastStep = end($loanApplicationSteps);

                $dealer_details = Dealer::where('dealer_unq', $application->dealer_id)->first();

                if(isset($dealer_details->id)) {

                    $product_details = ProductModel::where('dealer_id', $dealer_details->id)->where('name', $application->model)->first();
                    $interest_rate = isset($product_details) ? $product_details->interest : 17.5; 
                } else {
                    $interest_rate = 17.5;
                }

                return [
                    'borrower_id' => $application->borrower_id,
                    'loan_application_steps' => [
                        'details' => $lastStep['details'] ?? null,
                        'status' => $lastStep['status'] ?? null,
                    ],
                    'preferred_language' => $application->preferred_language,
                    'loan_id' => '',
                    'dealer_id' => $application->dealer_id,
                    'model' => $application->model,
                    'loan_amount' => $this->calculateNetLoanAmount($application->loan_amount, $application->loan_term),
                    'emi_amount' => number_format($this->helper_controller->calculateFlatInterestEMI($application->loan_amount, $interest_rate, $application->loan_term), 2, '.', ''),
                    'tenure' => $application->loan_term,
                    'paid_amount' => 0,
                    'amount_due' => 0,
                    'status' => $application->status,
                    'created_at' => isset($application->created_at) ? date('d-m-Y H:i:s', strtotime($application->created_at)) : '',
                ];
            });


            return response()->json($applications, 200);

        } catch (Exception $e) {
            // Handle errors
            return response()->json([
                'error' => 'An error occurred while fetching loan records.',
                'message' => $e->getMessage()
            ], 422);
        }
    }
 
    
    // public function get_borrower_list(Request $request) {
    //     date_default_timezone_set('Asia/Kolkata');
    //     try {
    //         $startDate = $request->get('start_date');
    //         $endDate = $request->get('end_date');
    //         $numberOfRecords = $request->input('number_of_records', 10); // Default to 10 records per page
    //         $page = $request->input('page', 1); // Default to page 1
    //         $stepFilter = $request->input('step'); // Step filter, might be null
    //         $show_rejection_reason = true;

    //         // Validation (optional, can be expanded)
    //         $validator = Validator::make($request->all(), [
    //             'page' => 'integer|min:1',
    //             'number_of_records' => 'integer|min:1',
    //             'start_date' => 'date|nullable',
    //             'end_date' => 'date|nullable|after_or_equal:start_date',
    //             'step' => 'integer|min:1|nullable',
    //             'borrower_name' => 'string|regex:/^[a-zA-Z\s]+$/|nullable', // Only characters and spaces
    //             'borrower_id' => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable', // Alphanumeric and hyphens
    //             'mobile_no' => 'digits:10|nullable',
    //             'pan_no' => 'string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|nullable',
    //             'aadhar_no' => 'string|min:4|max:12|nullable',
    //             'dob' => 'date|nullable',
    //             'dealer_id' => 'nullable|integer',
    //             'lsp_id' => 'nullable|integer',
    //             'product_type_id' => 'nullable|integer',
    //             'category' => 'nullable|string|in:B2B,B2C',
    //             'application_date' => 'date|nullable',
    //             'status' => 'nullable|string|in:NEW,APPROVED,DISBURSED,REJECTED,HOLD,CLOSED',
    //         ]);
    
    //         // Check if validation fails
    //         if ($validator->fails()) {
    //             return $this->helper_controller->send_response('failed', $validator->errors()->first(), 400);
    //         }
    
    //         $query = LoanBasicDetails::query()
    //             ->join('users', 'loan_basic_details.user_id', '=', 'users.id')
    //             ->leftJoin('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
    //             ->leftJoin('dealers', 'loan_basic_details.dealer_id', '=', 'dealers.dealer_unq')
    //             ->select(
    //                 'users.name as user_name',
    //                 'users.mobile as user_mobile',
    //                 'borrower_kycs.name as borrower_name',
    //                 'loan_basic_details.borrower_id as borrower_unq',
    //                 'loan_basic_details.status',
    //                 'loan_basic_details.user_id as user_primary_key',
    //                 'borrower_kycs.mobile as mobile_no',
    //                 'borrower_kycs.dob_date as date_of_birth',
    //                 'borrower_kycs.marital_status',
    //                 'borrower_kycs.husband_name as spouses_name',
    //                 'borrower_kycs.father_name',
    //                 'borrower_kycs.gender',
    //                 'borrower_kycs.email as email_id',
    //                 'borrower_kycs.pan as pan_no',
    //                 'borrower_kycs.aadhar as aadhar_no',
    //                 'borrower_kycs.driving_license_no',
    //                 'borrower_kycs.alternate_number1',
    //                 'loan_basic_details.id as borrower_primary',
    //                 'loan_basic_details.loan_amount',
    //                 'loan_basic_details.dealer_id',
    //                 'loan_basic_details.model',
    //                 'loan_basic_details.loan_id',
    //                 'loan_basic_details.loan_term',
    //                 'loan_basic_details.product_id',
    //                 'loan_basic_details.down_payment',
    //                 'loan_basic_details.loan_application_steps',
    //                 'loan_basic_details.created_at as created_on',
    //                 'loan_basic_details.admin_remarks',
    //                 'dealers.id as dealer_id',
    //                 'dealers.dealer_unq',
    //             );
                
    //         if($request->filled('category')) {
    //             if ($request->category == 'B2B') {
    //                 $query->whereNotNull('loan_basic_details.executive_id');
    //             } elseif ($request->category == 'B2C') {
    //                 $query->whereNull('loan_basic_details.executive_id');
    //             } else {
    //                 return $this->helper_controller->send_response('failed', "Invalid category", 422, []);
    //             }
    //         }

    //         if ($request->filled('product_type_id')) {
    //             $query->where('product_id', $request->product_type_id);
    //         }

    //         $logged_in_user = auth()->user()->id;
    //         $lsp_details = LoanServiceProvider::where('lsp_auth_id', $logged_in_user)->first();

    //         if ($lsp_details) {
    //             $show_rejection_reason = false;

    //             $lsp_executives = LspExecutiveDetails::where('lsp_id', $lsp_details->id)->pluck('id');

    //             if ($lsp_executives->isNotEmpty()) {  // Use isNotEmpty() instead of count()
    //                 $query->whereIn('loan_basic_details.executive_id', $lsp_executives);
    //             } else {
    //                 return $this->helper_controller->send_response('success', "Borrower details fetched", 200, []);
    //             }
    //         }

    //         $dealer_auth_details = Dealer::where('dealer_auth_id', $logged_in_user)->first();
    //         if($dealer_auth_details){
    //             $show_rejection_reason = false;
    //             $query->where('dealer_id', $dealer_auth_details->dealer_unq);
    //         }
    
    //         // Apply date filters if provided

    //         if ($startDate && !empty($startDate)) {
    //             $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
    //             $query->where('loan_basic_details.created_at', '>=', $startDate);
    //         }
            
    //         // Filter by end date (end of the day in Asia/Kolkata timezone)
    //         if ($endDate && !empty($endDate)) {
    //             $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
    //             $query->where('loan_basic_details.created_at', '<=', $endDate);
    //         }

    //         // Apply additional filters
    //         if(!$dealer_auth_details && !$lsp_details) {

    //             if ($request->has('dealer_id') && !empty($request->input('dealer_id'))) {
    //                 $dealerDetails = Dealer::find($request->input('dealer_id'));
    //                 if($dealerDetails) {
    //                     $query->where('loan_basic_details.dealer_id', $dealerDetails->dealer_unq);
    //                 }
    //             }

    //             if ($request->has('lsp_id') && !empty($request->input('lsp_id'))) {
    //                 $lsp_executives_ids = LspExecutiveDetails::where('lsp_id', $request->input('lsp_id'))->pluck('id');

    //                 if ($lsp_executives_ids->isNotEmpty()) {  // Use isNotEmpty() instead of count()
    //                     $query->whereIn('loan_basic_details.executive_id', $lsp_executives_ids);
    //                 }
    //             }
    //         }

    //         if ($request->has('borrower_id') && !empty($request->input('borrower_id'))) {
    //             $query->where('loan_basic_details.borrower_id', 'like', '%' . $request->input('borrower_id') . '%');
    //         }
            
    //         if ($request->has('borrower_name') && !empty($request->input('borrower_name'))) {
    //             // $query->where('borrower_kycs.name', 'like', '%' . $request->input('borrower_name') . '%');
    //             // $query->whereRaw('LOWER(borrower_kycs.name) like ?', ['%' . strtolower($request->input('borrower_name')) . '%']);
    //             // $query->whereRaw('LOWER(users.name) like ?', ['%' . strtolower($request->input('borrower_name')) . '%']);
    //             $borrowerName = strtolower($request->input('borrower_name'));
    //             $query->where(function($q) use ($borrowerName) {
    //                 $q->whereRaw('LOWER(borrower_kycs.name) like ?', ['%' . $borrowerName . '%'])
    //                   ->orWhereRaw('LOWER(users.name) like ?', ['%' . $borrowerName . '%']);
    //             });
    //         }

    //         if ($request->has('mobile_no') && !empty($request->input('mobile_no'))) {
    //             // $query->where('borrower_kycs.mobile', 'like', '%' . $request->input('mobile_no') . '%');
    //             $mobileNo = $request->input('mobile_no');
    //             $query->where(function($q) use ($mobileNo) {
    //                 $q->where('borrower_kycs.mobile', 'like', '%' . $mobileNo . '%')
    //                   ->orWhere('users.mobile', 'like', '%' . $mobileNo . '%');
    //             });
    //         }
    //         if ($request->has('pan_no') && !empty($request->input('pan_no'))) {
    //             $query->where('borrower_kycs.pan', 'like', '%' . $request->input('pan_no') . '%');
    //         }
    //         if ($request->has('aadhar_no') && !empty($request->input('aadhar_no'))) {
    //             $query->where('borrower_kycs.aadhar', 'like', '%' . $request->input('aadhar_no') . '%');
    //         }
    //         if ($request->has('dob') && !empty($request->input('dob'))) {
    //             $query->whereDate('borrower_kycs.dob_date', $request->input('dob'));
    //         }
            
    //         if ($request->has('status') && !empty($request->input('status'))) {
    //             $query->where('loan_basic_details.status', $request->input('status'));
    //         }
    //         if ($request->has('application_date') && !empty($request->input('application_date'))) {
    //             $query->whereDate('loan_basic_details.created_at', $request->input('application_date'));
    //         }

    //         $year = $request->input('year', date('Y'));
    //         $month = $request->input('month', date('F'));
            
    //         if(isset($request->year)) {
    //             $year = $request->input('year', date('Y'));;
    //             $query->whereYear('loan_basic_details.created_at', $year);
    //         }
            
    //         if(isset($request->month)) {
    //             $year = $request->input('year', date('Y'));
    //             $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
    //             $query->whereMonth('loan_basic_details.created_at', $monthNumber);
    //         }
    
    //         // Fetch results before pagination
    //         $borrowers = $query->orderBy('created_on', 'desc')->get();

    //         // Transform the result to replace null or empty values and include step status
    //         $borrowers = $borrowers->map(function ($borrower) use ($show_rejection_reason) {
    //             $stepStatus = '';
    //             $lastStep = null;
    //             if ($borrower->loan_application_steps) {
    //                 $steps = json_decode($borrower->loan_application_steps, true);
    //                 if (is_array($steps) && !empty($steps)) {
    //                     $lastStep = end($steps);
    //                     if (isset($lastStep['step'])) {
    //                         $stepValue = (int)$lastStep['step'];
    //                         if ($stepValue < 8) {
    //                             $stepStatus = 'step1';
    //                         } elseif ($stepValue >= 8 && $stepValue < 26) {
    //                             $stepStatus = 'step2';
    //                         } elseif ($stepValue >= 26 && $stepValue < 27) {
    //                             $stepStatus = 'step3';
    //                         } elseif ($stepValue >= 27) {
    //                             $stepStatus = 'step4';
    //                         }
    //                     }
    //                 }
    //             }

    //             $borrower_user_id = $borrower->user_primary_key;
    //             // $userDetails = User::find($borrower_user_id);
    //             // $user_phone_number = $userDetails->mobile ?? '';
    //             // $user_name = $userDetails->name ?? '';

    //             $borrower_primary = $borrower->borrower_primary;

    //             $credit_summary = LoanApplicationSummary::where('borrower_id', $borrower_primary)->first();

    //             $borrower_cibil = $credit_summary->cibil_score ?? '';
    //             $borrower_cibil = ($borrower_cibil >= 0) 
    //                 ? (($borrower_cibil <= 10 || $borrower_cibil == '-1') ? 'NTC' : $borrower_cibil) 
    //                 : '';

    //             $coapplicant_cibil = $credit_summary->coappli_cibil_score ?? '';
    //             $coapplicant_cibil = ($coapplicant_cibil >= 0) 
    //                 ? (($coapplicant_cibil <= 10 || $coapplicant_cibil == '-1') ? 'NTC' : $coapplicant_cibil) 
    //                 : '';

    //             $guarantor_cibil = $credit_summary->guarantor_cibil_score ?? '';
    //             $guarantor_cibil = ($guarantor_cibil >= 0) 
    //                 ? (($guarantor_cibil <= 10 || $guarantor_cibil == '-1') ? 'NTC' : $guarantor_cibil) 
    //                 : '';


    //             $user_address_details = []; //$this->helper_controller->user_address_details($borrower_primary, "BORROWER");

    //             $borrower_mobile = !empty($borrower->mobile_no) 
    //                 ? $borrower->mobile_no 
    //                 : (!empty($borrower->user_mobile) ? $borrower->user_mobile : '');

    //             $borrower_name = !empty($borrower->borrower_name) 
    //                 ? $borrower->borrower_name 
    //                 : (!empty($borrower->user_name) ? $borrower->user_name : '');


    //             $lender_details = $this->get_borrower_lender_details($borrower_primary);

    //             $model_name = $borrower->model;
    //             if($borrower->product_id == 4) {
    //                 $loan_products = BorrowerLoanProducts::where('borrower_id', $borrower_primary)->get();
    //                 $product_count = 0;
    //                 $inverter = false;
    //                 $model_name = '';
    //                 foreach($loan_products as $loan_product) {
    //                     $loan_product_model = ProductModel::find($loan_product->product_id);
    //                     if($loan_product_model) {
    //                         $product_count++;
    //                         if($product_count == 1) {
    //                             $model_name .= $loan_product_model->name . '(' . $loan_product->quantity . ')';
    //                         } else {
    //                             $model_name .= ', ' . $loan_product_model->name . '(' . $loan_product->quantity . ')';
    //                         }
    //                     }
    //                 }
    //             }

    //             $dealer_details = Dealer::where('dealer_unq', $borrower->dealer_id)->first();
    //             $dealer_id = $borrower->dealer_id;
    //             $product_details = $dealer_details 
    //                 ? ProductModel::where('dealer_id', $dealer_details->id)->where('name', $borrower->model)->first() 
    //                 : null;
    //             $interest_rate = isset($product_details) ? $product_details->interest : 0; 

    //             $insurance_2nd_year = $product_details->insurance_2nd_year ?? 0;

    //             $iot_amount = $product_details->iot ?? 0;
    //             $second_year_insurance = ($borrower->loan_term > 15 && $borrower->loan_term <= 24) ? $insurance_2nd_year : 0;
    //             $final_loan_amount = $borrower->loan_amount + $iot_amount + $second_year_insurance;

    //             if(!empty($borrower->aadhar_no)) {
    //                 $borrower_aadhar = $this->helper_controller->maskAadharNumber($borrower->aadhar_no);
    //             } else {
    //                 $borrower_aadhar = '';
    //             }

    //             $rejection_reason = '';
    //             if($show_rejection_reason) {
    //                 if (strpos($borrower->admin_remarks, '{') !== false) {
    //                     $decoded = json_decode($borrower->admin_remarks, true);
    //                     $rejection_reason = json_last_error() === JSON_ERROR_NONE ? $decoded : $borrower->admin_remarks;
    //                 } else {
    //                     $rejection_reason = ($borrower->admin_remarks === null) ? '' : ['admin_remarks' => $borrower->admin_remarks];
    //                 }
    //             }

    //             $sales_executive_name = '';
    //             $salesExecutive = SalesExecutiveDealerGrouping::with([
    //                 'executive:id,name'
    //                 ])
    //                 ->where('dealer_id', $dealer_id)
    //                 ->where('status', 'active')->first();
    //             if($salesExecutive) {
    //                 $sales_executive_name = $salesExecutive->executive->name ?? '';
    //             }

    //             $user_address_details = $this->helper_controller->user_address_details($borrower->id, "BORROWER");
    //             return [
    //                 'borrower_name' => $borrower_name ?? '',
    //                 'borrower_id' => $borrower->borrower_unq ?? '',
    //                 'loan_status' => $borrower->status ?? '',
    //                 'model' => $model_name ?? '',
    //                 'mobile_no' => $borrower_mobile,
    //                 'alternate_number1' => $borrower->alternate_number1 ?? '',
    //                 'date_of_birth' => $borrower->date_of_birth ?? '',
    //                 'marital_status' => $borrower->marital_status ?? '',
    //                 'spouse_name' => $borrower->spouses_name ?? '',
    //                 'fathers_name' => $borrower->father_name ?? '',
    //                 'gender' => $borrower->gender ?? '',
    //                 'email_id' => $borrower->email_id ?? '',
    //                 'pan_no' => $borrower->pan_no ?? '',
    //                 'aadhar_no' => $borrower_aadhar ?? '',
    //                 'driving_license_no' => $borrower->driving_license_no ?? '',
    //                 'borrower_cibil' => $borrower_cibil ?? 'NTC',
    //                 'coapplicant_cibil' => $coapplicant_cibil ?? 'NTC',
    //                 'guarantor_cibil' => $guarantor_cibil ?? 'NTC',
    //                 'loan_amount' => $final_loan_amount, //$this->calculateNetLoanAmount($borrower->loan_amount, $borrower->loan_term),
    //                 'address' => $user_address_details ?? [],
    //                 'down_payment' => $borrower->down_payment ?? 0,
    //                 'loan_id' => $borrower->loan_id ?? '',
    //                 'created_on' => $borrower->created_on ?? '',
    //                 'step_status' => $stepStatus,
    //                 'lender' => $lender_details['lender'] ?? '',
    //                 'dealer_id' => $borrower->dealer_id ?? '',
    //                 'dealer_unq' => $borrower->dealer_unq ?? '',
    //                 'sales_executive_name' => $sales_executive_name ?? '',
    //                 'last_step' => $lastStep ? [
    //                     'application_step' => $lastStep['step'],
    //                     'details' => $lastStep['details'],
    //                     'status' => $lastStep['status'],
    //                 ] : null, // Adding last step
    //                 'action' => '', // You can specify actions if needed, currently left empty
    //                 'rejection_reason' => $rejection_reason // You can specify actions if needed, currently left empty
    //             ];
    //         });

    //         // Filter by step status if provided
    //         if ($stepFilter !== null) {
    //             $borrowers = $borrowers->filter(function ($borrower) use ($stepFilter) {
    //                 return $borrower['step_status'] === 'step' . $stepFilter;
    //             });
    //         }
    
    //         // Convert collection to array for pagination
    //         $borrowers = $borrowers->values();
    
    //         // Paginate the filtered results
    //         $total = $borrowers->count();
    //         $borrowers = array_slice($borrowers->toArray(), ($page - 1) * $numberOfRecords, $numberOfRecords);
    
    //         // Prepare pagination links
    //         $paginatedData = [
    //             'current_page' => $page,
    //             'data' => $borrowers,
    //             'first_page_url' => url()->current() . '?page=1',
    //             'from' => ($page - 1) * $numberOfRecords + 1,
    //             'last_page' => (int)ceil($total / $numberOfRecords),
    //             'last_page_url' => url()->current() . '?page=' . (int)ceil($total / $numberOfRecords),
    //             'links' => [
    //                 [
    //                     'url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
    //                     'label' => '&laquo; Previous',
    //                     'active' => false
    //                 ],
    //                 [
    //                     'url' => url()->current() . '?page=1',
    //                     'label' => '1',
    //                     'active' => $page == 1
    //                 ],
    //                 [
    //                     'url' => url()->current() . '?page=' . $page,
    //                     'label' => (string)$page,
    //                     'active' => true
    //                 ],
    //                 [
    //                     'url' => $page < (int)ceil($total / $numberOfRecords) ? url()->current() . '?page=' . ($page + 1) : null,
    //                     'label' => 'Next &raquo;',
    //                     'active' => false
    //                 ]
    //             ],
    //             'next_page_url' => $page < (int)ceil($total / $numberOfRecords) ? url()->current() . '?page=' . ($page + 1) : null,
    //             'path' => url()->current(),
    //             'per_page' => $numberOfRecords,
    //             'prev_page_url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
    //             'to' => min($page * $numberOfRecords, $total),
    //             'total' => $total
    //         ];
    
    //         return $this->helper_controller->send_response('success', "Borrower details fetched", 200, $paginatedData);
    
    //     } catch (Exception $e) {
    //         // Handle errors
    //         return response()->json([
    //             'error' => 'An error occurred while fetching loan records.',
    //             'message' => $e->getMessage()
    //         ], 400);
    //     }
    // }

    public function get_borrower_list(Request $request)
    {
        date_default_timezone_set('Asia/Kolkata');

        try {
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $numberOfRecords = $request->input('number_of_records', 10);
            $page = $request->input('page', 1);
            $stepFilter = $request->input('step');
            $show_rejection_reason = true;

            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'start_date' => 'date|nullable',
                'end_date' => 'date|nullable|after_or_equal:start_date',
                'step' => 'integer|min:1|nullable',
                'borrower_name' => 'string|regex:/^[a-zA-Z\s]+$/|nullable',
                'borrower_id' => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable',
                'mobile_no' => 'digits:10|nullable',
                'pan_no' => 'string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|nullable',
                'aadhar_no' => 'string|min:4|max:12|nullable',
                'dob' => 'date|nullable',
                'dealer_id' => 'nullable|integer',
                'lsp_id' => 'nullable|integer',
                'product_type_id' => 'nullable|integer',
                'category' => 'nullable|string|in:B2B,B2C',
                'application_date' => 'date|nullable',
                'status' => 'nullable|string|in:NEW,APPROVED,REJECTED,HOLD,CLOSED,DISBURSED,CANCELLED',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 400);
            }

            $query = LoanBasicDetails::with([
                'dealer_detail:id,dealer_unq',
                'user_detail:id,mobile,name',
                'latestBorrowerKyc:id,borrower_id,name,mobile,dob_date,marital_status,husband_name,father_name,gender,email,pan,aadhar,driving_license_no,alternate_number1',
                // 'sales_executive.executive'
                // 'latestCreditSummary:id,borrower_id,cibil_score,coappli_cibil_score,guarantor_cibil_score'
            ])
            ->select([
                'id', 'borrower_id', 'status', 'user_id', 'loan_amount', 'model', 'loan_term',
                'loan_application_steps', 'created_at', 'dealer_id', 'admin_remarks', 'down_payment'
            ]);

            // Filters...
            if ($request->filled('category')) {
                $request->category === 'B2B'
                    ? $query->whereNotNull('executive_id')
                    : ($request->category === 'B2C'
                        ? $query->whereNull('executive_id')
                        : abort(422, 'Invalid category'));
            }

            if ($request->filled('product_type_id')) {
                $query->where('product_id', $request->product_type_id);
            }

            $authId = auth()->id();
            $lsp = LoanServiceProvider::where('lsp_auth_id', $authId)->first();
            $dealerAuth = Dealer::where('dealer_auth_id', $authId)->first();

            if ($lsp) {
                // echo 'lsp';
                $show_rejection_reason = false;
                
                $execIds = LspExecutiveDetails::where('lsp_id', $lsp->id)->pluck('id');
                
                if ($execIds->isNotEmpty()) {
                    $query->whereIn('loan_basic_details.executive_id', $execIds);
                } else {
                    // No executives assigned, so return empty data
                    return $this->helper_controller->send_response('success', "Borrower list fetched", 200, []);
                }

                // ❌ Ignore request lsp_id or dealer_id — force LSP-level scope

            }

            if ($dealerAuth) {
                $show_rejection_reason = false;
                $query->where('dealer_id', $dealerAuth->dealer_unq);
            }

            if (!$lsp && !$dealerAuth) {
                if ($request->filled('dealer_id') && ($d = Dealer::find($request->dealer_id))) {
                    $query->where('dealer_id', $d->dealer_unq);
                }

                if ($request->filled('lsp_id')) {
                    $execIds = LspExecutiveDetails::where('lsp_id', $request->lsp_id)->pluck('id');
                    if ($execIds->isNotEmpty()) $query->whereIn('executive_id', $execIds);
                }
            }

            if ($request->filled('borrower_name')) {
                $name = strtolower($request->borrower_name);
                $query->where(function ($q) use ($name) {
                    $q->whereHas('latestBorrowerKyc', fn ($k) => $k->whereRaw('LOWER(name) LIKE ?', ["%{$name}%"]))
                      ->orWhereHas('user_detail', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', ["%{$name}%"]));
                });
            }

            if ($request->filled('borrower_id')) {
                $query->whereRaw('LOWER(borrower_id) LIKE ?', ['%' . strtolower($request->borrower_id) . '%']);
            }

            if ($request->filled('mobile_no')) {
                $mobile = $request->mobile_no;
                $query->where(function ($q) use ($mobile) {
                    $q->whereHas('latestBorrowerKyc', fn ($k) => $k->where('mobile', 'like', "%{$mobile}%"))
                      ->orWhereHas('user_detail', fn ($u) => $u->where('mobile', 'like', "%{$mobile}%"));
                });
            }

            foreach (['pan_no' => 'pan', 'aadhar_no' => 'aadhar', 'dob' => 'dob_date'] as $req => $col) {
                if ($request->filled($req)) {
                    $query->whereHas('latestBorrowerKyc', fn ($k) =>
                        $col === 'dob_date'
                            ? $k->whereDate($col, $request->$req)
                            : $k->where($col, 'like', "%" . $request->$req . "%"));
                }
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('start_date')) {
                $query->where('created_at', '>=', Carbon::parse($startDate)->startOfDay());
            }

            if ($request->filled('end_date')) {
                $query->where('created_at', '<=', Carbon::parse($endDate)->endOfDay());
            }

            if ($request->filled('application_date')) {
                $query->whereDate('created_at', $request->application_date);
            }

            $year = $request->input('year', date('Y'));

            if ($request->filled('year')) {
                $query->whereYear('created_at', $request->year);
            }

            if ($request->filled('month')) {
                $month = $request->input('month', date('F'));
                $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
                $query->whereYear('created_at', $year)
                      ->whereMonth('created_at', $monthNumber);
            }

            // ry->whereRaw("
                // CASE 
                //     WHEN jsonb_array_length(loan_application_steps::jsonb) > 0 THEN (
                //         CASE
                //             WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int < 8 AND :step = 1 THEN true
                //             WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 8 AND 19 AND :step = 2 THEN true
                //             WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 24 AND 26 AND :step = 3 THEN true
                //             WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int >= 27 AND :step = 4 THEN true
                //             ELSE false
                //         END
                //     )
                //     ELSE false
                // END
            if ($request->filled('step')) {
                $step = (int) $request->input('step');

                $query->whereRaw("
                    CASE 
                        WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int >= 27 AND :step = 4 THEN true
                        WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 21 AND 26 AND :step = 3 THEN true
                        WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 8 AND 20 AND :step = 2 THEN true
                        WHEN ((loan_application_steps::jsonb)->(jsonb_array_length(loan_application_steps::jsonb)-1)->>'step')::int BETWEEN 1 AND 7 AND :step = 1 THEN true
                        ELSE false
                    END
                ", ['step' => $step]);
            }


            $paginated = $query->orderBy('created_at', 'desc')->paginate($numberOfRecords);

            // $paginated->getCollection()->transform(function ($borrower, $show_rejection_reason) {

            //     $summary = LoanApplicationSummary::select(['cibil_score','coappli_cibil_score','guarantor_cibil_score'])->where('borrower_id', $borrower->id)->first();
            //     $parseCibil = fn($value) =>
            //         ($value === null || $value === '') ? '' : ($value == '0' ? 'NTC' : $value);

            //     $borrower_cibil     = $parseCibil($summary->cibil_score ?? null);
            //     $coapplicant_cibil  = $parseCibil($summary->coappli_cibil_score ?? null);
            //     $guarantor_cibil    = $parseCibil($summary->guarantor_cibil_score ?? null);

            //     $kyc = $borrower->latestBorrowerKyc;
            //     $user = $borrower->user_detail;
            //     $dealer = $borrower->dealer_detail;
            //     if ($dealer) {
            //         $salesExecutive = $dealer->sales_executives()->with('executive:id,name')->first();

            //         if ($salesExecutive && $salesExecutive->executive) {
            //             $sales_executive_name = $salesExecutive->executive->name;
            //         }
            //     }

            //     $steps = json_decode($borrower->loan_application_steps, true);
            //     $stepStatus = null;
            //     // $lastStep = is_array($steps) && !empty($steps) ? end($steps) : null;
            //     if (is_array($steps) && !empty($steps)) {
            //         $lastStep = end($steps);
            //         $step = (int) ($lastStep['step'] ?? 0);

            //         if ($step < 8) {
            //             $stepStatus = 'step1';
            //         } elseif ($step >= 8 && $step <= 20) {
            //             $stepStatus = 'step2';
            //         } elseif ($step >= 21 && $step < 27) {
            //             $stepStatus = 'step3';
            //         } else {
            //             $stepStatus = 'step4';
            //         }
            //     }

            //     $lender_details = $this->get_borrower_lender_details($borrower->id);

            //     // $rejection_reason = '';
            //     // if($show_rejection_reason) {
            //     //     if (strpos($borrower->admin_remarks, '{') !== false) {
            //     //         $decoded = json_decode($borrower->admin_remarks, true);
            //     //         $rejection_reason = json_last_error() === JSON_ERROR_NONE ? $decoded : $borrower->admin_remarks;
            //     //     } else {
            //     //         $rejection_reason = !empty($borrower->admin_remarks) ? ['admin_remarks' => $borrower->admin_remarks] : '' ;
            //     //     }
            //     // }
            //     $rejection_reason = null;

            //     if ($show_rejection_reason && !empty($borrower->admin_remarks)) {

            //         $decoded = json_decode($borrower->admin_remarks, true);

            //         if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            //             $rejection_reason = $decoded;
            //         } else {
            //             $rejection_reason = [
            //                 'admin_remarks' => $borrower->admin_remarks
            //             ];
            //         }
            //     }

            //     $user_address_details = $this->helper_controller->user_address_details($borrower->id, "BORROWER");
            //     return [
            //         'borrower_name'        => $kyc->name ?? $user->name ?? '',
            //         'borrower_id'          => $borrower->borrower_id ?? '',
            //         'loan_status'          => $borrower->status ?? '',
            //         'model'                => $borrower->model ?? '',
            //         'mobile_no'            => $kyc->mobile ?? $user->mobile ?? '',
            //         'alternate_number1'    => $kyc->alternate_number1 ?? '',
            //         'date_of_birth'        => $kyc->dob_date ?? '',
            //         'marital_status'       => $kyc->marital_status ?? '',
            //         'spouse_name'          => $kyc->husband_name ?? '',
            //         'fathers_name'         => $kyc->father_name ?? '',
            //         'gender'               => $kyc->gender ?? '',
            //         'email_id'             => $kyc->email ?? '',
            //         'pan_no'               => $kyc->pan ?? '',
            //         'aadhar_no'            => $kyc->aadhar ?? '',
            //         'driving_license_no'   => $kyc->driving_license_no ?? '',
            //         'borrower_cibil'       => $parseCibil($summary->cibil_score ?? null),
            //         'coapplicant_cibil'    => $parseCibil($summary->coappli_cibil_score ?? null),
            //         'guarantor_cibil'      => $parseCibil($summary->guarantor_cibil_score ?? null),
            //         'loan_amount'          => $this->calculateNetLoanAmount($borrower->loan_amount, $borrower->loan_term),
            //         'address'              => $user_address_details ?? [],
            //         'down_payment'         => $borrower->down_payment ?? 0,
            //         'loan_id'              => '',
            //         'created_on'           => $borrower->created_at ?? '',
            //         'dealer_id'            => $dealer->id ?? '',
            //         'dealer_unq'           => $dealer->dealer_unq ?? '',
            //         'lender'               => $lender_details['lender'] ?? '',
            //         'sales_executive_name' => $sales_executive_name ?? '',
            //         'rejection_reason'     => $rejection_reason,
            //         'step_status'          => $stepStatus,
            //         'last_step'            => $lastStep ? [
            //             'application_step' => $lastStep['step'] ?? '',
            //             'details'          => $lastStep['details'] ?? '',
            //             'status'           => $lastStep['status'] ?? '',
            //         ] : null,
            //         'action' => ''
            //     ];
            // });
            $paginated->getCollection()->transform(function ($borrower) use ($show_rejection_reason) {

                    $summary = LoanApplicationSummary::select([
                        'cibil_score','coappli_cibil_score','guarantor_cibil_score'
                    ])->where('borrower_id', $borrower->id)->first();

                    $parseCibil = fn($value) =>
                        ($value === null || $value === '') ? '' : ($value == '0' ? 'NTC' : $value);

                    $borrower_cibil     = $parseCibil($summary->cibil_score ?? null);
                    $coapplicant_cibil  = $parseCibil($summary->coappli_cibil_score ?? null);
                    $guarantor_cibil    = $parseCibil($summary->guarantor_cibil_score ?? null);

                    $kyc = $borrower->latestBorrowerKyc;
                    $user = $borrower->user_detail;
                    $dealer = $borrower->dealer_detail;

                    $sales_executive_name = '';
                    if ($dealer) {
                        $salesExecutive = $dealer->sales_executives()->with('executive:id,name')->first();
                        if ($salesExecutive && $salesExecutive->executive) {
                            $sales_executive_name = $salesExecutive->executive->name;
                        }
                    }

                    $steps = json_decode($borrower->loan_application_steps, true);
                    $stepStatus = null;
                    $lastStep = null;

                    if (is_array($steps) && !empty($steps)) {
                        $lastStep = end($steps);
                        $step = (int) ($lastStep['step'] ?? 0);

                        if ($step < 8) $stepStatus = 'step1';
                        elseif ($step <= 20) $stepStatus = 'step2';
                        elseif ($step < 27) $stepStatus = 'step3';
                        else $stepStatus = 'step4';
                    }

                    $rejection_reason = null;
                    if ($show_rejection_reason && !empty($borrower->admin_remarks)) {
                        $decoded = json_decode($borrower->admin_remarks, true);
                        $rejection_reason = (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
                            ? $decoded
                            : ['admin_remarks' => $borrower->admin_remarks];
                    }

                    $user_address_details = $this->helper_controller
                        ->user_address_details($borrower->id, "BORROWER");

                    return [
                        'borrower_name'        => $kyc->name ?? $user->name ?? '',
                        'borrower_id'          => $borrower->borrower_id ?? '',
                        'loan_status'          => $borrower->status ?? '',
                        'model'                => $borrower->model ?? '',
                        'mobile_no'            => $kyc->mobile ?? $user->mobile ?? '',
                        'alternate_number1'    => $kyc->alternate_number1 ?? '',
                        'date_of_birth'        => $kyc->dob_date ?? '',
                        'marital_status'       => $kyc->marital_status ?? '',
                        'spouse_name'          => $kyc->husband_name ?? '',
                        'fathers_name'         => $kyc->father_name ?? '',
                        'gender'               => $kyc->gender ?? '',
                        'email_id'             => $kyc->email ?? '',
                        'pan_no'               => $kyc->pan ?? '',
                        'aadhar_no'            => $kyc->aadhar ?? '',
                        'driving_license_no'   => $kyc->driving_license_no ?? '',
                        'borrower_cibil'       => $parseCibil($summary->cibil_score ?? null),
                        'coapplicant_cibil'    => $parseCibil($summary->coappli_cibil_score ?? null),
                        'guarantor_cibil'      => $parseCibil($summary->guarantor_cibil_score ?? null),
                        'loan_amount'          => $this->calculateNetLoanAmount($borrower->loan_amount, $borrower->loan_term),
                        'address'              => $user_address_details ?? [],
                        'down_payment'         => $borrower->down_payment ?? 0,
                        'loan_id'              => '',
                        'created_on'           => $borrower->created_at ?? '',
                        'dealer_id'            => $dealer->id ?? '',
                        'dealer_unq'           => $dealer->dealer_unq ?? '',
                        'lender'               => $lender_details['lender'] ?? '',
                        'sales_executive_name' => $sales_executive_name ?? '',
                        'rejection_reason'     => $rejection_reason,
                        'step_status'          => $stepStatus,
                        'last_step'            => $lastStep ? [
                            'application_step' => $lastStep['step'] ?? '',
                            'details'          => $lastStep['details'] ?? '',
                            'status'           => $lastStep['status'] ?? '',
                        ] : null,
                    ];
                });

            return $this->helper_controller->send_response('success', 'Borrower details fetched', 200, $paginated);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching loan records.',
                'message' => $e->getMessage()
            ], 400);
        }
    }
    
    public function get_borrower_details($borrower_id = null) {
        try {
            if (is_null($borrower_id)) {
                return $this->helper_controller->send_response('failed', "Borrower id is required", 422);
            }

            $loan_basic_details = LoanBasicDetails::with([
                'dealer',
                'latestBorrowerKyc',
                'latestBorrowerAddress',
                'latestBorrowerOccupation',
                'latestCoapplicantKyc',
                'latestCoapplicantOccupation',
                'latestGuarantor',
                'latestGuarantorOccupation',
                'latestDocuments'
            ])->where('borrower_id', (string)$borrower_id)->first();

            if (!$loan_basic_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }

            $dealer_details = $loan_basic_details->dealer;
            // $product_details = $dealer_details 
            //     ? ProductModel::where('dealer_id', $dealer_details->id)->where('name', $loan_basic_details->model)->first() 
            //     : null;
            if(!empty($product_model_id)) {

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
                'interest' => $product_details->interest ?? '',
                'manufacturer_name' => $product_details->manufacturer_name ?? '',
                'min_loan_amount' => $product_details->min_loan_amount ?? '',
                'max_loan_amount' => $product_details->max_loan_amount ?? '',
                'min_loan_tenure' => $product_details->min_loan_tenure ?? '',
                'max_loan_tenure' => $product_details->max_loan_tenure ?? '',
                'processing_fee' => $product_details->processing_fee ?? '',
                'subvention_amount' => $product_details->subvention_amount ?? '',
                'fldg' => $product_details->fldg ?? '',
                'iot' => $product_details->iot ?? '',
                'insurance_1st_year' => $product_details->insurance_1st_year ?? '',
                'insurance_1nd_year' => $product_details->insurance_1nd_year ?? '',
            ];
            $interest_rate = isset($product_details) ? $product_details->interest : 17.5; 

            $loan_summary_details = LoanApplicationSummary::where('borrower_id', $loan_basic_details->id)->latest()->first();

            $result_array = [
                'loan_details' => [
                    'id'                => $loan_basic_details->id,
                    'borrower_id'       => $borrower_id,
                    'dealer_name'       => $dealer_details->name ?? '',
                    'model_name'        => $product_details->name ?? $loan_basic_details->model,
                    'product_type'      => $product_details->product_type ?? '',
                    'loan_amount'       => $this->calculateNetLoanAmount($loan_basic_details->loan_amount, $loan_basic_details->loan_term),
                    'roi'               => $interest_rate ?? 17.5,
                    'tenure'            => $loan_basic_details->loan_term,
                    'emi'               => number_format($this->helper_controller->calculateFlatInterestEMI($loan_basic_details->loan_amount, $interest_rate, $loan_basic_details->loan_term), 2, '.', ''),
                    'iot_amount'        => 0,
                    'second_year_insurance' => ($loan_basic_details->loan_term > 15 && $loan_basic_details->loan_term <= 24) ? 0 : 0,
                    'processing_fee'    => 0,
                    'down_payment'    => $loan_basic_details->down_payment ?? 0,
                    'subvention_amount' => 0,
                    'fldg' => '',
                    'first_year_insurance' => 0,
                    'paid_amount' => 0,
                    'amount_due' => 0,
                    'status' => $loan_basic_details->status,
                    'created_at' => date('d-m-Y H:i:s', strtotime($loan_basic_details->created_at)) ?? '',
                ],
                // 'product_scheme'        => $product_scheme,
                'borrower_details'      => $this->transformBorrowerDetails($loan_basic_details->latestBorrowerKyc),
                'borrower_address'      => $this->transformBorrowerAddress($loan_basic_details->latestBorrowerAddress, $loan_basic_details->id),
                'borrower_occupation'   => $this->transformBorrowerOccupation($loan_basic_details->latestBorrowerOccupation),
                'co_applicant_details'  => $this->transformCoApplicantDetails($loan_basic_details->latestCoapplicantKyc),
                'co_applicant_address'  => $this->transformCoApplicantAddress($loan_basic_details->latestCoapplicantOccupation, $loan_basic_details->id),
                'guarantor_details'     => $this->transformGuarantorDetails($loan_basic_details->latestGuarantor),
                'guarantor_occupation'  => $this->transformGuarantorOccupation($loan_basic_details->latestGuarantorOccupation, $loan_basic_details->id),
                'credit_details'        => $this->transformCreditSummary($loan_basic_details->latestCreditSummary),
                'documents'             => $this->transformDocuments($loan_basic_details->latestDocuments),
                'iot_details'           => $this->get_borrower_iot_details($loan_basic_details->id),
                'disbursement_details'  => $this->get_borrower_disbursement_details($loan_basic_details->id),
                'lender_details'        => $this->get_borrower_lender_details($loan_basic_details->id),
                'account_details'        => $this->get_borrower_account_details($loan_basic_details->id),
                'field_inspection_details' => $this->get_borrower_field_inspection_details($loan_basic_details->id),
                'loan_scheme'           => $this->get_borrower_loan_scheme($loan_basic_details->id),
                'emi_chart'             => $this->get_borrower_loan_chart($loan_basic_details->id),
            ];

            return $this->helper_controller->send_response('success', "Borrower details fetched", 200, $result_array);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }

    private function calculateNetLoanAmount($loan_amount, $loan_term) {
        $iot_amount = 3000;
        $second_year_insurance = ($loan_term > 15 && $loan_term <= 24) ? 6000 : 0;
        return $loan_amount + $iot_amount + $second_year_insurance;
    }

    private function transformBorrowerDetails($borrower_kyc) {
        if (!$borrower_kyc) return [];

        $borrower_aadhar = $this->helper_controller->maskAadharNumber($borrower_kyc->aadhar);
        return [
            'name' => $borrower_kyc->name ?? '',
            'father_name' => $borrower_kyc->father_name ?? '',
            'mobile' => $borrower_kyc->mobile ?? '',
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
            'chasis_number' => $borrower_kyc->chasis_number ?? '',
        ];
    }

    private function transformDocuments($documents)  {
        if (!$documents) return [];

        return [
            'borrower_aadhar_front' => $documents->aadhar_front ?? '',
            'borrower_aahar_back' => $documents->aadhar_back ?? '',
            'borrower_pan_doc' => $documents->pan_doc ?? '',
            'borrower_driving_license' => $documents->driving_license ?? '',
            'borrower_per_address_proof' => $documents->per_address_proof ?? '',
            'borrower_res_address_proof' => $documents->res_address_proof ?? '',
            'vehicle_quotation' => $documents->vehicle_quotation ?? '',
            'coAadhar_front' => $documents->coAadhar_front ?? '',
            'coAahar_back' => $documents->coAadhar_back ?? '',
            'coPan_doc' => $documents->coPan_doc ?? '',
            'coDriving_license' => $documents->coDriving_license ?? '',
            'coPerAddress_proof' => $documents->coPerAddress_proof ?? '',
            'coResAddress_proof' => $documents->coResAddress_proof ?? '',
            'additional_doc' => $documents->additional_doc ?? '',
            'invoice' => $documents->invoice ?? '',
            'performa' => $documents->performa ?? '',
            'down_payment_receipt' => $documents->down_payment_receipt ?? '',
            'insurance' => $documents->insurance ?? '',
            'rto_tax_receipt' => $documents->rto_tax_receipt ?? '',
            'dl_receipt' => $documents->dl_receipt ?? '',
            'documents_form' => $documents->documents_form ?? '',
            'guarantor_aadhar_front' => $documents->guarantor_aadhar_front ?? '',
            'guarantor_aadhar_back' => $documents->guarantor_aadhar_back ?? '',
            'guarantor_driving_license' => $documents->guarantor_driving_license ?? '',
            'guarantor_pan_doc' => $documents->guarantor_pan_doc ?? '',
            'guarantor_peraddress_proof' => $documents->guarantor_peraddress_proof ?? '',
            'guarantor_coresaddress_proof' => $documents->guarantor_coresaddress_proof ?? '',
            'borrower_avtar' => $documents->borrower_avtar ?? '',
            'coapplicant_avtar' => $documents->coapplicant_avtar ?? '',
            'guarantor_avtar' => $documents->guarantor_avtar ?? '',
            'loan_file' => $documents->loan_file ?? '',
            'battery_guaranty' => $documents->battery_guaranty ?? '',
            'warrant_document' => $documents->warrant_document ?? '',
            'rto_document' => $documents->rto_document ?? '',
            'mmr' => $documents->mmr ?? '',
            'vehicle_image' => $documents->vehicle_image ?? '',
            'urc_certificate' => $documents->urc_certificate ?? '',
            'borrower_signed_cheque' => $documents->borrower_signed_cheque ?? '',
            'vehicle_signed_cheque' => $documents->vehicle_signed_cheque ?? '',
            'doh' => $documents->doh ?? '',
            'borrower_other_docs' => $documents->borrower_other_docs ?? '',
            'vehicle_other_docs' => $documents->vehicle_other_docs ?? '',
            'borrower_rent_doc' => $documents->borrower_rent_doc ?? '',
            'coapplicant_rent_doc' => $documents->coapplicant_rent_doc ?? '',
            'guarantor_rent_doc' => $documents->guarantor_rent_doc ?? '',
            'borrower_name_declaration' => $documents->borrower_name_declaration ?? '',
            'coapplicant_name_declaration' => $documents->coapplicant_name_declaration ?? '',
            'guarantor_name_declaration' => $documents->guarantor_name_declaration ?? '',
            'chasis_number' => $documents->chasis_number ?? '',
            'motor_controller' => $documents->motor_controller ?? '',
            'battery_image' => $documents->battery_image ?? '',
            'borrower_income_proof_doc' => $documents->borrower_income_proof_doc ?? '',
            'coapplicant_income_proof_doc' => $documents->coapplicant_income_proof_doc ?? '',
            'guarantor_income_proof_doc' => $documents->guarantor_income_proof_doc ?? '',
            'borrower_dob_declaration' => $documents->borrower_dob_declaration ?? '',
            'coapplicant_dob_declaration' => $documents->coapplicant_dob_declaration ?? '',
            'guarantor_dob_declaration' => $documents->guarantor_dob_declaration ?? '',
        ];
    }

    private function transformCreditSummary($creditSummary) {
        if (!$creditSummary) return [];
        
        $borrower_cibil = $creditSummary->cibil_score ?? '';
        $borrower_cibil = ($borrower_cibil >= 0) ? (($borrower_cibil == 0 || $borrower_cibil == '-1') ? 'NTC' : $borrower_cibil) : '';

        $coapplicant_cibil = $creditSummary->coappli_cibil_score ?? '';
        $coapplicant_cibil = ($coapplicant_cibil >= 0) ? (($coapplicant_cibil == 0 || $coapplicant_cibil == '-1') ? 'NTC' : $coapplicant_cibil) : '';

        $guarantor_cibil = $creditSummary->guarantor_cibil_score ?? '';
        $guarantor_cibil = ($guarantor_cibil >= 0) ? (($guarantor_cibil == 0 || $guarantor_cibil == '-1') ? 'NTC' : $guarantor_cibil) : '';


        return [
            'borrower_cibil_score' => $borrower_cibil, //!empty($creditSummary->cibil_score) ? $creditSummary->cibil_score : 'NTC',
            'borrower_dpd_30' => $creditSummary->dpd_30_in_3_month ?? '',
            'borrower_dpd_90' => $creditSummary->dpd_90_in_1_year ?? '',
            'borrower_is_npa' => $creditSummary->is_npa ?? '',
            'borrower_is_defaulter' => $creditSummary->is_willful_defaulter ?? '',
            'coappli_cibil_score' => $coapplicant_cibil, //!empty($creditSummary->coappli_cibil_score) ? $creditSummary->coappli_cibil_score : 'NTC',
            'coappli_dpd_30' => $creditSummary->coappli_dpd_30 ?? '',
            'coappli_dpd_90' => $creditSummary->coappli_dpd_90 ?? '',
            'coappli_is_npa' => $creditSummary->coappli_is_npa ?? '',
            'coappli_defaulter' => $creditSummary->coappli_defaulter ?? '',
            'guarantor_cibil_score' => $guarantor_cibil, //!empty($creditSummary->guarantor_cibil_score) ? $creditSummary->guarantor_cibil_score : 'NTC',
            'guarantor_dpd_30' => $creditSummary->guarantor_dpd_30 ?? '',
            'guarantor_dpd_90' => $creditSummary->guarantor_dpd_90 ?? '',
            'guarantor_is_npa' => $creditSummary->guarantor_is_npa ?? '',
            'guarantor_defaulter' => $creditSummary->guarantor_defaulter ?? '',
        ];
    }

    private function transformBorrowerAddress($borrower_address, $borrower_primary) {
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

    private function transformBorrowerOccupation($borrower_occupation) {
        if (!$borrower_occupation) return [];
        if(isset($borrower_occupation->occupation_type) && is_numeric($borrower_occupation->occupation_type)) {

            $b_occupation_type = $this->helper_controller->get_occupation_type($borrower_occupation->occupation_type);
        } else {
            $b_occupation_type = $borrower_occupation->occupation_type;
        }

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

    private function transformCoApplicantDetails($co_applicant_details) {
        if (!$co_applicant_details) return [];
        $co_applicant_aadhar = $this->helper_controller->maskAadharNumber($co_applicant_details->aadhar);

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

    private function transformCoApplicantAddress($coapplicant_address, $borrower_primary) {
        if (!$coapplicant_address) return [];

        $coapplicant_aadhar_address = $this->helper_controller->user_address_details($borrower_primary, 'COAPPLICANT');
        $address_details = isset($coapplicant_aadhar_address['status']) && $coapplicant_aadhar_address['status'] == 'success'
            ? $coapplicant_aadhar_address['data']
            : '';
        $c_occupation_type = isset($coapplicant_address->occupation_type)
        ? $this->helper_controller->get_occupation_type($coapplicant_address->occupation_type)
        : '';

        if(isset($coapplicant_address->occupation_type) && is_numeric($coapplicant_address->occupation_type)) {

            $c_occupation_type = $this->helper_controller->get_occupation_type($coapplicant_address->occupation_type);
        } else {
            $c_occupation_type = $coapplicant_address->occupation_type;
        }

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

    private function transformGuarantorDetails($guarantor_details) {
        if (!$guarantor_details) return [];

        $guarantor_aadhar = $this->helper_controller->maskAadharNumber($guarantor_details->aadhar);
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

    private function transformGuarantorOccupation($guarantor_occupation, $borrower_primary) {
        if (!$guarantor_occupation) return [];
        $guarantor_aadhar_address = $this->helper_controller->user_address_details($borrower_primary, 'GUARANTOR');
        $address_details = isset($guarantor_aadhar_address['status']) && $guarantor_aadhar_address['status'] == 'success'
            ? $guarantor_aadhar_address['data']
            : '';

        if(isset($guarantor_occupation->occupation_type) && is_numeric($guarantor_occupation->occupation_type)) {

            $g_occupation_type = $this->helper_controller->get_occupation_type($guarantor_occupation->occupation_type);
        } else {
            $g_occupation_type = $guarantor_occupation->occupation_type;
        }
        // $g_occupation_type = isset($guarantor_occupation->occupation_type)
        //     ? $this->helper_controller->get_occupation_type($guarantor_occupation->occupation_type)
        //     : '';

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
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 400);
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
                // return $this->helper_controller->send_response('success', "$module list fetched", 200, $records);
            } else {
                return $this->helper_controller->send_response('success', "No records found", 200);
            }
        
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    
    public function downloadFolder($folderName)
    {
        $folderPath = storage_path('app/public/Borrower/' . $folderName);
        $zipFileName = $folderName . '.zip';
        $zipFilePath = storage_path('app/public/Borrower_zip/' . $zipFileName);
        // echo $folderPath;
        // echo $zipFilePath;
        // die();
        try {
            // Ensure the storage directory exists
            if (!File::exists(storage_path('app/public/Borrower_zip'))) {
                File::makeDirectory(storage_path('app/public/Borrower_zip'), 0755, true);
            }

            // Check if the folder exists
            if (!File::exists($folderPath)) {

                return $this->helper_controller->send_response('failed', "Folder not found", 400);
            }

            // Create ZIP archive
            $zip = new ZipArchive;

            if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folderPath));

                foreach ($files as $name => $file) {
                    // Skip directories (they are added automatically)
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($folderPath) + 1);
                        $zip->addFile($filePath, $relativePath);
                    }
                }

                // Close the ZIP file
                if (!$zip->close()) {
                    throw new Exception('Failed to close ZIP file');
                }
            } else {
                throw new Exception('Failed to create ZIP file');
            }

            $publicZipPath = 'Borrower_zip/' . $zipFileName;
            // $publicUrl = 'storage/' . $publicZipPath;
            $publicUrl = 'http://13.200.65.87/storage/' . $publicZipPath;
            return $this->helper_controller->send_response('success', "ZIP file created", 200, ['file_path' => $publicUrl]);

            // Delete the ZIP file after sending the response [use this if deletion needed]
            // File::delete($zipFilePath);

        } catch (Exception $e) {
            // Delete the ZIP file if it was created but an error occurred
            // if (File::exists($zipFilePath)) {
            //     File::delete($zipFilePath);
            // }

            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // public function download_agreement_file($borrower_id)
    // {
    //     $filePath = storage_path('app/public/e-sign/' . $borrower_id . '/BORROWER/final_signed_agreement.pdf');

    //     try {
    //         // Check if the file exists
    //         if (!File::exists($filePath)) {
    //             // return response()->json(['error' => 'File not found'], 404);
    //             return $this->helper_controller->send_response('failed', "File not found", 400);
    //         }

    //         $downloadUrl = url('storage/e-sign/' . $borrower_id . '/BORROWER/final_signed_agreement.pdf');
    //         return $this->helper_controller->send_response('success', "Download URL generated", 200, ['download_url' => $downloadUrl]);

    //         // Stream the file for download with a new name
    //         // return new StreamedResponse(function () use ($filePath) {
    //         //     $stream = fopen($filePath, 'r');
    //         //     fpassthru($stream);
    //         //     fclose($stream);
    //         // }, 200, [
    //         //     'Content-Type' => File::mimeType($filePath),
    //         //     'Content-Length' => File::size($filePath),
    //         //     'Content-Disposition' => 'attachment; filename="Agreement-document-' . $borrower_id . '.pdf"',
    //         // ]);

    //     } catch (Exception $e) {
    //         return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
    //     }
    // }
    public function download_agreement_file($borrower_id)
    {
        $filePath = storage_path('app/public/e-sign/' . $borrower_id . '/BORROWER/final_signed_agreement.pdf');

        try {
            // Check if the file exists
            if (!File::exists($filePath)) {
                return $this->helper_controller->send_response('failed', "File not found", 404);
            }

            // Generate the download URL
            $downloadUrl = 'http://13.200.65.87/storage/e-sign/' . $borrower_id . '/BORROWER/final_signed_agreement.pdf';
            // $downloadUrl = 'storage/e-sign/' . $borrower_id . '/BORROWER/final_signed_agreement.pdf';
            return $this->helper_controller->send_response('success', "Download URL generated", 200, ['download_url' => $downloadUrl]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function cam_sheet_doc($borrower_id)
{
    if(!isset($borrower_id) || empty($borrower_id)) {
        return $this->helper_controller->send_response('failed', "Borrower id is required", 422);
    }

    $loan_basic_details = LoanBasicDetails::with([
        'dealer',
        'latestBorrowerKyc',
        'latestBorrowerAddress',
        'latestBorrowerOccupation',
        'latestCoapplicantKyc',
        'latestCoapplicantOccupation',
        'latestGuarantor',
        'latestGuarantorOccupation'
    ])->where('borrower_id', $borrower_id)->first();
    
    if (!$loan_basic_details) {
        return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
    }

    date_default_timezone_set('Asia/Kolkata');
    $date = date('d-m-Y');

    // Example data array fetched from the database
    $sourcingData[] = [
        'col_1' => 'PREPARE DATE',
        'col_2' => $date,
        'col_3' => 'PREPARED BY (CREDIT MANAGER & ID)',
        'col_4' => '',
    ];
    $sourcingData[] = [
        'col_1' => 'STATE NAME',
        'col_2' => '',
        'col_3' => 'SOURCING THROUGH (DEALER NAME)',
        'col_4' => $loan_basic_details->dealer->name ?? '',
    ];
    $sourcingData[] = [
        'col_1' => 'BRANCH NAME',
        'col_2' => '',
        'col_3' => 'DEALER EXECUTIVE',
        'col_4' => '',
    ];
    $sourcingData[] = [
        'col_1' => 'PRODUCT TYPE',
        'col_2' => 'EV',
        'col_3' => 'DST NAME',
        'col_4' => '',
    ];
    $sourcingData[] = [
        'col_1' => 'PORTFOLIO',
        'col_2' => '',
        'col_3' => 'CHECKED BY CM /OPS @ FINAYO',
        'col_4' => '',
    ];
    
    $borrower_kyc = $loan_basic_details->latestBorrowerKyc;
    $coapplicant_kyc = $loan_basic_details->latestCoapplicantKyc;
    $guarantor_kyc = $loan_basic_details->latestGuarantor;
    $personalDetails[] = [
        'col_1' => 'NAME',
        'col_2' => $borrower_kyc->name ?? '',
        'col_3' => $coapplicant_kyc->name ?? '',
        'col_4' => $guarantor_kyc->name ?? '',
    ];
    
    $personalDetails[] = [
        'col_1' => 'FATHER/SPOUSE NAME',
        'col_2' => $borrower_kyc->father_name ?? $borrower_kyc->husband_name ?? '',
        'col_3' => $coapplicant_kyc->father_name ?? $coapplicant_kyc->husband_name ?? '',
        'col_4' => $guarantor_kyc->father_name ?? $guarantor_kyc->husband_name ?? '',
    ];
    
    $personalDetails[] = [
        'col_1' => 'GENDER',
        'col_2' => $borrower_kyc->gender ?? '',
        'col_3' => $coapplicant_kyc->gender ?? '',
        'col_4' => $guarantor_kyc->gender ?? '',
    ];
    
    $personalDetails[] = [
        'col_1' => 'CONTACT NO',
        'col_2' => $borrower_kyc->mobile ?? '',
        'col_3' => $coapplicant_kyc->mobile ?? '',
        'col_4' => $guarantor_kyc->mobile ?? '',
    ];
    
    $personalDetails[] = [
        'col_1' => 'DOB (DD/MM/YY)',
        'col_2' => isset($borrower_kyc->dob_date) ? date('d-M-Y', strtotime($borrower_kyc->dob_date)) : '',
        'col_3' => isset($coapplicant_kyc->dob_date) ? date('d-M-Y', strtotime($coapplicant_kyc->dob_date)) : '',
        'col_4' => isset($guarantor_kyc->dob_date) ? date('d-M-Y', strtotime($guarantor_kyc->dob_date)) : '',
    ];
    
    $personalDetails[] = [
        'col_1' => 'Age as on date',
        'col_2' => '',
        'col_3' => '',
        'col_4' => '',
    ];
    
    $personalDetails[] = [
        'col_1' => 'Age EOT',
        'col_2' => '',
        'col_3' => '',
        'col_4' => '',
    ];

    $personalDetails[] = [
        'col_1' => 'MARITAL STATUS',
        'col_2' => $borrower_kyc->marital_status ?? '',
        'col_3' => $coapplicant_kyc->marital_status ?? '',
        'col_4' => $guarantor_kyc->marital_status ?? '',
    ];

    $credit_summary = $loan_basic_details->latestCreditSummary;
    if($loan_basic_details->latestCoapplicantKyc) {
        $coappli_cibil_score = $credit_summary->coappli_cibil_score; 
    }
    $personalDetails[] = [
        'col_1' => 'CIBIL SCORE',
        'col_2' => $credit_summary->cibil_score ?? '-1',
        'col_3' => $coappli_cibil_score ?? '',
        'col_4' => $credit_summary->guarantor_cibil_score ?? '-1',
    ];
    $borrower_address = $loan_basic_details->latestBorrowerAddress;
    $coapplicant_occupation = $loan_basic_details->latestCoapplicantOccupation;
    $guarantor_occupation = $loan_basic_details->latestGuarantorOccupation;

    $personalDetails[] = [
        'col_1' => 'RESIDENCE TYPE',
        'col_2' => $borrower_address->resident_type ?? '',
        'col_3' => $coapplicant_occupation->resident_type ?? '',
        'col_4' => $guarantor_occupation->resident_type ?? '',
    ];

    if($borrower_address) {
        $borrower_single_line_address = trim($borrower_address->current_address, ', ');
        $borrower_single_line_address = strtoupper($borrower_single_line_address);
    }

    if($guarantor_occupation) {
        $guarantor_single_line_address = trim($guarantor_occupation->current_address, ', ');
        $guarantor_single_line_address = strtoupper($guarantor_single_line_address);
    }
        
    if($coapplicant_occupation) {

        $coapplicant_single_line_address = trim($coapplicant_occupation->current_address, ',');
        $coapplicant_single_line_address = strtoupper($coapplicant_single_line_address);
    }

    $personalDetails[] = [
        'col_1' => 'Current Adderess',
        'col_2' => $borrower_single_line_address ?? '',
        'col_3' => $coapplicant_single_line_address ?? '',
        'col_4' => $guarantor_single_line_address ?? '',
    ];
    $personalDetails[] = [
        'col_1' => 'Native Place',
        'col_2' => '',
        'col_3' => '',
        'col_4' => '',
    ];
    $personalDetails[] = [
        'col_1' => 'PAN Number',
        'col_2' => $borrower_kyc->pan ?? '',
        'col_3' => $coapplicant_kyc->pan ?? '',
        'col_4' => $guarantor_kyc->pan ?? '',
    ];
    
    $personalDetails[] = [
        'col_1' => 'DISTANCE FROM REEYANSH DEALER OFFICE',
        'col_2' => '',
        'col_3' => '',
        'col_4' => '',
    ];
    $personalDetails[] = [
        'col_1' => 'FI STATUS',
        'col_2' => '',
        'col_3' => '',
        'col_4' => '',
    ];
    $personalDetails[] = [
        'col_1' => 'RELATION WITH APPLICANT',
        'col_2' => 'SELF',
        'col_3' => isset($coapplicant_kyc->relation_with_borrower) ? strtoupper($coapplicant_kyc->relation_with_borrower) : '',
        'col_4' => isset($guarantor_kyc->relation_with_borrower) ? strtoupper($guarantor_kyc->relation_with_borrower) : '',
    ];

    $personalDetails[] = [
        'col_1' => 'Residence since (Years)',
        'col_2' => isset($borrower_address->years_living) ? round($borrower_address->years_living) : '',
        'col_3' => isset($coapplicant_occupation->years_living) ? round($coapplicant_occupation->years_living) : '',
        'col_4' => isset($guarantor_occupation->years_living) ? round($guarantor_occupation->years_living) : '',
    ];


    $product_details = ProductModel::where('dealer_id', $loan_basic_details->dealer->id)->where('name',  $loan_basic_details->model)->first();
    $assetDetails[] = [
        "col_1" => 'VEHICLE SEGMENT',
        "col_2" => 'ELECTRONIC VEHICLE',
        "col_3" => 'VEHICLE MODEL',
        "col_4" => $loan_basic_details->model ?? '',
    ];
    $assetDetails[] = [
        "col_1" => 'VEHICLE MANUFACTURE',
        "col_2" => isset($product_details) ? $product_details->manufacture_name : '',
        "col_3" => 'DLX',
        "col_4" => isset($product_details) ? $product_details->battery_type : '',
    ];
    $assetDetails[] = [
        "col_1" => 'Cost of Vehicle (Ex-Showroom Price)',
        "col_2" => isset($product_details) ? $product_details->ex_showroom_price : '',
        "col_3" => 'Cost of Vehicle (On-Road Price)',
        "col_4" => '',
    ];
    $assetDetails[] = [
        "col_1" => 'COST OF VEHICLE (On Road / Ex-showroom)',
        "col_2" => '',
        "col_3" => 'MFG YEAR',
        "col_4" => '',
    ];
    $assetDetails[] = [
        "col_1" => 'LOAN AMOUNT REQUESTED',
        "col_2" => $loan_basic_details->loan_amount ?? '',
        "col_3" => 'VEHICLE INSURANCE AMOUNT (IF FUNDED)',
        "col_4" => '',
    ];
    $assetDetails[] = [
        "col_1" => 'AMOUNT OF MARGIN',
        "col_2" => '',
        "col_3" => 'VEHICLE INSURANCE AMOUNT (IF FUNDED)',
        "col_4" => '',
    ];
    $assetDetails[] = [
        "col_1" => 'NET LOAN AMOUNT',
        "col_2" => $loan_basic_details->loan_amount ?? '',
        "col_3" => 'LOAN SURAKSHA AMOUNT (IF FUNDED)',
        "col_4" => '',
    ];

    $assetDetails[] = [
        "col_1" => 'TOTAL NET LTV %',
        "col_2" => '',
        "col_3" => 'LOAN SURAKSHA AMOUNT (IF FUNDED)',
        "col_4" => '',
    ];
    $assetDetails[] = [
        "col_1" => 'Gross Loan Amt. (Including Loan Suraksha & Insurance)',
        "col_2" => $loan_basic_details->loan_amount ?? '',
        "col_3" => 'IRR',
        "col_4" => '',
    ];
    $assetDetails[] = [
        "col_1" => 'Gross LTV % (Including Loan Suraksha & Insurance)',
        "col_2" => '',
        "col_3" => 'INSURANCE COMPANY NAME',
        "col_4" => '',
    ];
    $assetDetails[] = [
        "col_1" => 'LTV AS PER POLICY',
        "col_2" => '',
        "col_3" => 'Dealer Quotation Availbale',
        "col_4" => '',
    ];

    $loan_amount = isset($loan_basic_details->loan_amount) ? floatval($loan_basic_details->loan_amount) : 0;
    $loan_tenure = isset($loan_basic_details->loan_term) ? $loan_basic_details->loan_term : 0;
    $interest_rate = isset($product_details) ? floatval($product_details->interest) : 17.5;

    $emi = $this->helper_controller->calculateFlatInterestEMI($loan_amount, $interest_rate, $loan_tenure);
    // return round($emi, 2);
    $assetDetails[] = [
        "col_1" => 'TENURE REQUEST (IN MONTHS)',
        "col_2" => $loan_basic_details->loan_term ?? '',
        "col_3" => 'FINAL EMI AMOUNT',
        "col_4" => '',
    ];
    
    $borrower_occupation = $loan_basic_details->latestBorrowerOccupation;   
    $businessProfile[] = [
        "col_1" => 'Nature of Work',
        "col_2" => isset($borrower_occupation) ? $borrower_occupation->current_occupation : '',
        "col_3" => 'Current Business Since',    
        "col_4" => '',
    ];
    $businessProfile[] = [
        "col_1" => 'Working Since',
        "col_2" => '',
        "col_3" => 'Agricalture land',
        "col_4" => '',
    ];
    $businessProfile[] = [
        "col_1" => 'Monthly Income',
        "col_2" => isset($borrower_occupation) ? $borrower_occupation->monthly_earning : '',
        "col_3" => 'Vehicle used by',
        "col_4" => '',
    ];
    
    $marketReference[] = [
        "col_1" => 'Name of Person',
        "col_2" => 'Address',
        "col_3" => 'Contact No',
        "col_4" => 'Relation with Applicant',
    ];

    $reference_details = BorrowerReferenceDetails::where('borrower_id', $loan_basic_details->id)->where('is_verified_1', true)->where('is_verified_2', true)->first();
    
    $marketReference[] = [
        "col_1" => isset($reference_details) ? $reference_details->reference_1 : '',
        "col_2" => isset($reference_details) ? $reference_details->address_1 : '',
        "col_3" => isset($reference_details) ? $reference_details->mobile_1 : '',
        "col_4" => isset($reference_details) ? $reference_details->relation_1 : '',
    ];

    $marketReference[] = [
        "col_1" => isset($reference_details) ? $reference_details->reference_2 : '',
        "col_2" => isset($reference_details) ? $reference_details->address_2 : '',
        "col_3" => isset($reference_details) ? $reference_details->mobile_2 : '',
        "col_4" => isset($reference_details) ? $reference_details->relation_2 : '',
    ];

    $dealBackGround[] = [
        "col_1" => 'Applicant Profile',
        "col_2" => '',
        "col_3" => '',
        "col_4" => '',
    ];
    
    $dealBackGround[] = [
        "col_1" => 'Co applicant Profile',
        "col_2" => '',
        "col_3" => '',
        "col_4" => '',
    ];
    $dealBackGround[] = [
        "col_1" => 'Guarantor Profile',
        "col_2" => '',
        "col_3" => '',
        "col_4" => '',
    ];


    // return $personalDetails;

    // Define the Excel file name
    $fileName = 'Sourcing_Details_' . $borrower_id . '.xlsx';
    
    // Store the Excel file in the storage
    Excel::store(new class($sourcingData, $personalDetails, $assetDetails, $businessProfile, $marketReference, $dealBackGround) implements \Maatwebsite\Excel\Concerns\WithEvents {
        protected $sourcingData;
        protected $personalDetails;
        protected $assetDetails;
        protected $businessProfile;
        protected $marketReference;
        protected $dealBackGround;

        public function __construct(array $sourcingData, array $personalDetails, array $assetDetails, array $businessProfile, array $marketReference, array $dealBackGround)
        {
            $this->sourcingData = $sourcingData;
            $this->personalDetails = $personalDetails;
            $this->assetDetails = $assetDetails;
            $this->businessProfile = $businessProfile;
            $this->marketReference = $marketReference;
            $this->dealBackGround = $dealBackGround;
        }

        public function registerEvents(): array
        {
            return [
                \Maatwebsite\Excel\Events\AfterSheet::class => function (\Maatwebsite\Excel\Events\AfterSheet $event) {
                    $sheet = $event->sheet->getDelegate();

                    // Auto-size columns
                    foreach (range('A', 'D') as $column) {
                        $sheet->getColumnDimension($column)->setAutoSize(true);
                    }
                    
                    // Set title and format
                    $sheet->mergeCells('A1:D1');
                    $sheet->setCellValue('A1', 'SOURCING DETAIL');
                    $sheet->getStyle('A1')->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        ],
                    ]);

                    // Fill data
                    $row = 2;
                    foreach ($this->sourcingData as $item) {
                        $sheet->setCellValue('A' . $row, $item['col_1']);
                        $sheet->setCellValue('B' . $row, $item['col_2']);
                        $sheet->setCellValue('C' . $row, $item['col_3']);
                        $sheet->setCellValue('D' . $row, $item['col_4']);
                        $row++;
                    }

                    $sheet->mergeCells('A'.$row.':D'.$row);
                    $sheet->setCellValue('A'.$row, 'PERSONAL DETAIL');
                    $sheet->getStyle('A'.$row)->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        ],
                    ]);
                    
                    $row++;
                    
                    $sheet->setCellValue('A' . $row, "PARTICULAR");
                    $sheet->setCellValue('B' . $row, "APPLICANT");
                    $sheet->setCellValue('C' . $row, "CO-APPLICANT");
                    $sheet->setCellValue('D' . $row, "GAURANTOR");
                    $sheet->getStyle('A'.$row.':D'.$row)->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        ],
                    ]);

                    $row++;
                    foreach ($this->personalDetails as $item) {
                        $sheet->setCellValue('A' . $row, $item['col_1']);
                        $sheet->setCellValue('B' . $row, $item['col_2']);
                        $sheet->setCellValue('C' . $row, $item['col_3']);
                        $sheet->setCellValue('D' . $row, $item['col_4']);
                        $row++;
                    }

                    $sheet->mergeCells('A'.$row.':D'.$row);
                    $sheet->setCellValue('A'.$row, 'FINANCE & ASSET DETAIL (New Vehicle)');
                    $sheet->getStyle('A'.$row)->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        ],
                    ]);

                    $row++;

                    foreach ($this->assetDetails as $item) {
                        $sheet->setCellValue('A' . $row, $item['col_1']);
                        $sheet->setCellValue('B' . $row, $item['col_2']);
                        $sheet->setCellValue('C' . $row, $item['col_3']);
                        $sheet->setCellValue('D' . $row, $item['col_4']);
                        $row++;
                    }

                    $sheet->mergeCells('A'.$row.':D'.$row);
                    $sheet->setCellValue('A'.$row, 'BUSINESS PROFILE');
                    $sheet->getStyle('A'.$row)->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        ],
                    ]);

                    $row++;
                    
                    $sheet->mergeCells('A'.$row.':D'.$row);
                    $sheet->setCellValue('A'.$row, 'SELF EMPLOYED - Professional or Non Professional');
                    $sheet->getStyle('A'.$row)->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        ],
                    ]);

                    $row++;
                    foreach ($this->businessProfile as $item) {
                        $sheet->setCellValue('A' . $row, $item['col_1']);
                        $sheet->setCellValue('B' . $row, $item['col_2']);
                        $sheet->setCellValue('C' . $row, $item['col_3']);
                        $sheet->setCellValue('D' . $row, $item['col_4']);
                        $row++;
                    }
                    
                    $sheet->mergeCells('A'.$row.':D'.$row);
                    $sheet->setCellValue('A'.$row, 'MARKET REFERENCE');
                    $sheet->getStyle('A'.$row)->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        ],
                    ]);
                    
                    $row++;
                    foreach ($this->marketReference as $item) {
                        $sheet->setCellValue('A' . $row, $item['col_1']);
                        $sheet->setCellValue('B' . $row, $item['col_2']);
                        $sheet->setCellValue('C' . $row, $item['col_3']);
                        $sheet->setCellValue('D' . $row, $item['col_4']);
                        $row++;
                    }

                    $sheet->mergeCells('A'.$row.':D'.$row);
                    $sheet->setCellValue('A'.$row, 'Deal Background  (IN Short)');
                    $sheet->getStyle('A'.$row)->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        ],
                    ]);
                    
                    $row++;
                    foreach ($this->dealBackGround as $item) {
                        $sheet->setCellValue('A' . $row, $item['col_1']);
                        $sheet->setCellValue('B' . $row, $item['col_2']);
                        $sheet->setCellValue('C' . $row, $item['col_3']);
                        $sheet->setCellValue('D' . $row, $item['col_4']);
                        $row++;
                    }

                    $sheet->mergeCells('A'.$row.':D'.$row);
                    $sheet->setCellValue('A'.$row, 'FLEET LIST (If Applicable)');
                    $sheet->getStyle('A'.$row)->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        ],
                    ]);
                    
                    $row++;
                    
                    $sheet->setCellValue('A' . $row, "Owner name & relation with applicant");
                    $sheet->setCellValue('B' . $row, "Make /Model/Veh. No.");
                    $sheet->setCellValue('C' . $row, "Free /Finance, Seasoning paid/total");
                    $sheet->setCellValue('D' . $row, "Loan Amount / Status ETR/GTR/STR/PTR");
                    $sheet->getStyle('A'.$row.':D'.$row)->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        ],
                    ]);
                    $row++;
                    for($i=0; $i<2; $i++) {

                        $sheet->setCellValue('A' . $row, '');
                        $sheet->setCellValue('B' . $row, '');
                        $sheet->setCellValue('C' . $row, '');
                        $sheet->setCellValue('D' . $row, '');
                        $row++;
                    }
                    
                    $row++;

                    $sheet->mergeCells('A'.$row.':D'.$row);
                    $sheet->setCellValue('A'.$row, 'CIBIL JUSTIFICATION (If Applicable)');
                    $sheet->getStyle('A'.$row)->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        ],
                    ]);
                    
                    $row++;
                    
                    $sheet->setCellValue('A' . $row, "App./ Co-app/ G'tor NAME");
                    $sheet->setCellValue('B' . $row, "OWNERSHIP TYPE");
                    $sheet->setCellValue('C' . $row, "DPD  & OD Amount");
                    $sheet->setCellValue('D' . $row, "Justification");
                    $sheet->getStyle('A'.$row.':D'.$row)->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        ],
                    ]);
                    $row++;
                    for($i=0; $i<2; $i++) {

                        $sheet->setCellValue('A' . $row, '');
                        $sheet->setCellValue('B' . $row, '');
                        $sheet->setCellValue('C' . $row, '');
                        $sheet->setCellValue('D' . $row, '');
                        $row++;
                    }
                },
            ];
        }
    }, 'public/Camsheet/' . $fileName);

    // Return the download URL
    // $downloadUrl = Storage::url('Camsheet/'.$fileName);
    // $downloadUrl = 'storage/Camsheet/'.$fileName;
    $downloadUrl = 'http://13.200.65.87/storage/Camsheet/'.$fileName;
    return $this->helper_controller->send_response('success', "File generated successfully", 200, ['download_url' => $downloadUrl]);
}

    public function get_borrower_iot_details($borrower_id = null) {
        try {
            if(!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $borrower_iot_details = BorrowerIotDetails::where('borrower_id', $borrower_id)->first();
            if(!$borrower_iot_details) {
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

    public function get_loan_status_list() {
        // 'NEW', 'APPROVED', 'REJECTED', 'HOLD', 'AWAITED', 'INPROCESS', 'DISBURSED', 'CLOSED', 'INTRANSIT', 'SOFT_APPROVED', 'CREDITCHECK', 'FI', 'CAM_REPORT'
        $statuses = [
            'NEW',
            // 'APPROVED',
            'CANCELLED',
            // 'CLOSED',
            'DISBURSED',
            // 'HOLD',
            'REJECTED' 
        ];

        return $this->helper_controller->send_response('success', "Loan statuses fetched", 200, $statuses);
    }

    public function get_borrower_disbursement_details($borrower_id = null) {
        try {
            if(!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $disbursement_details = DisbursementDetails::select(
                                        [
                                            'disbursement_details.*',
                                            'lenders.name as lender_name'
                                        ])
                                        ->leftJoin('lenders', DB::raw('CAST(disbursement_details.lender_id AS INTEGER)'), '=', 'lenders.id')
                                        ->where('borrower_id', $borrower_id)->first();
            if(!$disbursement_details) {
                return [];
            }

            if(isset($disbursement_details->disbursement_date) && !empty($disbursement_details->disbursement_date)) {
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

    public function get_borrower_lender_details($borrower_id = null) {
        try {
            if(!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $borrower_lender_details = LenderBorrowerGroup::where('borrower_id', $borrower_id)->first();
            if(!$borrower_lender_details) {
                return [];
            }

            $lender = Lenders::find($borrower_lender_details->lender_id);
            if(!$lender) {
                return [];
            }

            $lender_name = $lender->name ?? '';
            $lender_unq = $lender->lender_unq ?? '';

            $response_name = $lender_name . ($lender_unq ? ' ('. $lender_unq .')' : '');
            return [
                'lender' => $response_name,
            ];

        } catch (Exception $e) {
            return [];
        }
    }
    public function get_borrower_account_details($borrower_id = null) {
        try {
            if(!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $account_detail = BorrowerAccountDetails::where('borrower_id', $borrower_id)->first();
            if(!$account_detail) {
                return [];
            }

            $loan_basic_details = LoanBasicDetails::find($borrower_id);
            if(!$loan_basic_details) {
                return [];
            }

            return [
                'emi_debit_consent' =>  $loan_basic_details->emi_debit_consent ? 'Accepted' : '',
                'account_holder_name' => $account_detail->account_holder_name,
                'account_number' => $account_detail->account_number,
                'ifsc' => $account_detail->ifsc,
                'bank_name' => $account_detail->bank_name,
                'branch_code' => $account_detail->branch_code,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    public function get_borrower_product_scheme($borrower_id) {
        try {
            if (is_null($borrower_id)) {
                return $this->helper_controller->send_response('failed', "Borrower id is required", 422);
            }

            $loan_basic_details = LoanBasicDetails::with([
                'dealer'])->where('borrower_id', $borrower_id)->first();
            if(!$loan_basic_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower", 422);
            }
            $dealer_details = $loan_basic_details->dealer;

            if(!empty($loan_basic_details->model_id)) {

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
                'interest' => $product_details->interest ?? '',
                'manufacturer_name' => $product_details->manufacture_name ?? '',
                'min_loan_amount' => $product_details->min_loan_amount ?? '',
                'max_loan_amount' => $product_details->max_loan_amount ?? '',
                'min_loan_tenure' => $product_details->min_loan_tenure ?? '',
                'max_loan_tenure' => $product_details->max_loan_tenure ?? '',
                'processing_fee' => $product_details->processing_fee ?? '',
                'subvention_amount' => $product_details->subvention_amount ?? '',
                'fldg' => $product_details->fldg ?? '',
                'iot' => $product_details->iot ?? '',
                'insurance_1st_year' => $product_details->insurance_1st_year ?? '',
                'insurance_1nd_year' => $product_details->insurance_1nd_year ?? '',
            ];
        
            return $this->helper_controller->send_response('success', "Product scheme fetched", 200, $product_scheme);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function get_borrower_field_inspection_details($borrower_id = null) {
        try {
            if(!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $fi_details = FieldInspectionDetails::where('borrower_id', $borrower_id)->first();
            if(!$fi_details) {
                return [];
            }
            
            return [
                'name' => $fi_details->name ?? '',
                'mobile' => $fi_details->mobile ?? '',
                'field_verification_status' => $fi_details->is_inspection_verified ?? '',
                'verification_time' => isset($fi_details->verification_time) ? date('d-m-Y H:i:s', strtotime($fi_details->verification_time)) : '',
                'field_inspection_image' => $fi_details->fi_image ?? '',
                'latitude' => $fi_details->latitude ?? '',
                'longitude' => $fi_details->longitude ?? '',
                'distance' => $fi_details->distance ?? '',
                'address' => $fi_details->address ?? ''
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    public function get_borrower_loan_scheme($borrower_id = null) {
        try {
            if(!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $loan_scheme = BorrowerLoanScheme::where('borrower_id', $borrower_id)->first();
            if(!$loan_scheme) {
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
                'down_payment_amount' => $loan_scheme->down_payment_amount ?? 0,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    public function get_borrower_loan_chart($borrower_id = null) {
        try {
            if(!isset($borrower_id) || empty($borrower_id)) {
                return [];
            }

            $emiSchedules = BorrowerRepaymentSchedule::select([
                            'id', 'payment_status', 'emi_amount', 'number_of_installment', 'principal_amount', 'interest_amount',
                            'fine_amount', 'total_payment', 'receipt_no', 'payment_date', 'due_date', 'payment_mode',
                            'reference', 'remarks',
                        ])->where('borrower_id', $borrower_id)
                        ->orderBy('id', 'asc')
                        ->get();

            if ($emiSchedules->isEmpty()) {
                return []; 
            }

            // Map each schedule to an array of its properties
            $chartData = $emiSchedules->map(function($schedule) {
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

    public function fetchBorrowers(Request $request)
    {
        try {
            $userId = auth()->id();
            $numberOfRecords = $request->input('number_of_records', 10);
            $page = $request->input('page', 1);

            $query = LoanBasicDetails::query()
                ->with([
                    'user:id,name,mobile',
                    'latestBorrowerKyc:id,borrower_id,name,mobile,pan,aadhar,dob_date as date_of_birth,gender,email,marital_status,father_name,husband_name as spouses_name,driving_license_no',
                    'dealer:id,dealer_unq,name'
                ])
                ->leftJoin('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
                ->leftJoin('users', 'loan_basic_details.user_id', '=', 'users.id')
                ->select('loan_basic_details.*');

            if ($request->filled('category')) {
                match ($request->category) {
                    'B2B' => $query->whereNotNull('loan_basic_details.executive_id'),
                    'B2C' => $query->whereNull('loan_basic_details.executive_id'),
                    default => abort(422, 'Invalid category')
                };
            }

            $this->applyDateFilters($query, $request);
            $this->applyBorrowerFilters($query, $request, $userId);

            $borrowers = $query->orderByDesc('loan_basic_details.created_at')
                ->paginate($numberOfRecords, ['*'], 'page', $page);


            $transformed = $this->transformBorrowers($borrowers->items());

            return response()->json([
                'status' => 'success',
                'message' => 'Borrower details fetched',
                'data' => $transformed,    
                'current_page' => $borrowers->currentPage(),
                'total' => $borrowers->total(),
                'last_page' => $borrowers->lastPage(),
                'per_page' => $borrowers->perPage(),
                'from' => $borrowers->firstItem(),
                'to' => $borrowers->lastItem()
            
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error fetching borrowers',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    private function applyDateFilters(&$query, $request)
    {
        if ($request->filled('start_date')) {
            $query->where('loan_basic_details.created_at', '>=', Carbon::parse($request->start_date)->startOfDay());
        }

        if ($request->filled('end_date')) {
            $query->where('loan_basic_details.created_at', '<=', Carbon::parse($request->end_date)->endOfDay());
        }

        if ($request->filled('application_date')) {
            $query->whereDate('loan_basic_details.created_at', $request->application_date);
        }

        if ($request->filled('month')) {
            $query->whereMonth('loan_basic_details.created_at', $request->month);
        }

        if ($request->filled('year')) {
            $query->whereYear('loan_basic_details.created_at', $request->year);
        }
    }

    private function applyBorrowerFilters(&$query, $request, $userId)
    {
        if ($lsp = LoanServiceProvider::where('lsp_auth_id', $userId)->first()) {
            $executiveIds = LspExecutiveDetails::where('lsp_id', $lsp->id)->pluck('id');
            $query->whereIn('loan_basic_details.executive_id', $executiveIds);
        }

        if ($dealer = Dealer::where('dealer_auth_id', $userId)->first()) {
            $query->where('loan_basic_details.dealer_id', $dealer->dealer_unq);
        }

        if ($request->filled('dealer_id')) {
            $dealer = Dealer::find($request->dealer_id);
            if ($dealer) {
                $query->where('loan_basic_details.dealer_id', $dealer->dealer_unq);
            }
        }

        if ($request->filled('lsp_id')) {
            $executiveIds = LspExecutiveDetails::where('lsp_id', $request->lsp_id)->pluck('id');
            $query->whereIn('loan_basic_details.executive_id', $executiveIds);
        }

        if ($request->filled('borrower_id')) {
            $query->where('loan_basic_details.borrower_id', 'like', '%' . $request->borrower_id . '%');
        }

        if ($request->filled('borrower_name')) {
            $name = strtolower($request->borrower_name);
            $query->where(function ($q) use ($name) {
                $q->whereRaw('LOWER(borrower_kycs.name) LIKE ?', ["%$name%"])
                  ->orWhereRaw('LOWER(users.name) LIKE ?', ["%$name%"]);
            });
        }

        if ($request->filled('mobile_no')) {
            $query->where(function ($q) use ($request) {
                $q->where('borrower_kycs.mobile', 'like', '%' . $request->mobile_no . '%')
                  ->orWhere('users.mobile', 'like', '%' . $request->mobile_no . '%');
            });
        }

        if ($request->filled('pan_no')) {
            $query->where('borrower_kycs.pan', 'like', '%' . $request->pan_no . '%');
        }

        if ($request->filled('aadhar_no')) {
            $query->where('borrower_kycs.aadhar', 'like', '%' . $request->aadhar_no . '%');
        }

        if ($request->filled('dob')) {
            $query->whereDate('borrower_kycs.dob_date', $request->dob);
        }

        if ($request->filled('status')) {
            $query->where('loan_basic_details.status', $request->status);
        }
    }

    private function transformBorrowers(array $borrowers): array
    {
        return collect($borrowers)->map(function ($borrower) {
            $borrowerKyc = $borrower->borrowerKyc;
            $user = $borrower->user;
            $dealer = $borrower->dealer;

            return [
                'id'              => $borrower->id,
                'borrower_id'     => $borrower->borrower_id,
                'status'          => $borrower->status,
                'created_at'      => optional($borrower->created_at)->toDateTimeString(),
                'borrower_name'   => $borrowerKyc->name ?? $user->name ?? '',
                'mobile'          => $borrowerKyc->mobile ?? $user->mobile ?? '',
                'pan'             => $borrowerKyc->pan ?? '',
                'aadhar'          => $borrowerKyc->aadhar ?? '',
                'dob'             => $borrowerKyc->date_of_birth ?? '',
                'gender'          => $borrowerKyc->gender ?? '',
                'email_id'        => $borrowerKyc->email ?? '',
                'marital_status'  => $borrowerKyc->marital_status ?? '',
                'father_name'     => $borrowerKyc->father_name ?? '',
                'spouse_name'     => $borrowerKyc->spouses_name ?? '',
                'driving_license' => $borrowerKyc->driving_license_no ?? '',
                'dealer_name'     => $dealer->name ?? '',
                'dealer_id'       => $dealer->dealer_unq ?? '',
                'user_name'       => $user->name ?? ''
            ];
        })->toArray();
    }
}
