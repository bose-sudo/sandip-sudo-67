<?php

namespace App\Http\Controllers;

use App\Models\EligibilityCheck;
use App\Models\User;
use App\Models\AdminUser;
use App\Models\UserDetails;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Throwable;

class UserController extends Controller
{
    public function __construct() {
        $this->middleware('auth:api', ['except' => ['check_if_mobile_number_exist', 'check_if_email_id_exist']]);
    }

    public function update_address(Request $request) {
        $input = $request->all();
        $userId = $request->user()->id;

        $errorArray = [];
        
        $request->validate([
            'address' => 'required|regex:/^[a-zA-Z0-9,.\-\s]+$/',
            'pincode' => 'required|digits:6',
            'city' => 'required|string',
            'state' => 'required',
        ]);

        $userDetailsExist = UserDetails::where('user_id', $userId)->first();
        if($userDetailsExist) {
            $userDetailsExist->address = $input['address'];
            $userDetailsExist->pincode = $input['pincode'];
            $userDetailsExist->city = $input['city'];
            $userDetailsExist->state = $input['state'];
            $userDetailsExist->save();
        } else {
            $userDetails = new UserDetails;
            $userDetails->user_id = $userId;
            $userDetails->address = $input['address'];
            $userDetails->pincode = $input['pincode'];
            $userDetails->city = $input['city'];
            $userDetails->state = $input['state'];
            $userDetails->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'User address updated'
        ], 201);
    }

    public function update_basic_info(Request $request) {
        $input = $request->all();
        $userId = $request->user()->id;

        $errorArray = [];
        
        $request->validate([
            'dob' => 'required|date_format:Y-m-d',
            'gender' => 'required|string',
            'father_name' => 'required|string',
            'no_of_dependents' => 'required|integer',
            'marital_status' => 'required|string',
            'residence_status' => 'required|string',
            'education' => 'required|string',
            'occupation' => 'required|string',
            'reference_name' => 'required|string',
            'phone_number' => 'required|digits:10',
            'relation' => 'required|string',
        ]);

        if(isset($input['agree_for_bureau_check']) && !empty($input['agree_for_bureau_check'])) {
            $agree_for_bureau_check = true;
        } else {
            $agree_for_bureau_check = false;
        }

        $userDetailsExist = UserDetails::where('user_id', $userId)->first();
        if($userDetailsExist) {
            $userDetailsExist->dob = $input['dob'];
            $userDetailsExist->gender = $input['gender'];
            $userDetailsExist->father_name = $input['father_name'];
            $userDetailsExist->no_of_dependents = $input['no_of_dependents'];
            $userDetailsExist->marital_status = $input['marital_status'];
            $userDetailsExist->residence_status = $input['residence_status'];
            $userDetailsExist->education = $input['education'];
            $userDetailsExist->occupation = $input['occupation'];
            $userDetailsExist->reference_name = $input['reference_name'];
            $userDetailsExist->reference_number = $input['phone_number'];
            $userDetailsExist->reference_relation = $input['relation'];
            $userDetailsExist->agree_for_bureau_check = $agree_for_bureau_check;
            $userDetailsExist->save();

        } else {
            $userDetails = new UserDetails;
            $userDetails->user_id = $userId;
            $userDetails->dob = $input['dob'];
            $userDetails->gender = $input['gender'];
            $userDetails->father_name = $input['father_name'];
            $userDetails->no_of_dependents = $input['no_of_dependents'];
            $userDetails->marital_status = $input['marital_status'];
            $userDetails->residence_status = $input['residence_status'];
            $userDetails->education = $input['education'];
            $userDetails->occupation = $input['occupation'];
            $userDetails->reference_name = $input['reference_name'];
            $userDetails->reference_number = $input['phone_number'];
            $userDetails->reference_relation = $input['relation'];
            $userDetails->agree_for_bureau_check = $agree_for_bureau_check;
            $userDetails->save();

        }

        return response()->json([
            'success' => true,
            'message' => 'User basic info saved'
        ], 201);
    }

    public function loan_eligibility_check(Request $request) {
        $input = $request->all();
        $userId = $request->user()->id;

        $errorArray = [];
        
        $request->validate([
            'language' => 'required',
            'partner_code' => 'required|string',
            'service' => 'required|string',
            'sub_service' => 'required|string',
            'dob' => 'required|date_format:Y-m-d',
            'monthly_income' => 'required|numeric',
            'occupation' => 'required|string',
            'loan_amount' => 'required|numeric',
            'loan_term' => 'required|int',
            'loan_purpose' => 'required|string',
        ]);

        $is_eligible = true;

        $eligibilty_check                   = new EligibilityCheck;
        $eligibilty_check->user_id          = $userId;
        $eligibilty_check->language         = $input['language'];
        $eligibilty_check->partner_code     = $input['partner_code'];
        $eligibilty_check->service          = $input['service'];
        $eligibilty_check->sub_service      = $input['sub_service'];
        $eligibilty_check->dob              = $input['dob'];
        $eligibilty_check->monthly_income   = $input['monthly_income'];
        $eligibilty_check->occupation       = $input['occupation'];
        $eligibilty_check->loan_amount      = $input['loan_amount'];
        $eligibilty_check->loan_term        = $input['loan_term'];
        $eligibilty_check->loan_purpose     = $input['loan_purpose'];
        $eligibilty_check->is_eligible      = $is_eligible;

        if($eligibilty_check->save()) {
            return response()->json([
                'success' => true,
                'is_eligible' => true,
            ], 201);
        } else {
            return response()->json([
                'success' => false,
                'error' => 'Failed to store data.',
            ], 400);
        }
    }
    
    public function add_borrower_details(Request $request) {
        $input = $request->all();
        $userId = $request->user()->id;

        $errorArray = [];
        $request->validate([
            'dealer_id' => 'required',
            'name' => 'required',
            'mobile' => 'required',
            'pan' => 'required',
            'pan_message' => 'required',
            'aadhar' => 'required',
            'asset_value' => 'required',
            'loan_amount' => 'required',
            'down_payment' => 'required',
            'dob_date' => 'required',
            'father_name' => 'required',
            'gender' => 'required',
            'email' => 'required',
            'cibil' => 'required',
            'postal' => 'required',
            'state' => 'required',
            'country' => 'required',
            'avtar' => 'required',
            'alternate_number1' => 'required',
            'alternate_number2' => 'required',

            'resident_type' => 'required',
            'current_address' => 'required',
            'years_living' => 'required',
            'permanent_address' => 'required',
            'family_members' => 'required',
            'earning_members' => 'required',
            'family_income' => 'required',
            'reference_one' => 'required',
            'reference_two' => 'required',

            'occupation_type' => 'required',
            'current_occupation' => 'required',
            'driving_experience' => 'required',
            'vehicle_type' => 'required',
            'monthly_earning' => 'required',
            'expected_monthly_earning' => 'required',
            'extra_source_income' => 'required',
            'source_income_area' => 'required',
            'total_income' => 'required',
            'current_city' => 'required',
        ]);

        
    }

    public function check_if_mobile_number_exist(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        date_default_timezone_set('Asia/Kolkata');

        if (!isset($request->mobile) || empty($request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Mobile number is required";
        } elseif (!preg_match('/^(?:\+91|91)?[6-9]\d{9}$/', $request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Invalid mobile number format";
        }

        if($errorCount > 0) {
            return response()->json([
                'status'=>'error',
                'message'=>"Invalid data",
                'error_list'=> $errorArray
            ], 422);

        } else {
            $mobile = $request->mobile;
            $mobileNumberExist = User::where('mobile', $mobile)->where('is_active', true)->first();
            if($mobileNumberExist) {
                return response()->json([
                    'status'=>'failed',
                    'message'=>"This number is already registered",
                ], 409);
            } else {
                return response()->json([
                    'status'=>'success',
                    'message'=>"This number is not registered",
                ], 200);
            }
        }
    }

    public function check_if_email_id_exist(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        date_default_timezone_set('Asia/Kolkata');

        if (!isset($request->email) || empty($request->email)) {
            $errorCount++;
            $errorArray['email'] = "Email ID is required";
        } elseif (!filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
            $errorCount++;
            $errorArray['email'] = "Invalid email format";
        }

        if($errorCount > 0) {
            return response()->json([
                'status'=>'error',
                'message'=>"Invalid data",
                'error_list'=> $errorArray
            ], 422);

        } else {
            $email = $request->email;
            $emailIdExist = User::where('email', $email)->where('is_active', true)->first();
            if($emailIdExist) {
                return response()->json([
                    'status'=>'failed',
                    'message'=>"This email id is already registered",
                ], 409);
            } else {
                return response()->json([
                    'status'=>'success',
                    'message'=>"This email id is not registered",
                ], 200);
            }
        }
    }

    public function send_otp_for_forgot_password(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';
        date_default_timezone_set('Asia/Kolkata');

        if (!isset($request->username) || empty($request->username)) {
            $errorCount++;
            $errorArray['username'] = "Username is required";
        }

        if($errorCount > 0) {
            return response()->json([
                'status'=>'error',
                'message'=>"Invalid data",
                'error_list'=> $errorArray
            ], 422);

        } else {
            $username = $request->$username;
            $user = User::where('mobile', $username)
                        ->orWhere('email', $username)
                        ->first();
            if($user) {
                // send otp

            } else {
                return response()->json([
                    'status'=>'failed',
                    'message'=>"User not registered",
                ], 204);
            }
        }
    }

    public function test() {
        // return User::all();
    }

    public function get_user_list_screener() {
        try {
            $all_users = AdminUser::select(['name','mobile','email'])->where('is_active', true)->where('is_deleted', false)->get();

            return $all_users;
        } catch (Throwable $e) {
            return response()->json([
                'status'=>'failed',
                'message'=>"Unknown error occurred ". $e->getMessage,
            ], 422);
        }
    }
}
