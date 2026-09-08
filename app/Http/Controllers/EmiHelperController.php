<?php

namespace App\Http\Controllers;

use App\Models\BorrowerLoanScheme;
use App\Models\BorrowerRepaymentSchedule;
use App\Models\LoanBasicDetails;
use App\Models\ProductModel;
use App\Traits\CalculatesLoanSchedule;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EmiHelperController extends Controller
{
    use CalculatesLoanSchedule;
    
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function calculateEffectiveIRR($loan_amount, $flat_interest, $tenure)
    {
        $flatInterest = ($loan_amount * $flat_interest / 100) * ($tenure / 12);
        $totalRepaymentAmount = $loan_amount + $flatInterest;
    
        $low = 0;
        $high = 1;
        $accuracy = 0.00001;
        $maxIterations = 200;
        $iterations = 0;
    
        while ($high - $low > $accuracy && $iterations < $maxIterations) {
            $mid = ($low + $high) / 2;
            $monthlyInterestRate = $mid / 12;
    
            $emi = $loan_amount * $monthlyInterestRate / (1 - pow(1 + $monthlyInterestRate, -$tenure));
            $totalRepayment = $emi * $tenure;
    
            if ($totalRepayment < $totalRepaymentAmount) {
                $low = $mid;
            } else {
                $high = $mid;
            }
            $iterations++;
        }
    
        return round($mid * 100, 2);
    }
    
    public function generateReducingInterestSchedule($loan_amount, $flat_interest, $tenure)
    {
        $effectiveIRR = $this->calculateEffectiveIRR($loan_amount, $flat_interest, $tenure);
        $monthlyInterestRate = ($effectiveIRR / 100) / 12;
        $emi = ceil($loan_amount * $monthlyInterestRate / (1 - pow(1 + $monthlyInterestRate, -$tenure)));
    
        $schedule = [];
        $remainingBalance = $loan_amount;
        $totalInterest = 0;
    
        for ($month = 1; $month <= $tenure; $month++) {
            $interestPortion = $remainingBalance * $monthlyInterestRate;
            $roundedInterest = round($interestPortion);
            $principalPortion = $emi - $roundedInterest; // Ensuring Principal + Interest = EMI
    
            if ($month == $tenure) {
                $principalPortion = $remainingBalance;
                $emi = $principalPortion + $roundedInterest;
            }
    
            $remainingBalance -= $principalPortion;
            $totalInterest += $roundedInterest;
    
            $schedule[] = [
                'month' => $month,
                'principal' => round($principalPortion),
                'interest' => $roundedInterest,
                'total_payment' => round($emi),
                'remaining_balance' => max(0, round($remainingBalance))
            ];
        }
    
       
        $totalInterest = round(($loan_amount * $flat_interest / 100) * ($tenure / 12));
        $totalLoanAmount = $loan_amount + $totalInterest;
    
        return [
            'schedule' => $schedule,
            'total_interest' => $totalInterest,
            'total_loan_amount' => $totalLoanAmount,
            'emi_amount' => ceil($emi),
            'effective_irr' => round($effectiveIRR, 2)
        ];
    }

    // public function storeSampleEmiSchedule(Request $request, $borrower_id)
    // {
    //     try {
    //         // $validator = Validator::make($request->all(), [
    //         //     'borrower_id' => 'required|integer|exists:loan_basic_details,id',
    //         // ]);

    //         // if ($validator->fails()) {
    //         //     return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
    //         // }
            
    //         $loan_amount = 129000;
    //         $flat_interest = 17.5;
    //         $tenure = 18;
    //         // $borrower_id = $request->borrower_id;
    //         $borrower_id = $borrower_id;

    //         $emiCalculation = $this->generateReducingInterestSchedule($loan_amount, $flat_interest, $tenure);

    //         if (!isset($emiCalculation['schedule']) || empty($emiCalculation['schedule'])) {
    //             return $this->helper_controller->send_response('failed', 'EMI schedule generation failed.', 422);
    //         }
            
    //         $emi_chart_exist = BorrowerRepaymentSchedule::where('borrower_id', $request->borrower_id)->first();
    //         if($emi_chart_exist){
    //             return $this->helper_controller->send_response('failed', 'EMI chart already exist', 422);
    //         } 

    //         $tenure_count = 0;
    //         $start_date = Carbon::now()->addMonth()->startOfMonth(); // Assuming first EMI due next month.

    //         foreach ($emiCalculation['schedule'] as $emi) {
    //             $tenure_count++;

    //             $schedule = new BorrowerRepaymentSchedule();
    //             $schedule->borrower_id = $borrower_id;
    //             $schedule->payment_status = 'UNPAID';
    //             $schedule->emi_amount = $emi['total_payment'];
    //             $schedule->number_of_installment = $tenure_count;
    //             $schedule->principal_amount = $emi['principal'];
    //             $schedule->interest_amount = $emi['interest'];
    //             $schedule->fine_amount = 0;
    //             $schedule->due_date = $start_date->copy()->addMonths($tenure_count - 1);
    //             // $schedule->user_id = auth()->id();
    //             // $schedule->ip = request()->ip();
    //             // $schedule->save();
    //         }

    //         return $this->helper_controller->send_response('success', 'EMI schedule stored successfully.', 200);
    //     } catch (Exception $e) {
    //         return $this->helper_controller->send_response('failed', 'Error: ' . $e->getMessage(), 500);
    //     }
    // }

    public function getEmiSchedule_old(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'borrower_id' => 'required|integer|exists:loan_basic_details,id',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $emiSchedule = BorrowerRepaymentSchedule::select([
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
        ])->where('borrower_id', $request->borrower_id)
            ->orderBy('id', 'asc')
            ->get();

        if ($emiSchedule->isEmpty()) {
            return $this->helper_controller->send_response('failed', 'No EMI schedule found for this borrower.', 404);
        }

        return $this->helper_controller->send_response('success', 'EMI schedule retrieved successfully.', 200, $emiSchedule);
    }

    public function getEmiSchedule(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'borrower_id' => ['required', 'regex:/^[A-Z0-9-]+$/'],
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }
        
        if(is_numeric($request->borrower_id)) {
            $loan_details = LoanBasicDetails::find($request->borrower_id);
        } else {
            $loan_details = LoanBasicDetails::where('borrower_id', $request->borrower_id)->first();
        }
        
        if(!$loan_details) {
            return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
        }

        $emiSchedule = BorrowerRepaymentSchedule::select([
                            'id', 'payment_status', 'emi_amount', 'number_of_installment', 'principal_amount', 'interest_amount',
                            'fine_amount', 'total_payment', 'receipt_no', 'payment_date', 'due_date', 'payment_mode',
                            'reference', 'remarks',
                        ])
                        ->where('borrower_id', $loan_details->id)
                        ->orderBy('id', 'asc')
                        ->get();

        if ($emiSchedule->isEmpty()) {
            return $this->helper_controller->send_response('failed', 'No EMI schedule found for this borrower.', 404);
        }

        // Filter EMI schedule into different categories
        $payment_history = $emiSchedule->where('payment_status', 'PAID')->values();
        $upcoming_emi = $emiSchedule->where('payment_status', 'UNPAID')->values();
        $current_payable_emi = $upcoming_emi->first();

        // return $this->helper_controller->send_response('success', 'EMI schedule retrieved successfully.', 200, $emiSchedule);
        return $this->helper_controller->send_response('success', 'EMI schedule retrieved successfully.', 200, [
            'emis' => $emiSchedule,
            'paid_emis' => $payment_history,
            'upcoming_emis' => $upcoming_emi,
            'current_payable_emi' => $current_payable_emi]);
    }

    public function store_manual_loan_scheme(Request $request) {
        try {
            DB::beginTransaction();

            $applications = LoanBasicDetails::whereNotNull('model_id')->get();

            $count = 0;
            foreach($applications as $application) {
                $count++;
                echo $count . ".";
                echo "<br>";
                $productModel = ProductModel::where('model_unq', $application->model_id)->first();
                if($productModel) {
                    echo " Product-> " . $productModel->name;
                    echo "<br>";

                    $checkExisting = BorrowerLoanScheme::where('borrower_id', $application->id)->first();
                    if(!$checkExisting) {
                        $loan_scheme                        = new BorrowerLoanScheme();
                        $loan_scheme->borrower_id           = $application->id;
                        $loan_scheme->product_id            = $productModel->id;
                        $loan_scheme->ex_showroom_price     = $productModel->ex_showroom_price ?? 0;
                        $loan_scheme->loan_to_value_ratio   = $productModel->loan_to_value_ratio ?? '';
                        $loan_scheme->min_loan_amount       = $productModel->min_loan_amount ?? 0;
                        $loan_scheme->max_loan_amount       = $productModel->max_loan_amount ?? 0;
                        $loan_scheme->min_loan_tenure       = $productModel->min_loan_tenure ?? 0;
                        $loan_scheme->max_loan_tenure       = $productModel->max_loan_tenure ?? 0;
                        $loan_scheme->interest              = $productModel->interest ?? 0;
                        $loan_scheme->processing_fee        = $productModel->processing_fee ?? 0;
                        $loan_scheme->subvention_amount     = $productModel->subvention_amount ?? 0;
                        $loan_scheme->fldg                  = $productModel->fldg ?? 0;
                        $loan_scheme->iot                   = $productModel->iot ?? 0;
                        $loan_scheme->insurance_1st_year    = $productModel->insurance_1st_year ?? 0;
                        $loan_scheme->insurance_2nd_year    = $productModel->insurance_2nd_year ?? 0;
                        echo "Scheme Saved";
                        echo "<br>";
                        // $loan_scheme->save();
                    } else {
                        echo "Scheme Exist";
                        echo "<br>";
                    }
                }
            }

            // return $this->helper_controller->send_response('success', 'Loan scheme stored successfully', 200);
            // DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Error: ' . $e->getMessage(), 500);
        }
    }
    
}
