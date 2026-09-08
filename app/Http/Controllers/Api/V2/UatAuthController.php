<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;
use App\Services\TwilioService;
use Tymon\JWTAuth\Exceptions\JWTException;

class UatAuthController extends Controller
{
    protected $twilio;
    
    public function __construct(TwilioService $twilio) {
        $this->twilio = $twilio;
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

    // Registration
    public function register(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';

        if (!isset($request->name) || empty($request->name)) {
            $errorCount++;
            $errorArray['name'] = "Name is required";
        } elseif (!preg_match('/^[a-zA-Z\s]+$/', $request->name)) {
            $errorCount++;
            $errorArray['name'] = "Name should contain only alphabets and spaces";
        }
        
        if (!isset($request->mobile) || empty($request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Mobile number is required";
        } elseif (!preg_match('/^\d{10}$/', $request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Invalid mobile number format. Mobile number must be a 10-digit number";
        } else {
            // Check if the mobile number already exists in the database
            $existingUser = User::where('mobile', $request->mobile)->where('is_active', true)->first();
            if ($existingUser) {
                $errorCount++;
                $errorArray['mobile'] = "Mobile number already exists";
            }
        }
        
        if (!isset($request->email) || empty($request->email)) {
            $errorCount++;
            $errorArray['email'] = "Email is required";
        } elseif (!filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
            $errorCount++;
            $errorArray['email'] = "Invalid email format";
        } else {
            // Check if the email already exists in the database
            $existingUser = User::where('email', $request->email)->where('is_active', true)->first();
            if ($existingUser) {
                $errorCount++;
                $errorArray['email'] = "Email already exists";
            }
        }

        if($errorCount > 0) {
            return response()->json([
                'status'=>'failed',
                'message'=>"Invalid data",
                'error_list'=> $errorArray
            ], 422);
        }
        
        // Store User
        $otp = '346296'; //$this->generateUniqueOtp();
        $step = 2;
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'mobile' => $request->mobile,
            'password' => Hash::make($request->mobile),
            'otp' => $otp,
            'step_no' => $step,
            // 'password' => Hash::make($request->password),
        ]);

        // Response
        $phone = "+91" . $request->mobile;
        $message = $otp." is your Finayo account verification code. Please enter this code to verify your mobile number.";

        // return response()->json([
        //     'status' => 'success',
        //     'message' => 'OTP sent successfully',
        //     'otp' => $otp,
        //     'step' => $step
        // ], 200);

        /**
         * Send SMS start
         */
        try {
            // $this->twilio->sendSms($phone, $message);
            return response()->json([
                'status' => 'success',
                'message' => 'OTP sent successfully',
                'otp' => $otp,
                'step' => $step
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 422);
        }
        /**
         * Send SMS ends
         */
    }

    // mobile otp verification at the time of registration

    public function verifyRegistrationMobileOtp(Request $request) {
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

        // Validate OTP for mobile number
        $user = User::where('otp', $request->otp)->first();
        if($user) {
            // Authenticate user and generate JWT token
            $token = JWTAuth::fromUser($user);

            if(!empty($token)) {
                $user->otp = null;
                $user->is_active = true;
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

    public function login(Request $request) {
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

        $user = User::where('otp', $request->otp)->where('is_active', true)->first();

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

        $user = User::where('mobile', $request->mobile)->where('is_active', true)->first();
        if($user) {
            if($user->is_active) {

                $otp = '346296'; $this->generateUniqueOtp();
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
                } catch (\Exception $e) {
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

        } while (User::where('otp', $otp)->exists());
    
        return $otp;
    }

    public function generateUniqueEmailOtp() {
        do {
            $otp = mt_rand(100000, 999999);

        } while (User::where('email_confirmation_code', $otp)->exists());
    
        return $otp;
    }

    public function createNewToken($token) {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL()*60,
            'user' => auth()->user()
        ]);
    }

    public function profile() {
        $userData = auth()->user();

        return response()->json([
            "status" => 'success',
            "user" => $userData
        ], 200);
    }

    public function logout() {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return response()->json([
                "status" => 'success',
                "message" => "User logged out successfully"
            ], 200);
        } catch (JWTException $e) {
            return response()->json([
                "status" => 'failed',
                'message' => 'Failed to log out, please try again'
            ], 422);
        }
        // auth()->logout();

        // return response()->json([
        //     "status" => 'success',
        //     "message" => "User logged out successfully"
        // ], 200);
    }

    public function refreshToken() {
        $newToken = auth()->refresh();

        return response()->json([
            "status" => true,
            "message" => "New access token generated",
            "token" => $newToken
        ]);
    }
}
