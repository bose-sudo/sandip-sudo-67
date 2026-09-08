<?php

namespace App\Http\Controllers;

use App\Mail\Mailer;
use App\Models\BorrowerAddress;
use App\Models\BorrowerKyc;
use App\Models\BorrowerOccupation;
use App\Models\BorrowerReferenceDetails;
use App\Models\CoapplicantAddress;
use App\Models\CoapplicantKyc;
use App\Models\CoapplicantOccupation;
use App\Models\Dealer;
use App\Models\Documents;
use App\Models\Guarantor;
use App\Models\GuarantorOccupation;
use App\Models\LoanBasicDetails;
use App\Models\Occupation;
use App\Models\ProductModel;
use App\Models\User;
use App\Models\VendorServiceApi;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Maatwebsite\Excel\Facades\Excel;
use Mpdf\Mpdf;

use App\Models\BorrowerAccountDetails;
use App\Models\BorrowerRepaymentSchedule;
use App\Models\EmiTransactions;
use App\Models\PresentmentStatusUpdate;
use App\Services\SmsService;
use App\Services\PdfSplitService;

class WebController extends Controller
{
    private $signzy_auth_key;
    protected SmsService $smsService;
    // protected $helper_controller;
    public function __construct(SmsService $smsService) {
        $this->signzy_auth_key = "";
        // $this->helper_controller = $helper_controller;
        $this->smsService = $smsService;
    }



    public function splitAgreement(
        $borrowerId,
        PdfSplitService $pdfSplitService
    ) {
        $result = $pdfSplitService->splitIfRequired($borrowerId);

        return response()->json($result);
    }

    public function send_response($status, $message, $code, $data=null) {
        $result = [
            'status' => $status,
            'message' => $message
        ];

        if(isset($data) && !empty($data)) {
            $result['data'] = $data;
        }

        return response()->json($result, $code);
    }

    public function get_user_ip() {
        $ipAddress = '';

        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ipAddress = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
            $ipAddress = $_SERVER['HTTP_X_FORWARDED'];
        } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ipAddress = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
            $ipAddress = $_SERVER['HTTP_FORWARDED'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ipAddress = $_SERVER['REMOTE_ADDR'];
        }

        return $ipAddress;
    }

    public function reference_check($str) {
        $reference_details = BorrowerReferenceDetails::where('shortend_url_1', $str)->first();
        if($reference_details) {
            // reference 1
            if($reference_details->is_verified_1 == '1' ){

                $message = "You're already verified as a reference at Finayo...";
            } else {
                $reference_details->is_verified_1 = true;
                $reference_details->save();
                $message = "Thank you for verifying yourself as a reference at Finayo...";
            }

            return view('reference')->with('ref_code', $str)->with('message', $message);
        } else {
            $reference_details = BorrowerReferenceDetails::where('shortend_url_2', $str)->first();
            if($reference_details) {
                // reference 2
                if($reference_details->is_verified_2 == '1' ){

                    $message = "You're already verified as a reference at Finayo...";
                } else {
    
                    $reference_details->is_verified_2 = true;
                    $reference_details->save();
                    $message = "Thank you for verifying yourself as a reference at Finayo...";
                }
    
                return view('reference')->with('ref_code', $str)->with('message', $message);
            } else {
                $message = "Reference code is invalid";
                return view('reference')->with('message', $message);
            }
        }
    }

    public function field_inspection($str = null) {
        if($str != '') {
            return view('field-inspection')->with('str', $str);
        } else {
            return view('field-inspection');
        }
    }

    public function calculateEMI($principal, $annualInterestRate, $tenureMonths) {
        // $principal = floatval($principal);
        // $annualInterestRate = floatval($annualInterestRate);
        // $tenureMonths = floatval($tenureMonths);
        
        // Convert annual interest rate to a monthly rate (decimal)
        $monthlyInterestRate = ($annualInterestRate / 100) / 12;
        
        // Calculate EMI using the formula
        $emi = ($principal * $monthlyInterestRate * pow((1 + $monthlyInterestRate), $tenureMonths)) / (pow((1 + $monthlyInterestRate), $tenureMonths) - 1);
        
        return $emi;
    }

    public function numberToWords($number) {
        $number = intval($number);
        $hyphen      = '-';
        $conjunction = ' and ';
        $separator   = ', ';
        $negative    = 'negative ';
        $decimal     = ' point ';
        $dictionary  = [
            0                   => 'zero',
            1                   => 'one',
            2                   => 'two',
            3                   => 'three',
            4                   => 'four',
            5                   => 'five',
            6                   => 'six',
            7                   => 'seven',
            8                   => 'eight',
            9                   => 'nine',
            10                  => 'ten',
            11                  => 'eleven',
            12                  => 'twelve',
            13                  => 'thirteen',
            14                  => 'fourteen',
            15                  => 'fifteen',
            16                  => 'sixteen',
            17                  => 'seventeen',
            18                  => 'eighteen',
            19                  => 'nineteen',
            20                  => 'twenty',
            30                  => 'thirty',
            40                  => 'forty',
            50                  => 'fifty',
            60                  => 'sixty',
            70                  => 'seventy',
            80                  => 'eighty',
            90                  => 'ninety',
            100                 => 'hundred',
            1000                => 'thousand',
            1000000             => 'million',
            1000000000          => 'billion',
            1000000000000       => 'trillion',
            1000000000000000    => 'quadrillion',
            1000000000000000000 => 'quintillion'
        ];
        
        if (!is_numeric($number)) {
            return false;
        }
        
        if (($number >= 0 && (int) $number < 0) || (int) $number < 0 - PHP_INT_MAX) {
            // overflow
            trigger_error(
                'numberToWords only accepts numbers between -' . PHP_INT_MAX . ' and ' . PHP_INT_MAX,
                E_USER_WARNING
            );
            return false;
        }

        if ($number < 0) {
            return $negative . $this->numberToWords(abs($number));
        }

        $string = $fraction = null;

        if (strpos($number, '.') !== false) {
            list($number, $fraction) = explode('.', $number);
        }

        switch (true) {
            case $number < 21:
                $string = $dictionary[$number];
                break;
            case $number < 100:
                $tens   = ((int) ($number / 10)) * 10;
                $units  = $number % 10;
                $string = $dictionary[$tens];
                if ($units) {
                    $string .= $hyphen . $dictionary[$units];
                }
                break;
            case $number < 1000:
                $hundreds  = $number / 100;
                $remainder = $number % 100;
                $string = $dictionary[$hundreds] . ' ' . $dictionary[100];
                if ($remainder) {
                    $string .= $conjunction . $this->numberToWords($remainder);
                }
                break;
            default:
                $baseUnit = pow(1000, floor(log($number, 1000)));
                $numBaseUnits = (int) ($number / $baseUnit);
                $remainder = $number % $baseUnit;
                $string = $this->numberToWords($numBaseUnits) . ' ' . $dictionary[$baseUnit];
                if ($remainder) {
                    $string .= $remainder < 100 ? $conjunction : $separator;
                    $string .= $this->numberToWords($remainder);
                }
                break;
        }

        if (null !== $fraction && is_numeric($fraction)) {
            $string .= $decimal;
            $words = [];
            foreach (str_split((string) $fraction) as $number) {
                $words[] = $dictionary[$number];
            }
            $string .= implode(' ', $words);
        }

        return $string;
    }

    public function generatePdf() {
        $data = [
            'title' => 'Welcome to Finayo',
            'content' => 'This is an example PDF.'
        ];
        // $data = $this->prepare_data_for_esign_doc(144);
        // $this->helper_controller->prepare_data_for_esign_doc(527);
        $data['borrower_details']['gender'] = 'Male';
        // return view('pdf.agreement-2', $data);
        // $pdf = Pdf::loadView('pdf.example', $data);
        // $pdf = Pdf::loadView('pdf.agreement-2', $data)->setPaper('a4', 'portrait');
        $pdf = Pdf::loadView('pdf.loan-agreement-new', $data)->setPaper('a4', 'portrait');
        return $pdf->stream();
    }

    // public function old_prepare_data_for_esign_doc($borrower_id) {
    //     date_default_timezone_set('Asia/Kolkata');
    //     $data['date_of_agreement'] = date('d-m-Y');
    //     $data['lender_details']['company_name'] = '';
    //     $data['lender_details']['company_short'] = '';
    //     $data['lender_details']['address'] = '';

    //     $web_controller = new WebController;

    //     $loan_basic_details = LoanBasicDetails::find($borrower_id);
    //     $loan_amount = isset($loan_basic_details->loan_amount) ? $loan_basic_details->loan_amount : 0;
    //     $loan_term = isset($loan_basic_details->loan_term) ? $loan_basic_details->loan_term : 0;

    //     $data['loan_details']['dealer_id']  = isset($loan_basic_details->dealer_id) ? $loan_basic_details->dealer_id : '';
    //     $data['loan_details']['dealer_name'] = 'Test Dealer';
    //     $data['loan_details']['vehicle_cost'] = '180000';
    //     $data['loan_details']['model']      = isset($loan_basic_details->model) ? $loan_basic_details->model : '';
    //     $data['loan_details']['loan_amount'] = $loan_amount;
    //     $data['loan_details']['loan_term'] = $loan_term;
    //     $data['loan_details']['product_id'] = isset($loan_basic_details->product_id) ? $loan_basic_details->product_id : '';
    //     $data['loan_details']['roi']        = '17.5';
    //     $data['loan_details']['purpose_of_loan'] = 'E-Rickshaw';
    //     $data['loan_details']['processing_fee'] = '';
    //     $data['loan_details']['application_charges'] = '';
    //     $data['loan_details']['add_delay_interest'] = '';
    //     $data['loan_details']['bouncing_charges'] = '';
    //     $data['loan_details']['pre_closure_charge'] = '4%';

    //     $emi = $web_controller->calculateEMI($loan_amount, 17.5, $loan_term);
    //     $data['loan_details']['emi'] = round($emi, 2);
    //     $data['loan_details']['loan_amount_in_words'] = $web_controller->numberToWords($loan_amount);

    //     $borrower_details = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
        
    //     $data['borrower_details']['name'] = isset($borrower_details->name) ? $borrower_details->name : '';
    //     $data['borrower_details']['father_name'] = isset($borrower_details->father_name) ? $borrower_details->father_name : '';
    //     $data['borrower_details']['mobile'] = isset($borrower_details->mobile) ? $borrower_details->mobile : '';
    //     $data['borrower_details']['dob_date'] = isset($borrower_details->dob_date) ? date('d-m-Y', strtotime($borrower_details->dob_date)) : '';
    //     $data['borrower_details']['marital_status'] = isset($borrower_details->marital_status) ? $borrower_details->marital_status : '';
    //     $data['borrower_details']['husband_name'] = isset($borrower_details->husband_name) ? $borrower_details->husband_name : '';
    //     $data['borrower_details']['gender'] = isset($borrower_details->gender) ? $borrower_details->gender : '';
    //     $data['borrower_details']['residing_at'] = isset($borrower_details->residing_at) ? $borrower_details->residing_at : 'Test Address of borrower';
    //     $data['borrower_details']['pan'] = isset($borrower_details->pan) ? $borrower_details->pan : '';
    //     $data['borrower_details']['skip_co_applicant'] = isset($borrower_details->skip_co_applicant) ? $borrower_details->skip_co_applicant : '';

    //     $co_applicant_details = CoapplicantKyc::where('borrower_id', $borrower_id)->latest()->first();

    //     $data['co_applicant_details']['name'] = isset($co_applicant_details->name) ? $co_applicant_details->name : '';
    //     $data['co_applicant_details']['father_name'] = isset($co_applicant_details->father_name) ? $co_applicant_details->father_name : '';
    //     $data['co_applicant_details']['marital_status'] = isset($co_applicant_details->marital_status) ? $co_applicant_details->marital_status : '';
    //     $data['co_applicant_details']['gender'] = isset($co_applicant_details->gender) ? $co_applicant_details->gender : '';
    //     $data['co_applicant_details']['mobile'] = isset($co_applicant_details->mobile) ? $co_applicant_details->mobile : '';
    //     $data['co_applicant_details']['husband_name'] = isset($co_applicant_details->husband_name) ? $co_applicant_details->husband_name : '';
    //     $data['co_applicant_details']['pan'] = isset($co_applicant_details->pan) ? $co_applicant_details->pan : '';
    //     $data['co_applicant_details']['dob_date'] = isset($co_applicant_details->dob_date) ? date('d-m-Y', strtotime($co_applicant_details->dob_date)) : '';

    //     $guarantor_details = Guarantor::where('borrower_id', $borrower_id)->latest()->first();

    //     $data['guarantor_details']['name'] = isset($guarantor_details->name) ? $guarantor_details->name : '';
    //     $data['guarantor_details']['father_name'] = isset($guarantor_details->father_name) ? $guarantor_details->father_name : '';
    //     $data['guarantor_details']['marital_status'] = isset($guarantor_details->marital_status) ? $guarantor_details->marital_status : '';
    //     $data['guarantor_details']['gender'] = isset($guarantor_details->gender) ? $guarantor_details->gender : '';
    //     $data['guarantor_details']['mobile'] = isset($guarantor_details->mobile) ? $guarantor_details->mobile : '';
    //     $data['guarantor_details']['pan'] = isset($guarantor_details->pan) ? $guarantor_details->pan : '';
    //     $data['guarantor_details']['dob_date'] = isset($guarantor_details->dob_date) ? date('d-m-Y', strtotime($guarantor_details->dob_date)) : '';
    //     $data['guarantor_details']['husband_name'] = isset($guarantor_details->husband_name) ? $guarantor_details->husband_name : '';

    //     return $data;
    // }
    
    public function prepare_data_for_esign_doc($borrower_id) {
        date_default_timezone_set('Asia/Kolkata');
        $data['date_of_agreement'] = date('d-m-Y');
        // $data['lender_details']['company_name'] = '';
        // $data['lender_details']['company_short'] = '';
        // $data['lender_details']['address'] = '';

        $loan_basic_details = LoanBasicDetails::find($borrower_id);
        $loan_amount        = isset($loan_basic_details->loan_amount) ? $loan_basic_details->loan_amount : 0;
        $loan_term          = isset($loan_basic_details->loan_term) ? intval($loan_basic_details->loan_term) : 0;
        $dealer_id          = $loan_basic_details->dealer_id;
        $model              = $loan_basic_details->model;

        $dealer_product_details = Dealer::join('product_models', 'dealers.id', '=', 'product_models.dealer_id')
                                    ->where('dealers.dealer_unq', $dealer_id)
                                    ->where('product_models.name', $model)
                                    ->select(
                                        'dealers.id as dealer_primary',
                                        'product_models.interest',
                                        'product_models.processing_fee',
                                        'product_models.subvention_amount',
                                        'product_models.fldg',
                                        'product_models.iot',
                                        'product_models.insurance_1st_year',
                                        'product_models.insurance_2nd_year'
                                    )
                                    ->first();

        if (!$dealer_product_details) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Invalid dealer or product',
            ], 422);
        }

        // Extract product details
        $product_interest           = $dealer_product_details->interest ?? 17.5;
        $product_processing_fee     = $dealer_product_details->processing_fee ?? 5000;
        $product_subvention_amount  = $dealer_product_details->subvention_amount ?? 5000;
        $product_fldg               = $dealer_product_details->fldg ?? 5000;
        $product_iot                = $dealer_product_details->iot ?? 3000;
        $product_insurance_1st_year = $dealer_product_details->insurance_1st_year ?? 7000;
        $product_insurance_2nd_year = $dealer_product_details->insurance_2nd_year ?? 6000;

        $roi = $product_interest;
        $interest = $roi;
        $tenure = $loan_term;
        $iot_amount = $product_iot;
        if($tenure > 0 && $tenure <= 15) {
            $second_year_insurance = 0;
        } elseif($tenure > 15 && $tenure <= 24) {
            $second_year_insurance = $product_insurance_2nd_year;
        } else {
            $second_year_insurance = 0;
        }

        $net_loan_amount = $loan_amount + $iot_amount + $second_year_insurance;
        $processing_fee = $product_processing_fee;
        $subvention_amount = $product_subvention_amount;
        $fldg = $product_fldg;
        $first_year_insurance = $product_insurance_1st_year;

        $net_loan_amount = $loan_amount + $product_iot + $second_year_insurance;

        $net_disbursal = $net_loan_amount - ($product_processing_fee + $product_subvention_amount + $product_insurance_1st_year + $product_fldg + $product_iot + $second_year_insurance);

        // $net_disbursal = $loan_amount - ($processing_fee + $subvention_amount + $first_year_insurance);
        $emi_amount = $this->calculateFlatInterestEMI($net_loan_amount, $interest, $tenure);
        // $emi = $this->calculateFlatInterestEMI($loan_amount, 17.5, $loan_term);

        
        $loan_amount = $net_loan_amount;
        $emi = $emi_amount;

        $dealer_name        = '';
        if($loan_basic_details) {
            $dealer_id = $loan_basic_details->dealer_id;
            $model_name = $loan_basic_details->model;

            $dealer_details = Dealer::where('dealer_unq', $dealer_id)->first();
            if($dealer_details) {
                $dealer_name = $dealer_details->name;
                $dealer_primary_key = $dealer_details->id;

                $product_details = ProductModel::where('dealer_id', $dealer_primary_key)->where('name', $model_name)->first();
                if($product_details) {
                    $model_name = $product_details->name;
                    $ex_showroom_price = $product_details->ex_showroom_price;
                    $product_id = $product_details->model_unq;
                    $product_type = $product_details->product_type;
                }
            }

        }
        

        $data['loan_details'] = [
            'dealer_id' => isset($loan_basic_details->dealer_id) ? $loan_basic_details->dealer_id : '',
            'dealer_name' => isset($dealer_name) ? $dealer_name : '',
            'vehicle_cost' => isset($ex_showroom_price) ? $ex_showroom_price : 0,
            'model' => isset($model_name) ? $model_name : '',
            'loan_amount' =>$loan_amount,
            'loan_term' =>$loan_term,
            'product_id' =>isset($product_id) ? $product_id : '',
            'roi' => $interest,
            'purpose_of_loan' => isset($product_type) ? $product_type : '',
            'processing_fee' =>'',
            'application_charges' =>'',
            'add_delay_interest' =>'',
            'bouncing_charges' =>'',
            'pre_closure_charge' =>'4%',
            'emi' =>round($emi, 2),
            'loan_amount_in_words' =>$this->numberToWords($loan_amount),
        ];

        $borrower_details = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
        
        $data['borrower_details']['basic'] = [
            'name'              => isset($borrower_details->name) ? $borrower_details->name : '',
            'father_name'       => isset($borrower_details->father_name) ? $borrower_details->father_name : '',
            'mobile'            => isset($borrower_details->mobile) ? $borrower_details->mobile : '',
            'email'             => isset($borrower_details->email) ? $borrower_details->email : '',
            'dob_date'          => isset($borrower_details->dob_date) ? date('d-m-Y', strtotime($borrower_details->dob_date)) : '',
            'marital_status'    => isset($borrower_details->marital_status) ? $borrower_details->marital_status : '',
            'husband_name'      => isset($borrower_details->husband_name) ? $borrower_details->husband_name : '',
            'gender'            => isset($borrower_details->gender) ? $borrower_details->gender : '',
            'residing_at'       => isset($borrower_details->residing_at) ? $borrower_details->residing_at : '',
            'pan'               => isset($borrower_details->pan) ? $borrower_details->pan : '',
            'skip_co_applicant' => isset($borrower_details->skip_co_applicant) ? $borrower_details->skip_co_applicant : ''
        ];

        $borrower_address = BorrowerAddress::where('borrower_id', $borrower_id)->latest()->first();
        $data['borrower_details']['address'] = [
            'resident_type'     => isset($borrower_address->resident_type) ? $borrower_address->resident_type : '',
            'current_address'   => isset($borrower_address->current_address) ? $borrower_address->current_address : '',
            'years_living'      => isset($borrower_address->years_living) ? intval($borrower_address->years_living) : '',
            'permanent_address' => isset($borrower_address->permanent_address) ? $borrower_address->permanent_address : '',
            'family_members'    => isset($borrower_address->family_members) ? $borrower_address->family_members : '',
            'earning_members'   => isset($borrower_address->earning_members) ? $borrower_address->earning_members : '',
            'family_income'     => isset($borrower_address->family_income) ? $borrower_address->family_income : '',
        ];

        $borrower_occupation = BorrowerOccupation::where('borrower_id', $borrower_id)->latest()->first();
        if(isset($borrower_occupation->occupation_type) && !empty($borrower_occupation->occupation_type)) {
            $b_occupation_type = $this->get_occupation_type($borrower_occupation->occupation_type);
        }
        $data['borrower_details']['occupation'] = [
            'occupation_type'       => isset($b_occupation_type) ? $b_occupation_type : '',
            'current_occupation'    => isset($borrower_occupation->current_occupation) ? $borrower_occupation->current_occupation : '',
            'driving_experience'    => isset($borrower_occupation->driving_experience) ? intval($borrower_occupation->driving_experience) : '',
            'vehicle_type'          => isset($borrower_occupation->vehicle_type) ? $borrower_occupation->vehicle_type : '',
            'monthly_earning'       => isset($borrower_occupation->monthly_earning) ? $borrower_occupation->monthly_earning : '',
            'expected_monthly_earning' => isset($borrower_occupation->expected_monthly_earning) ? $borrower_occupation->expected_monthly_earning : '',
            'extra_source_income'   => isset($borrower_occupation->extra_source_income) ? $borrower_occupation->extra_source_income : '',
            'source_income_area'    => isset($borrower_occupation->source_income_area) ? $borrower_occupation->source_income_area : '',
            'total_income'          => isset($borrower_occupation->total_income) ? $borrower_occupation->total_income : '',
            'current_city'          => isset($borrower_occupation->current_city) ? $borrower_occupation->current_city : '',
        ];

        $documents = Documents::where('borrower_id', $borrower_id)->latest()->first();
        $data['documents'] = [
            'borrower_avtar'    => isset($documents->borrower_avtar) ? $documents->borrower_avtar : '',
            'coapplicant_avtar' => isset($documents->coapplicant_avtar) ? $documents->coapplicant_avtar : '',
            'guarantor_avtar'   => isset($documents->guarantor_avtar) ? $documents->guarantor_avtar : '',
        ];

        $co_applicant_details = CoapplicantKyc::where('borrower_id', $borrower_id)->latest()->first();

        $data['co_applicant_details']['basic'] = [
            'name'              => isset($co_applicant_details->name) ? $co_applicant_details->name : '',
            'father_name'       => isset($co_applicant_details->father_name) ? $co_applicant_details->father_name : '',
            'marital_status'    => isset($co_applicant_details->marital_status) ? $co_applicant_details->marital_status : '',
            'gender'            => isset($co_applicant_details->gender) ? $co_applicant_details->gender : '',
            'mobile'            => isset($co_applicant_details->mobile) ? $co_applicant_details->mobile : '',
            'husband_name'      => isset($co_applicant_details->husband_name) ? $co_applicant_details->husband_name : '',
            'pan'               => isset($co_applicant_details->pan) ? $co_applicant_details->pan : '',
            'dob_date'          => isset($co_applicant_details->dob_date) ? date('d-m-Y', strtotime($co_applicant_details->dob_date)) : '',
        ];

        $coapplicant_address = CoapplicantOccupation::where('borrower_id', $borrower_id)->latest()->first();
        $data['co_applicant_details']['address'] = [
            'resident_type'     => isset($coapplicant_address->resident_type) ? $coapplicant_address->resident_type : '',
            'current_address'   => isset($coapplicant_address->current_address) ? $coapplicant_address->current_address : '',
            'years_living'      => isset($coapplicant_address->years_living) ? $coapplicant_address->years_living : '',
            'permanent_address' => isset($coapplicant_address->permanent_address) ? $coapplicant_address->permanent_address : ''
        ];

        $guarantor_details = Guarantor::where('borrower_id', $borrower_id)->latest()->first();

        $data['guarantor_details']['basic'] = [
            'name'              => isset($guarantor_details->name) ? $guarantor_details->name : '',
            'father_name'       => isset($guarantor_details->father_name) ? $guarantor_details->father_name : '',
            'marital_status'    => isset($guarantor_details->marital_status) ? $guarantor_details->marital_status : '',
            'gender'            => isset($guarantor_details->gender) ? $guarantor_details->gender : '',
            'mobile'            => isset($guarantor_details->mobile) ? $guarantor_details->mobile : '',
            'email'            => isset($guarantor_details->email) ? $guarantor_details->email : '',
            'pan'               => isset($guarantor_details->pan) ? $guarantor_details->pan : '',
            'dob_date'          => isset($guarantor_details->dob_date) ? date('d-m-Y', strtotime($guarantor_details->dob_date)) : '',
            'husband_name'      => isset($guarantor_details->husband_name) ? $guarantor_details->husband_name : '',
        ];

        $guarantor_address = GuarantorOccupation::where('borrower_id', $borrower_id)->latest()->first();
        
        if(isset($guarantor_address->occupation_type) && !empty($guarantor_address->occupation_type)) {
            $g_occupation_type = $this->get_occupation_type($guarantor_address->occupation_type);
        }
        
        $data['guarantor_details']['address'] = [
            'occupation_type'   => isset($g_occupation_type) ? $g_occupation_type : '',
            'resident_type'     => isset($guarantor_address->resident_type) ? $guarantor_address->resident_type : '',
            'current_address'   => isset($guarantor_address->current_address) ? $guarantor_address->current_address : '',
            'years_living'      => isset($guarantor_address->years_living) ? intval($guarantor_address->years_living) : '',
            'permanent_address' => isset($guarantor_address->permanent_address) ? $guarantor_address->permanent_address : '',
            'total_income'      => isset($guarantor_address->total_income) ? $guarantor_address->total_income : '',
        ];

        return $data;
    }

    public function get_occupation_type($occupation) {
        if(!isset($occupation) || empty($occupation)) {
            return false;
        }

        $occupation = Occupation::find($occupation);
        if($occupation) {
            return $occupation->occupation;
        } else {
            return false;
        }
    }

    public function store_dealer_models() {
        return 'OK';
        die();
        $dealerJson = '';
          
        $result = json_decode($dealerJson, true);

        echo "<pre>";
        // print_r($result['dealers']);
        $dealers = $result['dealers'];
        foreach($dealers as $dealer) {
            echo $dealer_id = $dealer['dealer_id'];
            echo "<br>";
            $name = $dealer['name'];

            $dealer_details = new Dealer;
            $dealer_details->dealer_unq = $dealer_id;
            $dealer_details->name = $name;
            // $dealer_details->save();
            $dealer_models = $dealer['models'];
            // print_r($dealer_models);
            
            foreach($dealer_models as $model) {
                $model_details = new ProductModel;
                $model_details->dealer_id = $dealer_details->id;
                echo $model_details->model_unq = $model['model_id'];
                $model_details->name = $model['name'];
                $model_details->battery_type = $model['battery_type'];
                if(is_numeric($model['ex_showroom_price'])) {
                    $ex_showroom_price = $model['ex_showroom_price'];
                } else {
                    $ex_showroom_price = 0;
                }
                $model_details->ex_showroom_price = $ex_showroom_price;
                $model_details->loan_to_value_ratio = $model['loan_to_value_ratio'];
                $model_details->manufacture_name = $model['manufacture_name'];
                $model_details->min_loan_amount = $model['min_loan_amount'];
                $model_details->max_loan_amount = $model['max_loan_amount'];
                $model_details->min_loan_tenure = $model['min_loan_tenure'];
                $model_details->max_loan_tenure = $model['max_loan_tenure'];
                
                // $model_details->save();
                echo " -> done <br>  ";
            }


        }
    }

    public function download_apk() {
        return view('download-finayo-app');
    } 

    public function get_credit_score_details_web(Request $request) {
        $access_key         = "MTEwN0BmaW5heW8=";
        $errorArray         = [];
        $errorCount         = 0;
        $ip                 = $this->get_user_ip();

        if (!isset($request->access_token) || empty($request->access_token)) {
            $errorCount++;
            $errorArray['access_token'] = "Access token is missing";
            return redirect()->route('credit_report_form')->with('failed', $errorArray['access_token']);
        } elseif($request->access_token != $access_key) {
            $errorCount++;
            $errorArray['access_token'] = "Access token is not valid";
            return redirect()->route('credit_report_form')->with('failed', $errorArray['access_token']);
        }
        
        if (!isset($request->mobile) || empty($request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Mobile number is required";
            return redirect()->route('credit_report_form')->with('failed', $errorArray['mobile']);
        } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Invalid mobile number format";
            return redirect()->route('credit_report_form')->with('failed', $errorArray['mobile']);
        }

        $first_name = '';
        $last_name  = '';
        if (!isset($request->name) || empty($request->name)) {
            $errorCount++;
            $errorArray['name'] = "Name is required";
            return redirect()->route('credit_report_form')->with('failed', $errorArray['name']);
        } else {
            $name_parts = explode(" ", $request->name);
            $first_name = $name_parts[0];
            $last_name  = $name_parts[count($name_parts) - 1];
        }

        if (!isset($request->dob) || empty($request->dob)) {
            $errorCount++;
            $errorArray['dob'] = "Date of birth is required";
            return redirect()->route('credit_report_form')->with('failed', $errorArray['dob']);
        } elseif (!strtotime($request->dob)) {
            $errorCount++;
            $errorArray['dob'] = "Invalid date of birth";
            return redirect()->route('credit_report_form')->with('failed', $errorArray['dob']);
        } else {
            // Calculate age
            $dobTimestamp = strtotime($request->dob);
            $age = date('Y') - date('Y', $dobTimestamp);
            if (date('md') < date('md', $dobTimestamp)) {
                $age--;
            }
            if ($age < 21) {
                $errorCount++;
                $errorArray['dob'] = "Age must be within 21 to 55";
                return redirect()->route('credit_report_form')->with('failed', $errorArray['dob']);
            }

            if ($age > 55) {
                $errorCount++;
                $errorArray['dob'] = "Age must be within 21 to 55";
                return redirect()->route('credit_report_form')->with('failed', $errorArray['dob']);
            }
        }

        if (!isset($request->pan) || empty($request->pan)) {
            $errorCount++;
            $errorArray['pan'] = "Pan number is required";
            return redirect()->route('credit_report_form')->with('failed', $errorArray['pan']);
        } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $request->pan)) {
            $errorCount++;
            $errorArray['pan'] = "Invalid Pan number format";
            return redirect()->route('credit_report_form')->with('failed', $errorArray['pan']);
        }

        if(!isset($request->pin) || empty($request->pin)) {
            $errorCount++;
            $errorArray['pin'] = "Pin is required";
            return redirect()->route('credit_report_form')->with('failed', $errorArray['pin']);
        } elseif (!preg_match('/^[0-9]{6}$/', $request->pin)) {
            $errorCount++;
            $errorArray['pin'] = "Invalid Pin code format";
            return redirect()->route('credit_report_form')->with('failed', $errorArray['pin']);
        }

        $phone  = $request->mobile;
        $pan    = $request->pan;
        $pin    = $request->pin;
        $dob    = date('Y-m-d', strtotime($request->dob));

        $jsonRequestBody = json_encode([
            "phoneNumber" => intval($phone),
            "pan" => $pan,
            "firstName" => $first_name,
            "lastName" => $last_name,
            "dateOfBirth" => $dob,
            "pincode" => intval($pin),
            "consent" => [
                "consentFlag" => true,
                "consentTimestamp" => strtotime(date('Y-m-d')),
                "consentIpAddress" => $ip,
                "consentMessageId" => "CM_1"
            ],
        ]);

        $result_response = [];

        try{
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api.signzy.app/api/v3/bureau/experian-report',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS =>$jsonRequestBody,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Authorization: '. $this->signzy_auth_key
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }

            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            // echo $response;
            // die();
            $result = json_decode($response);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }
            
            if(isset($result->statusCode) && $result->statusCode == 200) {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }
            $vendorServiceApi               = new VendorServiceApi;
            $vendorServiceApi->user_id      = 0;
            $vendorServiceApi->ip_address   = $ip;
            $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
            $vendorServiceApi->status       = $api_status;
            $vendorServiceApi->api_flag     = "CREDIT_SCORE_API_SIGNZY_EXPERIAN";
            $vendorServiceApi->request      = $jsonRequestBody;
            $vendorServiceApi->response     = $response;
            $vendorServiceApi->borrower_id      = 0;
            $vendorServiceApi->applicant_type   = "Finayo Web Admin";
            $vendorServiceApi->save();

            $resultData['data'] = json_encode($result, JSON_PRETTY_PRINT);
            $pdf = Pdf::loadView('pdf.credit-report', $resultData)->setPaper('a4', 'portrait');
            // return $pdf->stream();
            return $pdf->download('credit-report-'.$phone.'.pdf');

        } catch (Exception $e) {
            $result_response = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            // return response()->json($result_response, 422);
            return redirect()->route('credit_report_form')->with('failed', $e->getMessage());
        }
    }

    public function credit_report_form() {
        return view('generate-credit-report');
    }

    public function calculateFlatInterestEMI($principal, $annualInterestRate, $tenureInMonths) {
        $annualInterestRate = $annualInterestRate / 100; // Convert percentage to decimal
        $flatInterest = ($principal * $annualInterestRate * $tenureInMonths) / 12;
        $totalRepayment = $principal + $flatInterest;
        return $totalRepayment / $tenureInMonths; // Return the EMI
    }

    public function generateRepaymentSchedule($loan_amount, $interest, $tenure) {
        $netLoanAmount      = $loan_amount; 
        $annualInterestRate = $interest;
        $tenure             = $tenure;
        
        $monthlyInterestRate = $annualInterestRate / 12 / 100;
        $monthlyPayment = $netLoanAmount / $tenure + ($netLoanAmount * $monthlyInterestRate);

        $schedule = [];
        $remainingBalance = $netLoanAmount;

        for ($month = 1; $month <= $tenure; $month++) {
            $interestPortion = $remainingBalance * $monthlyInterestRate;
            $principalPortion = $monthlyPayment - $interestPortion;
            $remainingBalance -= $principalPortion;

            $schedule[] = [
                'month' => $month,
                'principal' => round($principalPortion, 2),
                'interest' => round($interestPortion, 2),
                'total_payment' => round($monthlyPayment, 2),
                'remaining_balance' => round(max($remainingBalance, 0), 2)
            ];
        }

        return $schedule;
    }

    public function test_emi_breakdown($loan_amount = 0, $interest = 0, $tenure = 0) {
        
        // $loan_amount = $loan_amount;
        // $interest = $interest;
        // $tenure = $tenure;
        $iot_amount = 3000;
        if($tenure > 0 && $tenure <= 15) {
            $second_year_insurance = 0;
        } elseif($tenure > 15 && $tenure <= 24) {
            $second_year_insurance = 6000;
        } else {
            $second_year_insurance = 0;
        }

        $net_loan_amount = $loan_amount + $iot_amount + $second_year_insurance;
        $processing_fee = 5000;
        $subvention_amount = 5000;
        $fldg = '';
        $first_year_insurance = 7000;

        $net_disbursal = $loan_amount - ($processing_fee + $subvention_amount + $first_year_insurance);
        $emi_amount = $this->calculateFlatInterestEMI($net_loan_amount, $interest, $tenure);

        $loan_offers = [
            [
                'id' => "2",
                'lender_id' => "2",
                'lender' => "Offer 2",
                'loan_amount' => intval($net_loan_amount),
                'interest' => $interest. '%',
                "terms" => $tenure." month",
                "emi_per_month" => number_format($emi_amount, 2,".",""),
                "loan_details_break_down" => [
                    'loan_amount' => intval($loan_amount),
                    'roi' => floatval($interest),
                    'tenure' => $tenure. ' months',
                    'iot_amount' => $iot_amount,
                    'first_year_insurance_amount' => $first_year_insurance,
                    'second_year_insurance_amount' => $second_year_insurance,
                    'processing_fee' => $processing_fee,
                    'subvention_amount' => $subvention_amount,
                    'fldg' => $fldg,
                    'net_loan_amount' => $net_loan_amount,
                    'net_disbursal' => $net_disbursal,
                    'emi' => number_format($emi_amount, 2, '.', '')
                ],
                "repayment_schedule"  => $this->generateRepaymentSchedule($net_loan_amount, $interest, $tenure),
            ],
        ];

        return response()->json([
            'status' =>'success',
            'message' => "You are eligible for loan",
            'data' => '',
            'offers' => $loan_offers
        ], 200);
    }

    public function update_borrower_data()
    {
        $dealer_data = [
            ['FIN-DEA-1', 'Byby', "M/S Riyansh Mobility", 'Rohit', '7543816675', 'Sahibganj', 'Jharkhand', 'rhtkmr715@gmail.com', 'Jun-24'],
            ['FIN-DEA-2',  'Byby',    'Nandan Enterprises',  'Nandan Jha',  '9162867473',  'Supul',   'Bihar',   'Nandan1997jha@gmail.com', 'Jun-24'],
            ['FIN-DEA-3',  'Ketron',  'Rita Enterprise', 'Mohan Kumar', '9709702627',  'Madhubani',   'Bihar',   'mohanmdb@gmail.com', 'May-24'],
            ['FIN-DEA-40', 'Ketron',  'Unique Trader',   'Firoj Khan',  '7318955901',  'Begusrai', 'Bihar',   'firojkhan404w@gmail.com', 'Jan-24'],
            ['FIN-DEA-41', 'Ketron',  'Jaiswal Automobile',  'Dronavishek Jaiswal', '8521164702',  'Araria',  'Bihar',   'dronavishekjaiswal@gmail.com',    'Jan-24'],
            ['FIN-DEA-44', 'Byby',    "M/S Shri Ram Enterprises",    'Rahul Kumar',     '8877625046',  'Begusrai',    'Bihar',   'rahulkumarram880@gmail.com',  'Jun-24'],
            ['FIN-DEA-46',  'Byby',    "M/S Tripathi Building Meterial",  'Saket Mani Tripathi', '9161099362',  'Siddharth Nagar', 'Uttar Pradesh',   'tripathisaket579@gmail.com',   'Jul-24'],
            ['FIN-DEA-45',  'Ketron',  'Aanchal Auto Motors', 'Mohammad Ali Nadwi',  '8757314789',  'Muzaffarpur', 'Bihar',   'malinadwai1973@gmail.com',    'Jun-24'],
            ['FIN-DEA-65',  'Khushi',  'Sunil Icecream',  'Sunil Kumar', '9546868329',  'Saran',   'Bihar',   'sunil94396@gmail.com',    'Aug-24'],
            ['FIN-DEA-66',  'Khushi',  'Pooja Enterprises',   'Pooja Kumari',    '8340601149',  'Sitamarhi',   'Bihar',   'Poojastm95@gmail.com',    'Aug-24'],
            ['FIN-DEA-98',  'Rajhans', 'Onkar Singh Trading Company', 'Deepa Singh', '9621037416',  'Raebareli',   'Uttar Pradesh',   'adarshsinghchd22@gmail.com',  'Aug-24'],
            ['FIN-DEA-85',  'Rajhans', 'Guru Nanak Enterprises',  'Guraminder Singh',    '8960467707',  'Active',  'Uttar Pradesh',   'gurminders1993@gmail.com',    'Aug-24'],
            ['FIN-DEA-89',  'Rajhans', 'Safi Enterprises',    'Sushil Yadav',    '9839033117',  'Ghazipur',    'Uttar Pradesh',   'Sushil.seg@gmail.com',    'Aug-24'],
            ['FIN-DEA-99',  'Ketron',  'Mg Automobiles',  'Vikash Kumar',    '9386591415',  'Muzaffarpur', 'Bihar',   'vikashmaxxgroup@gmail.com',   'Aug-24'],
            ['FIN-DEA-23',  'City Shine',  'Jyoti Enterprises',   'Manish Gaur', '9286496828',  'Kasganj', 'Uttar Pradesh',   'Manishgaur928649@gmail.com',  'Jan-24'],
            ['FIN-DEA-23',  'Jangid Dlx',  'Jyoti Enterprises',   'Manish Gaur', '9286496828',  'Aligarh', 'Uttar Pradesh',   'Manishgaur928649@gmail.com',  'Jan-24'],
            ['FIN-DEA-37',  'Ms Passnager',    'Ayan Enterprises',    'Mohammed Akeel',  '9917058945',  'Aligarh', 'Uttar Pradesh',   'ayanentaligarh@gamil.com',    'Jan-24'],
            ['FIN-DEA-38',  'Ele', 'Ashirwad Agency', 'Gopal Shukla',    '9115304111',  'Balrampur',   'Uttar Pradesh',   'aashirwadagency2023@gmail.com',   'Jan-24'],
            ['FIN-DEA-38',  'Alenti',  'Ashirwad Agency', 'Gopal Shukla',    '9115304111',  'Balrampur',   'Uttar Pradesh',   'aashirwadagency2023@gmail.com',   'Jan-24'],
            ['FIN-DEA-38',  'Rajhans', 'Ashirwad Agency', 'Gopal Shukla',    '9115304111',  'Balrampur',   'Uttar Pradesh',   'aashirwadagency2023@gmail.com',   'Jan-24'],
            ['FIN-DEA-39',  'Yaan Super Dlx',  'Sk Battery & Tractor',    'Shahid Khan', '7252924450',  'Aligarh', 'Uttar Pradesh',   'skbatterytractor@gmail.com',  'Jan-24'],
            ['FIN-DEA-35',  'Sun E Ride',  'Balaji Auto Agency',  'Sonu Goyal',  '7500009533',  'Mathura', 'Uttar Pradesh',   'sg5707111@gmail.com', 'Jan-24'],
            ['FIN-DEA-35',  'Perfect Auto',    'Balaji Auto Agency',  'Sonu Goyal',  '7500009533',  'Mathura', 'Uttar Pradesh',   'sg5707111@gmail.com', 'Jan-24'],
            ['FIN-DEA-100', 'Byby',    'MS SHIV SHAKTI AUTOMOBILES',  'Sunil Kumar', '9931420700',  'Samastipur',  'Bihar',   'sarairanjanindanevitrak@gmail.com',   'Sep-24'],
            ['FIN-DEA-101', 'Byby',    'RUKMANI AUTOMOBILE',  'Akhilesh kumar Diwakar',  '9955129495',  'Samastipur',  'Bihar',   'rukmaniauto05,@gmail.com',    'Sep-24'],
            ['FIN-DEA-104', 'Byby',    "M/S J B ENTERPRISES", 'ADITYA KUMAR',    '9973205727',  'Siwan',   'Bihar',   'adityaupadhaya312@gmail.com', 'Sep-24'],
            ['FIN-DEA-105', 'Rajhans', 'SHUBHAM ENTERPRISES', 'Sunil Kumar', '9608070907',  'Simdega',     'Jharkhand',   'sunil.mahindra12@gmail.com',  'Sep-24'],
            ['FIN-DEA-107', 'Byby',    'PANDEY AUTOMOBILES',  'Ripu sudan paney',    '9971782952',  'Paschim champaran',   'Bihar',   'pandeyautomobiles199@gmail.com',  'Sep-24'],
            ['FIN-DEA-108', 'Khushi',  'OM ASSOCIATES NDT SERVICES',  'RAKESH KUMAR',    '7091498643',  'Begusrai',    'Bihar',   'rakeshaibm@gmail.com',    'Sep-24'],
            ['FIN-DEA-106', 'Ketron',  'UJJWAL ENTERPRISES',  'UJJWAL KUMAR',    '9504910233',  'Auranagabad', 'Bihar',   'ujjwalkumar10121998@gmail.com',   'Sep-24'],
            ['FIN-DEA-42',  'RAJHANS', 'NEW KAILASH ENTERPRISES',    'ANIL',    '8770916596',  'BHIND',   'MADHYA PRADESH',  'Akashbaghel422000@gmail.com',]
        ];
        // echo "<pre>";
        // print_r($dealer_data);
        $count = 0;
        foreach($dealer_data as $data) {
            $count++;
            echo $count .'--> ';
            echo $dealer_unq = $data[0];
            echo ' <--> ';
            
            $dealer_details = Dealer::where('dealer_unq', $dealer_unq)->first();
            if($dealer_details) {
                echo $dealer_details->mobile = $data[4] ?? '';
                $dealer_details->city = $data[5] ?? '';
                $dealer_details->address_line_1 = $data[5] . ', '. $data[6] ?? '';
                $dealer_details->email = $data[7] ?? '';
                echo "<br>";
                // if($dealer_details->save()) {
                //     echo "Success";
                //     echo "<br>";
                // }
            } else {
                echo "Not found";
                echo "<br>";   
            }
        }
    }

    public function testNewSms($type='otp') {
        $otp = rand(100000, 999999);

        // return $type;
        if($type === 'otp') {
            $response = $this->smsService->sendOtp(
                '7098007402',
                "{$otp} is your one-time password (OTP) to log in to Finayo. Please enter OTP to proceed"
            );
        } elseif($type === 'link') {
            $response = $this->smsService->sendLink(
                '7098007402',
                "Your Finayo VKYC application number is Test Please click this link to start the video kyc process: https://vkycfe.digitap.ai/okycvkyc?transactionId=Acbvldfdkvndflvndfbnfdbckfjjhgsdgflkbdgfvbhhrvruureh_-wGJfinal=="
            );
        } else {
            return response()->json([
                'status' => 'failed',
                'message' => $response->error ?? '',
            ], 500);
        }

        if (!$response->success) {
            return response()->json([
                'status' => 'failed',
                'message' => $response->error ?? '',
            ], 500);
        }

        return response()->json([
            'status' => 'debug',
            'raw' => $response->raw,
            'provider_message' => $response->providerMessage,
        ]);


        return response()->json([
            'status' => 'success',
            'message_id' => $response->messageId,
        ]);
    }
}
