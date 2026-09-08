<?php
// b2b uat
namespace App\Http\Controllers\B2B\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelperController;
use App\Models\BorrowerKyc;
use App\Models\CoapplicantKyc;
use App\Models\Guarantor;
use App\Models\DocumentBackups;
use App\Models\Documents;
use App\Models\LoanBasicDetails;
use App\Models\User;
use App\Models\VendorServiceApi;
use App\Services\TwilioService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class BorrowerController extends Controller
{
    protected $twilio;
    protected $helper_controller;
    public function __construct(TwilioService $twilio, HelperController $helper_controller) {
        $this->twilio = $twilio;
        $this->helper_controller = $helper_controller;
    }

    // Registration
    // public function borrower_registration(Request $request) {
    //     $input = $request->all();
    //     $errorArray = [];
    //     $errorCount = 0;
    //     $step = '';

    //     if (!isset($request->name) || empty($request->name)) {
    //         $errorCount++;
    //         $errorArray['name'] = "Name is required";
    //         return $this->helper_controller->send_response("failed", $errorArray['name'], 422);
    //     } elseif (!preg_match('/^[a-zA-Z\s]+$/', $request->name)) {
    //         $errorCount++;
    //         $errorArray['name'] = "Name should contain only alphabets and spaces";
    //         return $this->helper_controller->send_response("failed", $errorArray['name'], 422);
    //     }
        
    //     if (!isset($request->mobile) || empty($request->mobile)) {
    //         $errorCount++;
    //         $errorArray['mobile'] = "Mobile number is required";
    //         return $this->helper_controller->send_response("failed", $errorArray['mobile'], 422);
    //     } elseif (!preg_match('/^\d{10}$/', $request->mobile)) {
    //         $errorCount++;
    //         $errorArray['mobile'] = "Invalid mobile number format. Mobile number must be a 10-digit number";
    //         return $this->helper_controller->send_response("failed", $errorArray['mobile'], 422);
    //     } else {
    //         // Check if the mobile number already exists in the database
    //         $existingUser = User::where('mobile', $request->mobile)->where('is_active', true)->first();
    //         if ($existingUser) {
    //             $errorCount++;
    //             $errorArray['mobile'] = "Mobile number already exists";
    //             return $this->helper_controller->send_response("failed", $errorArray['mobile'], 422);
    //         }
    //     }
        
    //     if (!isset($request->email) || empty($request->email)) {
    //         $errorCount++;
    //         $errorArray['email'] = "Email is required";
    //         return $this->helper_controller->send_response("failed", $errorArray['email'], 422);
    //     } elseif (!filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
    //         $errorCount++;
    //         $errorArray['email'] = "Invalid email format";
    //         return $this->helper_controller->send_response("failed", $errorArray['email'], 422);
    //     } else {
    //         // Check if the email already exists in the database
    //         $existingUser = User::where('email', $request->email)->where('is_active', true)->first();
    //         if ($existingUser) {
    //             $errorCount++;
    //             $errorArray['email'] = "Email already exists";
    //             return $this->helper_controller->send_response("failed", $errorArray['email'], 422);
    //         }
    //     }

    //     if($errorCount > 0) {
    //         return response()->json([
    //             'status'=>'failed',
    //             'message'=>"Invalid data",
    //             'error_list'=> $errorArray
    //         ], 422);
    //     }
        
    //     try {
    //         // Store User
    //         $otp = $this->generateUniqueOtp();
    //         $step = 2;
    //         $user = User::create([
    //             'name' => $request->name,
    //             'email' => $request->email,
    //             'mobile' => $request->mobile,
    //             'password' => Hash::make($request->mobile),
    //             'otp' => $otp,
    //             'step_no' => $step,
    //             'executive_id' => auth()->id() ?? null,
    //             // 'password' => Hash::make($request->password),
    //         ]);

    //         // Response
    //         $phone = "+91" . $request->mobile;
    //         $message = $otp." is your Finayo account verification code. Please enter this code to verify your mobile number.";

    //         /**
    //          * Send SMS
    //          */

    //         $this->twilio->sendSms($phone, $message);
    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'OTP sent successfully',
    //             'otp' => $otp
    //         ], 200);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => $e->getMessage()
    //         ], 422);
    //     }
    // }
    public function borrower_registration(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'   => ['required', 'regex:/^[a-zA-Z\s]+$/'],
            'mobile' => ['required', 'regex:/^\d{10}$/'],
            'email'  => ['required', 'email'],
        ], [
            'name.regex'   => 'Name should contain only alphabets and spaces.',
            'mobile.regex' => 'Mobile number must be a 10-digit number.',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        // Active duplicate check
        $activeByMobile = User::where('mobile', $request->mobile)->where('is_active', true)->exists();
        if ($activeByMobile) {
            return $this->helper_controller->send_response('failed', 'Mobile number already registered.', 422);
        }

        $activeByEmail = User::whereRaw('LOWER(email) = ?', [strtolower($request->email)])->where('is_active', true)->exists();
        if ($activeByEmail) {
            return $this->helper_controller->send_response('failed', 'Email already registered.', 422);
        }

        return DB::transaction(function () use ($request) {
            $otp = 346296; //$this->generateUniqueOtp();

            // Reuse inactive user if exists (prevents duplicate rows)
            $user = User::where('mobile', $request->mobile)->where('is_active', false)->where('is_deleted', false)->first();

            if ($user) {
                $user->update([
                    'name'         => ucwords(strtolower($request->name)),
                    'email'        => strtolower($request->email),
                    'otp'          => $otp,
                    'step_no'      => 2,
                    'executive_id' => auth()->id() ?? null,
                ]);
            } else {
                $user = User::create([
                    'name'         => ucwords(strtolower($request->name)),
                    'email'        => strtolower($request->email),
                    'mobile'       => $request->mobile,
                    'otp'          => $otp,
                    'step_no'      => 2,
                    'is_active'    => false,
                    'is_deleted'   => false,
                    'executive_id' => auth()->id() ?? null,
                ]);
            }

            // $this->twilio->sendSms(
            //     '+91' . $request->mobile,
            //     "{$otp} is your account verification code. Please enter this code to verify your mobile number."
            // );

            return response()->json(['status' => 'success', 'message' => 'OTP sent successfully.'], 200);
        });
    }

    // public function verify_borrower_registration_otp(Request $request) {
    //     $input = $request->all();
    //     $errorArray = [];
    //     $errorCount = 0;
    //     $step = '';

    //     if (!isset($request->otp) || empty($request->otp)) {
    //         $errorCount++;
    //         $errorArray['otp'] = "OTP is required";
    //         return $this->helper_controller->send_response("failed", $errorArray['otp'], 422);
    //     } elseif (!preg_match('/^\d{6}$/', $request->otp)) {
    //         $errorCount++;
    //         $errorArray['otp'] = "Invalid OTP format. OTP must be a 6-digit number";
    //         return $this->helper_controller->send_response("failed", $errorArray['otp'], 422);
    //     }

    //     if($errorCount > 0) {
    //         return response()->json([
    //             'status'=>'failed',
    //             'message'=>"Invalid data",
    //             'error_list'=> $errorArray
    //         ], 422);
    //     }

    //     // Validate OTP for mobile number
    //     $user = User::where('otp', $request->otp)->first();
    //     if($user) {
    //         $user->otp = null;
    //         $user->is_active = true;
    //         $user->save();
            
    //         return response()->json([
    //             "status" => 'success',
    //             "message" => "User registered successfully",
    //             "user" => $user
    //         ], 200);
    //     }

    //     return response()->json([
    //         "status" => 'failed',
    //         "message" => "Invalid otp"
    //     ], 422);
    // }

    public function verify_borrower_registration_otp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => ['required', 'regex:/^\d{10}$/'],
            'otp'    => ['required', 'regex:/^\d{6}$/'],
        ], [
            'mobile.regex' => 'Mobile number must be a 10-digit number.',
            'otp.regex'    => 'OTP must be a 6-digit number.',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        return DB::transaction(function () use ($request) {
            $user = User::where('mobile', $request->mobile)
                ->where('otp', $request->otp)
                ->where('is_active', false)
                ->lockForUpdate()
                ->first();

            if (!$user) {
                return response()->json(['status' => 'failed', 'message' => 'Invalid OTP or mobile number.'], 422);
            }

            $user->update([
                'otp'       => null,
                'is_active' => true,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'User registered successfully.',
                'user'    => $user,
            ], 200);
        });
    }

    public function generateUniqueOtp() {
        do {
            $otp = mt_rand(100000, 999999);

        } while (User::where('otp', $otp)->exists());
    
        return $otp;
    }

    // public function verify_borrower_otp(Request $request) {
    //     $input = $request->all();
    //     $errorArray = [];
    //     $errorCount = 0;
    //     $step = '';

    //     if (!isset($request->otp) || empty($request->otp)) {
    //         $errorCount++;
    //         $errorArray['otp'] = "OTP is required";
    //     } elseif (!preg_match('/^\d{6}$/', $request->otp)) {
    //         $errorCount++;
    //         $errorArray['otp'] = "Invalid OTP format. OTP must be a 6-digit number";
    //     }

    //     if($errorCount > 0) {
    //         return response()->json([
    //             'status'=>'failed',
    //             'message'=>"Invalid data",
    //             'error_list'=> $errorArray
    //         ], 422);
    //     }

    //     $user = User::where('otp', $request->otp)->where('is_active', true)->first();

    //     if($user) {
    //         $user->otp = null;
    //         $user->save();
    //         return response()->json([
    //             "status" => 'success',
    //             "message" => "User verified successfully",
    //             "user" => $user
    //         ], 200);
    //     }

    //     return response()->json([
    //         "status" => 'failed',
    //         "message" => "Invalid otp"
    //     ], 422);
    // }

    // public function send_borrower_otp(Request $request) {
    //     $input = $request->all();
    //     $errorArray = [];
    //     $errorCount = 0;
    //     $step = '';

    //     if (!isset($request->mobile) || empty($request->mobile)) {
    //         $errorCount++;
    //         $errorArray['mobile'] = "Mobile number is required";
    //     } elseif (!preg_match('/^\d{10}$/', $request->mobile)) {
    //         $errorCount++;
    //         $errorArray['mobile'] = "Invalid mobile number format. Mobile number must be a 10-digit number";
    //     }

    //     if($errorCount > 0) {
    //         return response()->json([
    //             'status'=>'failed',
    //             'message'=>"Invalid data",
    //             'error_list'=> $errorArray
    //         ], 422);
    //     }

    //     $user = User::where('mobile', $request->mobile)->where('is_active', true)->first();
    //     if($user) {
    //         if($user->is_active) {

    //             $otp = $this->generateUniqueOtp();
    //             $user->otp = $otp;
    //             $user->save();

    //             $phone = "+91" . $request->mobile;
    //             $message = $otp." is your one time password (OTP) to log in to Finayo. Please enter OTP to proceed";

    //             // return response()->json([
    //             //     'status' => 'success',
    //             //     'message' => 'OTP sent successfully',
    //             //     'otp' => $otp
    //             // ], 200);

    //             /**
    //              * Send SMS start
    //              */
    //             try {
    //                 $this->twilio->sendSms($phone, $message);
    //                 // return response()->json(['status' => 'SMS Sent Successfully']);
    //                 return response()->json([
    //                     'status' => 'success',
    //                     'message' => 'OTP sent successfully',
    //                     'otp' => $otp
    //                 ], 200);
    //             } catch (\Exception $e) {
    //                 // return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
    //                 return response()->json([
    //                     'status' => 'error',
    //                     'message' => $e->getMessage()
    //                 ], 403);
    //             }
    //             /**
    //              * Send SMS ends
    //              */
                
    //         } else {
    //             return response()->json([
    //                 'status' => 'failed',
    //                 'message' => 'Your account is inactive'
    //             ], 403);
    //         }
    //     } else {
    //         return response()->json([
    //             "status" => 'failed',
    //             "message" => "Please register yourself first"
    //         ], 422);
    //     }
    // }

    public function send_borrower_otp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => ['required', 'regex:/^\d{10}$/'],
        ], [
            'mobile.regex' => 'Mobile number must be a 10-digit number.',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        // Accept both active and inactive — activate on verify
        $user = User::where('mobile', $request->mobile)->where('is_deleted', false)->first();

        if (!$user) {
            return response()->json(['status' => 'failed', 'message' => 'Please register yourself first.'], 422);
        }

        return DB::transaction(function () use ($request, $user) {
            $otp = 346296; // $this->generateUniqueOtp();
            $user->update(['otp' => $otp]);

            try {
                // $this->twilio->sendSms(
                //     '+91' . $request->mobile,
                //     "{$otp} is your one-time password (OTP) to log in. Please enter OTP to proceed."
                // );

                return response()->json(['status' => 'success', 'message' => 'OTP sent successfully.'], 200);
            } catch (\Exception $e) {
                throw $e; // rolls back transaction
            }
        });
    }

    public function verify_borrower_otp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => ['required', 'regex:/^\d{10}$/'],
            'otp'    => ['required', 'regex:/^\d{6}$/'],
        ], [
            'mobile.regex' => 'Mobile number must be a 10-digit number.',
            'otp.regex'    => 'OTP must be a 6-digit number.',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        return DB::transaction(function () use ($request) {
            $user = User::where('mobile', $request->mobile)
                ->where('otp', $request->otp)
                ->where('is_deleted', false)
                ->lockForUpdate()
                ->first();

            if (!$user) {
                return response()->json(['status' => 'failed', 'message' => 'Invalid OTP or mobile number.'], 422);
            }

            $user->update([
                'otp'       => null,
                'is_active' => true,
            ]);

            // $token = $user->createToken('borrower')->plainTextToken;

            return response()->json([
                'status'  => 'success',
                'message' => 'User verified successfully.',
                // 'token'   => $token,
                'user'    => $user,
            ], 200);
        });
    }

    public function get_borrower_loan_details($borrower_id = null) {
        try {
            if (is_null($borrower_id)) {
                return $this->helper_controller->send_response('failed', "Borrower id is required", 422);
            }
            
            $loan_basic_details = LoanBasicDetails::where('id', $borrower_id)->first();
            if(!$loan_basic_details) {
                return $this->helper_controller->send_response('failed', "Borrower details not found", 422);
            }
            
            $product_type = '';

            if($loan_basic_details->product_id == '1') {
                $product_type = "E-Rickshaw (L3)";
            } elseif ($loan_basic_details->product_id == '2') {
                $product_type = "E-Auto (L5)";
            } elseif ($loan_basic_details->product_id == '3') {
                $product_type = "E-Two Wheeler";
            } elseif ($loan_basic_details->product_id == '4') {
                $product_type = "Battery";
            }

            $result = [
                'id' => $loan_basic_details->id,
                'borrower_unq_id' => $loan_basic_details->borrower_id,
                'product_id' => $loan_basic_details->product_id,
                'loan_amount' => $loan_basic_details->loan_amount,
                'product_type' => $product_type,
            ];
            return $this->helper_controller->send_response('success', "Loan basic details fetched", 200, $result);
            
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function get_borrower_list_for_executive() {
        $executive_id = auth()->id();
        $result_response = [];

        $borrower_list = LoanBasicDetails::select([
                                'loan_basic_details.id', 'loan_basic_details.borrower_id',
                                'borrower_kycs.name', 'borrower_kycs.mobile',  
                                'users.name as user_name', 'users.mobile as user_mobile'  
                            ])
                            ->join('users', 'loan_basic_details.user_id', '=', 'users.id')
                            ->leftJoin('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
                            ->where('loan_basic_details.executive_id', $executive_id)->get();
        if($borrower_list) {
            $result_response = $borrower_list->map(function ($item) {
                return [
                    'id'          => $item->id,
                    'borrower_id' => $item->borrower_id,
                    'name'        => $item->borrower_name ?? $item->user_name, // Fallback to user_name if borrower_name is null
                    'mobile'      => $item->borrower_mobile ?? $item->user_mobile, // Fallback to user_mobile if borrower_mobile is null
                ];
            });
        }
        
        return $this->helper_controller->send_response('success', "Borrower list fetched", 200, $result_response);
    }

    public function get_applicant_address_data(Request $request) {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
                'user_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $api_detail = VendorServiceApi::where('borrower_id', $request->borrower_id)
                            ->where('applicant_type', $request->user_type)
                            ->where('api_flag', 'AADHAR_CARD_OCR_BACK')
                            ->where('status', 'VALID')
                            ->latest()
                            ->first();
            if(!$api_detail) {
                return $this->helper_controller->send_response('failed', "Aadhar OCR not done yet", 422);
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

            // $single_line_address = $this->helper_controller->prepare_one_line_address_from_aadhar($address);
            
            $name = $mobile = $email = '';
            if($request->user_type == "BORROWER") {
                $borrower_details = BorrowerKyc::where('borrower_id', $request->borrower_id)->latest()->first();
                if($borrower_details) {
                    $name = $borrower_details->name;
                    $mobile = $borrower_details->mobile;
                    $email = $borrower_details->email;
                }      
            } elseif($request->user_type == "COAPPLICANT") {
                $coapplicant_details = CoapplicantKyc::where('borrower_id', $request->borrower_id)->latest()->first();
                if($coapplicant_details) {
                    $name = $coapplicant_details->name;
                    $mobile = $coapplicant_details->mobile;
                    $email = $coapplicant_details->email;
                }
            } elseif($request->user_type == "GUARANTOR") {
                $guarantor_details = Guarantor::where('borrower_id', $request->borrower_id)->latest()->first();
                if($guarantor_details) {
                    $name = $guarantor_details->name;
                    $mobile = $guarantor_details->mobile;
                    $email = $guarantor_details->email;

                }
            }

            $result_data = [
                'borrower_id' => $request->borrower_id,
                'user_type' => $request->user_type,
                'name' => $name,
                'mobile' => $mobile,
                'email' => $email,
                'single_line_address' => $single_line_address,
                'full_address' => $address
            ];

            return $this->helper_controller->send_response('success', "Address details fetched", 200, $result_data);
        } catch (\Throwable $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    public function get_user_details_for_esign(Request $request) {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
                'user_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $api_detail = VendorServiceApi::where('borrower_id', $request->borrower_id)
                            ->where('applicant_type', $request->user_type)
                            ->where('api_flag', 'AADHAR_CARD_OCR_BACK')
                            ->first();
            if(!$api_detail) {
                return $this->helper_controller->send_response('failed', "Aadhar OCR not done yet", 422);
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

            // $single_line_address = $this->helper_controller->prepare_one_line_address_from_aadhar($address);
            
            $name = $mobile = $email = '';
            if($request->user_type == "BORROWER") {
                $borrower_details = BorrowerKyc::where('borrower_id', $request->borrower_id)->latest()->first();
                if($borrower_details) {
                    $name = $borrower_details->name;
                    $mobile = $borrower_details->mobile;
                    $email = $borrower_details->email;
                }      
            } elseif($request->user_type == "COAPPLICANT") {
                $coapplicant_details = CoapplicantKyc::where('borrower_id', $request->borrower_id)->latest()->first();
                if($coapplicant_details) {
                    $name = $coapplicant_details->name;
                    $mobile = $coapplicant_details->mobile;
                    $email = $coapplicant_details->email;
                }
            } elseif($request->user_type == "GUARANTOR") {
                $guarantor_details = Guarantor::where('borrower_id', $request->borrower_id)->latest()->first();
                if($guarantor_details) {
                    $name = $guarantor_details->name;
                    $mobile = $guarantor_details->mobile;
                    $email = $guarantor_details->email;

                }
            }

            $city = $address['district'];
            $result = [
                'name' => $name,
                'mobile' => $mobile,
                'email' => $email,
                'city' => $city,
            ];

            return $this->helper_controller->send_response('success', "Address details fetched", 200, $result);
        } catch (\Throwable $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }
}
