<?php

namespace App\Http\Controllers;

use App\Models\AppActivityLog;
// use App\Models\B2cAppLogs;
use App\Models\BorrowerAddress;
use App\Models\BorrowerIotDetails;
use App\Models\BorrowerKyc;
use App\Models\BorrowerLoanProducts;
use App\Models\BorrowerOccupation;
use App\Models\BeneficiaryDetails;
use App\Models\BorrowerLoanScheme;
use App\Models\BorrowerAccountDetails;
use App\Models\CoapplicantKyc;
use App\Models\CoapplicantOccupation;
use App\Models\Dealer;
use App\Models\DisbursementDetails;
use App\Models\Documents;
use App\Models\FieldInspectionDetails;
use App\Models\Guarantor;
use App\Models\GuarantorOccupation;
use App\Models\LoanBasicDetails;
use App\Models\Occupation;
use App\Models\ProductModel;
use App\Models\VendorServiceApi;
use App\Traits\CalculatesLoanSchedule;
use DateTime;
use DateTimeZone;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;


class HelperController extends Controller
{
    use CalculatesLoanSchedule;

    public function send_response($status, $message, $code, $data=null) {
        $result = [
            'status' => $status,
            'message' => $message
        ];

        if(isset($data) && !empty($data)) {
            if (isset($data['current_page'])) {
                $data['current_page'] = (int)$data['current_page'];
            }
            $result['data'] = $data;
        }

        return response()->json($result, $code);
    }

    /**
     * Method to get user IP address
     */
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

    /**
     * Method to generate random numbers
     */
    public function generate_random_number($length = 6) {
        $characters = "0123456789";
        $characters_length = strlen($characters);
        $random_number = '';
        for ($i=0; $i < $length; $i++) { 
            $random_number .= $characters[rand(0, $characters_length - 1)];
        }

        return $random_number;
    }

    /**
     * Method to generate alpha numeric id
     */
    public function generate_random_id($length = 4) {
        $random_str = $this->generate_random_string($length);
        date_default_timezone_set('Asia/Kolkata');
        $date = date('YmdHis');
        $generate_random_id = $random_str.$date;

        return $generate_random_id;
    }

    public function get_borrower_id($user_id) {
        $loan_details = LoanBasicDetails::where('user_id', $user_id)
                                        ->whereIn('status', ['NEW', 'DISBURSED'])
                                        ->where('is_active', false)
                                        ->where('borrower_id', '!=', '')
                                        ->latest()
                                        ->first();
                                        // ->where('status', 'NEW')
        if($loan_details) {
            return $loan_details->id; // borrower primary key
        } else {
            return 'Invalid';
        }
    }

    /**
     * Method to generate random string
     */
    public function generate_random_string($length = 16) {
        $characters = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $characters_length = strlen($characters);
        $random_string = '';
        for ($i=0; $i < $length; $i++) { 
            $random_string .= $characters[rand(0, $characters_length - 1)];
        }

        return $random_string;
    }

    public function prepare_one_line_address_from_aadhar($address = []){
        $address_line = '';
        if(!empty($address['house'])){
            $address_line .= $address['house'];
        }
    
        if(!empty($address['street'])){
            if(!empty($address['house'])) {
                $address_line .= ', ';
            }
            $address_line .= $address['street'];
        }
    
        if(!empty($address['landmark'])){
            if(!empty($address['street'])) {
                $address_line .= ', ';
            }
            $address_line .= $address['landmark'];
        }
    
        if(!empty($address['loc'])){
            if(!empty($address['landmark'])) {
                $address_line .= ', ';
            }
            $address_line .= $address['loc'];
        }
    
        if(!empty($address['po'])){
            if(!empty($address['loc'])) {
                $address_line .= ', ';
            }
            $address_line .= $address['po'];
        }
    
        if(!empty($address['dist'])){
            if(!empty($address['po'])) {
                $address_line .= ', ';
            }
            $address_line .= $address['dist'];
        }
    
        if(!empty($address['subdist'])){
            if(!empty($address['dist'])) {
                $address_line .= ', ';
            }
            $address_line .= $address['subdist'];
        }
    
        if(!empty($address['vtc'])){
            if(!empty($address['subdist'])) {
                $address_line .= ', ';
            }
            $address_line .= $address['vtc'];
        }
    
        if(!empty($address['pc'])){
            if(!empty($address['vtc'])) {
                $address_line .= ', ';
            }
            $address_line .= $address['pc'];
        }
    
        if(!empty($address['state'])){
            if(!empty($address['pc'])) {
                $address_line .= ', ';
            }
            $address_line .= $address['state'];
        }
    
        if(!empty($address['country'])){
            if(!empty($address['state'])) {
                $address_line .= ', ';
            }
            $address_line .= $address['country'];
        }

        return $address_line;
    }

    // calculate Reducing Interest
    public function calculateEMI($principal, $annualInterestRate, $tenureMonths) {
        // Convert annual interest rate to monthly interest rate
        $monthlyInterestRate = ($annualInterestRate / 100) / 12;
        
        // Calculate EMI using the reducing balance formula
        $emi = ($principal * $monthlyInterestRate * pow((1 + $monthlyInterestRate), $tenureMonths)) / (pow((1 + $monthlyInterestRate), $tenureMonths) - 1);
        
        return $emi;
    }

    function calculateFlatInterestEMI($principal, $annualInterestRate, $tenureInMonths) {
        $annualInterestRate = $annualInterestRate / 100; // Convert percentage to decimal
        $flatInterest = ($principal * $annualInterestRate * $tenureInMonths) / 12;
        $totalRepayment = $principal + $flatInterest;
        return $totalRepayment / $tenureInMonths; // Return the EMI
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

    // public function user_address_details($borrower_id, $user_type) {
    //     $borrower_id    = $borrower_id;
    //     $user_type      = $user_type;
    //     $errorArray     = [];
    //     $errorCount     = 0;
    //     date_default_timezone_set('Asia/Kolkata');

    //     $result = [];
    //     $vendor_service_api = VendorServiceApi::where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
    //                             ->where('status', "VALID")
    //                             ->where('borrower_id', $borrower_id);
    //     if($user_type == "BORROWER") {

    //         $borrower_deails = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
    //         if($borrower_deails) {
    //             $name = $borrower_deails->name;
    //             $mobile = $borrower_deails->mobile;
    //             $email = $borrower_deails->email;

    //             $dist = '';
    //             $vendor_service_api = $vendor_service_api->where('applicant_type', "BORROWER")
    //                                     ->latest()
    //                                     ->first();


    //             if($vendor_service_api) {
    //                 $response   = $vendor_service_api->response;
    //                 $response   = base64_decode($response);
    //                 $response   = unserialize($response);
    //                 $data       = json_decode($response, 200);

    //                 if($data['msg'] == 'success') {
    //                     $dist   = $data['model']['address']['dist'];
    //                 }

    //                 $single_line_address = $this->prepare_one_line_address_from_aadhar($data['model']['address']);
    //                 $city = $dist; // $borrower_deails->name;
    //             }


    //             $result = [
    //                 'name' => $name ?? '',
    //                 'mobile' => $mobile ?? '',
    //                 'email' => $email ?? '',
    //                 'city' => $city ?? '',
    //                 'single_line_address' => $single_line_address ?? '',
    //                 'full_address' => $data['model']['address'] ?? '',
    //             ];
    //         }
    //     } elseif($user_type == "COAPPLICANT") {
    //         $borrower_deails = CoapplicantKyc::where('borrower_id', $borrower_id)->latest()->first();
    //         if($borrower_deails) {
    //             $name = $borrower_deails->name;
    //             $mobile = $borrower_deails->mobile;
    //             $email = $borrower_deails->email;

    //             $dist = '';
    //             $vendor_service_api = $vendor_service_api->where('applicant_type', "COAPPLICANT")
    //                                     ->latest()
    //                                     ->first();
    //             if($vendor_service_api) {
    //                 $response   = $vendor_service_api->response;
    //                 $response   = base64_decode($response);
    //                 $response   = unserialize($response);
    //                 $data       = json_decode($response, 200);

    //                 if($data['msg'] == 'success') {
    //                     $dist   = $data['model']['address']['dist'];
    //                 }

    //                 $single_line_address = $this->prepare_one_line_address_from_aadhar($data['model']['address']);
    //                 $city = $dist;  // $borrower_deails->name;
    //             }

    //             $result = [
    //                 'name' => $name ?? '',
    //                 'mobile' => $mobile ?? '',
    //                 'email' => $email ?? '',
    //                 'city' => $city ?? '',
    //                 'single_line_address' => $single_line_address ?? '',
    //                 'full_address' => $data['model']['address'] ?? '',
    //             ];
    //         }
    //     } elseif($user_type == "GUARANTOR") {
    //         $borrower_deails = Guarantor::where('borrower_id', $borrower_id)->latest()->first();
    //         if($borrower_deails) {
    //             $name = $borrower_deails->name;
    //             $mobile = $borrower_deails->mobile;
    //             $email = $borrower_deails->email;

    //             $dist = '';
    //             $vendor_service_api = $vendor_service_api->where('applicant_type', "GUARANTOR")
    //                                     ->latest()
    //                                     ->first();
    //             if($vendor_service_api) {
    //                 $response   = $vendor_service_api->response;
    //                 $response   = base64_decode($response);
    //                 $response   = unserialize($response);
    //                 $data       = json_decode($response, 200);

    //                 if($data['msg'] == 'success') {
    //                     $dist   = $data['model']['address']['dist'];
    //                 }

    //                 $single_line_address = $this->prepare_one_line_address_from_aadhar($data['model']['address']);
    //                 $city = $dist; // $borrower_deails->name;
    //             }

    //             $result = [
    //                 'name' => $name ?? '',
    //                 'mobile' => $mobile ?? '',
    //                 'email' => $email ?? '',
    //                 'city' => $city ?? '',
    //                 'single_line_address' => $single_line_address ?? '',
    //                 'full_address' => $data['model']['address'] ?? '',
    //             ];
    //         }
    //     }

    //     if(count($result) > 0) {
    //         return [
    //             'status' => 'success',
    //             'data' => $result
    //         ];
    //     } else {
    //         return [
    //             'status' => 'failed',
    //             'message' => 'No records'
    //         ];
    //     }
    // }


    public function user_address_details($borrower_id, $user_type) {
        $borrower_id    = $borrower_id;
        $user_type      = $user_type;
        $errorArray     = [];
        $errorCount     = 0;
        date_default_timezone_set('Asia/Kolkata');

        $result = [];
        $vendor_service_api = VendorServiceApi::where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
                                ->where('status', "VALID")
                                ->where('borrower_id', $borrower_id)
                                ->where('applicant_type', $user_type)
                                ->latest()->first();
        if($vendor_service_api) {
            $response   = $vendor_service_api->response;
            $response   = base64_decode($response);
            $response   = unserialize($response);
            $data       = json_decode($response, 200);

            if($data['msg'] == 'success') {
                $dist   = $data['model']['address']['dist'];
            }

            $single_line_address = $this->prepare_one_line_address_from_aadhar($data['model']['address']);
            $city = $dist; // $borrower_deails->name;
            $address_data = $data['model']['address'];
        } else {
            $api_detail = VendorServiceApi::where('borrower_id', $borrower_id)
                            ->where('applicant_type', $user_type)
                            ->where('api_flag', 'AADHAR_CARD_OCR_BACK')
                            ->first();
            if(!$api_detail) {
                return [];
                // return $this->send_response('failed', "Aadhar OCR not done yet", 422);
            }

            $response   = $api_detail->response;
            $response   = base64_decode($response);
            $response   = unserialize($response);
            $data       = json_decode($response, true);

            $address = $data['result'][0]['details']['address'];

            $parts = [
                $address['house_number'] ?? '',
                $address['street'] ?? '',
                $address['locality'] ?? '',
                $address['landmark'] ?? '',
                $address['city'] ?? '',
                $address['district'] ?? '',
                $address['state'] ?? '',
                $address['pin'] ?? ''
            ];
            
            // Filter out empty parts and trim each part
            $filteredParts = array_filter(array_map('trim', $parts));

            // Join with comma and return
            $single_line_address = implode(', ', $filteredParts);

            $address_data = [
                'house' => $address['house_number'] ?? '',
                'street' => $address['street'] ?? '',
                'landmark' => $address['landmark'] ?? '',
                'loc' => $address['locality'] ?? '',
                'po' => $address['city'] ?? '',
                'dist' => $address['district'] ?? '',
                'subdist' => '',
                'vtc' => $address['city'] ?? '',
                'pc' => $address['pin'] ?? '',
                'state' => $address['state'] ?? '',
                'country' => 'India'
            ];

        }
        if($user_type == "BORROWER") {
            $borrower_deails = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
        } elseif($user_type == "COAPPLICANT") {
            $borrower_deails = CoapplicantKyc::where('borrower_id', $borrower_id)->latest()->first();
        } elseif($user_type == "GUARANTOR") {
            $borrower_deails = Guarantor::where('borrower_id', $borrower_id)->latest()->first();    
        }

        if($borrower_deails) {
            $result['name'] = $borrower_deails->name ?? '';
            $result['mobile'] = $borrower_deails->mobile ?? '';
            $result['email'] = $borrower_deails->email ?? '';
        }

        
        $result['single_line_address'] = $single_line_address ?? '';
        $result['full_address'] = $address_data ?? '';

        if(count($result) > 0) {
            return [
                'status' => 'success',
                'data' => $result
            ];
        } else {
            return [
                'status' => 'failed',
                'message' => 'No records'
            ];
        }
    }

    public function user_address_details_for_oem($borrower_id, $user_type) {
        $borrower_id    = $borrower_id;
        $user_type      = $user_type;
        $errorArray     = [];
        $errorCount     = 0;
        date_default_timezone_set('Asia/Kolkata');

        $result = [];
        $vendor_service_api = VendorServiceApi::where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
                                ->where('status', "VALID")
                                ->where('borrower_id', $borrower_id);
        if($user_type == "BORROWER") {

            $borrower_deails = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
            if($borrower_deails) {
                $vendor_service_api = $vendor_service_api->where('applicant_type', "BORROWER")
                                        ->latest()
                                        ->first();
                if($vendor_service_api) {
                    $response   = $vendor_service_api->response;
                    $response   = base64_decode($response);
                    $response   = unserialize($response);
                    $data       = json_decode($response, 200);
                }
                
                $result = [
                    'full_address' => [
                        'state' => $data['model']['address']['state'] ?? '',
                        'country' => $data['model']['address']['country'] ?? '',
                    ],
                ];
            }
        } elseif($user_type == "COAPPLICANT") {
            $borrower_deails = CoapplicantKyc::where('borrower_id', $borrower_id)->latest()->first();
            if($borrower_deails) {
                $vendor_service_api = $vendor_service_api->where('applicant_type', "COAPPLICANT")
                                        ->latest()
                                        ->first();
                if($vendor_service_api) {
                    $response   = $vendor_service_api->response;
                    $response   = base64_decode($response);
                    $response   = unserialize($response);
                    $data       = json_decode($response, 200);
                }

                $result = [
                    'full_address' => ['address' => [
                        'state' => $data['model']['address']['state'] ?? '',
                        'country' => $data['model']['address']['country'] ?? '',
                    ]],
                ];
            }
        } elseif($user_type == "GUARANTOR") {
            $borrower_deails = Guarantor::where('borrower_id', $borrower_id)->latest()->first();
            if($borrower_deails) {
                $vendor_service_api = $vendor_service_api->where('applicant_type', "GUARANTOR")
                                        ->latest()
                                        ->first();
                if($vendor_service_api) {
                    $response   = $vendor_service_api->response;
                    $response   = base64_decode($response);
                    $response   = unserialize($response);
                    $data       = json_decode($response, 200);
                }
                
                $result = [
                    'full_address' => ['address' => [
                        'state' => $data['model']['address']['state'] ?? '',
                        'country' => $data['model']['address']['country'] ?? '',
                    ]],
                ];
            }
        }

        if(count($result) > 0) {
            return [
                'status' => 'success',
                'data' => $result
            ];
        } else {
            return [
                'status' => 'failed',
                'message' => 'No records'
            ];
        }
    }

    public function maskAadharNumber($aadharNumber) {
        // Ensure the input is a string
        $aadharNumber = strval($aadharNumber);
    
        // Get the length of the Aadhaar number
        $length = strlen($aadharNumber);
    
        // Check if the length is valid (Aadhaar numbers are 12 digits long)
        if ($length != 12) {
            return "Invalid Aadhaar Number";
        }
    
        // Create the masked Aadhaar number (e.g., XXXXXXXX1234)
        $maskedAadhar = str_repeat('X', $length - 4) . substr($aadharNumber, -4);
    
        return $maskedAadhar;
    }

    // public function prepare_data_for_esign_doc($borrower_id) {
    //     date_default_timezone_set('Asia/Kolkata');
    //     $data['date_of_agreement'] = date('d-m-Y');
    //     // $data['lender_details']['company_name'] = '';
    //     // $data['lender_details']['company_short'] = '';
    //     // $data['lender_details']['address'] = '';

    //     $loan_basic_details = LoanBasicDetails::find($borrower_id);
    //     $loan_amount        = isset($loan_basic_details->loan_amount) ? $loan_basic_details->loan_amount : 0;
    //     $loan_term          = isset($loan_basic_details->loan_term) ? intval($loan_basic_details->loan_term) : 0;
    //     $dealer_id          = $loan_basic_details->dealer_id;
    //     $model              = $loan_basic_details->model;

    //     $dealer_product_details = Dealer::join('product_models', 'dealers.id', '=', 'product_models.dealer_id')
    //                                 ->where('dealers.dealer_unq', $dealer_id)
    //                                 ->where('product_models.name', $model)
    //                                 ->select(
    //                                     'dealers.id as dealer_primary',
    //                                     'product_models.interest',
    //                                     'product_models.processing_fee',
    //                                     'product_models.subvention_amount',
    //                                     'product_models.fldg',
    //                                     'product_models.iot',
    //                                     'product_models.insurance_1st_year',
    //                                     'product_models.insurance_2nd_year'
    //                                 )
    //                                 ->first();

    //     if (!$dealer_product_details) {
    //         return response()->json([
    //             'status' => 'failed',
    //             'message' => 'Invalid dealer or product',
    //         ], 422);
    //     }

    //     // Extract product details
    //     $product_interest           = $dealer_product_details->interest ?? 17.5;
    //     $product_processing_fee     = $dealer_product_details->processing_fee ?? 5000;
    //     $product_subvention_amount  = $dealer_product_details->subvention_amount ?? 5000;
    //     $product_fldg               = $dealer_product_details->fldg ?? 5000;
    //     $product_iot                = $dealer_product_details->iot ?? 3000;
    //     $product_insurance_1st_year = $dealer_product_details->insurance_1st_year ?? 7000;
    //     $product_insurance_2nd_year = $dealer_product_details->insurance_2nd_year ?? 6000;

    //     $roi = $product_interest;
    //     $interest = $roi;
    //     $tenure = $loan_term;
    //     $iot_amount = $product_iot;
    //     if($tenure > 0 && $tenure <= 15) {
    //         $second_year_insurance = 0;
    //     } elseif($tenure > 15 && $tenure <= 24) {
    //         $second_year_insurance = $product_insurance_2nd_year;
    //     } else {
    //         $second_year_insurance = 0;
    //     }

    //     $net_loan_amount = $loan_amount + $iot_amount + $second_year_insurance;
    //     $processing_fee = $product_processing_fee;
    //     $subvention_amount = $product_subvention_amount;
    //     $fldg = $product_fldg;
    //     $first_year_insurance = $product_insurance_1st_year;

    //     $net_loan_amount = $loan_amount + $product_iot + $second_year_insurance;

    //     $net_disbursal = $net_loan_amount - ($product_processing_fee + $product_subvention_amount + $product_insurance_1st_year + $product_fldg + $product_iot + $second_year_insurance);

    //     // $net_disbursal = $loan_amount - ($processing_fee + $subvention_amount + $first_year_insurance);
    //     $emi_amount = $this->calculateFlatInterestEMI($net_loan_amount, $interest, $tenure);
    //     // $emi = $this->calculateFlatInterestEMI($loan_amount, 17.5, $loan_term);

        
    //     $loan_amount = $net_loan_amount;
    //     $emi = $emi_amount;

    //     $dealer_name        = '';
    //     if($loan_basic_details) {
    //         $dealer_id = $loan_basic_details->dealer_id;
    //         $model_name = $loan_basic_details->model;

    //         $dealer_details = Dealer::where('dealer_unq', $dealer_id)->first();
    //         if($dealer_details) {
    //             $dealer_name = $dealer_details->name;
    //             $dealer_primary_key = $dealer_details->id;

    //             $product_details = ProductModel::where('dealer_id', $dealer_primary_key)->where('name', $model_name)->first();
    //             if($product_details) {
    //                 $model_name = $product_details->name;
    //                 $ex_showroom_price = $product_details->ex_showroom_price;
    //                 $product_id = $product_details->model_unq;
    //                 $product_type = $product_details->product_type;
    //             }
    //         }

    //     }
        

    //     $data['loan_details'] = [
    //         'dealer_id' => isset($loan_basic_details->dealer_id) ? $loan_basic_details->dealer_id : '',
    //         'dealer_name' => isset($dealer_name) ? $dealer_name : '',
    //         'vehicle_cost' => isset($ex_showroom_price) ? $ex_showroom_price : 0,
    //         'model' => isset($model_name) ? $model_name : '',
    //         'loan_amount' =>$loan_amount,
    //         'loan_term' =>$loan_term,
    //         'product_id' =>isset($product_id) ? $product_id : '',
    //         'roi' => $interest,
    //         'purpose_of_loan' => isset($product_type) ? $product_type : '',
    //         'processing_fee' =>'',
    //         'application_charges' =>'',
    //         'add_delay_interest' =>'',
    //         'bouncing_charges' =>'',
    //         'pre_closure_charge' =>'4%',
    //         'emi' =>round($emi, 2),
    //         'loan_amount_in_words' =>$this->numberToWords($loan_amount),
    //     ];

    //     $borrower_details = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
        
    //     $data['borrower_details']['basic'] = [
    //         'name'              => isset($borrower_details->name) ? $borrower_details->name : '',
    //         'father_name'       => isset($borrower_details->father_name) ? $borrower_details->father_name : '',
    //         'mobile'            => isset($borrower_details->mobile) ? $borrower_details->mobile : '',
    //         'email'             => isset($borrower_details->email) ? $borrower_details->email : '',
    //         'dob_date'          => isset($borrower_details->dob_date) ? date('d-m-Y', strtotime($borrower_details->dob_date)) : '',
    //         'marital_status'    => isset($borrower_details->marital_status) ? $borrower_details->marital_status : '',
    //         'husband_name'      => isset($borrower_details->husband_name) ? $borrower_details->husband_name : '',
    //         'gender'            => isset($borrower_details->gender) ? $borrower_details->gender : '',
    //         'residing_at'       => isset($borrower_details->residing_at) ? $borrower_details->residing_at : '',
    //         'pan'               => isset($borrower_details->pan) ? $borrower_details->pan : '',
    //         'skip_co_applicant' => isset($borrower_details->skip_co_applicant) ? $borrower_details->skip_co_applicant : ''
    //     ];

    //     $borrower_address = BorrowerAddress::where('borrower_id', $borrower_id)->latest()->first();
    //     $data['borrower_details']['address'] = [
    //         'resident_type'     => isset($borrower_address->resident_type) ? $borrower_address->resident_type : '',
    //         'current_address'   => isset($borrower_address->current_address) ? $borrower_address->current_address : '',
    //         'years_living'      => isset($borrower_address->years_living) ? intval($borrower_address->years_living) : '',
    //         'permanent_address' => isset($borrower_address->permanent_address) ? $borrower_address->permanent_address : '',
    //         'family_members'    => isset($borrower_address->family_members) ? $borrower_address->family_members : '',
    //         'earning_members'   => isset($borrower_address->earning_members) ? $borrower_address->earning_members : '',
    //         'family_income'     => isset($borrower_address->family_income) ? $borrower_address->family_income : '',
    //     ];

    //     $borrower_occupation = BorrowerOccupation::where('borrower_id', $borrower_id)->latest()->first();
    //     if(isset($borrower_occupation->occupation_type) && !empty($borrower_occupation->occupation_type)) {
    //         $b_occupation_type = $this->get_occupation_type($borrower_occupation->occupation_type);
    //     }
    //     $data['borrower_details']['occupation'] = [
    //         'occupation_type'       => isset($b_occupation_type) ? $b_occupation_type : '',
    //         'current_occupation'    => isset($borrower_occupation->current_occupation) ? $borrower_occupation->current_occupation : '',
    //         'driving_experience'    => isset($borrower_occupation->driving_experience) ? intval($borrower_occupation->driving_experience) : '',
    //         'vehicle_type'          => isset($borrower_occupation->vehicle_type) ? $borrower_occupation->vehicle_type : '',
    //         'monthly_earning'       => isset($borrower_occupation->monthly_earning) ? $borrower_occupation->monthly_earning : '',
    //         'expected_monthly_earning' => isset($borrower_occupation->expected_monthly_earning) ? $borrower_occupation->expected_monthly_earning : '',
    //         'extra_source_income'   => isset($borrower_occupation->extra_source_income) ? $borrower_occupation->extra_source_income : '',
    //         'source_income_area'    => isset($borrower_occupation->source_income_area) ? $borrower_occupation->source_income_area : '',
    //         'total_income'          => isset($borrower_occupation->total_income) ? $borrower_occupation->total_income : '',
    //         'current_city'          => isset($borrower_occupation->current_city) ? $borrower_occupation->current_city : '',
    //     ];

    //     $documents = Documents::where('borrower_id', $borrower_id)->latest()->first();
    //     $data['documents'] = [
    //         'borrower_avtar'    => isset($documents->borrower_avtar) ? $documents->borrower_avtar : '',
    //         'coapplicant_avtar' => isset($documents->coapplicant_avtar) ? $documents->coapplicant_avtar : '',
    //         'guarantor_avtar'   => isset($documents->guarantor_avtar) ? $documents->guarantor_avtar : '',
    //     ];

    //     $co_applicant_details = CoapplicantKyc::where('borrower_id', $borrower_id)->latest()->first();

    //     $data['co_applicant_details']['basic'] = [
    //         'name'              => isset($co_applicant_details->name) ? $co_applicant_details->name : '',
    //         'father_name'       => isset($co_applicant_details->father_name) ? $co_applicant_details->father_name : '',
    //         'marital_status'    => isset($co_applicant_details->marital_status) ? $co_applicant_details->marital_status : '',
    //         'gender'            => isset($co_applicant_details->gender) ? $co_applicant_details->gender : '',
    //         'mobile'            => isset($co_applicant_details->mobile) ? $co_applicant_details->mobile : '',
    //         'husband_name'      => isset($co_applicant_details->husband_name) ? $co_applicant_details->husband_name : '',
    //         'pan'               => isset($co_applicant_details->pan) ? $co_applicant_details->pan : '',
    //         'dob_date'          => isset($co_applicant_details->dob_date) ? date('d-m-Y', strtotime($co_applicant_details->dob_date)) : '',
    //     ];

    //     $coapplicant_address = CoapplicantOccupation::where('borrower_id', $borrower_id)->latest()->first();
    //     $data['co_applicant_details']['address'] = [
    //         'resident_type'     => isset($coapplicant_address->resident_type) ? $coapplicant_address->resident_type : '',
    //         'current_address'   => isset($coapplicant_address->current_address) ? $coapplicant_address->current_address : '',
    //         'years_living'      => isset($coapplicant_address->years_living) ? $coapplicant_address->years_living : '',
    //         'permanent_address' => isset($coapplicant_address->permanent_address) ? $coapplicant_address->permanent_address : ''
    //     ];

    //     $guarantor_details = Guarantor::where('borrower_id', $borrower_id)->latest()->first();

    //     $data['guarantor_details']['basic'] = [
    //         'name'              => isset($guarantor_details->name) ? $guarantor_details->name : '',
    //         'father_name'       => isset($guarantor_details->father_name) ? $guarantor_details->father_name : '',
    //         'marital_status'    => isset($guarantor_details->marital_status) ? $guarantor_details->marital_status : '',
    //         'gender'            => isset($guarantor_details->gender) ? $guarantor_details->gender : '',
    //         'mobile'            => isset($guarantor_details->mobile) ? $guarantor_details->mobile : '',
    //         'email'            => isset($guarantor_details->email) ? $guarantor_details->email : '',
    //         'pan'               => isset($guarantor_details->pan) ? $guarantor_details->pan : '',
    //         'dob_date'          => isset($guarantor_details->dob_date) ? date('d-m-Y', strtotime($guarantor_details->dob_date)) : '',
    //         'husband_name'      => isset($guarantor_details->husband_name) ? $guarantor_details->husband_name : '',
    //     ];

    //     $guarantor_address = GuarantorOccupation::where('borrower_id', $borrower_id)->latest()->first();
        
    //     if(isset($guarantor_address->occupation_type) && !empty($guarantor_address->occupation_type)) {
    //         $g_occupation_type = $this->get_occupation_type($guarantor_address->occupation_type);
    //     }
        
    //     $data['guarantor_details']['address'] = [
    //         'occupation_type'   => isset($g_occupation_type) ? $g_occupation_type : '',
    //         'resident_type'     => isset($guarantor_address->resident_type) ? $guarantor_address->resident_type : '',
    //         'current_address'   => isset($guarantor_address->current_address) ? $guarantor_address->current_address : '',
    //         'years_living'      => isset($guarantor_address->years_living) ? intval($guarantor_address->years_living) : '',
    //         'permanent_address' => isset($guarantor_address->permanent_address) ? $guarantor_address->permanent_address : '',
    //         'total_income'      => isset($guarantor_address->total_income) ? $guarantor_address->total_income : '',
    //     ];

    //     return $data;
    // }
    public function prepare_data_for_esign_doc($borrower_id) {
        date_default_timezone_set('Asia/Kolkata');
        $data['date_of_agreement'] = date('d-m-Y');

        $loan_basic_details = LoanBasicDetails::find($borrower_id);
        $loan_amount        = isset($loan_basic_details->loan_amount) ? $loan_basic_details->loan_amount : 0;
        $loan_term          = isset($loan_basic_details->loan_term) ? intval($loan_basic_details->loan_term) : 0;
        $dealer_id          = $loan_basic_details->dealer_id;
        $model              = $loan_basic_details->model;
        $model_id           = $loan_basic_details->model_id;

        $lender_id = $loan_basic_details->lender_id ?? null;

        if(!$lender_id) {
            $lenderBorrowerGroup = LenderBorrowerGroup::where('borrower_id', $borrower_id)->first();
            if($lenderBorrowerGroup) {
                $lender_id = $lenderBorrowerGroup->lender_id;
            }
        }

        $data['lender_details']['company_name'] = '';
        $data['lender_details']['company_short'] = '';
        $data['lender_details']['address'] = '';
        $data['lender_details']['corporate_address'] = '';
        if($lender_id) {
            if($lender_id == 3) {
                $data['lender_details']['company_name'] = 'Akme Fintrade India Ltd (AFIL)';
                $data['lender_details']['company_short'] = 'AKME';
                $data['lender_details']['licence_no'] = '11509 (RoC-Jaipur)';
                $data['lender_details']['address'] = 'Akme Business Centre, 4-5 Subcity Center, Savina Circle, Udaipur 313002, Rajasthan';
                $data['lender_details']['corporate_address'] = 'Office No. D-4, Neelkanth Business Park, Nathani Road, Vidhyavihar (West) Mumbai-400086';
                $data['lender_details']['approach_via_mail'] = 'https://www.akmefintrade.com/grievance-redressal-mechanism/';
                $data['lender_details']['contact_mail'] = 'rohit@akmefintrade.com';

            } elseif($lender_id == 1) {
                $data['lender_details']['company_name'] = 'F MEC International Financial Services Ltd';
                $data['lender_details']['company_short'] = 'F MEC';
                $data['lender_details']['address'] = '908, 9th Floor Mercantile House, 15 K.G. Marg New Delhi – 110 001';
                $data['lender_details']['corporate_address'] = '908, 9th Floor Mercantile House, 15 K.G. Marg New Delhi – 110 001'; 
                $data['lender_details']['licence_no'] = '';
                $data['lender_details']['approach_via_mail'] = '';
                $data['lender_details']['contact_mail'] = '';
            }
        }


        // $dealer_product_details_query = Dealer::join('product_models', 'dealers.id', '=', 'product_models.dealer_id')
        //                             ->where('dealers.dealer_unq', $dealer_id)
        //                             ->select(
        //                                 'dealers.id as dealer_primary',
        //                                 'product_models.interest',
        //                                 'product_models.processing_fee',
        //                                 'product_models.subvention_amount',
        //                                 'product_models.fldg',
        //                                 'product_models.iot',
        //                                 'product_models.insurance_1st_year',
        //                                 'product_models.insurance_2nd_year',
        //                                 'product_models.interest_type'
        //                             );
        // if (!empty($model_id)) {
        //     $dealer_product_details_query->where('product_models.model_unq', $model_id);
        // } else {
        //     $dealer_product_details_query->where('product_models.name', $model);
        // }

        // $dealer_product_details = $dealer_product_details_query->first();

        // if (!$dealer_product_details) {
        //     return response()->json([
        //         'status' => 'failed',
        //         'message' => 'Invalid dealer or product',
        //     ], 422);
        // }

        $dealer_name        = '';
        if($loan_basic_details) {
            $dealer_id = $loan_basic_details->dealer_id;
            $model_name = $loan_basic_details->model;
            $model_id = $loan_basic_details->model_id;

            $dealer_details = Dealer::where('dealer_unq', $dealer_id)->first();
            if($dealer_details) {
                $dealer_name = $dealer_details->name;
                $dealer_primary_key = $dealer_details->id;

                $dealer_bank_details = BeneficiaryDetails::where('user_type', 'DEALER')->where('user_id', $dealer_details->id)->first();
                if($dealer_bank_details) {
                    $dealer_beneficiary_name = $dealer_bank_details->beneficiary_name ?? '';
                    $dealer_account_number = $dealer_bank_details->account_number ?? '';
                    $dealer_ifsc_code = $dealer_bank_details->ifsc_code ?? '';

                    if($dealer_ifsc_code != '') {
                        $dealerBankInfoResponse = $this->get_ifsc_information($dealer_ifsc_code)->getData(true);

                        if (isset($dealerBankInfoResponse['status']) && $dealerBankInfoResponse['status'] == 'success') {
                            $dealer_bank_name = $dealerBankInfoResponse['data']['bank'] ?? '';
                        } else {
                            $dealer_bank_name = ''; // Handle failure case
                        }
                    }
                }
       
            }
        }

        $interest_type = 'flat';
        $loanScheme = BorrowerLoanScheme::where('borrower_id', $borrower_id)->first();
        if($loanScheme) {
            // product meta from the correct table based on pricing source
            if (($loanScheme->pricing_source ?? 'legacy') === 'oem_dynamic') {
                $product_details = \App\Models\OemDynamicProduct::find($loanScheme->product_id);
                if($product_details) {
                    $model_name       = $product_details->product_name;
                    $product_id       = $product_details->oem_product_unq;
                    $product_type     = $product_details->product_type;
                    $product_sub_type = $product_details->product_sub_type;
                    $manufacture_name = $product_details->manufacture_name;
                }
            } else {
                $product_details = ProductModel::find($loanScheme->product_id);
                if($product_details) {
                    $model_name       = $product_details->name;
                    $product_id       = $product_details->model_unq;
                    $product_type     = $product_details->product_type;
                    $product_sub_type = $product_details->sub_product_type;
                    $manufacture_name = $product_details->manufacture_name;
                }
            }

            // financials always come from the scheme (v2 = resolved per-borrower values)
            $ex_showroom_price          = $loanScheme->ex_showroom_price;
            $processing_fee             = $loanScheme->processing_fee;
            $product_interest           = $loanScheme->interest;
            $product_processing_fee     = $loanScheme->processing_fee;
            $product_subvention_amount  = $loanScheme->subvention_amount;
            $product_fldg               = $loanScheme->fldg;
            $product_iot                = $loanScheme->iot;
            $product_insurance_1st_year = $loanScheme->insurance_1st_year;
            $product_insurance_2nd_year = $loanScheme->insurance_2nd_year;
            $interest_type = !empty($loanScheme->interest_type) ? $loanScheme->interest_type : 'flat';
        } else {
            $product_details_query = ProductModel::where('dealer_id', $dealer_primary_key);
            if($model_id != '') {
                $product_details_query->where('model_unq', $model_id);
            } else {
                $product_details_query->where('name', $model_name);
            }
            $product_details = $product_details_query->first();
            if($product_details) {
                $model_name = $product_details->name;
                $product_id = $product_details->model_unq;
                $product_type = $product_details->product_type;
                $ex_showroom_price = $product_details->ex_showroom_price;
                $processing_fee = $product_details->processing_fee;
                $product_sub_type = $product_details->sub_product_type;
                $manufacture_name = $product_details->manufacture_name;
            
                // Extract product details
                $product_interest           = $product_details->interest;
                $product_processing_fee     = $product_details->processing_fee;
                $product_subvention_amount  = $product_details->subvention_amount;
                $product_fldg               = $product_details->fldg;
                $product_iot                = $product_details->iot;
                $product_insurance_1st_year = $product_details->insurance_1st_year;
                $product_insurance_2nd_year = $product_details->insurance_2nd_year;
                $interest_type = !empty($product_details->interest_type) ? $product_details->interest_type : 'flat';
            }
        }

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

        // $net_loan_amount = $loan_amount + $iot_amount + $second_year_insurance;
        $processing_fee = $product_processing_fee;
        $subvention_amount = $product_subvention_amount;
        $fldg = $product_fldg;
        $first_year_insurance = ($product_insurance_1st_year > 0) ? $product_insurance_1st_year : '............';

        $net_loan_amount = $loan_amount + $product_iot + $second_year_insurance;

        $net_disbursal = $net_loan_amount - ($product_processing_fee + $product_subvention_amount + $product_insurance_1st_year + $product_fldg + $product_iot + $second_year_insurance);

        // $net_disbursal = $loan_amount - ($processing_fee + $subvention_amount + $first_year_insurance);
        // $emi_amount = $this->calculateFlatInterestEMI($net_loan_amount, $interest, $tenure);
        // $emi = $this->calculateFlatInterestEMI($loan_amount, 17.5, $loan_term);
        
        $loan_amount = $net_loan_amount;
        // $repayment_schedule_calculation = $this->generateReducingInterestSchedule($loan_amount, $interest, $loan_term);
        $repayment_schedule_calculation = $this->generateInterestSchedule($loan_amount, $interest, $loan_term, $interest_type);
        $emi = $repayment_schedule_calculation['emi_amount'];

        

        $borrower_account_details = BorrowerAccountDetails::where('borrower_id',  $loan_basic_details->id)->first();
        if($borrower_account_details) {
            if($borrower_account_details->ifsc != '') {

                $borrowerBankInfoResponse = $this->get_ifsc_information($borrower_account_details->ifsc)->getData(true);
                
                if (isset($borrowerBankInfoResponse['status']) && $borrowerBankInfoResponse['status'] == 'success') {
                    $borrower_branch_name = $borrowerBankInfoResponse['data']['branch'] ?? '';
                } else {
                    $borrower_branch_name = ''; // Handle failure case
                }
            }
            $borrower_branch_name = $borrower_branch_name ?? '';
            $borrower_account_name = $borrower_account_details->account_holder_name;
            $borrower_account_number = $borrower_account_details->account_number;
            $borrower_ifsc = $borrower_account_details->ifsc;
            $borrower_bank_name = $borrower_account_details->bank_name;
        }

        if($loan_basic_details->product_id == 4) {
            $loan_products = BorrowerLoanProducts::where('borrower_id', $loan_basic_details->id)->get();
            $product_count = 0;
            foreach($loan_products as $loan_product) {
                $product_count++;
                $model_name = '';
                $loan_product_model = ProductModel::find($loan_product->product_id);
                if($loan_product_model) {
                    if($product_count == 1) {
                        $model_name .= $loan_product_model->name . '(' . $loan_product->quantity . ')';
                    } else {
                        $model_name .= ', ' . $loan_product_model->name . '(' . $loan_product->quantity . ')';
                    }
                }
            }
        }
        
        $differentBouncingCharge = (in_array($product_sub_type, ['EKA L5-3S', 'EKA L5-6S'])) ? true : false;
        $data['loan_details'] = [
            'borrower_id' => isset($loan_basic_details->borrower_id) ? $loan_basic_details->borrower_id : '',
            'dealer_id' => isset($loan_basic_details->dealer_id) ? $loan_basic_details->dealer_id : '',
            'dealer_name' => isset($dealer_name) ? $dealer_name : '',
            'vehicle_cost' => isset($ex_showroom_price) ? $ex_showroom_price : 0,
            'model' => isset($model_name) ? $model_name : '',
            'loan_amount' =>$loan_amount,
            'loan_term' =>$loan_term,
            'total_interest' =>$repayment_schedule_calculation['total_interest'],
            'product_id' =>isset($product_id) ? $product_id : '',
            'product_type_id' =>isset($loan_basic_details->product_id) ? $loan_basic_details->product_id : '',
            'roi' => $repayment_schedule_calculation['flat_rate'],
            'flat_rate' => $repayment_schedule_calculation['flat_rate'],
            'reducing_roi' => $repayment_schedule_calculation['effective_irr'],
            'annual_roi' => $interest,
            'interest_type' => $interest_type,
            'annual_reducing_roi' => $repayment_schedule_calculation['effective_irr'],
            'purpose_of_loan' => isset($product_type) ? $product_type : '',
            'processing_fee' => $processing_fee ?? '',
            'application_charges' =>'',
            'add_delay_interest' =>'3',
            'bouncing_charges' => ($differentBouncingCharge === true) ? '700' : '500',
            'iot_charges' =>$product_iot ?? '',
            'insurance_1st_year' => $first_year_insurance ?? '',
            'life_insurance_amount' =>'',
            'net_disbursal_amount' =>'',
            'disbursement_date' =>'',
            'loan_account_number' =>'',
            'ex_showroom_price' =>($ex_showroom_price > 0) ? $ex_showroom_price : '....................',
            'dealer_name' =>$dealer_name ?? '',
            'dealer_bank_name' =>$dealer_bank_name ?? '',
            'dealer_account_name' =>$dealer_beneficiary_name ?? '',
            'dealer_ifsc_code' =>$dealer_ifsc_code ?? '',
            'dealer_account_number' =>$dealer_account_number ?? '',
            'total_payment_by_borrower' => $repayment_schedule_calculation['total_loan_amount'],
            'total_payment_by_borrower_in_words' => $this->numberToWords($repayment_schedule_calculation['total_loan_amount']),
            'place_of_aggrement' =>'',
            'date_of_aggrement' =>'',
            'pre_closure_charge' =>'4',
            'emi' =>round($emi),
            'advance_emi' =>'',
            'emi_start_date' =>'',
            'emi_end_date' =>'',
            'monthly_emi_date' =>'',
            'vehicle_manufacture_year' =>'',
            'manufacturer_name' =>$manufacture_name,
            'registration_number' =>'',
            'other_vehicle_numbers' =>'',
            'hypothication_date' =>date('d-m-Y'),
            'loan_amount_in_words' =>$this->numberToWords($loan_amount),
            'repayment_schedule' => $repayment_schedule_calculation,
        ];

        $borrower_details = BorrowerKyc::where('borrower_id', $borrower_id)->latest()->first();
        
        $data['borrower_details']['basic'] = [
            'name'              => isset($borrower_details->name) ? $borrower_details->name : '',
            'father_name'       => isset($borrower_details->father_name) ? $borrower_details->father_name : '',
            'mobile'            => isset($borrower_details->mobile) ? $borrower_details->mobile : '',
            'email'             => isset($borrower_details->email) ? $borrower_details->email : '',
            'dob_date'          => !empty($borrower_details->dob_date) ? date('d-m-Y', strtotime($borrower_details->dob_date)) : '',
            'age'               => !empty($borrower_details->dob_date) ? (date('Y') - date('Y', strtotime($borrower_details->dob_date))) : '',
            'marital_status'    => isset($borrower_details->marital_status) ? $borrower_details->marital_status : '',
            'husband_name'      => isset($borrower_details->husband_name) ? $borrower_details->husband_name : '',
            'gender'            => isset($borrower_details->gender) ? $borrower_details->gender : '',
            'residing_at'       => isset($borrower_details->residing_at) ? $borrower_details->residing_at : '',
            'pan'               => isset($borrower_details->pan) ? $borrower_details->pan : '',
            'skip_co_applicant' => isset($borrower_details->skip_co_applicant) ? $borrower_details->skip_co_applicant : false,
            'skip_guarantor'    => isset($borrower_details->skip_guarantor) ? $borrower_details->skip_guarantor : false,
        ];

        $data['borrower_details']['bank'] = [
            'bank_name' => $borrower_bank_name ?? '',
            'branch_name' => $borrower_branch_name ?? '',
            'account_number' => $borrower_account_number ?? '',
            'ifsc' => isset($borrower_ifsc) ? strtoupper($borrower_ifsc) : '',
        ];

        $borrower_address = BorrowerAddress::where('borrower_id', $borrower_id)->latest()->first();
        $borrower_aadhar_address = $this->user_address_details($borrower_id, "BORROWER");

        $data['borrower_details']['address'] = [
            'resident_type'     => isset($borrower_address->resident_type) ? $borrower_address->resident_type : '',
            'current_address'   => isset($borrower_address->current_address) ? $borrower_address->current_address : '',
            'years_living'      => isset($borrower_address->years_living) ? intval($borrower_address->years_living) : '',
            'permanent_address' => isset($borrower_address->permanent_address) ? $borrower_address->permanent_address : '',
            'family_members'    => isset($borrower_address->family_members) ? $borrower_address->family_members : '',
            'earning_members'   => isset($borrower_address->earning_members) ? $borrower_address->earning_members : '',
            'family_income'     => isset($borrower_address->family_income) ? $borrower_address->family_income : '',
            'place_of_business' => isset($borrower_address->city) ? $borrower_address->city : '',
            'landmark' => $borrower_aadhar_address['data']['full_address']['landmark'] ?? '',
            'city' => $borrower_aadhar_address['data']['full_address']['vtc'] ?? '',
            'district' => $borrower_aadhar_address['data']['full_address']['dist'] ?? '',
            'state' => $borrower_aadhar_address['data']['full_address']['state'] ?? '',
            'pincode' => $borrower_aadhar_address['data']['full_address']['pc'] ?? '',
            'p_city' => $borrower_aadhar_address['data']['full_address']['vtc'] ?? '', // permanent
            'p_district' => $borrower_aadhar_address['data']['full_address']['dist'] ?? '', // permanent
            'p_state' => $borrower_aadhar_address['data']['full_address']['state'] ?? '', // permanent
            'p_pincode' => $borrower_aadhar_address['data']['full_address']['pc'] ?? '', // permanent
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
            'dob_date'          => !empty($co_applicant_details->dob_date) ? date('d-m-Y', strtotime($co_applicant_details->dob_date)) : '',
        ];

        $coapplicant_address = CoapplicantOccupation::where('borrower_id', $borrower_id)->latest()->first();
        $coapplicant_aadhar_address = $this->user_address_details($borrower_id, "COAPPLICANT");

        $co_full_address = $coapplicant_address ? ($coapplicant_aadhar_address['data']['full_address'] ?? []) : [];
        $data['co_applicant_details']['address'] = [
            'resident_type'     => isset($coapplicant_address->resident_type) ? $coapplicant_address->resident_type : '',
            'current_address'   => isset($coapplicant_address->current_address) ? $coapplicant_address->current_address : '',
            'years_living'      => isset($coapplicant_address->years_living) ? $coapplicant_address->years_living : '',
            'permanent_address' => isset($coapplicant_address->permanent_address) ? $coapplicant_address->permanent_address : '',
            'landmark' => $co_full_address['landmark'] ?? '',
            'city' => $co_full_address['vtc'] ?? '',
            'district' => $co_full_address['dist'] ?? '',
            'state' => $co_full_address['state'] ?? '',
            'pincode' => $co_full_address['pc'] ?? '',
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
            'dob_date'          => !empty($guarantor_details->dob_date) ? date('d-m-Y', strtotime($guarantor_details->dob_date)) : '',
            'husband_name'      => isset($guarantor_details->husband_name) ? $guarantor_details->husband_name : '',
            'age'               => !empty($guarantor_details->dob_date) ? date('Y') - date('Y', strtotime($guarantor_details->dob_date)) : '',
        ];

        $guarantor_address = GuarantorOccupation::where('borrower_id', $borrower_id)->latest()->first();
        
        $guarantor_aadhar_address = $this->user_address_details($borrower_id, "GUARANTOR");
        if(isset($guarantor_address->occupation_type) && !empty($guarantor_address->occupation_type)) {
            $g_occupation_type = $this->get_occupation_type($guarantor_address->occupation_type);
        }
        $full_address = $guarantor_address ? ($guarantor_aadhar_address['data']['full_address'] ?? []) : [];
        
        $data['guarantor_details']['address'] = [
            'occupation_type'   => isset($g_occupation_type) ? $g_occupation_type : '',
            'resident_type'     => isset($guarantor_address->resident_type) ? $guarantor_address->resident_type : '',
            'current_address'   => isset($guarantor_address->current_address) ? $guarantor_address->current_address : '',
            'years_living'      => isset($guarantor_address->years_living) ? intval($guarantor_address->years_living) : '',
            'permanent_address' => isset($guarantor_address->permanent_address) ? $guarantor_address->permanent_address : '',
            'total_income'      => isset($guarantor_address->total_income) ? $guarantor_address->total_income : '',
            
            'landmark' => $full_address['landmark'] ?? '',
            'city' => $full_address['vtc'] ?? '',
            'district' => $full_address['dist'] ?? '',
            'state' => $full_address['state'] ?? '',
            'pincode' => $full_address['pc'] ?? '',
            'p_city' => $full_address['vtc'] ?? '', // permanent
            'p_district' => $full_address['dist'] ?? '', // permanent
            'p_state' => $full_address['state'] ?? '', // permanent
            'p_pincode' => $full_address['pc'] ?? '', // permanent
        ];

        return $data;
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

    public function check_if_aadhar_exist($aadhar) {
        $validator = Validator::make(['aadhar' => $aadhar], [
            'aadhar' => 'required|digits:12',
        ]);
    
        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $is_exist = DB::table('borrower_kycs as bk')
                        ->join('loan_basic_details as lbd', 'bk.borrower_id', '=', 'lbd.id')
                        ->where('bk.aadhar', $aadhar)
                        ->where(function($query) {
                            $query->where('lbd.status', 'NEW')
                                ->orWhere('lbd.status', 'ACTIVE');
                        })
                        ->first();
        // && $is_exist->count() > 0
        if($is_exist) {
            return $this->send_response('failed', "An application already exists with this Aadhar. You can apply again only after the account is closed.", 422);
        } else {
            return $this->send_response('success', "You can apply for a loan", 200);
        }     
    }
    
    public function check_if_pan_exist($pan) {
        $validator = Validator::make(['pan' => $pan], [
            'pan' => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'],
        ]);
    
        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }
        
        $is_exist = DB::table('borrower_kycs as bk')
                        ->join('loan_basic_details as lbd', 'bk.borrower_id', '=', 'lbd.id')
                        ->where('bk.pan', $pan)
                        ->where(function($query) {
                            $query->where('lbd.status', 'NEW')
                                ->orWhere('lbd.status', 'ACTIVE');
                        })
                        ->first();
        // && $is_exist->count() > 0
        if($is_exist) {
            return $this->send_response('failed', "An application already exists with this PAN. You can apply again only after the account is closed.", 422);
        } else {
            return $this->send_response('success', "You can apply for a loan", 200);
        }     
    }

    public function get_professions() {
        $professions = [
            'Farming',
            'Fishing',
            'Teaching',
            'Carpentry',
            'Grocery',
            'Vending',
            'Weaving',
            'Cattle rearing',
            'Blacksmith',
            'Barber',
            'Mechanic',
            'Gardener',
            'Shop owner',
            'Others',
        ];

        sort($professions);

        return $this->send_response('success', "Professtion list fetched", 200, $professions);
    }

    public function delete_loan_steps(Request $request) {
        $baseUrl = request()->root();
        if($baseUrl != 'http://13.200.65.87') {
            return response()->json(['status' => 'error', 'message' => "Invalid request"], 422);
        }

        $validator = Validator::make($request->all(), [
            'borrower_id' => 'required|integer|exists:loan_basic_details,id',
            'steps_to_delete' => 'required|integer|min:1',
        ]);
    
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }
    
        try {
            DB::beginTransaction();
    
            $borrower_id = $request->borrower_id;
            $steps_to_delete = $request->steps_to_delete;
            // $executive_id = $request->user()->id;
    
            $loanDetails = LoanBasicDetails::where('id', $borrower_id)
                            ->where('status', '<>', 'CLOSED')
                            // ->where('executive_id', $executive_id)
                            ->first();
    
            if (!$loanDetails) {
                return response()->json(['status' => 'error', 'message' => 'Loan not found or already closed.'], 422);
            }
    
            $loan_application_steps = json_decode($loanDetails->loan_application_steps);
    
            if (empty($loan_application_steps)) {
                return response()->json(['status' => 'error', 'message' => 'No steps to delete.'], 
            422);
            }
    
            if ($steps_to_delete > count($loan_application_steps)) {
                return response()->json(['status' => 'error', 'message' => 'Number of steps to delete exceeds total steps.'], 
            422);
            }
    
            // Remove the specified number of steps from the end
            $updated_steps = array_slice($loan_application_steps, 0, count($loan_application_steps) - $steps_to_delete);
    
            // Get the last step of the remaining steps
            $last_remaining_step = !empty($updated_steps) ? end($updated_steps) : null;
    
            // Update the loan application steps in the database
            $loanDetails->loan_application_steps = json_encode($updated_steps);
            $loanDetails->save();
    
            DB::commit();
    
            return response()->json([
                'status' => 'success', 
                'message' => 'Steps deleted successfully.', 
                'last_remaining_step' => $last_remaining_step,  // showing the last step of the remaining steps
                'remaining_steps' => $updated_steps
            ], 200);
    
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'An error occurred while deleting steps.'], 422);
        }
    }

    public function yourApiMethod(Request $request)
    {
        // Retrieve the Device-ID from request headers
        $deviceId = $request->header('deviceid');

        // Check if the Device-ID exists
        if ($deviceId) {
            // Process with the device ID
            return response()->json(['device_id' => $deviceId]);
        } else {
            // Handle missing Device-ID case
            return response()->json(['error' => 'Device ID not provided'], 400);
        }
    }

    public function get_address_with_lat_long(Request $request)
    {
        return $this->send_response("failed", "Api not in use", 422);
        
        $errorCount = 0;
        $errorArray = [];
        $input      = $request->all();
        if($input == null) {
            $errorCount++;
            $errorArray['body'] = "Request body is empty";
            return $this->send_response("failed", $errorArray['body'], 422);
        }
        date_default_timezone_set('Asia/Kolkata');
        if (!isset($input['longitude']) || empty($input['longitude'])) {
            $errorCount++;
            $errorArray['longitude'] = "Longitude  is required";
            return $this->send_response("failed", $errorArray['longitude'], 422);
        }
        
        if (!isset($input['latitude']) || empty($input['latitude'])) {
            $errorCount++;
            $errorArray['latitude'] = "Latitude  is required";
            return $this->send_response("failed", $errorArray['latitude'], 422);
        }

        // $service_api    = new ServiceApiController;
        $ip             = $this->get_user_ip();
        $unique_id = $this->generate_random_id(4);

        $longitude  = $input['longitude'];
        $latitude   = $input['latitude'];
        // $user_id    = $data['user_id'];

        $jsonRequestBody = json_encode([
            'uniqueId' => $unique_id,
            'latitude' => $latitude,
            'longitude' => $longitude
        ]);
        try {
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api.digitap.ai/ent/v1/address-verification',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonRequestBody,
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/json',
                    'authorization: ' . base64_encode("17087122:MsNpuxGLcNkpBy41sDyXAc0KEtrWM3bM"),
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            $result = json_decode($response);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }

            if (isset($result->code) && $result->code == 200) {
                $api_status = "VALID";
            } else {
                $api_status = "INVALID";
            }

            $vendorServiceApi               = new VendorServiceApi;
            $vendorServiceApi->user_id      = isset($user_id) ? $user_id : 0;
            $vendorServiceApi->ip_address   = $ip;
            $vendorServiceApi->date_time    = date("Y-m-d H:i:s");
            $vendorServiceApi->status       = $api_status;
            $vendorServiceApi->api_flag     = "ADDRESS_VERIFICATION_API";
            $vendorServiceApi->request      = json_encode($input);
            $vendorServiceApi->response     = $response;
            $vendorServiceApi->save();

            // if (isset($result->code) && $result->code == 200) {

            //     $result_response = [
            //         'status' => 'success',
            //         'data' => [
            //             'address' => $result->model->address,
            //             'pincode' => $result->model->pincode,
            //             'district' => $result->model->district,
            //             'state' => $result->model->state,
            //         ]
            //     ];
            // } else {
            //     $result_response = [
            //         'status' => 'invalid',
            //         'data' => 'Address verification failed'
            //     ];
            // }

            return $result;
        } catch (Exception $e) {
            $result_response = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [],
            ];

            return $result_response;
        }
    
    }

    public function getFatherName($input) {
        // Check if the input is empty and return an empty string
        if (empty($input)) {
            return '';
        }

        // Define patterns for "S/O", "C/O", and "D/O"
        $patterns = [
            '/S\/O[: ]+(.+)/i',  // Matches "S/O" followed by the name
            '/C\/O[: ]+(.+)/i',  // Matches "C/O" followed by the name
            '/D\/O[: ]+(.+)/i'   // Matches "D/O" followed by the name
        ];

        // Iterate through patterns and return the first match found
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $matches)) {
                return trim($matches[1]);
            }
        }

        // If no pattern matches or the input starts with "W/O", return an empty string
        return '';
    }

    public function store_app_log($action, $details, $user_type, $borrower_id=null, $reference = null) {
        if($reference !== null) {
            $reference = json_encode($reference);
        }
        
        $activity_log               = new AppActivityLog();
        $activity_log->user_id      = auth()->id();
        $activity_log->user_type    = $user_type;
        $activity_log->borrower_id  = $borrower_id;
        $activity_log->action       = $action;
        $activity_log->details      = $details;
        $activity_log->reference    = $reference;
        $activity_log->ip_address   = $this->get_user_ip();
        $activity_log->save();
    }

    public function getHusbandName($input) {
        // Check if the input is empty and return an empty string
        if (empty($input)) {
            return '';
        }

        // Define pattern for "W/O"
        $pattern = '/^W\/O[: ]+(.+)/i';  // Matches "W/O" followed by the husband's name

        // Check if the pattern matches and return the husband's name
        if (preg_match($pattern, $input, $matches)) {
            return trim($matches[1]);
        }

        // If no pattern matches or input does not start with "W/O", return an empty string
        return '';
    }

    public function calculateNetLoanAmount($loan_amount, $loan_term) {
        $iot_amount = 3000;
        $second_year_insurance = ($loan_term > 15 && $loan_term <= 24) ? 0 : 0;
        return $loan_amount + $iot_amount + $second_year_insurance;
    }

    public function transformBorrowerDetails($borrower_kyc) {
        if (!$borrower_kyc) return [];

        $borrower_aadhar = $this->maskAadharNumber($borrower_kyc->aadhar);
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
        ];
    }

    public function transformDocuments($documents)  {
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
        ];
    }

    public function transformCreditSummary($creditSummary) {
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

    public function transformBorrowerAddress($borrower_address, $borrower_primary) {
        if (!$borrower_address) return [];

        $aadhar_address = $this->user_address_details($borrower_primary, 'BORROWER');
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

    public function transformBorrowerOccupation($borrower_occupation) {
        if (!$borrower_occupation) return [];

        $b_occupation_type = isset($borrower_occupation->occupation_type)
            ? $this->get_occupation_type($borrower_occupation->occupation_type)
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

    public function transformCoApplicantDetails($co_applicant_details) {
        if (!$co_applicant_details) return [];
        $co_applicant_aadhar = $this->maskAadharNumber($co_applicant_details->aadhar);

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

    public function transformCoApplicantAddress($coapplicant_address, $borrower_primary) {
        if (!$coapplicant_address) return [];

        $coapplicant_aadhar_address = $this->user_address_details($borrower_primary, 'COAPPLICANT');
        $address_details = isset($coapplicant_aadhar_address['status']) && $coapplicant_aadhar_address['status'] == 'success'
            ? $coapplicant_aadhar_address['data']
            : '';
        $c_occupation_type = isset($coapplicant_address->occupation_type)
        ? $this->get_occupation_type($coapplicant_address->occupation_type)
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

    public function transformGuarantorDetails($guarantor_details) {
        if (!$guarantor_details) return [];

        $guarantor_aadhar = $this->maskAadharNumber($guarantor_details->aadhar);
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

    public function transformGuarantorOccupation($guarantor_occupation, $borrower_primary) {
        if (!$guarantor_occupation) return [];
        $guarantor_aadhar_address = $this->user_address_details($borrower_primary, 'GUARANTOR');
        $address_details = isset($guarantor_aadhar_address['status']) && $guarantor_aadhar_address['status'] == 'success'
            ? $guarantor_aadhar_address['data']
            : '';

        $g_occupation_type = isset($guarantor_occupation->occupation_type)
            ? $this->get_occupation_type($guarantor_occupation->occupation_type)
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

    /**
     * Api to get ifsc code details
     * Created By: Mir Akib Javed
     * Created on: 17-12-2024
     */

    public function get_ifsc_information($ifsc) {
        try {            
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }
                
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://ifsc.razorpay.com/'. $ifsc,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json'
                ),
            ));
            
            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            $result = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }
            
            if($httpCode !== 200) {
                return $this->send_response('failed', "No records found", 422);
            }

            $result_response = [
                'ifsc' => $result['IFSC'] ?? '',
                'micr' => $result['MICR'] ?? '',
                'bankcode' => $result['BANKCODE'] ?? '',
                'bank' => $result['BANK'] ?? '',
                'branch' => $result['BRANCH'] ?? '',
                'city' => $result['CITY'] ?? '',
                'centre' => $result['CENTRE'] ?? '',
                'district' => $result['DISTRICT'] ?? '',
                'state' => $result['STATE'] ?? '',
                'address' => $result['ADDRESS'] ?? '',
            ];

            return $this->send_response('success', "Bank Information fetched", 200, $result_response);
        } catch (Exception $e) {
            return $this->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    // public function check_similar_aadhar_data($aadhar, $applicant_type, $borrower_id = null) {
    //     /**
    //      * Api to check:
    //      *      1. if any active application is there with same aadhar
    //      *      2. in case of Co-applicant:
    //      *          Check if aadhar is same as borrower
    //      *      3. in case of Guarantor:
    //      *          check if aadhar is same as borrower or co-applicant
    //      *      if any of the above condition is true then return false else true
    //      * for applicant type "COAPPLICANT" and "GUARANTOR" there will be borrower id else not. 
    //      * 
    //      * true: aadhar can be used and borrower can move forword
    //      */

    //     $query = DB::table('loan_basic_details as lbd')
    //         ->leftJoin('borrower_kycs as bk', 'bk.borrower_id', '=', 'lbd.id')
    //         ->leftJoin('coapplicant_kycs as ck', 'ck.borrower_id', '=', 'lbd.id')
    //         ->leftJoin('guarantors as g', 'g.borrower_id', '=', 'lbd.id')
    //         ->whereIn('lbd.status', ['NEW', 'ACTIVE']);

    //     // Restriction for borrower (existing application check)
    //     $is_existing_borrower = $query->where('bk.aadhar', $aadhar)->exists();
    //     if ($is_existing_borrower && $applicant_type === 'BORROWER') {
    //         return [
    //             'status' => false,
    //             'message' => 'An application with this Aadhar already exists.'
    //         ];
    //     }

    //     // Restriction for co-applicant (borrower Aadhar conflict)
    //     $is_coapplicant_conflict = $query->where(function ($q) use ($borrower_id, $aadhar) {
    //         $q->where('bk.borrower_id', $borrower_id)
    //           ->where('bk.aadhar', $aadhar);
    //     })->exists();
    //     if ($is_coapplicant_conflict && $applicant_type === 'COAPPLICANT') {
    //         return [
    //             'status' => false,
    //             'message' => 'Borrower and Co-applicant cannot have the same Aadhar.'
    //         ];
    //     }

    //     // Restriction for guarantor (borrower or co-applicant Aadhar conflict)
    //     $is_guarantor_conflict = $query->where(function ($q) use ($borrower_id, $aadhar) {
    //         $q->where('bk.borrower_id', $borrower_id)
    //           ->orWhere('ck.aadhar', $aadhar)
    //           ->where('bk.aadhar', $aadhar);
    //     })->exists();
    //     if ($is_guarantor_conflict && $applicant_type === 'GUARANTOR') {
    //         return [
    //             'status' => false,
    //             'message' => 'Guarantor cannot have the same Aadhar as Borrower or Co-applicant.'
    //         ];
    //     }

    //     return ['status' => true, 'message' => 'Aadhar is valid for use.'];  // Return true if no conflict, false otherwise

    // }
public function check_similar_aadhar_data($aadhar) {
    /**
     * API to check if:
     * 1. The Aadhar exists in `borrower_kycs` with an active application.
     * 2. The Aadhar exists in `coapplicant_kycs` with an active application.
     * 3. The Aadhar exists in `guarantors` with an active application.
     * 
     * If any of these conditions are true, return false with the relevant message.
     * Otherwise, return true.
     */

    // Check if Aadhar exists in `borrower_kycs` with an active application
    $is_existing_borrower = DB::table('borrower_kycs as bk')
        ->join('loan_basic_details as lbd', 'bk.borrower_id', '=', 'lbd.id')
        ->where('bk.aadhar', $aadhar)
        ->whereIn('lbd.status', ['NEW', 'ACTIVE'])
        ->exists();

    if ($is_existing_borrower) {
        return [
            'status' => false,
            'message' => 'This Aadhar is already associated with an active borrower application.'
        ];
    }

    // Check if Aadhar exists in `coapplicant_kycs` with an active application
    $is_existing_coapplicant = DB::table('coapplicant_kycs as ck')
        ->join('loan_basic_details as lbd', 'ck.borrower_id', '=', 'lbd.id')
        ->where('ck.aadhar', $aadhar)
        ->whereIn('lbd.status', ['NEW', 'ACTIVE'])
        ->exists();

    if ($is_existing_coapplicant) {
        return [
            'status' => false,
            'message' => 'This Aadhar is already associated with an active co-applicant application.'
        ];
    }

    // Check if Aadhar exists in `guarantors` with an active application
    $is_existing_guarantor = DB::table('guarantors as g')
        ->join('loan_basic_details as lbd', 'g.borrower_id', '=', 'lbd.id')
        ->where('g.aadhar', $aadhar)
        ->whereIn('lbd.status', ['NEW', 'ACTIVE'])
        ->exists();

    if ($is_existing_guarantor) {
        return [
            'status' => false,
            'message' => 'This Aadhar is already associated with an active guarantor application.'
        ];
    }

    // If no conflicts are found, return success
    return ['status' => true, 'message' => 'Aadhar is valid for use.'];
}

    
    // public function check_similar_pan_data($pan, $applicant_type, $borrower_id = null) {
    //     /**
    //      * Api to check:
    //      *      1. if any active application is there with same pan
    //      *      2. in case of Co-applicant:
    //      *          Check if pan is same as borrower
    //      *      3. in case of Guarantor:
    //      *          check if pan is same as borrower or co-applicant
    //      *      if any of the above condition is true then return false else true
    //      * for applicant type "COAPPLICANT" and "GUARANTOR" there will be borrower id else not. 
    //      * 
    //      * true: pan can be used and borrower can move forword
    //      */
        
    //     $query = DB::table('loan_basic_details as lbd')
    //         ->leftJoin('borrower_kycs as bk', 'bk.borrower_id', '=', 'lbd.id')
    //         ->leftJoin('coapplicant_kycs as ck', 'ck.borrower_id', '=', 'lbd.id')
    //         ->leftJoin('guarantors as g', 'g.borrower_id', '=', 'lbd.id')
    //         ->whereIn('lbd.status', ['NEW', 'ACTIVE']);

    //     // Restriction for borrower (existing application check)
    //     $is_existing_borrower = $query->where('bk.pan', $pan)->exists();
    //     if ($is_existing_borrower && $applicant_type === 'BORROWER') {
    //         return [
    //             'status' => false,
    //             'message' => 'An application with this PAN already exists.'
    //         ];
    //     }

    //     // Restriction for co-applicant (borrower PAN conflict)
    //     $is_coapplicant_conflict = $query->where(function ($q) use ($borrower_id, $pan) {
    //         $q->where('bk.borrower_id', $borrower_id)
    //           ->where('bk.pan', $pan);
    //     })->exists();
    //     if ($is_coapplicant_conflict && $applicant_type === 'COAPPLICANT') {
    //         return [
    //             'status' => false,
    //             'message' => 'Borrower and Co-applicant cannot have the same PAN.'
    //         ];
    //     }

    //     // Restriction for guarantor (borrower or co-applicant PAN conflict)
    //     $is_guarantor_conflict = $query->where(function ($q) use ($borrower_id, $pan) {
    //         $q->where('bk.borrower_id', $borrower_id)
    //           ->orWhere('ck.pan', $pan)
    //           ->where('bk.pan', $pan);
    //     })->exists();
    //     if ($is_guarantor_conflict && $applicant_type === 'GUARANTOR') {
    //         return [
    //             'status' => false,
    //             'message' => 'Guarantor cannot have the same PAN as Borrower or Co-applicant.'
    //         ];
    //     }

    //     return ['status' => true, 'message' => 'PAN is valid for use.'];
    // }
    public function check_similar_pan_data($pan) {
        /**
         * API to check if:
         * 1. The PAN exists in `borrower_kycs` with an active application.
         * 2. The PAN exists in `coapplicant_kycs` with an active application.
         * 3. The PAN exists in `guarantors` with an active application.
         * 
         * If any of these conditions are true, return false with the relevant message.
         * Otherwise, return true.
         */

        // Check if PAN exists in `borrower_kycs` with an active application
        $is_existing_borrower = DB::table('borrower_kycs as bk')
            ->join('loan_basic_details as lbd', 'bk.borrower_id', '=', 'lbd.id')
            ->where('bk.pan', $pan)
            ->whereIn('lbd.status', ['NEW', 'ACTIVE'])
            ->exists();

        if ($is_existing_borrower) {
            return [
                'status' => false,
                'message' => 'This PAN is already associated with an active borrower application.'
            ];
        }

        // Check if PAN exists in `coapplicant_kycs` with an active application
        $is_existing_coapplicant = DB::table('coapplicant_kycs as ck')
            ->join('loan_basic_details as lbd', 'ck.borrower_id', '=', 'lbd.id')
            ->where('ck.pan', $pan)
            ->whereIn('lbd.status', ['NEW', 'ACTIVE'])
            ->exists();

        if ($is_existing_coapplicant) {
            return [
                'status' => false,
                'message' => 'This PAN is already associated with an active co-applicant application.'
            ];
        }

        // Check if PAN exists in `guarantors` with an active application
        $is_existing_guarantor = DB::table('guarantors as g')
            ->join('loan_basic_details as lbd', 'g.borrower_id', '=', 'lbd.id')
            ->where('g.pan', $pan)
            ->whereIn('lbd.status', ['NEW', 'ACTIVE'])
            ->exists();

        if ($is_existing_guarantor) {
            return [
                'status' => false,
                'message' => 'This PAN is already associated with an active guarantor application.'
            ];
        }

        // If no conflicts are found, return success
        return ['status' => true, 'message' => 'PAN is valid for use.'];
    }

    public function get_rto_kit_template()
    {
        try {
            $fileName = 'rto-booklet-sample.pdf';
            // Define the path to the template directory
            $filePath = public_path("Assets/templates/{$fileName}");

            // Check if the file exists and is not a directory
            if (!file_exists($filePath) || !is_file($filePath)) {
                
                return $this->send_response('failed', "File not found", 404);
            }

            // Generate a secure download link
            $downloadLink = asset("Assets/templates/{$fileName}");

            return $this->send_response('success', "Template url retrieved", 200, ['download_link' => $downloadLink]);

        } catch (Exception $e) {
            // Log the exception for debugging purposes

            return $this->send_response('failed', "An unexpected error occurred. Please try again later.", 422);
        }
    }

    public function get_emi_debit_consent_data($borrower_id) {
        try {
            if(!isset($borrower_id) || empty($borrower_id)) {
                return $this->send_response('failed', "Invalid request", 422);
            }
            $borrower_details = LoanBasicDetails::select([
                'loan_basic_details.loan_amount', 'loan_basic_details.loan_term', 'loan_basic_details.lender_id',
                'dealers.id as dealer_id',
                'loan_basic_details.model_id',
                'loan_basic_details.model',
                'lenders.name as lender_name',
                'borrower_kycs.name',
                'borrower_addresses.current_address',
                'borrower_loan_schemes.interest',
                'borrower_loan_schemes.insurance_2nd_year',
                'borrower_loan_schemes.iot',
                'borrower_loan_schemes.interest_type',
                'borrower_loan_schemes.interest',
                'borrower_loan_schemes.insurance_2nd_year',
                'borrower_loan_schemes.iot',
                'borrower_loan_schemes.interest_type',
            ])
            ->leftJoin('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
            ->leftJoin('borrower_addresses', 'loan_basic_details.id', '=', 'borrower_addresses.borrower_id')
            ->leftJoin('lenders', 'loan_basic_details.lender_id', '=', 'lenders.id')
            ->leftJoin('dealers', 'loan_basic_details.dealer_id', '=', 'dealers.dealer_unq')
            ->leftJoin('borrower_loan_schemes', 'loan_basic_details.id', '=', 'borrower_loan_schemes.borrower_id')
            ->where('loan_basic_details.id', $borrower_id)->first();
            
            $interest_rate = $borrower_details->interest ?? null;
            $insurance_2nd_year = $borrower_details->insurance_2nd_year ?? null;
            $iot_amount = $borrower_details->iot ?? null;
            $second_year_insurance = ($borrower_details->loan_term > 15 && $borrower_details->loan_term <= 24) ? $insurance_2nd_year : 0;
            $interest_type = !empty($borrower_details->interest_type) ? $borrower_details->interest_type : 'flat';

            if(empty($borrower_details->interest)) {
                $product_query = ProductModel::where('dealer_id', $borrower_details->dealer_id);
                if($borrower_details->model_id != '') {
                    $product_query->where('model_unq', $borrower_details->model_id);
                } else {
                    $product_query->where('name', $borrower_details->model);
                }
                $dealer_product_details = $product_query->first();

                $interest_rate = isset($dealer_product_details) ? $dealer_product_details->interest : 0; 

                $insurance_2nd_year = $dealer_product_details->insurance_2nd_year ?? 0;

                $iot_amount = $dealer_product_details->iot ?? 0;
            }

            $final_loan_amount = $borrower_details->loan_amount + $iot_amount + $second_year_insurance;

            // $emi_chart_details = $this->generateReducingInterestSchedule($final_loan_amount, $interest_rate, $borrower_details->loan_term);
            $emi_chart_details = $this->generateInterestSchedule($final_loan_amount, $interest_rate, $borrower_details->loan_term, $interest_type);

            $emi_chart  = $emi_chart_details['schedule'];
            $emi = $emi_chart_details['emi_amount'];
            
            $result = [
                'borrower_name' => $borrower_details->name,
                'address' => $borrower_details->current_address,
                // 'bank_name' => $request->bank_name ?? '',
                // 'account_number' => $request->account_number ?? '',
                'lender_name' => $borrower_details->lender_name !== null ? $borrower_details->lender_name : '________________',
                'loan_type' => 'EV Loan',
                'loan_amount' => ($final_loan_amount) ? strval($final_loan_amount) : '',
                'emi_amount' => round($emi),
                'loan_term' => $borrower_details->loan_term ?? '',
                'emi_due_date' => '',
            ];
            
            return $this->send_response('success', "Debit consent data fetched", 200, $result);
        } catch (Exception $e) {
            return $this->send_response('failed', "Unknown error occurred:". $e->getMessage(), 422);
        }
    }

    public function get_emi_debit_consent_data_b2c() {
        try {
            $user_id = auth()->id();
            
            $borrower_details = LoanBasicDetails::select([
                'loan_basic_details.borrower_id', 'loan_basic_details.loan_amount', 'loan_basic_details.loan_term', 'loan_basic_details.lender_id',
                'dealers.id as dealer_id',
                'loan_basic_details.model_id',
                'loan_basic_details.model',
                'lenders.name as lender_name',
                'borrower_kycs.name',
                'borrower_addresses.current_address',
                'borrower_loan_schemes.interest',
                'borrower_loan_schemes.insurance_2nd_year',
                'borrower_loan_schemes.iot',
                'borrower_loan_schemes.interest_type',
            ])
            ->leftJoin('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
            ->leftJoin('borrower_addresses', 'loan_basic_details.id', '=', 'borrower_addresses.borrower_id')
            ->leftJoin('lenders', 'loan_basic_details.lender_id', '=', 'lenders.id')
            ->leftJoin('dealers', 'loan_basic_details.dealer_id', '=', 'dealers.dealer_unq')
            ->leftJoin('borrower_loan_schemes', 'loan_basic_details.id', '=', 'borrower_loan_schemes.borrower_id')
            ->where('loan_basic_details.user_id', $user_id)
            ->where('loan_basic_details.status', 'NEW')
            ->latest('loan_basic_details.created_at')
            ->first();
            
            $interest_rate = $borrower_details->interest ?? null;
            $insurance_2nd_year = $borrower_details->insurance_2nd_year ?? null;
            $iot_amount = $borrower_details->iot ?? null;
            $second_year_insurance = ($borrower_details->loan_term > 15 && $borrower_details->loan_term <= 24) ? $insurance_2nd_year : 0;
            $interest_type = !empty($borrower_details->interest_type) ? $borrower_details->interest_type : 'flat';

            if(empty($borrower_details->interest)) {
                $product_query = ProductModel::where('dealer_id', $borrower_details->dealer_id);
                if($borrower_details->model_id != '') {
                    $product_query->where('model_unq', $borrower_details->model_id);
                } else {
                    $product_query->where('name', $borrower_details->model);
                }
                $dealer_product_details = $product_query->first();
                $interest_rate = isset($dealer_product_details) ? $dealer_product_details->interest : 0; 

                $insurance_2nd_year = $dealer_product_details->insurance_2nd_year ?? 0;

                $iot_amount = $dealer_product_details->iot ?? 0;
                $second_year_insurance = ($borrower_details->loan_term > 15 && $borrower_details->loan_term <= 24) ? $insurance_2nd_year : 0;
            }
            $final_loan_amount = $borrower_details->loan_amount + $iot_amount + $second_year_insurance;

            // $emi_chart_details = $this->generateReducingInterestSchedule($final_loan_amount, $interest_rate, $borrower_details->loan_term);
            $emi_chart_details = $this->generateInterestSchedule($final_loan_amount, $interest_rate, $borrower_details->loan_term, $interest_type);

            $emi_chart  = $emi_chart_details['schedule'];
            $emi = intval($emi_chart_details['emi_amount']);
            // $emi = $this->calculateFlatInterestEMI($borrower_details->loan_amount, $dealer_product_details->interest, $borrower_details->loan_term);
            
            $result = [
                'borrower_id' => $borrower_details->borrower_id,
                'borrower_name' => $borrower_details->name,
                'address' => $borrower_details->current_address,
                // 'bank_name' => $request->bank_name ?? '',
                // 'account_number' => $request->account_number ?? '',
                'lender_name' => $borrower_details->lender_name !== null ? $borrower_details->lender_name : '________________',
                'loan_type' => 'EV Loan',
                'loan_amount' => ($final_loan_amount) ? strval($final_loan_amount) : '',
                'emi_amount' => round($emi),
                'loan_term' => $borrower_details->loan_term ?? '',
                'emi_due_date' => '',
            ];
            
            return $this->send_response('success', "Debit consent data fetched", 200, $result);
        } catch (Exception $e) {
            return $this->send_response('failed', "Unknown error occurred:". $e->getMessage(), 422);
        }
    }

    public function easebuzz_initiate_payment_api()
    {
        try {

            $key = '2PBP7IABZ2';
            $transactionId = "TEST". date('YmdHis'). $this->generate_random_string(4);
            $amount = 1;
            $productInfo = "TEST PRODUCT";
            $firstName = 'Test';
            $phone = 9732000000;
            $email = 'test@gmail.com';
            $success_url = 'https://google.com';
            $failure_url = 'https://google.com';
            $paymentMode = 'NB,DC,CC,MW,UPI,OM,EMI,BT,BT';
            $request_flow = 'SEAMLESS';
            $salt = 'DAH88E3UWQ';
            $hashData = $key."|".$transactionId."|".$amount."|".$productInfo."|".$firstName."|".$email."|||||||||||".$salt;
            $hash = hash('sha512', $hashData);

            $postData = "key=".$key."&txnid=".$transactionId."&amount=".$amount."&productinfo=".$productInfo."&firstname=".$firstName."&phone=".$phone."&email=".$email."&surl=".$success_url."&furl=".$failure_url."&hash=".$hash."&udf1=&udf2=&udf3=&udf4=&udf5=&udf6=&udf7=&address1=&address2=&city=&state=&country=&zipcode=&show_payment_mode=".$paymentMode."&request_flow=".$request_flow."&sub_merchant_id=&payment_category=&account_no=&ifsc=";
            
            $curl = curl_init();
            if ($curl === false) {
                throw new Exception('Failed to initialize cURL');
            }
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://testpay.easebuzz.in/payment/initiateLink',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded'
                ),
            ));

            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($curl));
            }
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }
            
            $status = 'failed';
            if($httpCode == 200) {
                $status = 'success';
            }
            $result = json_decode($response, true);
            $result['hash'] = $hash;

            return $this->send_response($status, "Api result fetched", $httpCode, $result);
        } catch (Exception $e) {
            return $this->send_response('failed', "An unknwon error occurred: " . $e->getMessage(), 422);
        }
    }

    public function get_vernacular_declaration_template()
    {
        try {
            $fileName = 'vernacular-declaration.pdf';
            // Define the path to the template directory
            $filePath = public_path("Assets/templates/{$fileName}");

            // Check if the file exists and is not a directory
            if (!file_exists($filePath) || !is_file($filePath)) {
                
                return $this->send_response('failed', "File not found", 404);
            }

            // Generate a secure download link
            $downloadLink = asset("Assets/templates/{$fileName}");

            return $this->send_response('success', "Template url retrieved", 200, ['download_link' => $downloadLink]);

        } catch (Exception $e) {
            // Log the exception for debugging purposes

            return $this->send_response('failed', "An unexpected error occurred. Please try again later.", 422);
        }
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

    function generateReducingInterestSchedule($loan_amount, $flat_interest, $tenure)
    {
        $effectiveIRR = $this->calculateEffectiveIRR($loan_amount, $flat_interest, $tenure);
        $monthlyInterestRate = ($effectiveIRR / 100) / 12;
        $emi = $loan_amount * $monthlyInterestRate / (1 - pow(1 + $monthlyInterestRate, -$tenure));
        $emi = round($emi); // Whole rupee

        $schedule = [];
        $remainingBalance = $loan_amount;
        $actualPrincipalPaid = 0;
        $actualInterestPaid = 0;

        $totalInterest = round(($loan_amount * $flat_interest / 100) * ($tenure / 12));
        $totalLoanAmount = $loan_amount + $totalInterest;

        for ($month = 1; $month <= $tenure; $month++) {
            $interestPortion = round($remainingBalance * $monthlyInterestRate);
            $principalPortion = round($emi - $interestPortion);

            // Adjust final month to fix rounding errors
            if ($month == $tenure) {
                $principalPortion = $loan_amount - $actualPrincipalPaid;
                $interestPortion = $totalInterest - $actualInterestPaid;
                $emi = $principalPortion + $interestPortion;
            }

            $remainingBalance -= $principalPortion;
            $actualPrincipalPaid += $principalPortion;
            $actualInterestPaid += $interestPortion;

            $schedule[] = [
                'month' => $month,
                'principal' => $principalPortion,
                'interest' => $interestPortion,
                'total_payment' => $emi,
                'remaining_balance' => max(0, $remainingBalance)
            ];
        }

        return [
            'schedule' => $schedule,
            'total_interest' => $actualInterestPaid,
            'total_loan_amount' => $totalLoanAmount,
            'emi_amount' => $schedule[0]['total_payment'],
            'effective_irr' => round($effectiveIRR, 2)
        ];
    }
    
    public function get_bureau_report_download_link($borrower_id, $user_type)
    {
        $validator = Validator::make([
            'borrower_id' => $borrower_id,
            'user_type' => $user_type,
        ], [
            'borrower_id' => ['required', 'regex:/^[a-zA-Z0-9\-]+$/'],
            'user_type' => ['required', 'in:BORROWER,COAPPLICANT,GUARANTOR'],
        ]);
    
        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $user_type = $user_type;
        $lower_case_user_type = strtolower($user_type);

        $filePath = storage_path("app/public/bureau_report/{$borrower_id}/{$user_type}/bureau-report-{$borrower_id}-{$lower_case_user_type}.xlsx");
        try {
            // Check if the file exists
            if (!File::exists($filePath)) {
                return $this->send_response('failed', "File not found", 404);
            }
    
            // Generate the download URL
            $downloadUrl = "http://13.200.65.87/storage/bureau_report/{$borrower_id}/{$user_type}/bureau-report-{$borrower_id}-{$lower_case_user_type}.xlsx";

            return $this->send_response('success', "Download URL generated", 200, ['download_url' => $downloadUrl]);
    
        } catch (Exception $e) {
            return $this->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }


    public function get_signature_verification_certificate_template()
    {
        try {
            $fileName = 'signature-verification-certificate.pdf';
            // Define the path to the template directory
            $filePath = public_path("Assets/templates/{$fileName}");

            // Check if the file exists and is not a directory
            if (!file_exists($filePath) || !is_file($filePath)) {
                
                return $this->send_response('failed', "File not found", 404);
            }

            // Generate a secure download link
            $downloadLink = asset("Assets/templates/{$fileName}");

            return $this->send_response('success', "Template url retrieved", 200, ['download_link' => $downloadLink]);

        } catch (Exception $e) {
            // Log the exception for debugging purposes

            return $this->send_response('failed', "An unexpected error occurred. Please try again later.", 422);
        }
    }

    public function get_name_declaration_doc()
    {
        try {
            $fileName = 'name-declaration-form.pdf';
            // Define the path to the template directory
            $filePath = public_path("Assets/templates/{$fileName}");

            // Check if the file exists and is not a directory
            if (!file_exists($filePath) || !is_file($filePath)) {
                
                return $this->send_response('failed', "File not found", 404);
            }

            // Generate a secure download link
            $downloadLink = asset("Assets/templates/{$fileName}");

            return $this->send_response('success', "Template url retrieved", 200, ['download_link' => $downloadLink]);

        } catch (Exception $e) {
            // Log the exception for debugging purposes

            return $this->send_response('failed', "An unexpected error occurred. Please try again later.", 422);
        }
    }

    public function get_dob_declaration()
    {
        try {
            $fileName = 'dob-declaration-form.pdf';
            // Define the path to the template directory
            $filePath = public_path("Assets/templates/{$fileName}");

            // Check if the file exists and is not a directory
            if (!file_exists($filePath) || !is_file($filePath)) {
                
                return $this->send_response('failed', "File not found", 404);
            }

            // Generate a secure download link
            $downloadLink = asset("Assets/templates/{$fileName}");

            return $this->send_response('success', "Template url retrieved", 200, ['download_link' => $downloadLink]);

        } catch (Exception $e) {
            // Log the exception for debugging purposes

            return $this->send_response('failed', "An unexpected error occurred. Please try again later.", 422);
        }
    }

    // =========================================================================
    // Add this method to HelperController
    // =========================================================================
    // Replaces N individual user_address_details() calls with 3 bulk queries.
    // Returns: array keyed by borrower_id (loan_basic_details.id)
    //
    // Usage in ReportsController:
    //   $addressMap = $this->helper_controller->user_address_details_bulk($borrowerIds, 'BORROWER');
    //   $addressData = $addressMap[$borrower->id] ?? [];
    //   $state = $addressData['data']['full_address']['state'] ?? '';
    //   $city  = $addressData['data']['full_address']['dist']  ?? '';
    // =========================================================================
     
    /**
     * Bulk-load addresses for multiple borrowers in 3 queries instead of N×2.
     *
     * Priority per borrower (matches user_address_details() exactly):
     *   1. VendorServiceApi with api_flag = AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML
     *   2. VendorServiceApi with api_flag = AADHAR_CARD_OCR_BACK
     *
     * @param  int[]   $borrowerIds   loan_basic_details.id values
     * @param  string  $userType      'BORROWER' | 'COAPPLICANT' | 'GUARANTOR'
     * @return array<int, array>      keyed by borrower_id, same shape as user_address_details()
     */
    public function user_address_details_bulk(array $borrowerIds, string $userType): array
    {
        if (empty($borrowerIds)) {
            return [];
        }
     
        // ── 1. Bulk-load KYC records (one query) ──────────────────────────────
        $kycMap = match ($userType) {
            'COAPPLICANT' => CoapplicantKyc::whereIn('borrower_id', $borrowerIds)
                                ->latest()
                                ->get(['borrower_id', 'name', 'mobile', 'email'])
                                ->keyBy('borrower_id'),
            'GUARANTOR'   => Guarantor::whereIn('borrower_id', $borrowerIds)
                                ->latest()
                                ->get(['borrower_id', 'name', 'mobile', 'email'])
                                ->keyBy('borrower_id'),
            default       => BorrowerKyc::whereIn('borrower_id', $borrowerIds)
                                ->latest()
                                ->get(['borrower_id', 'name', 'mobile', 'email'])
                                ->keyBy('borrower_id'),
        };
     
        // ── 2. Priority-1 vendor API records (one query) ──────────────────────
        // We grab the latest record per borrower using a subquery to avoid loading
        // all historical rows.
        $okcyRecords = VendorServiceApi::whereIn('borrower_id', $borrowerIds)
            ->where('applicant_type', $userType)
            ->where('status', 'VALID')
            ->where('api_flag', 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML')
            ->orderBy('id', 'desc')
            ->get(['borrower_id', 'response'])
            // Keep only the latest per borrower
            ->unique('borrower_id')
            ->keyBy('borrower_id');
     
        // ── 3. Priority-2 vendor API records (one query, only for gaps) ───────
        $needOcr = array_diff($borrowerIds, $okcyRecords->keys()->all());
     
        $ocrRecords = collect();
        if (!empty($needOcr)) {
            $ocrRecords = VendorServiceApi::whereIn('borrower_id', $needOcr)
                ->where('applicant_type', $userType)
                ->where('status', 'VALID')
                ->where('api_flag', 'AADHAR_CARD_OCR_BACK')
                ->orderBy('id', 'desc')
                ->get(['borrower_id', 'response'])
                ->unique('borrower_id')
                ->keyBy('borrower_id');
        }
     
        // ── 4. Build result map ───────────────────────────────────────────────
        $result = [];
     
        foreach ($borrowerIds as $borrowerId) {
            $kyc = $kycMap->get($borrowerId);
     
            $base = [];
            if ($kyc) {
                $base['name']   = $kyc->name   ?? '';
                $base['mobile'] = $kyc->mobile ?? '';
                $base['email']  = $kyc->email  ?? '';
            }
     
            // ── Priority-1: OKCY / Paperless XML ─────────────────────────────
            if ($okcyRecords->has($borrowerId)) {
                $raw  = $okcyRecords[$borrowerId]->response;
                $data = json_decode(unserialize(base64_decode($raw)), true);
     
                if (isset($data['msg']) && $data['msg'] === 'success' && isset($data['model']['address'])) {
                    $addressData       = $data['model']['address'];
                    $singleLineAddress = $this->prepare_one_line_address_from_aadhar($addressData);
     
                    $result[$borrowerId] = [
                        'status' => 'success',
                        'data'   => array_merge($base, [
                            'single_line_address' => $singleLineAddress,
                            'full_address'        => $addressData,
                        ]),
                    ];
                    continue;
                }
            }
     
            // ── Priority-2: OCR Back ──────────────────────────────────────────
            if ($ocrRecords->has($borrowerId)) {
                $raw  = $ocrRecords[$borrowerId]->response;
                $data = json_decode(unserialize(base64_decode($raw)), true);
     
                if (isset($data['result'][0]['details']['address'])) {
                    $address = $data['result'][0]['details']['address'];
     
                    $parts = array_filter(array_map('trim', [
                        $address['house_number'] ?? '',
                        $address['street']       ?? '',
                        $address['locality']     ?? '',
                        $address['landmark']     ?? '',
                        $address['city']         ?? '',
                        $address['district']     ?? '',
                        $address['state']        ?? '',
                        $address['pin']          ?? '',
                    ]));
     
                    $addressData = [
                        'house'   => $address['house_number'] ?? '',
                        'street'  => $address['street']       ?? '',
                        'landmark'=> $address['landmark']     ?? '',
                        'loc'     => $address['locality']     ?? '',
                        'po'      => $address['city']         ?? '',
                        'dist'    => $address['district']     ?? '',
                        'subdist' => '',
                        'vtc'     => $address['city']         ?? '',
                        'pc'      => $address['pin']          ?? '',
                        'state'   => $address['state']        ?? '',
                        'country' => 'India',
                    ];
     
                    $result[$borrowerId] = [
                        'status' => 'success',
                        'data'   => array_merge($base, [
                            'single_line_address' => implode(', ', $parts),
                            'full_address'        => $addressData,
                        ]),
                    ];
                    continue;
                }
            }
     
            // ── No address data found ─────────────────────────────────────────
            $result[$borrowerId] = [
                'status'  => 'failed',
                'message' => 'No records',
            ];
        }
     
        return $result;
    }

    public function get_form_twenty()
    {
        try {
            $fileName = 'FORM-20.pdf';
            // Define the path to the template directory
            $filePath = public_path("Assets/templates/{$fileName}");

            // Check if the file exists and is not a directory
            if (!file_exists($filePath) || !is_file($filePath)) {
                
                return $this->send_response('failed', "File not found", 404);
            }

            // Generate a secure download link
            $downloadLink = asset("Assets/templates/{$fileName}");

            return $this->send_response('success', "Report url retrieved", 200, ['download_link' => $downloadLink]);

        } catch (Exception $e) {
            // Log the exception for debugging purposes

            return $this->send_response('failed', "An unexpected error occurred. Please try again later.", 422);
        }
    }
}
