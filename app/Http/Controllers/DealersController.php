<?php

namespace App\Http\Controllers;

use App\Mail\Mailer;
use App\Models\AdminUser;
use App\Models\BeneficiaryDetails;
use App\Models\Dealer;
use App\Models\DealerCompanyDocuments;
use App\Models\DealerShops;
use App\Models\LenderDealerProductCharges;
use App\Models\UserDepartments;
use App\Models\UserType;
use App\Models\Lenders;
use App\Models\LenderDirectors;
use App\Models\LenderDealerGroup;
use DateTime;
use Exception;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class DealersController extends Controller
{
    private $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function index()
    {
        $dealers = Dealer::where('is_deleted', false)->orderBy('created_at', 'desc')->get();
        return $this->helper_controller->send_response('success', 'Dealers fetched successfully', 200, $dealers);
    }

    public function dealer_screener() {
        $dealers = Dealer::select(['id', 'name', 'dealer_unq'])->where('is_deleted', false)->orderBy('name', 'asc')->get();
        return $this->helper_controller->send_response('success', 'Dealers fetched successfully', 200, $dealers);
    }

    public function dealer_screener_lsp() {
        $dealers = Dealer::select(['id', 'name'])->where('dealer_type', 'B2B')->where('is_deleted', false)->orderBy('name', 'asc')->get();
        return $this->helper_controller->send_response('success', 'Dealers fetched successfully', 200, $dealers);
    }

    public function read($id)
    {
        try { 
            $result = [];
            // $dealer = Dealer::where('is_deleted', false)->where('id', $id)->first();
            $dealer = Dealer::where('is_deleted', false)
                ->with(['designatedAuthorities', 'companyDocuments', 'state:id,state', 'oem:id,manufacturer', 'lender:id,name']) // Assuming these relationships are defined
                ->select([
                    'dealers.*', // Select all columns from the dealers table
                    DB::raw('pan as pan_number'), // Rename pan to pan_number
                    DB::raw('aadhar as aadhar_number'), // Rename aadhar to aadhar_number
                ])
                ->find($id);
            if (!$dealer) {
                return $this->helper_controller->send_response('error', 'Dealer not found', 422);
            }

            $beneficiary_details = BeneficiaryDetails::where('user_type', 'DEALER')->where('user_id', $id)->first();

            // Convert dealer object to array
            $dealer = $dealer->toArray();

            $result = [
                'id' => $dealer['id'],
                'dealer_unq' => $dealer['dealer_unq'],
                'dealer_type' => $dealer['dealer_type'],
                'name' => $dealer['name'],
                'is_active' => $dealer['is_active'],
                'modified_by' => $dealer['modified_by'],
                'created_at' => $dealer['created_at'],
                'dob' => $dealer['dob'],
                'mobile' => $dealer['mobile'],
                'alternative_number' => $dealer['alternative_number'],
                'email' => $dealer['email'],
                'pan' => $dealer['pan'],
                'aadhar' => $dealer['aadhar'],
                'pan_number' => $dealer['pan_number'],
                'aadhar_number' => $dealer['aadhar_number'],
                'landmark' => $dealer['landmark'],
                'city' => $dealer['city'],
                'pincode' => $dealer['pincode'],
                'address_line_1' => $dealer['address_line_1'],
                'address_line_2' => $dealer['address_line_2'],
                'dealer_loi' => $dealer['dealer_loi'],
                'cibil_report' => $dealer['cibil_report'],
                'trade_certificate' => $dealer['trade_certificate'],
                'gst_certificate' => $dealer['gst_certificate'],
                'security_cheque_1' => $dealer['security_cheque_1'],
                'security_cheque_2' => $dealer['security_cheque_2'],
                'security_cheque_3' => $dealer['security_cheque_3'],
                'security_cheque_4' => $dealer['security_cheque_4'],
                'dealer_image_1' => $dealer['dealer_image_1'],
                'dealer_image_2' => $dealer['dealer_image_2'],
                'showroom_img_1' => $dealer['showroom_img_1'],
                'showroom_img_2' => $dealer['showroom_img_2'],
                'showroom_img_3' => $dealer['showroom_img_3'],
                'showroom_img_4' => $dealer['showroom_img_4'],
                'onboarding_fees' => $dealer['onboarding_fees'],
                'payment_method' => $dealer['payment_method'],
                'payment_proof_img' => $dealer['payment_proof_img'],
                'longitude' => $dealer['longitude'],
                'latitude' => $dealer['latitude'],
                'lat_long_image' => $dealer['lat_long_image'],
                'dealer_finayo_agreement' => $dealer['dealer_finayo_agreement'],
                'state_id' => $dealer['state']['id'] ?? '',
                'state' => $dealer['state']['state'] ?? '',
                'company_type' => $dealer['company_documents']['company_type'] ?? '',
                'pan' => $dealer['company_documents']['pan'] ?? '',
                'aadhar' => $dealer['company_documents']['aadhar'] ?? '',
                'itr_file' => $dealer['company_documents']['itr_file'] ?? '',
                'bank_statement' => $dealer['company_documents']['bank_statement'] ?? '',
                'icat_certificate' => $dealer['company_documents']['icat_certificate'] ?? '',
                'rent_or_lease_agreement' => $dealer['company_documents']['rent_or_lease_agreement'] ?? '',
                'utility_bill' => $dealer['company_documents']['utility_bill'] ?? '',
                'manufacturing_license' => $dealer['company_documents']['manufacturing_license'] ?? '',
                'partnership_deed' => $dealer['company_documents']['partnership_deed'] ?? '',
                'balance_sheet' => $dealer['company_documents']['balance_sheet'] ?? '',
                'certificate_of_incorporation' => $dealer['company_documents']['certificate_of_incorporation'] ?? '',
                'factory_address_proof' => $dealer['company_documents']['factory_address_proof'] ?? '',
                'aoa' => $dealer['company_documents']['aoa'] ?? '',
                'moa' => $dealer['company_documents']['moa'] ?? '',
                'beneficiary_name' => $beneficiary_details ? $beneficiary_details->beneficiary_name : '',
                'account_number' => $beneficiary_details ? $beneficiary_details->account_number : '',
                'ifsc_code' => $beneficiary_details ? $beneficiary_details->ifsc_code : '',
                'micr_code' => $beneficiary_details ? $beneficiary_details->micr_code : '',
                'oem_name' => $dealer['oem']['manufacturer'] ?? '',
                'lender_name' => $dealer['lender']['name'] ?? '',
            ];
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
            }
            if(!isset($lender_id)) {
                $result['designatedAuthorities'] = $dealer['designated_authorities'] ?? null;
            }


            return $this->helper_controller->send_response('success', 'Dealer fetched successfully', 200, $result);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }

    public function destroy($id)
    {
        $dealer = Dealer::find($id);
        if (!$dealer || $dealer->is_deleted) {
            return $this->helper_controller->send_response('error', 'Dealer not found', 422);
        }

        try {
            $dealer->update(['is_deleted' => true, 'deleted_at' => now()]);
            return $this->helper_controller->send_response('success', 'Dealer deleted successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', 'Error deleting dealer: ' . $e->getMessage(), 500);
        }
    }

    public function store_dealer(Request $request) {

        DB::beginTransaction();
        try {
            $inputs = $request->all();
            date_default_timezone_set('Asia/Kolkata');
            $errors = [];
            $errorCount = 0;

            if(!isset($inputs['company_type']) || empty($inputs['company_type'])) {
                $errors['company_type'] = "Company type is required";
                $errorCount++;
                return $this->helper_controller->send_response('failed', $errors['company_type'], 422);
            }

            if(!in_array($inputs['company_type'], ["PARTNERSHIP", "PROPRIETORSHIP", "PRIVATE LIMITED"])) {
                $errors['company_type'] = "Invalid company type. Valid types are [PARTNERSHIP, PROPRIETORSHIP, PRIVATE LIMITED]";
                $errorCount++;
                return $this->helper_controller->send_response('failed', $errors['company_type'], 422);
            }

            if(!isset($inputs['dealer_type']) || empty($inputs['dealer_type'])) {
                $errors['dealer_type'] = "Dealer type is required";
                $errorCount++;
                return $this->helper_controller->send_response('failed', $errors['dealer_type'], 422);
            }
            
            if(!in_array($inputs['dealer_type'], ["B2B", "B2C"])) {
                $errors['dealer_type'] = "Invalid dealer type. Valid types are [B2B, B2C]";
                $errorCount++;
                return $this->helper_controller->send_response('failed', $errors['dealer_type'], 422);
            }

            if (!isset($inputs['name']) || empty($inputs['name'])) {
                $errors['name'] = "Name is required";
                return $this->helper_controller->send_response('failed', $errors['name'], 422);
                $errorCount++;
            } elseif (!preg_match("/^[a-zA-Z\s]+$/", $inputs['name'])) {
                $errors['name'] = "Name must contain only alphabetic characters and spaces";
                return $this->helper_controller->send_response('failed', $errors['name'], 422);
                $errorCount++;
            }
            
            // Validate date of birth
            if($inputs['company_type'] == 'PRIVATE LIMITED') {
                // $inputs['dob'] is actually date of incorporation

                if (!isset($inputs['dob']) || empty($inputs['dob'])) {
                    $errors['dob'] = "Date of incorporation is required";
                    return $this->helper_controller->send_response('failed', $errors['dob'], 422);
                    $errorCount++;
                } else{ 
                    $date = DateTime::createFromFormat('Y-m-d', $inputs['dob']);
                    if (!$date || $date->format('Y-m-d') !== $inputs['dob']) {
                        $errors['dob'] = "Date of incorporation must be in the format YYYY-MM-DD";
                        $errorCount++;
                        return $this->helper_controller->send_response('failed', $errors['dob'], 422);
                    } elseif ($date > new DateTime()) {
                        $errors['dob'] = "Date of incorporation cannot be a future date";
                        return $this->helper_controller->send_response('failed', $errors['dob'], 422);
                        $errorCount++;
                    }
                }
            }

            if (!isset($inputs['mobile']) || empty($inputs['mobile'])) {
                $errors['mobile'] = "Mobile number is required";
                return $this->helper_controller->send_response('failed', $errors['mobile'], 422);
                $errorCount++;
            } elseif (!preg_match('/^\d{10}$/', $inputs['mobile'])) {
                $errors['mobile'] = "Mobile number must be 10 digits";
                return $this->helper_controller->send_response('failed', $errors['mobile'], 422);
                $errorCount++;
            } elseif (Dealer::where('mobile', $inputs['mobile'])->exists()) {
                $errors['mobile'] = "Mobile number already exists";
                return $this->helper_controller->send_response('failed', $errors['mobile'], 422);
                $errorCount++;
            } elseif (AdminUser::where('mobile', $inputs['mobile'])->exists()) {
                $errors['mobile'] = "Mobile number already exists";
                return $this->helper_controller->send_response('failed', $errors['mobile'], 422);
                $errorCount++;
            }

            if(isset($inputs['alternative_number']) && !empty($inputs['alternative_number'])) {
                if(isset($inputs['mobile']) && !empty($inputs['mobile']) && $inputs['alternative_number'] == $inputs['mobile']) {
                    $errors['alternative_number'] = "Mobile number and alternative mobile number can not be same";
                    return $this->helper_controller->send_response('failed', $errors['alternative_number'], 422);
                    $errorCount++;
                }

                if (!preg_match('/^\d{10}$/', $inputs['alternative_number'])) {
                    $errors['alternative_number'] = "Alternaative mobile number must be 10 digits";
                    return $this->helper_controller->send_response('failed', $errors['alternative_number'], 422);
                    $errorCount++;
                } elseif (Dealer::where('mobile', $inputs['alternative_number'])
                                ->orWhere('alternative_number', $inputs['alternative_number'])
                                ->exists()
                    ) {
                        $errors['alternative_number'] = "Alternaative mobile number already exists";
                    return $this->helper_controller->send_response('failed',     $errors['alternative_number'], 422);
                    $errorCount++;
                }
            }

            if (!isset($inputs['email']) || empty($inputs['email'])) {
                $errors['email'] = "Email is required";
                return $this->helper_controller->send_response('failed', $errors['email'], 422);
                $errorCount++;
            } elseif (!filter_var($inputs['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = "Invalid email format";
                return $this->helper_controller->send_response('failed', $errors['email'], 422);
                $errorCount++;
            } elseif (Dealer::where('email', $inputs['email'])->exists()) {
                return $this->helper_controller->send_response('failed', $errors['email'], 422);
                $errorCount++;
            } elseif (AdminUser::where('email', $inputs['email'])->exists()) {
                $errors['email'] = "Email already exists";
                return $this->helper_controller->send_response('failed', $errors['email'], 422);
                $errorCount++;
            }

            if (!isset($inputs['password']) || empty($inputs['password'])) {
                $errors['password'] = "Password is required";
                return $this->helper_controller->send_response('failed', $errors['password'], 422);
                $errorCount++;
            } elseif (strlen($inputs['password']) < 8) {
                $errors['password'] = "Password must be at least 8 characters long";
                return $this->helper_controller->send_response('failed', $errors['password'], 422);
                $errorCount++;
            }

            if (!isset($inputs['pan_number']) || empty($inputs['pan_number'])) {
                $errors['pan_number'] = "PAN is required";
                return $this->helper_controller->send_response('failed', $errors['pan_number'], 422);
                $errorCount++;
            } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $inputs['pan_number'])) {
                $errors['pan_number'] = "Invalid PAN format";
                return $this->helper_controller->send_response('failed', $errors['pan_number'], 422);
                $errorCount++;
            } elseif (Dealer::where('pan', $inputs['pan_number'])->exists()) {
                $errors['pan_number'] = "PAN already exists";
                return $this->helper_controller->send_response('failed', $errors['pan_number'], 422);
                $errorCount++;
            }

            // if (isset($inputs['aadhar_number']) && empty($inputs['aadhar_number'])) {
            //     if (!preg_match("/^\d{12}$/", $inputs['aadhar_number'])) {
            //         $errors['aadhar_number'] = "Aadhar number must be a 12-digit number";
            //         return $this->helper_controller->send_response('failed', $errors['aadhar_number'], 422);
            //         $errorCount++;
            //     }
            // } 

            if (isset($inputs['landmark']) && !empty($inputs['landmark'])) {
                // $errors['landmark'] = "Landmark is required";
                // return $this->helper_controller->send_response('failed', $errors['landmark'], 422);
                // $errorCount++;

                if (!preg_match("/^[a-zA-Z0-9\s,.\-&]+$/", $inputs['landmark'])) {
                    $errors['landmark'] = "Landmark can only contain letters, numbers, spaces, commas, and periods";
                    return $this->helper_controller->send_response('failed', $errors['landmark'], 422);
                    $errorCount++;
                }
            }
            

            if (!isset($inputs['city']) || empty($inputs['city'])) {
                $errors['city'] = "City is required";
                return $this->helper_controller->send_response('failed', $errors['city'], 422);
                $errorCount++;
            } elseif (!preg_match("/^[a-zA-Z\s]+$/", $inputs['city'])) {
                $errors['city'] = "City can only contain letters and spaces";
                return $this->helper_controller->send_response('failed', $errors['city'], 422);
                $errorCount++;
            }

            if (!isset($inputs['pincode']) || empty($inputs['pincode'])) {
                $errors['pincode'] = "Pincode is required";
                return $this->helper_controller->send_response('failed', $errors['pincode'], 422);
                $errorCount++;
            } elseif (!preg_match("/^\d{6}$/", $inputs['pincode'])) {
                $errors['pincode'] = "Pincode must be a 6-digit number";
                return $this->helper_controller->send_response('failed', $errors['pincode'], 422);
                $errorCount++;
            }

            if (!isset($inputs['address_line_1']) || empty($inputs['address_line_1'])) {
                $errors['address_line_1'] = "Address Line 1 is required";
                return $this->helper_controller->send_response('failed', $errors['address_line_1'], 422);
                $errorCount++;
            } elseif (!preg_match("/^[a-zA-Z0-9\s,.\-&]+$/", $inputs['address_line_1'])) {
                $errors['address_line_1'] = "Address Line 1 can only contain letters, numbers, spaces, commas, and periods";
                return $this->helper_controller->send_response('failed', $errors['address_line_1'], 422);
                $errorCount++;
            }

            if (isset($inputs['address_line_2']) && !empty($inputs['address_line_2'])) {
                if (!preg_match("/^[a-zA-Z0-9\s,.\-&]+$/", $inputs['address_line_2'])) {
                    $errors['address_line_2'] = "Address Line 2 can only contain letters, numbers, spaces, commas, and periods";
                    return $this->helper_controller->send_response('failed', $errors['address_line_2'], 422);
                    $errorCount++;
                }
            }

            $rules = [
                'beneficiary_name' => ['nullable', 'regex:/^[a-zA-Z\s]+$/', 'max:255'], // Allow alphabets and spaces only
                'account_number' => 'nullable|integer', // Account number should be integer
                'ifsc_code' => ['nullable', 'regex:/^[a-zA-Z0-9]+$/', 'max:25'], // Alphanumeric for IFSC code
                'micr_code' => 'nullable|regex:/^\d{9}$/', // MICR code should be numeric (9 digits)

                'onboarding_fees' => 'nullable|numeric|regex:/^\d+(\.\d{1,2})?$/',
                'payment_method' => 'nullable|integer|exists:payment_methods.id',
                'payment_proof_img' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',

                'trade_certificate' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'dealer_loi' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
            
                'dealer_image_1' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                
                'security_cheque_1' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'security_cheque_2' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'security_cheque_3' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'security_cheque_4' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
            
                'showroom_img_1' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                
                'pan' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'gst_certificate' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'utility_bill' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                
                'itr_file' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'bank_statement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'rent_or_lease_agreement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'dealer_finayo_agreement' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'lat_long_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
                'latitude' => [
                    'nullable',
                    'regex:/^-?\d{1,2}\.\d{1,8}$/', // Allows up to 8 decimal places
                    'between:-90,90'
                ],
                'longitude' => [
                    'nullable',
                    'regex:/^-?\d{1,3}\.\d{1,8}$/', // Allows up to 8 decimal places
                    'between:-180,180'
                ],
                
                // 'dealer_image_2' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'showroom_img_2' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'showroom_img_3' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'showroom_img_4' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'icat_certificate' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'manufacturing_license' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',

                'state' => 'required|integer|exists:state_masters,id',
                'oem_id' => 'nullable|integer|exists:manufacturers,id',
                'lender_id' => 'nullable|integer|exists:lenders,id',
            ];
            
            // Conditional validation based on company type
            if ($inputs['company_type'] == 'PARTNERSHIP') {
                $rules = array_merge($rules, [
                    // 'aadhar' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                    'partnership_deed' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                ]);
            } elseif ($inputs['company_type'] == "PRIVATE LIMITED") {
                $rules = array_merge($rules, [
                    // 'moa_and_aoa' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                    // 'factory_address_proof' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                    'moa' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                    'aoa' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                    'certificate_of_incorporation' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                    'balance_sheet' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                ]);
            }
            
            $validator = Validator::make($inputs, $rules);
            
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
            
            if(!strtotime($inputs['dob'])) {
                $inputs['dob'] = null;
            }

            $dealer_create = new Dealer();
            $dealer_create->name = $inputs['name'] ?? '';
            $dealer_create->dob = $inputs['dob'] ?? null;
            $dealer_create->mobile = $inputs['mobile'] ?? '';
            $dealer_create->alternative_number = $inputs['alternative_number'] ?? '';
            $dealer_create->email = $inputs['email'] ?? '';
            $dealer_create->pan = $inputs['pan_number'] ?? '';
            $dealer_create->aadhar = $inputs['aadhar_number'] ?? '';
            $dealer_create->landmark = $inputs['landmark'] ?? '';
            $dealer_create->city = $inputs['city'] ?? '';
            $dealer_create->pincode = $inputs['pincode'] ?? '';
            $dealer_create->address_line_1 = $inputs['address_line_1'] ?? '';
            $dealer_create->address_line_2 = $inputs['address_line_2'] ?? '';
            $dealer_create->dealer_type = $inputs['dealer_type'] ?? '';
            $dealer_create->onboarding_fees = $inputs['onboarding_fees'] ?? null;
            $dealer_create->payment_method = $inputs['payment_method'] ?? null;
            $dealer_create->longitude = $inputs['longitude'] ?? null;
            $dealer_create->latitude = $inputs['latitude'] ?? null;
            $dealer_create->state = $inputs['state'] ?? null;
            $dealer_create->oem_id = $inputs['oem_id'] ?? null;
            $dealer_create->lender_id = $inputs['lender_id'] ?? null;

            if($dealer_create->save()) {
                $department = UserDepartments::where('department', 'Dealer')->first();
                $department_id = isset($department) ? $department->id : null;
                
                $user_types = UserType::where('user_type', 'Dealer')->first();
                $user_types_id = isset($user_types) ? $user_types->id : null;

                $adminUser = AdminUser::create([
                    'name' => $inputs['name'],
                    'mobile' => $inputs['mobile'],
                    'email' => $inputs['email'],
                    'password' => Hash::make($inputs['password']),
                    'department' => $department_id,
                    'user_type' => $user_types_id,
                ]);

                $dealer_unq = 'FIN-DEA-' . $dealer_create->id;
                $dealer_create->dealer_unq = $dealer_unq;
                $dealer_create->dealer_auth_id = $adminUser->id;
                $dealer_create->save();

                /**
                 * Beneficiary details Part start
                 * this part added on 17-12-2024
                 */

                if(isset($inputs['beneficiary_name']) && isset($inputs['account_number']) && isset($inputs['ifsc_code'])) {
                    $beneficiary = BeneficiaryDetails::where('user_type', 'DEALER')->where('user_id', $dealer_create->id)->first();
                    if(!$beneficiary) {
                        $beneficiary                = new BeneficiaryDetails();
                    }
                    $beneficiary->user_type         = 'DEALER';
                    $beneficiary->user_id           = $dealer_create->id;
                    $beneficiary->beneficiary_name  = $inputs['beneficiary_name'];
                    $beneficiary->account_number    = $inputs['account_number'];
                    $beneficiary->ifsc_code         = $inputs['ifsc_code'];
                    $beneficiary->micr_code         = $inputs['micr_code'];
                    $beneficiary->status            = 'active';
                    $beneficiary->action_taken_by   = auth()->id(); // Logged-in user ID
                    $beneficiary->ip                = $this->helper_controller->get_user_ip();
                    $beneficiary->save();
                    // Beneficiary Part ends
                }

                $base_dir = 'Dealers/' . $dealer_unq;
                $file_mappings = [
                    'trade_certificate',
                    'dealer_loi',
                    'dealer_image_1',
                    'security_cheque_1',
                    'security_cheque_2',
                    'security_cheque_3',
                    'security_cheque_4',
                    'showroom_img_1',
                    'gst_certificate',
                    'dealer_finayo_agreement',
                    'payment_proof_img',
                    'lat_long_image',
                ];
            
                $filePaths = []; // Array to store file paths

                foreach ($file_mappings as $fileKey) {
                    if (isset($inputs[$fileKey]) && $inputs[$fileKey]->isValid()) {
                        $filename = $fileKey . '.' . $inputs[$fileKey]->getClientOriginalExtension();
                        $path = $inputs[$fileKey]->storeAs($base_dir, $filename, 'public');
                        $filePaths[$fileKey] = 'storage/' . $path;

                        // Save the file path in the Dealer model
                        $dealer_create->$fileKey = $filePaths[$fileKey];
                    }
                }
            
                // Save the dealer record again with the file paths
                $dealer_create->save();
            
                $dealer_company_documents = new DealerCompanyDocuments();
                $dealer_company_documents->dealer_id =  $dealer_create->id;
                $dealer_company_documents->company_type =  $inputs['company_type'];
                
                $base_dir = 'Dealers/' . $dealer_create->dealer_unq . '/company_documents';
                // Initialize an array to map document fields to the corresponding file inputs
                $document_mappings = [];

                if ($inputs['company_type'] == 'PARTNERSHIP' || $inputs['company_type'] == "PROPRIETORSHIP") {
                    $document_mappings = [
                        'pan',
                        'utility_bill',
                        'itr_file',
                        'bank_statement',
                        'rent_or_lease_agreement',
                        'partnership_deed' => 'partnership_deed',
                    ];
                } elseif ($inputs['company_type'] == 'PRIVATE LIMITED') {
                    $document_mappings = [
                        'pan',
                        'utility_bill',
                        'itr_file',
                        'bank_statement',
                        'rent_or_lease_agreement',
                        'balance_sheet' => 'balance_sheet',
                        'aoa' => 'aoa',
                        'moa' => 'moa',
                        'certificate_of_incorporation' => 'certificate_of_incorporation',
                        'factory_address_proof' => 'factory_address_proof',
                    ];
                }

                // Loop through each document field and store the files
                foreach ($document_mappings as $field => $fileKey) {
                    if (isset($inputs[$fileKey]) && $inputs[$fileKey]->isValid()) {
                        $filename = $fileKey . '.' . $inputs[$fileKey]->getClientOriginalExtension();
                        $path = $inputs[$fileKey]->storeAs($base_dir, $filename, 'public');
                        $dealer_company_documents->$fileKey = 'storage/' . $path;
                    }
                }
                $dealer_company_documents->aadhar = '';
                $dealer_company_documents->save();

                if($request->latitude != '' && $request->longitude != '') {
                    $dealer_shop = DealerShops::where('dealer_id', $dealer_create->id)
                                    ->where('longitude', $request->longitude)
                                    ->where('latitude', $request->latitude)
                                    ->first();
                    if(!$dealer_shop) {
                        $dealer_shop = new DealerShops();
                    }

                    $storagePath = '';
                    if ($request->lat_long_image != '') {
                        $path = $request->file('lat_long_image')->storeAs('Dealers/' . $dealer_unq . '/shops', $request->file('lat_long_image')->getClientOriginalName(), 'public');
                        $storagePath = 'storage/' . $path;
                    }

                    $dealer_shop->dealer_id = $dealer_create->id;
                    $dealer_shop->shop_name = $inputs['name'];
                    $dealer_shop->longitude = $request->longitude;
                    $dealer_shop->latitude = $request->latitude;
                    $dealer_shop->lat_long_image = $storagePath;
                    $dealer_shop->status = 'active';
                    $dealer_shop->modified_by = auth()->id();
                    $dealer_shop->address = trim($inputs['address_line_1'] . $inputs['address_line_2']);
                    $dealer_shop->ip = $this->helper_controller->get_user_ip();
                    $dealer_shop->save();
                }
                
                $existsLenderBorrowerGroup = LenderDealerGroup::where('lender_id', $request->lender_id)
                    ->where('dealer_id', $dealer_create->id)
                    ->exists();

                if (!$existsLenderBorrowerGroup) {
                    $group              = new LenderDealerGroup();
                    $group->lender_id   = $request->lender_id;
                    $group->dealer_id   = $dealer_create->id;
                    $group->status      = 'active';
                    $group->modified_by = auth()->id();
                    $group->ip          = $this->helper_controller->get_user_ip();
                    $group->save();
                }

                $emailData = [
                    'name' => $request->name,
                    'admin_url' => 'http://uat.finayo.tech',
                    'user_id' => $request->email,
                    'password' => $request->password
                ];
                $viewName = 'emails.example';
                $subject = "Finayo Login Credential";
            
                Mail::to($request->email)->send(new Mailer($viewName, $emailData, $subject));
    
                DB::commit(); // Commit the transaction

                return $this->helper_controller->send_response('success', 'Dealer created successfully', 201, $dealer_create);
            } else {

                DB::rollBack(); // Rollback the transaction
                return $this->helper_controller->send_response('success', 'Failed to create dealer', 422);
            }
        } catch (Exception $e) {
            DB::rollBack(); // Rollback the transaction
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function update_dealer(Request $request) {
        DB::beginTransaction();
        try {
    
            $inputs = $request->all();
            date_default_timezone_set('Asia/Kolkata');
            if(!isset($inputs['id']) || empty($inputs['id'])) {
                return $this->helper_controller->send_response('failed', "Id is required", 422);
            }
            $id = $inputs['id'];
            // Validate inputs
            $validator = Validator::make($inputs, [
                'id' => 'required|integer',
                'name' => 'required|regex:/^[a-zA-Z\s]+$/',
                'mobile' => 'required|digits:10|unique:dealers,mobile,' . $id,
                'alternative_number' => 'nullable|digits:10|different:mobile|unique:dealers,alternative_number,' . $id,
                'email' => 'required|email|unique:dealers,email,' . $id,
                'password' => 'nullable|string|min:8',
                'pan_number' => 'required|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|unique:dealers,pan,' . $id,
                'aadhar_number' => 'nullable|digits:12',
                'landmark' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
                'company_type' => 'required|in:PARTNERSHIP,PROPRIETORSHIP,PRIVATE LIMITED',
                'dealer_type' => 'required|in:B2B,B2C',
                'city' => 'required|regex:/^[a-zA-Z\s]+$/',
                'pincode' => 'required|digits:6',
                'address_line_1' => 'required|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
                'address_line_2' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',

                'beneficiary_name' => ['nullable', 'regex:/^[a-zA-Z\s]+$/', 'max:255'], // Allow alphabets and spaces only
                'account_number' => 'nullable|integer', // Account number should be integer
                'ifsc_code' => ['nullable', 'regex:/^[a-zA-Z0-9]+$/', 'max:25'], // Alphanumeric for IFSC code
                'micr_code' => 'nullable|regex:/^\d{9}$/', // MICR code should be numeric (9 digits)
                
                'onboarding_fees' => 'nullable|numeric|regex:/^\d+(\.\d{1,2})?$/',
                'payment_method' => 'nullable|integer|exists:payment_methods,id',
                'payment_proof_img' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',

                'trade_certificate' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'dealer_loi' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
            
                'dealer_image_1' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                
                'security_cheque_1' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'security_cheque_2' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'security_cheque_3' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'security_cheque_4' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
            
                'showroom_img_1' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',

                'dealer_finayo_agreement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'pan' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'itr_file' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'bank_statement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'gst_certificate' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'rent_or_lease_agreement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'utility_bill' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'moa' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'aoa' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'certificate_of_incorporation' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'balance_sheet' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'partnership_deed' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                
                'lat_long_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
                'latitude' => [
                    'nullable',
                    'regex:/^-?\d{1,2}\.\d{1,8}$/', // Allows up to 8 decimal places
                    'between:-90,90'
                ],
                'longitude' => [
                    'nullable',
                    'regex:/^-?\d{1,3}\.\d{1,8}$/', // Allows up to 8 decimal places
                    'between:-180,180'
                ],
                // 'icat_certificate' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'manufacturing_license' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',

                'state' => 'required|integer|exists:state_masters,id',
                'oem_id' => 'nullable|integer|exists:manufacturers,id',
                'lender_id' => 'nullable|integer|exists:lenders,id',
            ]);
        
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            if(!strtotime($inputs['dob'])) {
                $inputs['dob'] = null;
            } else {
                $date = DateTime::createFromFormat('Y-m-d', $inputs['dob']);
                if (!$date || $date->format('Y-m-d') !== $inputs['dob']) {
                    $errors['dob'] = "Date of incorporation must be in the format YYYY-MM-DD";
                    $errorCount++;
                    return $this->helper_controller->send_response('failed', $errors['dob'], 422);
                } elseif ($date > new DateTime()) {
                    $errors['dob'] = "Date of incorporation cannot be a future date";
                    return $this->helper_controller->send_response('failed', $errors['dob'], 422);
                    $errorCount++;
                }
            }
        
            // Find existing dealer
            $dealer = Dealer::find($id);
        
            if (!$dealer) {
                return $this->helper_controller->send_response('failed', 'Dealer not found', 404);
            }

            $emailExists = AdminUser::where('id', '<>', $dealer->dealer_auth_id)
                            ->where('email', $request->email)
                            ->exists();

            if ($emailExists) {
                return $this->helper_controller->send_response('failed', 'User already exists with this email', 404);
            }
            
            $mobileExists = AdminUser::where('id', '<>', $dealer->dealer_auth_id)
                            ->where('mobile', $request->mobile)
                            ->exists();

            if ($mobileExists) {
                return $this->helper_controller->send_response('failed', 'User already exists with this mobile number', 404);
            }
        
            // Update dealer details
            $dealer->name = $inputs['name'] ?? '';
            $dealer->dob = $inputs['dob'] ?? null;
            $dealer->mobile = $inputs['mobile'] ?? '';
            $dealer->alternative_number = $inputs['alternative_number'] ?? $dealer->alternative_number;
            $dealer->email = $inputs['email'] ?? '';
            $dealer->pan = $inputs['pan_number'] ?? '';
            $dealer->aadhar = $inputs['aadhar_number'] ?? $dealer->aadhar;
            $dealer->landmark = $inputs['landmark'] ?? '';
            $dealer->city = $inputs['city'] ?? '';
            $dealer->pincode = $inputs['pincode'] ?? '';
            $dealer->address_line_1 = $inputs['address_line_1'] ?? '';
            $dealer->address_line_2 = $inputs['address_line_2'] ?? '';
            $dealer->dealer_type = $inputs['dealer_type'] ?? '';
            $dealer->onboarding_fees = $inputs['onboarding_fees'] ?? $dealer->onboarding_fees;
            $dealer->payment_method = $inputs['payment_method'] ?? $dealer->payment_method;
            $dealer->longitude = $inputs['longitude'] ?? $dealer->longitude;
            $dealer->latitude = $inputs['latitude'] ?? $dealer->latitude;
            $dealer->state = $inputs['state'] ?? $dealer->state;
            $dealer->oem_id = $inputs['oem_id'] ?? $dealer->oem_id;
            $dealer->lender_id = $inputs['lender_id'] ?? $dealer->lender_id;
            $dealer->save();

            $adminUser = AdminUser::find($dealer->dealer_auth_id);
            if($adminUser) {
                $adminUser->name = $inputs['name'];
                $adminUser->mobile = $inputs['mobile'];
                $adminUser->email = $inputs['email'];
                $adminUser->save();
            } else{
                $department = UserDepartments::where('department', 'Dealer')->first();
                $department_id = isset($department) ? $department->id : null;
                
                $user_types = UserType::where('user_type', 'Dealer')->first();
                $user_types_id = isset($user_types) ? $user_types->id : null;

                $adminUser = AdminUser::create([
                    'name' => $inputs['name'],
                    'mobile' => $inputs['mobile'],
                    'email' => $inputs['email'],
                    'password' => isset($inputs['password']) ? Hash::make($inputs['password']) : '',
                    'department' => $department_id,
                    'user_type' => $user_types_id,
                ]);
                $dealer->dealer_auth_id = $adminUser->id;
            }

            /**
             * Beneficiary details Part start
             * this part added on 17-12-2024
             */
            if(isset($inputs['beneficiary_name']) && isset($inputs['account_number']) && isset($inputs['ifsc_code'])) {
                $beneficiary = BeneficiaryDetails::where('user_type', 'DEALER')->where('user_id', $dealer->id)->first();
                if(!$beneficiary) {
                    $beneficiary                = new BeneficiaryDetails();
                }
                $beneficiary->user_type         = 'DEALER';
                $beneficiary->user_id           = $dealer->id;
                $beneficiary->beneficiary_name  = $inputs['beneficiary_name'];
                $beneficiary->account_number    = $inputs['account_number'];
                $beneficiary->ifsc_code         = $inputs['ifsc_code'];
                $beneficiary->micr_code         = $inputs['micr_code'];
                $beneficiary->status            = 'active';
                $beneficiary->action_taken_by   = auth()->id(); // Logged-in user ID
                $beneficiary->ip                = $this->helper_controller->get_user_ip();
                $beneficiary->save();
            }
            // Beneficiary Part ends
        
            $dealer_unq = 'FIN-DEA-' . $dealer->id;
        
            $base_dir = 'Dealers/' . $dealer_unq;
            $file_mappings = [
                'trade_certificate',
                'dealer_loi',
                'dealer_image_1',
                'security_cheque_1',
                'security_cheque_2',
                'security_cheque_3',
                'security_cheque_4',
                'showroom_img_1',
                'gst_certificate',
                'dealer_finayo_agreement',
                'payment_proof_img',
                'lat_long_image',
            ];
        
            // Update file fields if new files are provided
            foreach ($file_mappings as $fileKey) {
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    if ($file->isValid()) {
                        $filename = $fileKey . '.' . $file->getClientOriginalExtension();
                        $path = $file->storeAs($base_dir, $filename, 'public');
                        $dealer->$fileKey = 'storage/' . $path;
                    }
                }   else {
                    if (isset($dealer->$fileKey)) {
                        $dealer->$fileKey = $dealer->$fileKey;
                    }
                }
            }
        
            $dealer->save();
        
            // Handle company-specific documents
            $dealer_company_documents = DealerCompanyDocuments::where('dealer_id', $dealer->id)->first();
        
            if (!$dealer_company_documents) {
                $dealer_company_documents = new DealerCompanyDocuments();
                $dealer_company_documents->dealer_id = $dealer->id;
            }
        
            $company_documents_dir = 'Dealers/' . $dealer_unq . '/company_documents';
            $document_mappings = [];
        
            if ($inputs['company_type'] == 'PARTNERSHIP' || $inputs['company_type'] == 'PROPRIETORSHIP') {
                $document_mappings = [
                    'pan',
                    'utility_bill',
                    'itr_file',
                    'bank_statement',
                    'rent_or_lease_agreement',
                    'partnership_deed' => 'partnership_deed',
                ];
            } elseif ($inputs['company_type'] == 'PRIVATE LIMITED') {
                $document_mappings = [
                    'pan',
                    'utility_bill',
                    'itr_file',
                    'bank_statement',
                    'rent_or_lease_agreement',
                    'balance_sheet' => 'balance_sheet',
                    'aoa' => 'aoa',
                    'moa' => 'moa',
                    'certificate_of_incorporation' => 'certificate_of_incorporation',
                    'factory_address_proof' => 'factory_address_proof',
                ];
            } else {
                return $this->helper_controller->send_response('failed', "Invalid company type. Valid types are [PARTNERSHIP, PROPRIETORSHIP, PRIVATE LIMITED]", 422);
            }
        
            foreach ($document_mappings as $field => $fileKey) {
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    if ($file->isValid()) {
                        $filename = $fileKey . '.' . $file->getClientOriginalExtension();
                        $path = $file->storeAs($company_documents_dir, $filename, 'public');
                        $dealer_company_documents->$field = 'storage/' . $path;
                    }
                } else {
                    // If no new file is uploaded, retain the old file
                    if (isset($dealer_company_documents->$field)) {
                        $dealer_company_documents->$field = $dealer_company_documents->$field;
                    }
                }
            }
            $dealer_company_documents->aadhar = '';
        
            $dealer_company_documents->save();

            if($request->latitude != '' && $request->longitude != '') {
                $dealer_shop = DealerShops::where('dealer_id', $dealer->id)
                                ->where('longitude', $request->longitude)
                                ->where('latitude', $request->latitude)
                                ->first();
                if(!$dealer_shop) {
                    $dealer_shop = new DealerShops();
                }

                $storagePath = ($dealer->lat_long_image != '') ? $dealer->lat_long_image : '';
                if ($request->lat_long_image != '') {
                    $path = $request->file('lat_long_image')->storeAs('Dealers/' . $dealer->dealer_unq . '/shops', $request->file('lat_long_image')->getClientOriginalName(), 'public');
                    $storagePath = 'storage/' . $path;
                }

                $dealer_shop->dealer_id = $dealer->id;
                $dealer_shop->shop_name = $inputs['name'];
                $dealer_shop->longitude = $request->longitude;
                $dealer_shop->latitude = $request->latitude;
                $dealer_shop->lat_long_image = $storagePath;
                $dealer_shop->status = 'active';
                $dealer_shop->modified_by = auth()->id();
                $dealer_shop->address = trim($inputs['address_line_1'] . $inputs['address_line_2']);
                $dealer_shop->ip = $this->helper_controller->get_user_ip();
                $dealer_shop->save();
            }

            $existsLenderBorrowerGroup = LenderDealerGroup::where('lender_id', $request->lender_id)
                ->where('dealer_id', $dealer->id)
                ->exists();

            if (!$existsLenderBorrowerGroup) {
                $group              = new LenderDealerGroup();
                $group->lender_id   = $request->lender_id;
                $group->dealer_id   = $dealer->id;
                $group->status      = 'active';
                $group->modified_by = auth()->id();
                $group->ip          = $this->helper_controller->get_user_ip();
                $group->save();
            }
        
            DB::commit();
            return $this->helper_controller->send_response('success', 'Dealer record updated successfully', 200, $dealer);
        } catch (Exception $e) {

            DB::rollBack(); // Rollback the transaction
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function store_lender_dealer_charges(Request $request)
    {
        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'lender_id' => 'required|integer',
                'dealer_id' => 'required|integer',
                'charge_details' => 'required|array',
                'charge_details.*.charge_head' => 'required|string|max:255',
                'charge_details.*.charge_type' => 'nullable|string|max:100',
                'charge_details.*.charge_value' => 'nullable|integer',
                'token_id' => 'nullable|string|max:100' // Optional if updating
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $validated = $validator->validated();
            $userId = auth()->user()->id; // Assuming you have authentication
            $ip = $this->helper_controller->get_user_ip();

            if (!empty($validated['token_id'])) {
                // Update existing records with the provided token_id to 'HISTORY'
                LenderDealerProductCharges::where('token_id', $validated['token_id'])
                    ->update(['status' => 'HISTORY']);
                
                // Generate a new token in the format "dmY.primary_key"
                $tokenId = date('dmY') . (LenderDealerProductCharges::max('id') + 1);
            } else {
                // Generate a unique token for new records
                $tokenId = date('dmY') . (LenderDealerProductCharges::max('id') + 1);
            }

            // Create new records
            foreach ($validated['charge_details'] as $charge) {
                LenderDealerProductCharges::create([
                    'token_id' => $tokenId,
                    'lender_id' => $validated['lender_id'],
                    'dealer_id' => $validated['dealer_id'],
                    'charge_head' => $charge['charge_head'],
                    'charge_type' => $charge['charge_type'] ?? '',
                    'charge_value' => $charge['charge_value'] ?? 0,
                    'status' => 'ACTIVE',
                    'ip' => $ip,
                    'user_id' => $userId,
                ]);
            }

            return $this->helper_controller->send_response('success', 'Records processed successfully', 201);

        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }

    // Lender Dealer Charges
    public function getActiveChargesByDealer($dealer_id)
    {
        try {
            // Fetch active charges by dealer_id
            $charges = LenderDealerProductCharges::where('dealer_id', $dealer_id)
                ->where('status', 'ACTIVE')
                ->with([
                    'lender:id,name',
                    'dealer:id,name'
                ])
                ->get();

            if ($charges->isEmpty()) {
                return $this->helper_controller->send_response('failed', 'No active charges found for this dealer.', 200);
            }

            return $this->helper_controller->send_response('success', "Charges fetched successfully", 200, $charges);

        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function update_status($id)
    {
        DB::beginTransaction();
        try {
            $dealer = Dealer::find($id);
            if(!$dealer) {
                return $this->helper_controller->send_response('failed', "Dealer not found", 422);
            }

            if($dealer->is_active == true) {
                $status = false;
            } else {
                $status = true;
            }
            $dealer->is_active = $status;
            $dealer->save();

            $userDetails = AdminUser::find($dealer->dealer_auth_id);
            if($userDetails) {
                $userDetails->is_active = $status;
                $userDetails->save();
            }
            
            DB::commit(); // Commit the transaction
            return $this->helper_controller->send_response('success', 'User status updated', 200);
        } catch (Exception $e) {
            DB::rollBack(); // Rollback the transaction
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }
}