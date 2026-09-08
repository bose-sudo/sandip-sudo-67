<?php

namespace App\Http\Controllers;

use App\Mail\Mailer;
use App\Models\AdminUser;
use App\Models\Dealer;
use App\Models\LoanServiceProvider;
use App\Models\LspExecutiveDetails;
use App\Models\UserType;
use App\Services\TwilioService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminAuthController extends Controller
{
    protected $twilio;
    protected $helper_controller;

    public function __construct(HelperController $helper_controller, TwilioService $twilio)
    {
        $this->helper_controller = $helper_controller;
        $this->twilio = $twilio;
    }

    // Register a new admin user
    public function register(Request $request)
    {
        // echo 'hi'; die();
        $validator = Validator::make($request->all(), [
            'name' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            'mobile' => 'required|digits:10|unique:admin_users,mobile',
            'email' => 'required|email|unique:admin_users,email',
            'password' => 'required|string|min:8|confirmed',
            'department' => 'required|integer',
            'user_type' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();
            $adminUser = AdminUser::create([
                'name' => $request->name,
                'mobile' => $request->mobile,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'department' => $request->department,
                'user_type' => $request->user_type,
                'is_active' => true
            ]);

            $emailData = [
                'name' => $request->name,
                'admin_url' => 'http://uat.finayo.tech',
                'user_id' => $request->email,
                'password' => $request->password
            ];
            $viewName = 'emails.example';
            $subject = "Finayo Login Credential";
    
            // Mail::to($request->email)->send(new Mailer($viewName, $emailData, $subject));
            DB::commit();
            return $this->helper_controller->send_response('success', 'Admin user registered successfully', 201, $adminUser);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error", 400, $e->getMessage());
        }
    }

    // Login an admin user
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', "Input details are invalid", 400, $validator->errors());
        }

        $credentials = $request->only('email', 'password');

        try {

            $is_active = AdminUser::where('email', $request->email)->where('is_active', true)->first();
            if(!$is_active) {
                return $this->helper_controller->send_response('failed', 'Your account is inactive', 422);
            }

            if (!$token = auth()->guard('admin')->attempt($credentials)) {
                return $this->helper_controller->send_response('failed', 'Invalid credentials', 401);
            }

        } catch (JWTException $e) {
            return $this->helper_controller->send_response('failed', "Unknown error", 400, $e->getMessage());
        }

        // return $this->helper_controller->send_response('success', 'Login successful', 200, ['token' => $token]);
        $user_type_details = UserType::find($is_active->user_type);

        $user_type = $user_type_details ? $user_type_details->user_type : null;
        return $this->helper_controller->send_response('success', 'Login successful', 200, [
            'token' => $token,
            'user_type' => $user_type,
            'name' => $is_active->name,
            'mobile' => $is_active->mobile,
            'email' => $is_active->email,
            'is_active' => $is_active->is_active,
        ]);
    }

    public function change_admin_user_status(Request $request, $id) {
        if(is_null($id) || empty($id) || !is_numeric($id)) {
            return $this->helper_controller->send_response('failed', 'Id is required and must be a numeric value', 422);
        }
        
        try {
            // remarks input is added on 26-11-2024
            if(!$request->filled('remarks')) {
                return $this->helper_controller->send_response('failed', "Remark is requird", 422);
            }

            DB::beginTransaction();
            $user_details = AdminUser::find($id);
            if(!$user_details) {
                return $this->helper_controller->send_response('failed', 'Invalid request', 422);
            }
            
            if ($user_details->is_active === true) {
                $user_details->is_active = false;
                $response_text = "User de-activated successfully";
            } else {
                $user_details->is_active = true;
                $response_text = "User activated successfully";
            }
            $user_details->remarks = $request->remarks ?? '';
            $user_details->save();
            DB::commit();
            return $this->helper_controller->send_response('success', $response_text, 200);

        } catch (Exception $e) {

            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    // Logout an admin user
    public function logout()
    {
        try {
            auth()->guard('admin')->invalidate(JWTAuth::parseToken()->getToken());
            return $this->helper_controller->send_response('success', 'Successfully logged out', 200);
        } catch (JWTException $e) {
            return $this->helper_controller->send_response('failed', "Unknown error", 400, $e->getMessage());
        }
    }
    
    public function b2b_logout()
    {
        try {
            auth()->guard('lsp_executive')->invalidate(JWTAuth::parseToken()->getToken());
            return $this->helper_controller->send_response('success', 'Successfully logged out', 200);
        } catch (JWTException $e) {
            return $this->helper_controller->send_response('failed', "Unknown error", 400, $e->getMessage());
        }
    }

    // Get authenticated admin user details
    // public function user()
    // {
    //     try {
    //         $adminUser = auth('admin')->user();
    //         return $this->helper_controller->send_response('success', 'Admin user details fetched', 200, $adminUser);
    //     } catch (Exception $e) {
    //         return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
    //     }
    // }

    public function user()
    {
        try {
            $adminUser = auth('admin')->user();
            if (!$adminUser) {
                return $this->helper_controller->send_response('failed', 'Admin user not found', 400);
            }

            // Fetch department and user type names
            $departmentId = $adminUser->departmentRelation ? $adminUser->departmentRelation->id : null;
            $departmentName = $adminUser->departmentRelation ? $adminUser->departmentRelation->department : null;
            $userTypeId = $adminUser->userTypeRelation ? $adminUser->userTypeRelation->id : null;
            $userTypeName = $adminUser->userTypeRelation ? $adminUser->userTypeRelation->user_type : null;
            $role = $adminUser->userTypeRelation ? $adminUser->userTypeRelation->role : null;

            $userDetails = [
                'name' => $adminUser->name,
                'mobile' => $adminUser->mobile,
                'email' => $adminUser->email,
                'email_verified_at' => $adminUser->email_verified_at,
                'email_confirmation_code' => $adminUser->email_confirmation_code,
                'alternative_mobile_1' => $adminUser->alternative_mobile_1,
                'otp' => $adminUser->otp,
                'department_id' => $departmentId,
                'department' => $departmentName,
                'user_type_id' => $userTypeId,
                'user_type' => $userTypeName,
                'role' => $role,
                'is_active' => $adminUser->is_active,
                'is_deleted' => $adminUser->is_deleted,
                'updated_by' => $adminUser->updated_by,
                'deleted_at' => $adminUser->deleted_at,
                'auth_token' => $adminUser->auth_token,
            ];
            $logged_in_user = auth()->user()->id;
            $lsp_details = LoanServiceProvider::where('lsp_auth_id', $logged_in_user)->first();
            if($lsp_details) {
                $userDetails['lsp_id'] = $lsp_details->id;
            }
            
            $dealer_auth_details = Dealer::where('dealer_auth_id', $logged_in_user)->first();
            if($dealer_auth_details){
                $userDetails['dealer_id'] = $dealer_auth_details->id;
            }

            return $this->helper_controller->send_response('success', 'Admin user details fetched', 200, $userDetails);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "Unknown error", 400, $e->getMessage());
        }
    }

    public function b2b_user()
    {
        try {
            $adminUser = auth()->user();
            if (!$adminUser) {
                return $this->helper_controller->send_response('failed', 'Admin user not found', 400);
            }

            // Fetch department and user type names
            $departmentName = "LSP Executive";
            $userTypeName = "LSP Executive";
            $role = "";

            $userDetails = [
                'name' => $adminUser->name,
                'mobile' => $adminUser->mobile,
                'email' => $adminUser->email,
                'alternative_mobile_1' => $adminUser->alternative_mobile_1,
                'otp' => $adminUser->otp,
                'department' => $departmentName,
                'user_type' => $userTypeName,
                'role' => $role,
                'is_active' => $adminUser->is_active,
            ];

            return $this->helper_controller->send_response('success', 'LSP user details fetched', 200, $userDetails);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "Unknown error", 400, $e->getMessage());
        }
    }


    public function refresh(Request $request)
    {
        try {
            $newToken = auth->guard('admin')->refresh($request->bearerToken());
            return $this->helper_controller->send_response('success', 'Token refreshed', 200, ['token' => $newToken]);
        } catch (JWTException $e) {
            return $this->helper_controller->send_response('failed', 'Failed to refresh token', 400, ['error' => $e->getMessage()]);
        }
    }

    // // Edit an admin user
    // public function edit($id)
    // {
    //     try {
    //         $adminUser = AdminUser::findOrFail($id);
    //         return $this->helper_controller->send_response('success', 'Admin user details fetched', 200, $adminUser);
    //     } catch (Exception $e) {
    //         return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
    //     }
    // }
    public function edit($id)
    {
        try {
            // Fetch the admin user with department and user type details
            $adminUser = AdminUser::with(['departmentRelation:id,department', 'userTypeRelation:id,user_type'])
                ->findOrFail($id);

            // Construct response with the admin user details and associated department and user type
            return $this->helper_controller->send_response('success', 'Admin user details fetched', 200, $adminUser);
        } catch (Exception $e) {
            // Handle exception
            return $this->helper_controller->send_response('failed', "Unknown error", 400, $e->getMessage());
        }
    }

    // Update an admin user
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            'mobile' => 'required|digits:10|unique:admin_users,mobile,' . $id,
            'email' => 'required|email|unique:admin_users,email,' . $id,
            'password' => 'nullable|string|min:8|confirmed',
            'department' => 'required|integer',
            'user_type' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $adminUser = AdminUser::findOrFail($id);
            $adminUser->update([
                'name' => $request->name,
                'mobile' => $request->mobile,
                'email' => $request->email,
                'password' => $request->password ? Hash::make($request->password) : $adminUser->password,
                'department' => $request->department,
                'user_type' => $request->user_type,
            ]);

            return $this->helper_controller->send_response('success', 'Admin user updated successfully', 200, $adminUser);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "Unknown error", 400, $e->getMessage());
        }
    }

    public function userList(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1', // Page number must be a positive integer
                'number_of_records' => 'integer|min:1|max:100', // Limit number of records to a reasonable range
                'name' => ['nullable', 'regex:/^[a-zA-Z\s]+$/', 'max:255'], // Name must contain only letters and spaces
                'email' => 'nullable|email|max:255', // Validate proper email format
                'mobile' => 'nullable|regex:/^\d{10}$/', // Ensure mobile is a 10-digit number
                'department' => 'nullable|string|max:255', // Department should be a string, max 255 characters
            ]);

            // Handle validation failure
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $page = $request->get('page', 1);
            $numberOfRecords = $request->get('number_of_records', 10);

            $query = AdminUser::query();

            if ($request->filled('name')) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($request->get('name', '')) . '%']);
            }

            if ($request->filled('email')) {
                $query->whereRaw('LOWER(email) LIKE ?', ['%' . strtolower($request->get('email', '')) . '%']);
            }

            if ($request->filled('mobile')) {
                $query->where('mobile', 'LIKE', '%' . $request->mobile . '%');
            }

            if ($request->filled('department')) {
                $query->where('department', $request->department);
            }

            $adminUsers = $query->where('is_deleted', false)
                                ->orderBy('name', 'asc')
                                ->paginate($numberOfRecords, ['*'], 'page', $page);

            $adminUsers->getCollection()->transform(function ($adminUser) {
                return [
                    'id' => $adminUser->id,
                    'name' => $adminUser->name,
                    'mobile' => $adminUser->mobile,
                    'email' => $adminUser->email,
                    'department_id' => $adminUser->departmentRelation ? $adminUser->departmentRelation->id : null,
                    'department' => $adminUser->departmentRelation ? $adminUser->departmentRelation->department : null,
                    'user_type_id' => $adminUser->userTypeRelation ? $adminUser->userTypeRelation->id : null,
                    'user_type' => $adminUser->userTypeRelation ? $adminUser->userTypeRelation->user_type : null,
                    'is_active' => $adminUser->is_active,
                    'remarks' => $adminUser->remarks,
                    'is_deleted' => $adminUser->is_deleted,
                    'updated_by' => $adminUser->updated_by,
                    'deleted_at' => $adminUser->deleted_at,
                ];
            });

            return $this->helper_controller->send_response('success', 'Admin users fetched successfully', 200, $adminUsers);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "Unknown error", 400, $e->getMessage());
        }
    }

    public function login_with_otp(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';

        if (!isset($request->mobile) || empty($request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Mobile number is required";
        } elseif (!preg_match('/^\d{10}$/', $request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Invalid mobile number format. Mobile number must be a 10-digit number";
        }

        if (!isset($request->otp) || empty($request->otp)) {
            $errorCount++;
            $errorArray['otp'] = "OTP is required";
        } elseif (!preg_match('/^\d{6}$/', $request->otp)) {
            $errorCount++;
            $errorArray['otp'] = "Invalid OTP format. OTP must be a 6-digit number";
        }

        if($errorCount > 0) {
            return response()->json([
                'status'=>'failed',
                'message'=>"Invalid data",
                'error_list'=> $errorArray
            ], 422);
        }

        $user = AdminUser::where('otp', $request->otp)->where('mobile', $request->mobile)->first();

        if($user) {
            
            $token = JWTAuth::fromUser($user);

            if(!empty($token)) {
                $user->otp = null;
                $user->save();
                return response()->json([
                    "status" => 'success',
                    "message" => "User logged in successfully",
                    "token" => $token,
                    "user" => $user
                ], 200);
            }
            
        }

        return response()->json([
            "status" => 'failed',
            "message" => "Invalid otp"
        ], 422);
    }
    
    public function login_with_otp_b2b(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';

        if (!isset($request->otp) || empty($request->otp)) {
            $errorCount++;
            $errorArray['otp'] = "OTP is required";
        } elseif (!preg_match('/^\d{6}$/', $request->otp)) {
            $errorCount++;
            $errorArray['otp'] = "Invalid OTP format. OTP must be a 6-digit number";
        }

        if($errorCount > 0) {
            return response()->json([
                'status'=>'failed',
                'message'=>"Invalid data",
                'error_list'=> $errorArray
            ], 422);
        }

        $user = LspExecutiveDetails::where('otp', $request->otp)->first();

        if($user) {
            
            $token = JWTAuth::fromUser($user);

            if(!empty($token)) {
                $user->otp = null;
                $user->save();
                return response()->json([
                    "status" => 'success',
                    "message" => "User logged in successfully",
                    "token" => $token,
                    "user" => $user
                ], 200);
            }
            
        }

        return response()->json([
            "status" => 'failed',
            "message" => "Invalid otp"
        ], 422);
    }

    public function verify_mobile_otp(Request $request)
    {
        try {
            // Validate the request data
            $validator = Validator::make($request->all(), [
                'otp' => 'required|digits:6',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', 'Invalid data', 422, [
                    'error_list' => $validator->errors()
                ]);
            }

            $logedin_user = Auth::user();

            // Check if the user with the provided OTP exists
            $user = AdminUser::where('otp', $request->otp)
                ->where('id', $logedin_user->id)
                ->first();

            if ($user) {
                // Clear the OTP after successful verification
                $user->otp = null;
                $user->save();

                return $this->helper_controller->send_response('success', 'Account verified successfully', 200);
            } else {
                // Return an error if OTP is incorrect or not found
                return $this->helper_controller->send_response('failed', 'Invalid OTP or user not found', 422);
            }

        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred while verifying the OTP', 500);
        }
    }

    public function changePassword(Request $request)
    {
        try {
            // Get the authenticated user
            $user = Auth::user();
            
            // Validate the request data
            $validator = Validator::make($request->all(), [
                'new_password' => 'required|min:8',
                'confirm_password' => 'required|same:new_password',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            // Update the user's password
            $user->password = Hash::make($request->new_password);
            $user->save();

            return $this->helper_controller->send_response('success', 'Password changed successfully', 200);

        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred while changing the password', 500);
        }
    }

    public function sendOtp(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';

        if (!isset($request->mobile) || empty($request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Mobile number is required";
        } elseif (!preg_match('/^\d{10}$/', $request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Invalid mobile number format. Mobile number must be a 10-digit number";
        }

        if($errorCount > 0) {
            return response()->json([
                'status'=>'failed',
                'message'=>"Invalid data",
                'error_list'=> $errorArray
            ], 422);
        }

        // ->where('is_active', true)
        $user = AdminUser::where('mobile', $request->mobile)->first();
        if($user) {
            // if($user->is_active) {

                $otp = '346296'; // $this->generateUniqueOtp();
                $user->otp = $otp;
                $user->save();

                $phone = "+91" . $request->mobile;
                $message = $otp." is your one time password (OTP) to log in to Finayo. Please enter OTP to proceed";

                // return response()->json([
                //     'status' => 'success',
                //     'message' => 'OTP sent successfully',
                //     'otp' => $otp
                // ], 200);

                /**
                 * Send SMS start
                 */
                try {
                    // $this->twilio->sendSms($phone, $message);
                    // return response()->json(['status' => 'SMS Sent Successfully']);
                    return response()->json([
                        'status' => 'success',
                        'message' => 'OTP sent successfully',
                        'otp' => $otp
                    ], 200);
                } catch (Exception $e) {
                    // return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
                    return response()->json([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ], 403);
                }
                /**
                 * Send SMS ends
                 */
                
            // } else {
            //     return response()->json([
            //         'status' => 'failed',
            //         'message' => 'Your account is inactive'
            //     ], 403);
            // }
        } else {
            return response()->json([
                "status" => 'failed',
                "message" => "Please register yourself first"
            ], 422);
        }
    }
    
    public function b2b_send_otp(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';

        if (!isset($request->mobile) || empty($request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Mobile number is required";
        } elseif (!preg_match('/^\d{10}$/', $request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Invalid mobile number format. Mobile number must be a 10-digit number";
        }

        if($errorCount > 0) {
            return response()->json([
                'status'=>'failed',
                'message'=>"Invalid data",
                'error_list'=> $errorArray
            ], 422);
        }

        // ->where('is_active', true)
        $user = LspExecutiveDetails::where('mobile', $request->mobile)->first();
        if($user) {
            if($user->is_active === true) {
                $otp = '346296'; //$this->generateUniqueOtp();
                $user->otp = $otp;
                $user->save();

                $phone = "+91" . $request->mobile;
                $message = $otp." is your one time password (OTP) to log in to Finayo. Please enter OTP to proceed";

                /**
                 * Send SMS start
                 */
                try {
                    // $this->twilio->sendSms($phone, $message);
                    // return response()->json(['status' => 'SMS Sent Successfully']);
                    return response()->json([
                        'status' => 'success',
                        'message' => 'OTP sent successfully',
                        'otp' => $otp
                    ], 200);
                } catch (Exception $e) {
                    // return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
                    return response()->json([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ], 403);
                }
                /**
                 * Send SMS ends
                 */
                
            } else {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Your account is inactive'
                ], 403);
            }
        } else {
            return response()->json([
                "status" => 'failed',
                "message" => "Please register yourself first"
            ], 422);
        }
    }

    public function generateUniqueOtp() {
        do {
            $otp = mt_rand(100000, 999999);

        } while (AdminUser::where('otp', $otp)->exists());
    
        return $otp;
    }

    public function verify_token(Request $request){
        try {
            // Check if the token is valid
            $user = JWTAuth::parseToken()->authenticate();
            if (!$user) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'User not found'
                ], 404);
            }

            // Token is valid, proceed with the request
            return response()->json([
                'status' => 'success',
                'message' => 'Token is valid',
                'user' => $user
            ], 200);
        } catch (JWTException $e) {
            try {
                JWTAuth::invalidate(JWTAuth::getToken());
            } catch (JWTException $e) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Token is invalid or expired'
                ], 401);
            }

            // Return an error response
            return response()->json([
                'status' => 'failed',
                'message' => 'Token is invalid or expired'
            ], 401);
        }
    }

    // public function update_status($id)
    // {
    //     try {
    //         $userDetails = AdminUser::find($id);
    //         if(!$userDetails) {
    //             return $this->helper_controller->send_response('failed', "User not found", 422);
    //         }
    //         if($userDetails->is_active == true) {
    //             $userDetails->is_active = false;
    //         } else {
    //             $userDetails->is_active = true;
    //         }
    //         $userDetails->save();
    
    //         return $this->helper_controller->send_response('success', 'User status updated', 200);
    //     } catch (Exception $e) {
    //         return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
    //     }
    // } 

    public function update_status(Request $request, $id)
    {
        try {
            // remarks input is added on 26-11-2024
            if(!$request->filled('remarks')) {
                return $this->helper_controller->send_response('failed', "Remark is requird", 422);
            }

            $userDetails = AdminUser::find($id);
            if(!$userDetails) {
                return $this->helper_controller->send_response('failed', "User not found", 422);
            }
            
            if($userDetails->is_active == true) {
                $userDetails->is_active = false;
            } else {
                $userDetails->is_active = true;
            }

            $userDetails->remarks = $request->remarks ?? '';
            $userDetails->save();
    
            return $this->helper_controller->send_response('success', 'User status updated', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }
}
