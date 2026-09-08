<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppPermission;
use Illuminate\Http\Request;
use App\Http\Controllers\HelperController;
use App\Models\ApkVersion;
use App\Models\Dealer;
use App\Models\Occupation;
use App\Models\ProductModel;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class AppCommonController extends Controller
{
    use ApiResponse;
    private $helper_controller;
    public function __construct(HelperController $helper_controller) {
        $this->helper_controller = $helper_controller;
    }
    
    public function get_permission_status (Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        date_default_timezone_set('Asia/Kolkata');

        if (!$request->hasHeader('deviceid') || empty($request->header('deviceid'))) {
            $errorCount++;
            $errorArray['deviceid'] = "Device ID is required";
        }

        if($errorCount > 0) {
            return response()->json([
                'status'=>'error',
                'message'=>"Invalid data",
                'error_list'=> $errorArray
            ], 422);

        } else {
            
            $device_id = $request->header('deviceid');

            $app_permission = AppPermission::where('device_id', $device_id)->first();
            if($app_permission) {
                return response()->json([
                    'status'=>'success',
                    'message'=>"Record found",
                    'data'=> $app_permission
                ], 200);
            } else {
                return response()->json([
                    'status'=>'success',
                    'message'=>"Device id not exist"
                ], 200);
            }
        }
    }

    public function store_app_permission (Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        date_default_timezone_set('Asia/Kolkata');

        if (!$request->hasHeader('deviceid') || empty($request->header('deviceid'))) {
            $errorCount++;
            $errorArray['deviceid'] = "Device ID is required";
            
            return $this->helper_controller->send_response('failed', $errorArray['deviceid'], 422);
        }

        if(!isset($request->user_personal_information) || empty($request->user_personal_information)) {
            $errorCount++;
            $errorArray['user_personal_information'] = "User personal information permission is required";
            
            return $this->helper_controller->send_response('failed', $errorArray['user_personal_information'], 422);
        } else {
            $user_personal_information = true;
        } 
        if(!isset($request->phone) || empty($request->phone)) {
            $errorCount++;
            $errorArray['phone'] = "Phone permission is required";
            
            return $this->helper_controller->send_response('failed', $errorArray['phone'], 422);
        } else {
            $phone = true;
        }
        if(!isset($request->location) || empty($request->location)) {
            $errorCount++;
            $errorArray['location'] = "Location permission is required";
            
            return $this->helper_controller->send_response('failed', $errorArray['location'], 422);
        } else {
            $location = true;
        }
        if(!isset($request->camera) || empty($request->camera)) {
            $errorCount++;
            $errorArray['camera'] = "Camera permission is required";
            
            return $this->helper_controller->send_response('failed', $errorArray['camera'], 422);
        } else {
            $camera = true;
        }
        if(!isset($request->storage) || empty($request->storage)) {
            $errorCount++;
            $errorArray['storage'] = "Storage permission is required";
            
            return $this->helper_controller->send_response('failed', $errorArray['storage'], 422);
        } else {
            $storage = true;
        }
        if(!isset($request->third_party_accounts) || empty($request->third_party_accounts)) {
            $errorCount++;
            $errorArray['third_party_accounts'] = "Third party accounts permission is required";
            
            return $this->helper_controller->send_response('failed', $errorArray['third_party_accounts'], 422);
        } else {
            $third_party_accounts = true;
        }

        if(!isset($request->privacy_policy) || empty($request->privacy_policy)) {
            $errorCount++;
            $errorArray['privacy_policy'] = "Privacy policy is required";
            
            return $this->helper_controller->send_response('failed', $errorArray['privacy_policy'], 422);
        } else {
            $privacy_policy = true;
        }

        if($errorCount > 0) {
            return response()->json([
                'status'=>'error',
                'message'=>"Invalid data",
                'error_list'=> $errorArray
            ], 422);

        } else {
            $device_id  = $request->header('deviceid');

            $app_permission = AppPermission::where('device_id', $device_id)->first();
            if($app_permission) {
                $app_permission->user_personal_information  = $user_personal_information;
                $app_permission->phone                      = $phone;
                $app_permission->location                   = $location;
                $app_permission->camera                     = $camera;
                $app_permission->storage                    = $storage;
                $app_permission->third_party_accounts       = $third_party_accounts;
                $app_permission->privacy_policy             = $privacy_policy;
                if($app_permission->save()) {

                    return response()->json([
                        'status'=>'success',
                        'message'=>"Device permission updated"
                    ], 200);
                } else {
                    return response()->json([
                        'status'=>'failed',
                        'message'=>"failed to store information"
                    ], 400);
                }
            } else {
                $new_app_permission                             = new AppPermission;
                $new_app_permission->device_id                  = $device_id;
                $new_app_permission->user_personal_information  = $user_personal_information;
                $new_app_permission->phone                      = $phone;
                $new_app_permission->location                   = $location;
                $new_app_permission->camera                     = $camera;
                $new_app_permission->storage                    = $storage;
                $new_app_permission->privacy_policy             = $privacy_policy;
                if($new_app_permission->save()) {
                    return response()->json([
                        'status'=>'success',
                        'message'=>"Device permission stored"
                    ], 200);
                } else {
                    return response()->json([
                        'status'=>'failed',
                        'message'=>"failed to store information"
                    ], 400);
                }
            }
        }
    }

    public function get_onboarding_data() {
        $onboardingData = [
            [
                'title' => "Apply",
                'desctiption' => "Say goodbye to tedious paperwork and hello to a streamlined, hassle-free process. Enjoy flexible terms and competitive rates as you transition to greener mobility. Apply today and drive into a sustainable future!",
                // 'icon' => asset('Assets/on-boarding/apply.png'),
                'icon' => asset('Assets/on-boarding/apply.svg'),
            ],
            [
                'title' => "Select",
                'desctiption' => "Choose the best loan offer that fits your needs and enjoy a seamless path to greener mobility!",
                // 'icon' => asset('Assets/on-boarding/select.png'),
                'icon' => asset('Assets/on-boarding/select.svg'),
            ],
            [
                // 'title' => "Spend",
                'title' => "Save",
                'desctiption' => "Our quick loan process helps you secure the funds you need for greener mobility, so you can invest in your future without delay!",
                // 'icon' => asset('Assets/on-boarding/spend.png'),
                'icon' => asset('Assets/on-boarding/savings.svg'),
            ],
        ];

        // $onboardingData = json_encode($onboardingData);

        return response()->json([
            'status'=>'success',
            'data'=> $onboardingData
        ], 200);
    }
    
    public function get_languages() {
        $languages = [
            [
                'id' => "1",
                'language' => "English",
            ],
            [
                'id' => "2",
                'language' => "Hindi",
            ],
        ];

        // $languages = json_encode($languages);

        return response()->json([
            'status'=>'success',
            'data'=> $languages
        ], 200);
    }

    public function get_genders() {
        $genders = [
            "Male", "Female", "Others",
        ];

        // $genders = json_encode($genders);

        return response()->json([
            'status'=>'success',
            'data'=> $genders
        ], 200);
    }

    public function get_marital_status() {
        $merital_status = [
            "Married", "Unmarried",
        ];

        // $merital_status = json_encode($merital_status);

        return response()->json([
            'status'=>'success',
            'data'=> $merital_status
        ], 200);
    }

    public function get_vehicle_types() {
        $vehicle_types = [
            "Two Wheeler", "Three Wheeler",
        ];

        // $vehicle_types = json_encode($vehicle_types);

        return response()->json([
            'status'=>'success',
            'data'=> $vehicle_types
        ], 200);
    }

    public function get_resident_type(Request $request) {
        // if (!auth()->check()) {
        //     return $this->send_response('failed', "Unauthenticated access, please login", 401);
        // }

        $resident_types = [
            "Owned",
             // "Rented",
        ];
        if(!isset($request->user_type) || empty($request->user_type)) {
            return $this->send_response('failed', "User type is required", 422);
        }

        if(isset($request->borrower_primary) && !empty($request->borrower_primary)) {
            $borrower_id = $request->borrower_primary;
            $resident_types = [
                "Owned",
                 "Rented",
            ];
        } elseif(isset($request->borrower_id) && !empty($request->borrower_id)) {
            $borrower_id = $request->borrower_id;
            $resident_types = [
                "Owned",
                 "Rented",
            ];
        } else {
            $resident_types = [
                "Owned",
                 // "Rented",
            ];
            // return $this->send_response('failed', "Borrower id is required", 422);
        }



        // $resident_types = json_encode($resident_types);

        return response()->json([
            'status'=>'success',
            'data'=> $resident_types
        ], 200);
    }

    public function get_occupations() {

        $occupations = Occupation::where('is_active', true)->where('is_deleted', false)->get();
        $result = [];
        foreach($occupations as $data) {
            $result[] = [
                'id' => $data->id,
                'occupation' => $data->occupation,
            ];
        }  

        return response()->json([
            'status'=>'success',
            'data'=> $result
        ], 200);
    }

    public function get_dealer_ids(){
        $dealer_ids = [
            [
                'id'=>'1',
                'dealer_id' => 'A1234'
            ],
            [
                'id'=>'2',
                'dealer_id' => 'A1235'
            ],
            [
                'id'=>'3',
                'dealer_id' => 'A1236'
            ],
            [
                'id'=>'4',
                'dealer_id' => 'A1237'
            ],
            [
                'id'=>'5',
                'dealer_id' => 'A1238'
            ],
        ];

        return response()->json([
            'status'=>'success',
            'data'=> $dealer_ids
        ], 200);
    } 

    public function years_living() {
        $years_living = [
            '2 - 5 Years',
            '6 - 11 Years',
            '12 - 15 Years'
        ];

        return response()->json([
            'status'=>'success',
            'data'=> $years_living
        ], 200);
    }

    public function demo_loan_offers() {
        $loan_offers = [
            [
                'id' => "1",
                'lender_id' => "1",
                'lender' => "Offer 1",
                'loan_amount' => 42000,
                'interest' => "15%",
                "terms" => "15 month",
                "emi_per_month" => number_format(42000/12, 2),
            ],
            [
                'id' => "2",
                'lender_id' => "2",
                'lender' => "Offer 2",
                'loan_amount' => 42000,
                'interest' => "17%",
                "terms" => "15 month",
                "emi_per_month" => number_format(42000/12, 2),
            ],
            [
                'id' => "3",
                'lender_id' => "3",
                'lender' => "Offer 3",
                'loan_amount' => 35000,
                'interest' => "19%",
                "terms" => "15 month",
                "emi_per_month" => number_format(35000/12, 2),
            ],
        ];

        // $loan_offers = json_encode($loan_offers);

        return response()->json([
            'status'=>'success',
            'data'=> $loan_offers
        ], 200);
    }

    public function get_relation_ship($gender = null) {
        if(isset($gender) && $gender == 'Female') {
            $relation = [
                'Father',
                'Husband'
            ];
        } else {
            $relation = [
                'Father',
                'Mother',
                'Son',
                'Daughter',
                'Wife',
                'Husband',
                'Father in Law',
                'Mother in Law',
                'Son in Law',
                'Daughter in Law',
                'Friend',
                'Uncle',
                'Aunt',
            ];
        }

        return response()->json([
            'status'=>'success',
            'data'=> $relation
        ], 200);
    }

    public function emi_debit_consent_content() {
        $content = "Lorem ipsum dolor sit, amet consectetur adipisicing elit. Possimus voluptatem qui cum dolorem magnam vero earum illum, tempore recusandae ut dolore odio deserunt, dolorum, quisquam vitae ullam iste voluptates quo?";

        return response()->json([
            'status'=>'success',
            'data'=> $content
        ], 200);
    }

    public function other_doc_types() {
        $data = [
            [
                'key' => 'URC_CERTIFICATE',
                'value' => "Udyam Registration Certificate",
            ],
            [
                'key' => 'SIGNED_CHEQUE',
                'value' => "Signed Cheque",
            ],
            [
                'key' => 'DRIVING_LICENCE',
                'value' => "Driving Licence",
            ],
            [
                'key' => 'DOH',
                'value' => "Deed of Hypothecation",
            ],
            [
                'key' => 'MMR',
                'value' => "Margin Money Receipt",
            ],
            [
                'key' => 'OTHER',
                'value' => "Others",
            ],
        ];

        return response()->json([
            'status'=>'success',
            'data'=> $data
        ], 200);
    }

    public function get_bureau_consent_data() {
        // $content = "You hereby consent to Finayo being appointed as your authorized representative to receive your Credit Information from Experian for the purpose of (End Use Purpose).";
        // $content = "I allow Finayo to share my Aadhar and personal information with loan providers for the purpose of identity verification, address proof and wallet KYC upgrade in complience with KYC norms issued by RBI. I also allow Finayo to access my credit information and agree to CIBIL and CRIF Bureau TnC's";

        $content = "I allow Finayo to share my Aadhar and personal information with loan providers for the purpose of identity verification, address proof and wallet KYC upgrade in compliance with KYC norms issued by RBI. I also allow Finayo to access my credit information and agree to CIBIL and CRIF Bureau TnC's";

        // $content = [
        //     'credit_consent' => "I allow Finayo to share my Aadhar and personal information with loan providers for the purpose of identity verification, address proof and wallet KYC upgrade in compliance with KYC norms issued by RBI. I also allow Finayo to access my credit information and agree to CIBIL and CRIF Bureau TnC's",
            
        //     'account_aggregator_consent' => "I authorize Finayo to access my financial data via the Account Aggregator system for evaluating loan eligibility and related services. I understand that my data will be securely processed and not shared without my consent."
        // ];

        return response()->json([
            'status'=>'success',
            'data'=> $content
        ], 200);
    }

    public function create_dealer_and_assets(Request $request){
        // return $this->helper_controller->send_response("failed", "Access denied", 400);

        $input          = $request->all();
        if($input == null) {
            return $this->helper_controller->send_response("failed", "Request body is empty", 422);
        }

        if(!isset($request->dealer_name) || empty($request->dealer_name)) {
            return $this->helper_controller->send_response('failed', "dealer_name is required", 422);
        }
        
        if(!isset($request->model_name) || empty($request->model_name)) {
            return $this->helper_controller->send_response('failed', "model name is required", 422);
        }
        
        if(!isset($request->product_type) || empty($request->product_type)) {
            return $this->helper_controller->send_response('failed', "Product type is required", 422);

        } elseif (!in_array($request->product_type, ['E-Rickshaw (L3)', 'E-Auto (L5)', 'E-Two Wheeler', 'Battery'])) {
            return $this->helper_controller->send_response('failed', "Please add valid product types, [E-Rickshaw (L3), E-Auto (L5), E-Two Wheeler, Battery]", 422);
        }

        // if(!isset($request->battery_type)) {
        //     return $this->helper_controller->send_response('failed', "battery type is required", 422);
        // }

        if(!isset($request->ex_showroom_price) || empty($request->ex_showroom_price)) {
            return $this->helper_controller->send_response('failed', "ex showroom price is required", 422);
        }

        if(!isset($request->loan_to_value_ratio) || empty($request->loan_to_value_ratio)) {
            return $this->helper_controller->send_response('failed', "loan to value ratio is required", 422);
        }

        if(!isset($request->manufacture_name) || empty($request->manufacture_name)) {
            return $this->helper_controller->send_response('failed', "manufacture name is required", 422);
        }

        if(!isset($request->max_loan_amount) || empty($request->max_loan_amount)) {
            return $this->helper_controller->send_response('failed', "max loan amount is required", 422);
        }

        if(!isset($request->max_loan_tenure) || empty($request->max_loan_tenure)) {
            return $this->helper_controller->send_response('failed', "max loan tenure is required", 422);
        }

        if(!isset($request->min_loan_tenure) || empty($request->min_loan_tenure)) {
            return $this->helper_controller->send_response('failed', "min loan tenure is required", 422);
        }

        if(!isset($request->min_loan_amount) || empty($request->min_loan_amount)) {
            return $this->send_response('failed', "min loan amount is required", 422);
        }

        $dealer_name            = strtoupper(trim($request->dealer_name));

        $existing_dealer = Dealer::where('name', 'like', '%'.$dealer_name.'%')->first();
        if($existing_dealer) {
            $dealer_primary_id = $existing_dealer->id;
        } else {
            $dealer_details             = new Dealer;
            $dealer_details->dealer_unq = '';
            $dealer_details->name       = $dealer_name;
            $dealer_details->save();
            $dealer_primary_id = $dealer_details->id;
            
            $update_dealer_id = Dealer::find($dealer_primary_id);
            if($update_dealer_id) {
                $dealer_unq                     = 'FIN-DEA-'.$dealer_primary_id;
                $update_dealer_id->dealer_unq   = $dealer_unq;
                $update_dealer_id->save();
            }
        }
        
        $model_id               = '';
        $model_name             = $request->model_name;
        $battery_type           = ucfirst(trim($request->battery_type));
        $ex_showroom_price      = $request->ex_showroom_price;
        $loan_to_value_ratio    = $request->loan_to_value_ratio;
        $manufacture_name       = $request->manufacture_name;
        $max_loan_amount        = $request->max_loan_amount;
        $max_loan_tenure        = $request->max_loan_tenure;
        $min_loan_tenure        = $request->min_loan_tenure;
        $min_loan_amount        = $request->min_loan_amount;
        $product_type           = $request->product_type;

        $existing_product = ProductModel::where('dealer_id', $dealer_primary_id)
                                ->where('name', 'like', '%'.$model_name.'%')
                                ->where('battery_type', $battery_type)
                                ->first();
        if(!$existing_product) {
            $model_details                  = new ProductModel;
            $model_details->dealer_id       = $dealer_primary_id;
            echo $model_details->model_unq  = '';
            $model_details->name            = $model_name;
            $model_details->battery_type    = $battery_type;

            if(is_numeric($ex_showroom_price)) {
                $ex_showroom_price = $ex_showroom_price;
            } else {
                $ex_showroom_price = 0;
            }

            $model_details->ex_showroom_price   = $ex_showroom_price;
            $model_details->loan_to_value_ratio = $loan_to_value_ratio;
            $model_details->manufacture_name    = $manufacture_name;
            $model_details->min_loan_amount     = $min_loan_amount;
            $model_details->max_loan_amount     = $max_loan_amount;
            $model_details->min_loan_tenure     = $min_loan_tenure;
            $model_details->max_loan_tenure     = $max_loan_tenure;
            $model_details->product_type        = $product_type;

            if($model_details->save()) {

                $model_primary_key  = $model_details->id;
                $model_unq          = 'FIN-MOD-'.$model_primary_key;

                $update_model = ProductModel::find($model_primary_key);
                if($update_model) {
                    $update_model->model_unq = $model_unq;
                    $update_model->save();
                }

                return $this->helper_controller->send_response('success', "Product added successfully", 201);
            } else {
                return $this->helper_controller->send_response('failed', "Failed to add product, try again", 400);
            }
        } else {
            return $this->helper_controller->send_response('failed', "Product already exist", 422);
        }
    }

    public function upload_apk(Request $request) {
        // return $this->helper_controller->send_response("failed", "Access denied", 400);
        try {
            $input          = $request->all();
            if($input == null) {
                return $this->helper_controller->send_response("failed", "Request body is empty", 422);
            }

            if(!isset($request->version) || empty($request->version)) {
                return $this->helper_controller->send_response('failed', "version is required", 422);
            }

            $version    = trim($request->version);

            // Generate a unique file name
            // $serviceApiController = new ServiceApiController;
            $fileName = 'finayoinstaloan.apk';

            $apk_details = new ApkVersion;
            $apk_details->file_name = $fileName;
            $apk_details->file_path = '';
            $apk_details->version   = $request->version;

            if($apk_details->save()) {  
                return response()->json([
                    'status' => 'success',
                    'message' => "Apk details stored successfully",
                ], 200);
            } else {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Failed to store details, try again",
                ], 400);
            }
        } catch (Exception  $e) {
            $result_response = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            return response()->json($result_response, 422);
        }
    }

    public function get_apk_details() {
        try{
            $apk_details = ApkVersion::latest()->first();
            if($apk_details) {
                return response()->json([
                    'status' => 'success',
                    'message' => "Latest apk details fetched",
                    'data' => $apk_details
                ], 200);
            } else {
                return response()->json([
                    'status' => 'failed',
                    'message' => "No apk exist"
                ], 200);
            }
        } catch (Exception  $e) {
            $result_response = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            return response()->json($result_response, 422);
        }
    }
}
