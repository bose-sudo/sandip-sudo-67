<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelperController;
use App\Models\LoanBasicDetails;
use App\Models\User;
use App\Services\TwilioService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    protected $twilio;
    protected $helper_controller;
    public function __construct(TwilioService $twilio, HelperController $helper_controller) {
        $this->twilio = $twilio;
        $this->helper_controller = $helper_controller;
    }

    public function borrower_registration(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';

        if (!isset($request->name) || empty($request->name)) {
            $errorCount++;
            $errorArray['name'] = "Name is required";
            return $this->helper_controller->send_response("failed", $errorArray['name'], 422);
        } elseif (!preg_match('/^[a-zA-Z\s]+$/', $request->name)) {
            $errorCount++;
            $errorArray['name'] = "Name should contain only alphabets and spaces";
            return $this->helper_controller->send_response("failed", $errorArray['name'], 422);
        }
        
        if (!isset($request->mobile) || empty($request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Mobile number is required";
            return $this->helper_controller->send_response("failed", $errorArray['mobile'], 422);
        } elseif (!preg_match('/^\d{10}$/', $request->mobile)) {
            $errorCount++;
            $errorArray['mobile'] = "Invalid mobile number format. Mobile number must be a 10-digit number";
            return $this->helper_controller->send_response("failed", $errorArray['mobile'], 422);
        } else {
            // Check if the mobile number already exists in the database
            $existingUser = User::where('mobile', $request->mobile)->where('is_active', true)->first();
            if ($existingUser) {
                $errorCount++;
                $errorArray['mobile'] = "Mobile number already exists";
                return $this->helper_controller->send_response("failed", $errorArray['mobile'], 422);
            }
        }
        
        if (!isset($request->email) || empty($request->email)) {
            $errorCount++;
            $errorArray['email'] = "Email is required";
            return $this->helper_controller->send_response("failed", $errorArray['email'], 422);
        } elseif (!filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
            $errorCount++;
            $errorArray['email'] = "Invalid email format";
            return $this->helper_controller->send_response("failed", $errorArray['email'], 422);
        } else {
            // Check if the email already exists in the database
            $existingUser = User::where('email', $request->email)->where('is_active', true)->first();
            if ($existingUser) {
                $errorCount++;
                $errorArray['email'] = "Email already exists";
                return $this->helper_controller->send_response("failed", $errorArray['email'], 422);
            }
        }

        if($errorCount > 0) {
            return response()->json([
                'status'=>'failed',
                'message'=>"Invalid data",
                'error_list'=> $errorArray
            ], 422);
        }
        
        try {
            // Store User
            $otp = $this->generateUniqueOtp();
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

            /**
             * Send SMS
             */

            $this->twilio->sendSms($phone, $message);
            return response()->json([
                'status' => 'success',
                'message' => 'OTP sent successfully',
                // 'otp' => $otp
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function verify_borrower_registration_otp(Request $request) {
        $input = $request->all();
        $errorArray = [];
        $errorCount = 0;
        $step = '';

        if (!isset($request->otp) || empty($request->otp)) {
            $errorCount++;
            $errorArray['otp'] = "OTP is required";
            return $this->helper_controller->send_response("failed", $errorArray['otp'], 422);
        } elseif (!preg_match('/^\d{6}$/', $request->otp)) {
            $errorCount++;
            $errorArray['otp'] = "Invalid OTP format. OTP must be a 6-digit number";
            return $this->helper_controller->send_response("failed", $errorArray['otp'], 422);
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
            $user->otp = null;
            $user->is_active = true;
            $user->save();
            
            return response()->json([
                "status" => 'success',
                "message" => "User registered successfully",
                "user" => $user
            ], 200);
        }

        return response()->json([
            "status" => 'failed',
            "message" => "Invalid otp"
        ], 422);
    }

    public function generateUniqueOtp() {
        do {
            $otp = mt_rand(100000, 999999);

        } while (User::where('otp', $otp)->exists());
    
        return $otp;
    }

    public function verify_borrower_otp(Request $request) {
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
            $user->otp = null;
            $user->save();
            return response()->json([
                "status" => 'success',
                "message" => "User verified successfully",
                "user" => $user
            ], 200);
        }

        return response()->json([
            "status" => 'failed',
            "message" => "Invalid otp"
        ], 422);
    }

    public function send_borrower_otp(Request $request) {
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

                $otp = $this->generateUniqueOtp();
                $user->otp = $otp;
                $user->save();

                $phone = "+91" . $request->mobile;
                $message = $otp." is your one time password (OTP) to log in to Finayo. Please enter OTP to proceed";

                /**
                 * Send SMS start
                 */
                try {
                    $this->twilio->sendSms($phone, $message);
                    
                    return response()->json([
                        'status' => 'success',
                        'message' => 'OTP sent successfully',
                        // 'otp' => $otp
                    ], 200);
                } catch (\Exception $e) {
                    
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
}
