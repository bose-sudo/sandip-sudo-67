<?php

namespace App\Http\Controllers;

use App\Mail\Mailer;
use App\Models\AdminUser;
use App\Models\BeneficiaryDetails;
use App\Models\Dealer;
use App\Models\DisbursementDetails;
use App\Models\LenderServiceProvider;
use App\Models\LoanApplicationSummary;
use App\Models\LoanBasicDetails;
use App\Models\LspExecutiveDetails;
use App\Models\Manufacturers;
use App\Models\OemProducts;
use App\Models\Oems;
use App\Models\ProductModel;
use App\Models\UserDepartments;
use App\Models\UserType;
use Carbon\Carbon;
use DateTime;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class OemController extends Controller
{
    private $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function index(Request $request)
    {
        DB::beginTransaction();
        try {
            // Validate filter inputs and pagination inputs
            $validator = Validator::make($request->all(), [
                'name' => 'nullable|string|regex:/^[a-zA-Z\s]+$/',
                'mobile' => 'nullable|digits:10',
                'alternate_mobile_number' => 'nullable|digits:10|different:mobile',
                'email' => 'nullable|email',
                'pan' => 'nullable|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',
                'page' => 'nullable|integer|min:1',
                'number_of_records' => 'nullable|integer|min:1|max:100'
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            // Set default pagination values
            $page = $request->get('page', 1);
            $number_of_records = $request->get('number_of_records', 10); // Default is 10 records per page

            // Start query
            $query = Manufacturers::with(['productSpecification', 'state:id,state'])->where('is_deleted', false);

            // Apply filters if they exist
            if ($request->filled('name')) {
                // Case-insensitive filter for name
                $query->whereRaw('LOWER(manufacturer) LIKE ?', ['%' . strtolower($request->name) . '%']);
            }

            if ($request->filled('mobile')) {
                $query->where('mobile_number', $request->mobile);
            }

            if ($request->filled('alternate_mobile_number')) {
                $query->where('alternate_mobile_number', $request->alternate_mobile_number);
            }

            if ($request->filled('email')) {
                // Case-insensitive filter for email
                $query->whereRaw('LOWER(email) LIKE ?', ['%' . strtolower($request->email) . '%']);
            }

            if ($request->filled('pan')) {
                $query->where('pan_number', 'like', '%' . $request->pan . '%');
            }

            // Paginate results
            $oems = $query->with(['beneficiaryDetails' => function ($query) {
                            $query->where('user_type', 'OEM');
                        }])
                        ->orderBy('id', 'desc')
                        ->paginate($number_of_records, ['*'], 'page', $page);
            $oems->getCollection()->transform(function ($oem) {
                $beneficiaryDetails = $oem->beneficiaryDetails;

                // Combine OEM attributes with beneficiary details, excluding the relationship object
                $data = array_merge(
                    $oem->toArray(),
                    [
                        'beneficiary_name' => $beneficiaryDetails ? $beneficiaryDetails->beneficiary_name : '',
                        'account_number'   => $beneficiaryDetails ? $beneficiaryDetails->account_number : '',
                        'ifsc_code'        => $beneficiaryDetails ? $beneficiaryDetails->ifsc_code : '',
                        'micr_code'        => $beneficiaryDetails ? $beneficiaryDetails->micr_code : '',
                    ]
                );

                // Remove the beneficiaryDetails object
                unset($data['beneficiary_details']);

                return $data;
            });

            DB::commit();

            // Send response with paginated results
            return $this->helper_controller->send_response('success', 'OEM list fetched successfully', 200, $oems);
        } catch (Exception $e) {
            DB::rollBack(); // Rollback in case of any error
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 500);
        }
    }

    public function oem_screener() {
        $oems = Manufacturers::select(['id', 'manufacturer'])->where('is_deleted', false)->orderBy('manufacturer', 'asc')->get();
        return $this->helper_controller->send_response('success', 'OEM list fetched successfully', 200, $oems);
    }

    public function read($id)
    {
        try { 
            $result = [];
            $oem = Manufacturers::with(['state:id,state'])->find($id);
            if (!$oem) {
                return $this->helper_controller->send_response('failed', 'OEM not found', 422);
            }

            $beneficiary_details = BeneficiaryDetails::where('user_type', 'OEM')->where('user_id', $id)->first();

            // Convert OEM object to array
            $oem = $oem->toArray();

            $result = [
                'id'                    => $oem['id'],
                'oem_unq'               => $oem['oem_unq'] ?? '',
                'manufacturer'          => $oem['manufacturer'] ?? '',
                'company_type'          => $oem['company_type'] ?? '',
                'date_of_incorporation' => $oem['date_of_incorporation'] ?? '',
                'mobile'                => $oem['mobile_number'] ?? '',
                'alternate_mobile_number' => $oem['alternate_mobile_number'] ?? '',
                'email'                 => $oem['email'] ?? '',
                'pan_number'            => $oem['pan_number'] ?? '',
                'address_line_1'        => $oem['address_line_1'] ?? '',
                'address_line_2'        => $oem['address_line_2'] ?? '',
                'city'                  => $oem['city'] ?? '',
                'pin'               => $oem['pin'] ?? '',
                'landmark'              => $oem['landmark'] ?? '',
                'pan_doc'               => $oem['pan_doc'] ?? '',
                'oem_finayo_agreement'  => $oem['oem_finayo_agreement'] ?? '',
                'bank_statement'        => $oem['bank_statement'] ?? '',
                'cheque_1'              => $oem['cheque_1'] ?? '',
                'cheque_2'              => $oem['cheque_2'] ?? '',
                'cheque_3'              => $oem['cheque_3'] ?? '',
                'cheque_4'              => $oem['cheque_4'] ?? '',
                'gst_certificate'       => $oem['gst_certificate'] ?? '',
                'rent_agreement'        => $oem['rent_agreement'] ?? '',
                'office_image'          => $oem['office_image'] ?? '',
                'itr_file'              => $oem['itr_file'] ?? '',
                'utility_bill'          => $oem['utility_bill'] ?? '',
                'icat_certificate'      => $oem['icat_certificate'] ?? '',
                'certificate_of_incorporation' => $oem['certificate_of_incorporation'] ?? '',
                'partnership_deed'      => $oem['partnership_deed'] ?? '',
                'balance_sheet'         => $oem['balance_sheet'] ?? '',
                'aoa'                   => $oem['aoa'] ?? '',
                'moa'                   => $oem['moa'] ?? '',
                'is_active'             => $oem['is_active'] ?? '',
                'created_by'            => $oem['created_by'] ?? '',
                'created_at'            => $oem['created_at'] ?? '',
                'state_id'                 => $oem['state']['id'] ?? '',
                'state'                 => $oem['state']['state'] ?? '',
                'beneficiary_name'      => $beneficiary_details ? $beneficiary_details->beneficiary_name : '',
                'account_number'        => $beneficiary_details ? $beneficiary_details->account_number : '',
                'ifsc_code'             => $beneficiary_details ? $beneficiary_details->ifsc_code : '',
                'micr_code'             => $beneficiary_details ? $beneficiary_details->micr_code : '',
            ];

            return $this->helper_controller->send_response('success', 'OEM fetched successfully', 200, $result);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }

    public function store_oem(Request $request) {

        DB::beginTransaction();
        try {
            $inputs = $request->all();
            date_default_timezone_set('Asia/Kolkata');

            if(!isset($inputs['company_type']) || empty($inputs['company_type'])) {
                $errors['company_type'] = "Company type is required";
                return $this->helper_controller->send_response('failed', $errors['company_type'], 422);
                $errorCount++;
            } elseif(!in_array($inputs['company_type'], ['PARTNERSHIP', 'PROPRIETORSHIP', 'PRIVATE LIMITED'])) {
                $errors['company_type'] = "Invalid company type. Valid types are [PARTNERSHIP, PROPRIETORSHIP, PRIVATE LIMITED]";
                return $this->helper_controller->send_response('failed', $errors['company_type'], 422);
                $errorCount++;
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

                if (!isset($inputs['date_of_incorporation']) || empty($inputs['date_of_incorporation'])) {
                    $errors['date_of_incorporation'] = "Date of incorporation is required";
                    return $this->helper_controller->send_response('failed', $errors['date_of_incorporation'], 422);
                    $errorCount++;
                } else{ 
                    $date = DateTime::createFromFormat('Y-m-d', $inputs['date_of_incorporation']);
                    if (!$date || $date->format('Y-m-d') !== $inputs['date_of_incorporation']) {
                        $errors['date_of_incorporation'] = "Date of incorporation must be in the format YYYY-MM-DD";
                        $errorCount++;
                        return $this->helper_controller->send_response('failed', $errors['date_of_incorporation'], 422);
                    } elseif ($date > new DateTime()) {
                        $errors['date_of_incorporation'] = "Date of incorporation cannot be a future date";
                        return $this->helper_controller->send_response('failed', $errors['date_of_incorporation'], 422);
                        $errorCount++;
                    }
                }
            } else {
                $inputs['date_of_incorporation'] = null;
            }
            
            if (!isset($inputs['mobile']) || empty($inputs['mobile'])) {
                $errors['mobile'] = "Mobile number is required";
                return $this->helper_controller->send_response('failed', $errors['mobile'], 422);
                $errorCount++;
            } elseif (!preg_match('/^\d{10}$/', $inputs['mobile'])) {
                $errors['mobile'] = "Mobile number must be 10 digits";
                return $this->helper_controller->send_response('failed', $errors['mobile'], 422);
                $errorCount++;
            } elseif (Manufacturers::where('mobile_number', $inputs['mobile'])->exists()) {
                $errors['mobile'] = "Mobile number already exists";
                return $this->helper_controller->send_response('failed', $errors['mobile'], 422);
                $errorCount++;
            } elseif (AdminUser::where('mobile', $inputs['mobile'])->exists()) {
                $errors['mobile'] = "Mobile number already exists";
                return $this->helper_controller->send_response('failed', $errors['mobile'], 422);
                $errorCount++;
            }

            if(isset($inputs['alternate_mobile_number']) && !empty($inputs['alternate_mobile_number'])) {
                if(isset($inputs['mobile']) && !empty($inputs['mobile']) && $inputs['alternate_mobile_number'] == $inputs['mobile']) {
                    $errors['alternate_mobile_number'] = "Mobile number and alternate mobile number can not be same";
                    return $this->helper_controller->send_response('failed', $errors['alternate_mobile_number'], 422);
                    $errorCount++;
                }

                if (!preg_match('/^\d{10}$/', $inputs['alternate_mobile_number'])) {
                    $errors['alternate_mobile_number'] = "Alternate mobile number must be 10 digits";
                    return $this->helper_controller->send_response('failed', $errors['alternate_mobile_number'], 422);
                    $errorCount++;
                } elseif (Manufacturers::where('mobile_number', $inputs['alternate_mobile_number'])
                                ->orWhere('alternate_mobile_number', $inputs['alternate_mobile_number'])
                                ->exists()
                    ) {
                        $errors['alternate_mobile_number'] = "Alternaative mobile number already exists";
                    return $this->helper_controller->send_response('failed',     $errors['alternate_mobile_number'], 422);
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
            } elseif (Manufacturers::where('email', $inputs['email'])->exists()) {
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
            } elseif (Manufacturers::where('pan_number', $inputs['pan_number'])->exists()) {
                $errors['pan_number'] = "PAN already exists";
                return $this->helper_controller->send_response('failed', $errors['pan_number'], 422);
                $errorCount++;
            }

            if (isset($inputs['landmark']) && !empty($inputs['landmark'])) {
                if (!preg_match("/^[a-zA-Z0-9\s,.\-]+$/", $inputs['landmark'])) {
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

            if (!isset($inputs['pin']) || empty($inputs['pin'])) {
                $errors['pin'] = "Pincode is required";
                return $this->helper_controller->send_response('failed', $errors['pin'], 422);
                $errorCount++;
            } elseif (!preg_match("/^\d{6}$/", $inputs['pin'])) {
                $errors['pin'] = "Pincode must be a 6-digit number";
                return $this->helper_controller->send_response('failed', $errors['pin'], 422);
                $errorCount++;
            }

            if (!isset($inputs['address_line_1']) || empty($inputs['address_line_1'])) {
                $errors['address_line_1'] = "Address Line 1 is required";
                return $this->helper_controller->send_response('failed', $errors['address_line_1'], 422);
                $errorCount++;
            } elseif (!preg_match("/^[a-zA-Z0-9\s,.\-]+$/", $inputs['address_line_1'])) {
                $errors['address_line_1'] = "Address Line 1 can only contain letters, numbers, spaces, commas, and periods";
                return $this->helper_controller->send_response('failed', $errors['address_line_1'], 422);
                $errorCount++;
            }

            if (isset($inputs['address_line_2']) && !empty($inputs['address_line_2'])) {
                if (!preg_match("/^[a-zA-Z0-9\s,.\-]+$/", $inputs['address_line_2'])) {
                    $errors['address_line_2'] = "Address Line 2 can only contain letters, numbers, spaces, commas, and periods";
                    return $this->helper_controller->send_response('failed', $errors['address_line_2'], 422);
                    $errorCount++;
                }
            }

            $rules = [
                'beneficiary_name' => ['nullable', 'regex:/^[a-zA-Z\s]+$/', 'max:255'], // Allow alphabets and spaces only
                'account_number' => 'nullable|regex:/^[0-9]+$/', // Account number should be integer
                'ifsc_code' => ['nullable', 'regex:/^[a-zA-Z0-9]+$/', 'max:25'], // Alphanumeric for IFSC code
                'micr_code' => 'nullable|regex:/^\d{9}$/', // MICR code should be numeric (9 digits)

                'cheque_1' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'cheque_2' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'cheque_3' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'cheque_4' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
            
                'office_image' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                
                'pan_doc' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'gst_certificate' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'utility_bill' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'icat_certificate' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                
                'itr_file' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'bank_statement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'rent_agreement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'oem_finayo_agreement' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',

                'state' => 'required|integer|exists:state_masters,id',
            ];

            if ($inputs['company_type'] == 'PARTNERSHIP') {
                $rules = array_merge($rules, [
                    'partnership_deed' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                ]);
            } elseif ($inputs['company_type'] == "PRIVATE LIMITED") {
                $rules = array_merge($rules, [
                    'moa' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                    'aoa' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                    'certificate_of_incorporation' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                ]);
            }
            
            $validator = Validator::make($inputs, $rules);
            
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
            
            $oem_create                     = new Manufacturers();
            $oem_create->manufacturer       = $inputs['name'] ?? '';
            $oem_create->date_of_incorporation = $inputs['date_of_incorporation'] ?? null;
            $oem_create->company_type       = $inputs['company_type'];
            $oem_create->mobile_number      = $inputs['mobile'] ?? '';
            $oem_create->alternate_mobile_number = $inputs['alternate_mobile_number'] ?? '';
            $oem_create->email              = $inputs['email'] ?? '';
            $oem_create->pan_number         = $inputs['pan_number'] ?? '';
            $oem_create->landmark           = $inputs['landmark'] ?? '';
            $oem_create->city               = $inputs['city'] ?? '';
            $oem_create->pin                = $inputs['pin'] ?? '';
            $oem_create->address_line_1     = $inputs['address_line_1'] ?? '';
            $oem_create->address_line_2     = $inputs['address_line_2'] ?? '';
            $oem_create->state              = $inputs['state'] ?? '';
            $oem_create->created_by         = auth()->user()->id;
            $oem_create->ip                 = $this->helper_controller->get_user_ip();
            if($oem_create->save()) {
                $department     = UserDepartments::where('department', 'OEM')->first();
                $department_id  = isset($department) ? $department->id : null;

                $user_types     = UserType::where('user_type', 'OEM')->first();
                $user_types_id  = isset($user_types) ? $user_types->id : null;

                $adminUser = AdminUser::create([
                    'name'          => $inputs['name'],
                    'mobile'        => $inputs['mobile'],
                    'email'         => $inputs['email'],
                    'password'      => Hash::make($inputs['password']),
                    'department'    => $department_id,
                    'user_type'     => $user_types_id,
                    'is_active'     => true
                ]);

                $oem_unq                    = 'FIN-OEM-' . $oem_create->id;
                $oem_create->oem_unq        = $oem_unq;
                $oem_create->oem_auth_id    = $adminUser->id;
                $oem_create->save();

                /**
                 * Beneficiary details Part start
                 * this part added on 17-12-2024
                 */
                if(isset($inputs['beneficiary_name']) && isset($inputs['account_number']) && isset($inputs['ifsc_code'])) {
                    $beneficiary = BeneficiaryDetails::where('user_type', 'OEM')->where('user_id', $oem_create->id)->first();
                    if(!$beneficiary) {
                        $beneficiary                = new BeneficiaryDetails();
                    }
                    $beneficiary->user_type         = 'OEM';
                    $beneficiary->user_id           = $oem_create->id;
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

                $base_dir = 'OEM/' . $oem_unq;
                $file_mappings = [
                    'pan_doc',
                    'oem_finayo_agreement',
                    'bank_statement',
                    'cheque_1',
                    'cheque_2',
                    'cheque_3',
                    'cheque_4',
                    'gst_certificate',
                    'rent_agreement',
                    'office_image',
                    'itr_file',
                    'utility_bill',
                    'icat_certificate',
                ];
            
                $filePaths = []; // Array to store file paths

                foreach ($file_mappings as $fileKey) {
                    if (isset($inputs[$fileKey]) && $inputs[$fileKey]->isValid()) {
                        $filename = $fileKey . '.' . $inputs[$fileKey]->getClientOriginalExtension();
                        $path = $inputs[$fileKey]->storeAs($base_dir, $filename, 'public');
                        $filePaths[$fileKey] = 'storage/' . $path;

                        // Save the file path in the Manufacturer model
                        $oem_create->$fileKey = $filePaths[$fileKey];
                    }
                }

                if ($inputs['company_type'] == 'PARTNERSHIP') {
                    $document_mappings = [
                        'partnership_deed' => 'partnership_deed',
                    ];
                } elseif ($inputs['company_type'] == 'PRIVATE LIMITED') {
                    $document_mappings = [
                        'balance_sheet' => 'balance_sheet',
                        'aoa' => 'aoa',
                        'moa' => 'moa',
                        'certificate_of_incorporation' => 'certificate_of_incorporation',
                    ];
                }

                if(isset($document_mappings)) {
                    foreach ($document_mappings as $fileKey) {
                        if (isset($inputs[$fileKey]) && $inputs[$fileKey]->isValid()) {
                            $filename = $fileKey . '.' . $inputs[$fileKey]->getClientOriginalExtension();
                            $path = $inputs[$fileKey]->storeAs($base_dir, $filename, 'public');
                            $filePaths[$fileKey] = 'storage/' . $path;

                            // Save the file path in the Manufacturer model
                            $oem_create->$fileKey = $filePaths[$fileKey];
                        }
                    }
                }
            
                // Save the OEM record again with the file paths
                $oem_create->save();

                $emailData = [
                    'name'      => $request->name,
                    'admin_url' => 'http://uat.finayo.tech',
                    'user_id'   => $request->email,
                    'password'  => $request->password
                ];

                $viewName   = 'emails.example';
                $subject    = "Finayo Login Credential";
            
                // Mail::to($request->email)->send(new Mailer($viewName, $emailData, $subject));
    
                DB::commit(); // Commit the transaction

                return $this->helper_controller->send_response('success', 'OEM created successfully', 201, $oem_create);
            } else {

                DB::rollBack(); // Rollback the transaction
                return $this->helper_controller->send_response('success', 'Failed to create OEM', 422);
            }
        } catch (Exception $e) {
            DB::rollBack(); // Rollback the transaction
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function update_oem(Request $request) {
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
                'mobile' => 'required|digits:10|unique:manufacturers,mobile_number,' . $id,
                'alternate_mobile_number' => 'nullable|digits:10|different:mobile|unique:manufacturers,alternate_mobile_number,' . $id,
                'email' => 'required|email|unique:manufacturers,email,' . $id,
                'password' => 'nullable|string|min:8',
                'pan_number' => 'required|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|unique:manufacturers,pan_number,' . $id,
                'landmark' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-]+$/',
                'company_type' => 'required|in:PARTNERSHIP,PROPRIETORSHIP,PRIVATE LIMITED',
                'city' => 'required|regex:/^[a-zA-Z\s]+$/',
                'pin' => 'required|digits:6',
                'address_line_1' => 'required|regex:/^[a-zA-Z0-9\s,.\-]+$/',
                'address_line_2' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-]+$/',

                'beneficiary_name' => ['nullable', 'regex:/^[a-zA-Z\s]+$/', 'max:255'], // Allow alphabets and spaces only
                'account_number' => 'nullable|regex:/^[0-9]+$/', // Account number should be integer
                'ifsc_code' => ['nullable', 'regex:/^[a-zA-Z0-9]+$/', 'max:25'], // Alphanumeric for IFSC code
                'micr_code' => 'nullable|regex:/^\d{9}$/', // MICR code should be numeric (9 digits)
                
                'cheque_1' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'cheque_2' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'cheque_3' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'cheque_4' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
            
                'office_image' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',

                'oem_finayo_agreement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'pan_doc' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'itr_file' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'bank_statement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'gst_certificate' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'rent_agreement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'utility_bill' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'icat_certificate' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'moa' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'aoa' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'certificate_of_incorporation' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'balance_sheet' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'partnership_deed' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',

                'state' => 'required|integer|exists:state_masters,id',
            ]);
        
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            if(isset($inputs['date_of_incorporation']) && !empty($inputs['date_of_incorporation'])) {

                $date = DateTime::createFromFormat('Y-m-d', $inputs['date_of_incorporation']);
                if (!$date || $date->format('Y-m-d') !== $inputs['date_of_incorporation']) {
                    $errors['date_of_incorporation'] = "Date of incorporation must be in the format YYYY-MM-DD";
                    $errorCount++;
                    return $this->helper_controller->send_response('failed', $errors['date_of_incorporation'], 422);
                } elseif ($date > new DateTime()) {
                    $errors['date_of_incorporation'] = "Date of incorporation cannot be a future date";
                    return $this->helper_controller->send_response('failed', $errors['date_of_incorporation'], 422);
                    $errorCount++;
                }
            } else {
                $inputs['date_of_incorporation'] = null;
            }
        
            // Find existing OEM
            $oem = Manufacturers::find($id);
        
            if (!$oem) {
                return $this->helper_controller->send_response('failed', 'OEM not found', 404);
            }

            $emailExists = AdminUser::where('id', '<>', $oem->oem_auth_id)
                            ->where('email', $request->email)
                            ->exists();

            if ($emailExists) {
                return $this->helper_controller->send_response('failed', 'User already exists with this email', 404);
            }
            
            $mobileExists = AdminUser::where('id', '<>', $oem->oem_auth_id)
                            ->where('mobile', $request->mobile)
                            ->exists();

            if ($mobileExists) {
                return $this->helper_controller->send_response('failed', 'User already exists with this mobile number', 404);
            }
        
            // Update OEM details
            $oem->manufacturer          = $inputs['name'] ?? '';
            $oem->date_of_incorporation = $inputs['date_of_incorporation'] ?? null;
            $oem->company_type          = $inputs['company_type'] ?? '';
            $oem->mobile_number         = $inputs['mobile'] ?? '';
            $oem->alternate_mobile_number    = $inputs['alternate_mobile_number'] ?? $oem->alternate_mobile_number;
            $oem->email                 = $inputs['email'] ?? '';
            $oem->pan_number            = $inputs['pan_number'] ?? '';
            $oem->landmark              = $inputs['landmark'] ?? '';
            $oem->city                  = $inputs['city'] ?? '';
            $oem->pin                   = $inputs['pin'] ?? '';
            $oem->address_line_1        = $inputs['address_line_1'] ?? '';
            $oem->address_line_2        = $inputs['address_line_2'] ?? '';
            $oem->state                 = $inputs['state'] ?? $oem->state;
            $oem->created_by            = auth()->user()->id;
            $oem->ip                    = $this->helper_controller->get_user_ip();
            $oem->save();

            $adminUser  = AdminUser::find($oem->oem_auth_id);
            $password   = isset($inputs['password']) ? $inputs['password'] : 'Hello@1234';
            if($adminUser) {
                $adminUser->name    = $inputs['name'];
                $adminUser->mobile  = $inputs['mobile'];
                $adminUser->email   = $inputs['email'];
                $adminUser->save();
            } else{
                $department     = UserDepartments::where('department', 'OEM')->first();
                $department_id  = isset($department) ? $department->id : null;
                
                $user_types     = UserType::where('user_type', 'OEM')->first();
                $user_types_id  = isset($user_types) ? $user_types->id : null;

                $adminUser = AdminUser::create([
                    'name'          => $inputs['name'],
                    'mobile'        => $inputs['mobile'],
                    'email'         => $inputs['email'],
                    'password'      => Hash::make($password),
                    'department'    => $department_id,
                    'user_type'     => $user_types_id,
                    'is_active'     => true
                ]);
                $oem->oem_auth_id = $adminUser->id;

                $emailData = [
                    'name'      => $inputs['name'],
                    'admin_url' => 'http://uat.finayo.tech',
                    'user_id'   => $inputs['email'],
                    'password'  => $password, // $inputs['password']
                ];
                $viewName   = 'emails.example';
                $subject    = "Finayo Login Credential";
            
                // Mail::to($inputs['email'])->send(new Mailer($viewName, $emailData, $subject));
            }

            /**
             * Beneficiary details Part start
             * this part added on 17-12-2024
             */
            if(isset($inputs['beneficiary_name']) && isset($inputs['account_number']) && isset($inputs['ifsc_code'])) {
                $beneficiary = BeneficiaryDetails::where('user_type', 'OEM')->where('user_id', $oem->id)->first();
                if(!$beneficiary) {
                    $beneficiary                = new BeneficiaryDetails();
                }
                $beneficiary->user_type         = 'OEM';
                $beneficiary->user_id           = $oem->id;
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
        
            $oem_unq = 'FIN-OEM-' . $oem->id;
        
            $base_dir = 'OEM/' . $oem_unq;
            $file_mappings = [
                'pan_doc',
                'oem_finayo_agreement',
                'bank_statement',
                'cheque_1',
                'cheque_2',
                'cheque_3',
                'cheque_4',
                'gst_certificate',
                'rent_agreement',
                'office_image',
                'itr_file',
                'utility_bill',
                'icat_certificate',
            ];
        
            // Update file fields if new files are provided
            foreach ($file_mappings as $fileKey) {
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    if ($file->isValid()) {
                        $filename = $fileKey . '.' . $file->getClientOriginalExtension();
                        $path = $file->storeAs($base_dir, $filename, 'public');
                        $oem->$fileKey = 'storage/' . $path;
                    }
                }   else {
                    if (isset($oem->$fileKey)) {
                        $oem->$fileKey = $oem->$fileKey;
                    }
                }
            }

            if ($inputs['company_type'] == 'PARTNERSHIP') {
                $document_mappings = [
                    'partnership_deed' => 'partnership_deed',
                ];
            } elseif ($inputs['company_type'] == 'PRIVATE LIMITED') {
                $document_mappings = [
                    'balance_sheet' => 'balance_sheet',
                    'aoa' => 'aoa',
                    'moa' => 'moa',
                    'certificate_of_incorporation' => 'certificate_of_incorporation',
                ];
            }

            if(isset($document_mappings)) {
                
                foreach ($document_mappings as $fileKey) {
                    if ($request->hasFile($fileKey)) {
                        $file = $request->file($fileKey);
                        if ($file->isValid()) {
                            $filename = $fileKey . '.' . $file->getClientOriginalExtension();
                            $path = $file->storeAs($base_dir, $filename, 'public');
                            $oem->$fileKey = 'storage/' . $path;
                        }
                    }   else {
                        if (isset($oem->$fileKey)) {
                            $oem->$fileKey = $oem->$fileKey;
                        }
                    }
                }
            }
            
            $oem->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'OEM record updated successfully', 200, $oem);
        } catch (Exception $e) {

            DB::rollBack(); // Rollback the transaction
            return $this->helper_controller->send_response('failed', 'An error occurred: '. $e->getMessage(), 422);
        }
    }

    public function delete($id)
    {
        $oem = Manufacturers::find($id);

        if (!$oem || $oem->count() < 1) {
            return $this->helper_controller->send_response('success', 'OEM not found', 200);
        }

        try {
            date_default_timezone_set('Asia/kolkata');
            $oem->is_deleted = true;
            $oem->delete_date_time = date('Y-m-d H:i:s');
            $oem->save();

            return $this->helper_controller->send_response('success', 'OEM deleted successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred", 422);
        }
    }

    public function update_status($id)
    {
        try {
            $oem = Manufacturers::find($id);
            if(!$oem) {
                return $this->helper_controller->send_response('failed', "OEM not found", 422);
            }

            if($oem->is_active == true) {
                $status = false;
            } else {
                $status = true;
            }
            $oem->is_active = $status;
            $oem->save();

            $userDetails = AdminUser::find($oem->oem_auth_id);
            if($userDetails) {
                $userDetails->is_active = $status;
                $userDetails->save();
            }
            
            return $this->helper_controller->send_response('success', 'OEM status updated', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred", 422);
        }
    }

    public function get_oem_dealer_list(Request $request) {
        try {
            $page = $request->get('page', 1);
            $numberOfRecords = $request->get('number_of_records', 10);
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
    
            // Validation (optional, can be expanded)
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'start_date' => 'date|nullable',
                'end_date' => 'date|nullable|after_or_equal:start_date',
                'is_active' => 'boolean|nullable',
                'dealer_id' => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable',
                'dealer_name' => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable',
                'dealer_type' => 'nullable|string|in:B2B,B2C',
                'is_deleted' => 'boolean|nullable',
                'mobile' => [
                    'string',
                    'nullable',
                    'regex:/^[6-9]\d{9}$/', // Indian mobile number validation (10 digits starting with 6-9)
                ],
                'email' => [
                    'string',
                    'nullable',
                    'email:rfc,dns', // Standard email validation
                ],
                'pan' => [
                    'string',
                    'nullable',
                    'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/' // PAN validation (5 letters, 4 digits, 1 letter)
                ],
                'aadhar' => [
                    'string',
                    'nullable',
                    'regex:/^\d{12}$/' // Aadhar validation (12 digits)
                ],
            ]);
    
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $find_manufacturer = Manufacturers::where('oem_auth_id', auth()->user()->id)->first();
            if(!$find_manufacturer) {
                return $this->helper_controller->send_response("failed", "Invalid request", 422);
            }

            $manufacturer_name = $find_manufacturer->manufacturer;

            $dealer_ids = ProductModel::whereRaw('LOWER(manufacture_name) LIKE ?', ['%' . strtolower($manufacturer_name) . '%'])
                                        ->distinct()
                                        ->pluck('dealer_id');
            
            $query = Dealer::whereIn('id', $dealer_ids);

            if ($startDate) {
                $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
                $query->where('created_at', '>=', $startDate);
            }

            if ($endDate) {
                $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
                $query->where('created_at', '<=', $endDate);
            }
    
            // Apply additional filters
            if ($request->filled('is_active')) {
                $query->where('is_active', $request->input('is_active'));
            }
    
            if ($request->filled('is_deleted')) {
                $query->where('is_deleted', $request->input('is_deleted'));
            } else {
                $query->where('is_deleted', false); // Default to false if not provided
            }
    
            if ($request->filled('mobile')) {
                $query->where('mobile', 'like', '%' . $request->input('mobile') . '%');
            }
    
            if ($request->filled('email')) {
                $query->where('email', 'like', '%' . $request->input('email') . '%');
            }
    
            if ($request->filled('pan')) {
                $query->where('pan', 'like', '%' . $request->input('pan') . '%');
            }
    
            if ($request->filled('aadhar')) {
                $query->where('aadhar', 'like', '%' . $request->input('aadhar') . '%');
            }
    
            // Apply year and month filters
            if ($request->filled('year')) {
                $query->whereYear('created_at', $request->input('year'));
            }
    
            if ($request->filled('month')) {
                $monthNumber = Carbon::parse($request->input('month'), 'Asia/Kolkata')->month;
                $query->whereMonth('created_at', $monthNumber);
            }

            if($request->filled('dealer_id')) {
                $query->whereRaw('LOWER(dealer_unq) LIKE ?', ['%' . strtolower($request->dealer_id) . '%']);
            }
            if($request->filled('dealer_name')) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($request->dealer_name) . '%']);
            }
            if($request->filled('dealer_type')) {
                $query->whereRaw('LOWER(dealer_type) LIKE ?', ['%' . strtolower($request->dealer_type) . '%']);
            }
    
            // Apply pagination
            $dealers = $query->orderBy('created_at', 'desc')
                             ->paginate($numberOfRecords, ['*'], 'page', $page);
            
            return $this->helper_controller->send_response('success', "Dealer list fetched", 200, $dealers);
           
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred", 422);
        }
    }

    // public function get_oem_borrower_list(Request $request) {
    //     try {
    //         // Validation (optional, can be expanded)
    //         $validator = Validator::make($request->all(), [
    //             'page' => 'integer|min:1',
    //             'number_of_records' => 'integer|min:1',
    //             'start_date' => 'date|nullable',
    //             'end_date' => 'date|nullable|after_or_equal:start_date',
    //             'step' => 'integer|min:1|nullable',
    //             'borrower_name' => 'string|regex:/^[a-zA-Z\s]+$/|nullable', // Only characters and spaces
    //             'borrower_id' => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable', // Alphanumeric and hyphens
    //             'dealer_id' => 'nullable|integer',
    //             'lsp_id' => 'nullable|integer',
    //             'product_type_id' => 'nullable|integer',
    //             'category' => 'nullable|string|in:B2B,B2C',
    //             'application_date' => 'date|nullable',
    //             'status' => 'nullable|string|in:NEW,APPROVED,DISBURSED,REJECTED,HOLD,CLOSED',
    //         ]);
    
    //         // Check if validation fails
    //         if ($validator->fails()) {
    //             return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
    //         }

    //         $find_manufacturer = Manufacturers::where('oem_auth_id', auth()->user()->id)->first();
    //         if(!$find_manufacturer) {
    //             return $this->helper_controller->send_response("failed", "Invalid request", 422);
    //         }

    //         $manufacturer_name = $find_manufacturer->manufacturer;

    //         $dealer_ids = ProductModel::whereRaw('LOWER(manufacture_name) LIKE ?', ['%' . strtolower($manufacturer_name) . '%'])
    //                                     ->distinct()
    //                                     ->pluck('dealer_id');

    //         $dealer_unqs = Dealer::whereIn('id', $dealer_ids)->pluck('dealer_unq');
            
    //         $startDate = $request->get('start_date');
    //         $endDate = $request->get('end_date');
    //         $numberOfRecords = $request->input('number_of_records', 10); // Default to 10 records per page
    //         $page = $request->input('page', 1); // Default to page 1
    //         $stepFilter = $request->input('step'); // Step filter, might be null
    //         // 
            
    //         $query = LoanBasicDetails::query()
    //             ->join('users', 'loan_basic_details.user_id', '=', 'users.id')
    //             ->leftJoin('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
    //             ->leftJoin('dealers', 'loan_basic_details.dealer_id', '=', 'dealers.dealer_unq')
    //             ->select(
    //                 'borrower_kycs.name as borrower_name',
    //                 'loan_basic_details.borrower_id as borrower_unq',
    //                 'loan_basic_details.status',
    //                 'loan_basic_details.user_id as user_primary_key',
    //                 'loan_basic_details.id as borrower_primary',
    //                 'loan_basic_details.loan_amount',
    //                 'loan_basic_details.model',
    //                 'loan_basic_details.loan_term',
    //                 'loan_basic_details.loan_application_steps',
    //                 'loan_basic_details.created_at as created_on',
    //                 'loan_basic_details.updated_at',
    //                 'dealers.id as dealer_id',
    //                 'dealers.dealer_unq',
    //                 'users.name as user_name',
    //             )->whereIn('loan_basic_details.dealer_id', $dealer_unqs);

    //         if($request->filled('category')) {
    //             if ($request->category == 'B2B') {
    //                 $query->whereNotNull('loan_basic_details.executive_id');
    //             } elseif ($request->category == 'B2C') {
    //                 $query->whereNull('loan_basic_details.executive_id');
    //             } else {
    //                 return $this->helper_controller->send_response('failed', "Invalid category", 422, []);
    //             }
    //         }

    //         if ($request->filled('product_type_id')) {
    //             $query->where('product_id', $request->product_type_id);
    //         }
    
    //         // Apply date filters if provided

    //         if ($request->filled('start_date') && $startDate && !empty($startDate)) {
    //             $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
    //             $query->where('loan_basic_details.created_at', '>=', $startDate);
    //         }
            
    //         // Filter by end date (end of the day in Asia/Kolkata timezone)
    //         if ($request->filled('end_date') && $endDate && !empty($endDate)) {
    //             $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
    //             $query->where('loan_basic_details.created_at', '<=', $endDate);
    //         }

    //         if ($request->filled('borrower_name') && !empty($request->input('borrower_name'))) {
    //             $query->where(function($query) use ($request) {
    //                 $query->whereRaw('LOWER(borrower_kycs.name) LIKE ?', ['%' . strtolower($request->input('borrower_name')) . '%'])
    //                       ->orWhereRaw('LOWER(users.name) LIKE ?', ['%' . strtolower($request->input('borrower_name')) . '%']);
    //             });
    //         }
    //         if ($request->filled('borrower_id') && !empty($request->input('borrower_id'))) {
    //             $query->whereRaw('LOWER(loan_basic_details.borrower_id) like ?', ['%' . strtolower($request->input('borrower_id')) . '%']);
    //         }

    //         if ($request->filled('status') && !empty($request->input('status'))) {
    //             $query->where('loan_basic_details.status', $request->input('status'));
    //         }

    //         if ($request->filled('application_date') && !empty($request->input('application_date'))) {
    //             $query->whereDate('loan_basic_details.created_at', $request->input('application_date'));
    //         }

    //         $year = $request->input('year', date('Y'));
    //         $month = $request->input('month', date('F'));
            
    //         if(isset($request->year)) {
    //             $year = $request->input('year', date('Y'));;
    //             $query->whereYear('loan_basic_details.created_at', $year);
    //         }
            
    //         if(isset($request->month)) {
    //             $year = $request->input('year', date('Y'));
    //             $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
    //             $query->whereMonth('loan_basic_details.created_at', $monthNumber);
    //         }
    
    //         // Fetch results before pagination
    //         $borrowers = $query->orderBy('created_on', 'desc')->get();

    //         // Transform the result to replace null or empty values and include step status
    //         $borrowers = $borrowers->map(function ($borrower) {
    //             $stepStatus = '';
    //             $lastStep = null;
    //             if ($borrower->loan_application_steps) {
    //                 $steps = json_decode($borrower->loan_application_steps, true);
    //                 if (is_array($steps) && !empty($steps)) {
    //                     $lastStep = end($steps);
    //                     if (isset($lastStep['step'])) {
    //                         $stepValue = (int)$lastStep['step'];
    //                         if ($stepValue < 8) {
    //                             $stepStatus = 'step1';
    //                         } elseif ($stepValue >= 8 && $stepValue < 26) {
    //                             $stepStatus = 'step2';
    //                         } elseif ($stepValue >= 26 && $stepValue < 27) {
    //                             $stepStatus = 'step3';
    //                         } elseif ($stepValue >= 27) {
    //                             $stepStatus = 'step4';
    //                         }
    //                     }
    //                 }
    //             }

    //             $user_name      = $borrower->user_name ?? '';
    //             $borrower_name  = $borrower->borrower_name ?? $user_name ?? '';

    //             $user_address_details = $this->helper_controller->user_address_details($borrower->id, "BORROWER");

    //             return [
    //                 'borrower_name' => $borrower_name ?? '',
    //                 'borrower_id' => $borrower->borrower_unq ?? '',
    //                 'loan_status' => $borrower->status ?? '',
    //                 'model' => $borrower->model ?? '',
    //                 'loan_amount' => $this->helper_controller->calculateNetLoanAmount($borrower->loan_amount, $borrower->loan_term),
    //                 'address' => $user_address_details ?? [],
    //                 'down_payment' => $down_payment ?? 0,
    //                 'loan_id' => $loan_id ?? '',
    //                 'created_on' => $borrower->created_on ?? '',
    //                 'updated_at' => $borrower->updated_at ?? '',
    //                 'dealer_id' => $borrower->dealer_id ?? '',
    //                 'dealer_unq' => $borrower->dealer_unq ?? '',
    //                 'step_status' => $stepStatus,
    //                 'last_step' => $lastStep ? [
    //                     'application_step' => $lastStep['step'],
    //                     'details' => $lastStep['details'],
    //                     'status' => $lastStep['status'],
    //                 ] : null, // Adding last step
    //                 'action' => '' // You can specify actions if needed, currently left empty
    //             ];
    //         });

    //         // Filter by step status if provided
    //         if ($stepFilter !== null) {
    //             $borrowers = $borrowers->filter(function ($borrower) use ($stepFilter) {
    //                 return $borrower['step_status'] === 'step' . $stepFilter;
    //             });
    //         }
    
    //         // Convert collection to array for pagination
    //         $borrowers = $borrowers->values();
    
    //         // Paginate the filtered results
    //         $total = $borrowers->count();
    //         $borrowers = array_slice($borrowers->toArray(), ($page - 1) * $numberOfRecords, $numberOfRecords);
    
    //         // Prepare pagination links
    //         $paginatedData = [
    //             'current_page' => $page,
    //             'data' => $borrowers,
    //             'first_page_url' => url()->current() . '?page=1',
    //             'from' => ($page - 1) * $numberOfRecords + 1,
    //             'last_page' => (int)ceil($total / $numberOfRecords),
    //             'last_page_url' => url()->current() . '?page=' . (int)ceil($total / $numberOfRecords),
    //             'links' => [
    //                 [
    //                     'url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
    //                     'label' => '&laquo; Previous',
    //                     'active' => false
    //                 ],
    //                 [
    //                     'url' => url()->current() . '?page=1',
    //                     'label' => '1',
    //                     'active' => $page == 1
    //                 ],
    //                 [
    //                     'url' => url()->current() . '?page=' . $page,
    //                     'label' => (string)$page,
    //                     'active' => true
    //                 ],
    //                 [
    //                     'url' => $page < (int)ceil($total / $numberOfRecords) ? url()->current() . '?page=' . ($page + 1) : null,
    //                     'label' => 'Next &raquo;',
    //                     'active' => false
    //                 ]
    //             ],
    //             'next_page_url' => $page < (int)ceil($total / $numberOfRecords) ? url()->current() . '?page=' . ($page + 1) : null,
    //             'path' => url()->current(),
    //             'per_page' => $numberOfRecords,
    //             'prev_page_url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
    //             'to' => min($page * $numberOfRecords, $total),
    //             'total' => $total
    //         ];

    //         return $this->helper_controller->send_response('success', "Borrower details fetched", 200, $paginatedData);
    
    //     } catch (Exception $e) {
    //         return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
    //     }
    // }
    public function get_oem_borrower_list(Request $request) {
        try {
            // Validation (optional, can be expanded)
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'start_date' => 'date|nullable',
                'end_date' => 'date|nullable|after_or_equal:start_date',
                'step' => 'integer|min:1|nullable',
                'borrower_name' => 'string|regex:/^[a-zA-Z\s]+$/|nullable', // Only characters and spaces
                'borrower_id' => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable', // Alphanumeric and hyphens
                'dealer_id' => 'nullable|integer',
                'lsp_id' => 'nullable|integer',
                'product_type_id' => 'nullable|integer',
                'category' => 'nullable|string|in:B2B,B2C',
                'application_date' => 'date|nullable',
                'status' => 'nullable|string|in:NEW,APPROVED,DISBURSED,REJECTED,HOLD,CLOSED',
            ]);
    
            // Check if validation fails
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $find_manufacturer = Manufacturers::where('oem_auth_id', auth()->user()->id)->first();
            if(!$find_manufacturer) {
                return $this->helper_controller->send_response("failed", "Invalid request", 422);
            }

            $manufacturer_name = $find_manufacturer->manufacturer;

            $dealer_ids = ProductModel::whereRaw('LOWER(manufacture_name) LIKE ?', ['%' . strtolower($manufacturer_name) . '%'])
                                        ->distinct()
                                        ->pluck('dealer_id');

            $dealer_unqs = Dealer::whereIn('id', $dealer_ids)->pluck('dealer_unq');
            
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $numberOfRecords = $request->input('number_of_records', 10); // Default to 10 records per page
            $page = $request->input('page', 1); // Default to page 1
            $stepFilter = $request->input('step'); // Step filter, might be null
            $search_dealer_id = null;
            if($request->filled('dealer_id')) {
                $dealerData = Dealer::find($request->dealer_id);
                if($dealerData) {
                    // $search_dealer_id = 'FIN-DEA-'.$request->dealer_id;
                    $search_dealer_id = $dealerData->dealer_unq;
                }
            }
            // 
            
            $query = LoanBasicDetails::query()
                ->join('users', 'loan_basic_details.user_id', '=', 'users.id')
                ->join('product_models', 'loan_basic_details.model_id', '=', 'product_models.model_unq')
                ->join('manufacturers', 'product_models.manufacture_name', '=', 'manufacturers.manufacturer')
                ->leftJoin('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
                ->leftJoin('dealers', 'loan_basic_details.dealer_id', '=', 'dealers.dealer_unq')
                ->select(
                    'borrower_kycs.name as borrower_name',
                    'borrower_kycs.mobile',
                    'loan_basic_details.borrower_id as borrower_unq',
                    'loan_basic_details.status',
                    'loan_basic_details.user_id as user_primary_key',
                    'loan_basic_details.id as borrower_primary',
                    'loan_basic_details.loan_amount',
                    'loan_basic_details.model',
                    'loan_basic_details.loan_term',
                    'loan_basic_details.loan_application_steps',
                    'loan_basic_details.created_at as created_on',
                    'loan_basic_details.updated_at',
                    'loan_basic_details.down_payment',
                    'dealers.id as dealer_id',
                    'dealers.dealer_unq',
                    'users.name as user_name',
                    'users.mobile as user_mobile',
                )->whereIn('loan_basic_details.dealer_id', $dealer_unqs);

            if($request->filled('category')) {
                if ($request->category == 'B2B') {
                    $query->whereNotNull('loan_basic_details.executive_id');
                } elseif ($request->category == 'B2C') {
                    $query->whereNull('loan_basic_details.executive_id');
                } else {
                    return $this->helper_controller->send_response('failed', "Invalid category", 422, []);
                }
            }
            if($request->filled('dealer_id') && !empty($search_dealer_id)) {
                $query->where('loan_basic_details.dealer_id', $search_dealer_id);
            }

            if ($request->filled('product_type_id')) {
                $query->where('product_id', $request->product_type_id);
            }
    
            // Apply date filters if provided

            if ($request->filled('start_date') && $startDate && !empty($startDate)) {
                $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
                $query->where('loan_basic_details.created_at', '>=', $startDate);
            }
            
            // Filter by end date (end of the day in Asia/Kolkata timezone)
            if ($request->filled('end_date') && $endDate && !empty($endDate)) {
                $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
                $query->where('loan_basic_details.created_at', '<=', $endDate);
            }

            if ($request->filled('borrower_name') && !empty($request->input('borrower_name'))) {
                $query->where(function($query) use ($request) {
                    $query->whereRaw('LOWER(borrower_kycs.name) LIKE ?', ['%' . strtolower($request->input('borrower_name')) . '%'])
                          ->orWhereRaw('LOWER(users.name) LIKE ?', ['%' . strtolower($request->input('borrower_name')) . '%']);
                });
            }
            if ($request->filled('borrower_id') && !empty($request->input('borrower_id'))) {
                $query->whereRaw('LOWER(loan_basic_details.borrower_id) like ?', ['%' . strtolower($request->input('borrower_id')) . '%']);
            }

            if ($request->filled('status') && !empty($request->input('status'))) {
                $query->where('loan_basic_details.status', $request->input('status'));
            }

            if ($request->filled('application_date') && !empty($request->input('application_date'))) {
                $query->whereDate('loan_basic_details.created_at', $request->input('application_date'));
            }

            $year = $request->input('year', date('Y'));
            $month = $request->input('month', date('F'));
            
            if(isset($request->year)) {
                $year = $request->input('year', date('Y'));;
                $query->whereYear('loan_basic_details.created_at', $year);
            }
            
            if(isset($request->month)) {
                $year = $request->input('year', date('Y'));
                $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
                $query->whereMonth('loan_basic_details.created_at', $monthNumber);
            }
    
            // Fetch results before pagination
            $borrowers = $query->orderBy('created_on', 'desc')->get();

            // Transform the result to replace null or empty values and include step status
            $borrowers = $borrowers->map(function ($borrower) {
                $stepStatus = '';
                $application_status = '';
                $lastStep = null;
                if ($borrower->loan_application_steps) {
                    $steps = json_decode($borrower->loan_application_steps, true);
                    if (is_array($steps) && !empty($steps)) {
                        $lastStep = end($steps);
                        if (isset($lastStep['step'])) {
                            $stepValue = (int)$lastStep['step'];
                            if ($stepValue < 8) {
                                $stepStatus = 'step1';
                            } elseif ($stepValue >= 8 && $stepValue < 26) {
                                $stepStatus = 'step2';
                            } elseif ($stepValue >= 26 && $stepValue < 27) {
                                $stepStatus = 'step3';
                            } elseif ($stepValue >= 27) {
                                $stepStatus = 'step4';
                            }
                            if($stepValue >= 9) {
                                $application_status = 'Approved (in principle)';
                            }
                        }
                    }
                }

                $user_name      = $borrower->user_name ?? '';
                $borrower_name  = $borrower->borrower_name ?? $user_name ?? '';
                $borrower_mobile  = $borrower->mobile ?? $borrower->user_mobile ?? '';

                $user_address_details = $this->helper_controller->user_address_details($borrower->borrower_primary, "BORROWER");

                $disbursement_details = DisbursementDetails::select(
                    [
                        'disbursement_amount','disbursement_date'
                    ])
                    ->where('borrower_id', $borrower->borrower_primary)->first();
                

                return [
                    'borrower_name' => $borrower_name ?? '',
                    'mobile_no' => $borrower_mobile ?? '',
                    'borrower_id' => $borrower->borrower_unq ?? '',
                    'loan_status' => $borrower->status ?? '',
                    'model' => $borrower->model ?? '',
                    'loan_amount' => $this->helper_controller->calculateNetLoanAmount($borrower->loan_amount, $borrower->loan_term),
                    'address' => $user_address_details ?? [],
                    'down_payment' => $borrower->down_payment ?? 0,
                    'loan_id' => $loan_id ?? '',
                    'created_on' => $borrower->created_on ?? '',
                    'updated_at' => $borrower->updated_at ?? '',
                    'dealer_id' => $borrower->dealer_id ?? '',
                    'dealer_unq' => $borrower->dealer_unq ?? '',
                    'step_status' => $stepStatus,
                    'application_status' => $application_status,
                    'disbursement_details' => $disbursement_details ?? null,
                    'last_step' => $lastStep ? [
                        'application_step' => $lastStep['step'],
                        'details' => $lastStep['details'],
                        'status' => $lastStep['status'],
                    ] : null, // Adding last step
                    'action' => '' // You can specify actions if needed, currently left empty
                ];
            });

            // Filter by step status if provided
            if ($stepFilter !== null) {
                $borrowers = $borrowers->filter(function ($borrower) use ($stepFilter) {
                    return $borrower['step_status'] === 'step' . $stepFilter;
                });
            }
    
            // Convert collection to array for pagination
            $borrowers = $borrowers->values();
    
            // Paginate the filtered results
            $total = $borrowers->count();
            $borrowers = array_slice($borrowers->toArray(), ($page - 1) * $numberOfRecords, $numberOfRecords);
    
            // Prepare pagination links
            $paginatedData = [
                'current_page' => $page,
                'data' => $borrowers,
                'first_page_url' => url()->current() . '?page=1',
                'from' => ($page - 1) * $numberOfRecords + 1,
                'last_page' => (int)ceil($total / $numberOfRecords),
                'last_page_url' => url()->current() . '?page=' . (int)ceil($total / $numberOfRecords),
                'links' => [
                    [
                        'url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
                        'label' => '&laquo; Previous',
                        'active' => false
                    ],
                    [
                        'url' => url()->current() . '?page=1',
                        'label' => '1',
                        'active' => $page == 1
                    ],
                    [
                        'url' => url()->current() . '?page=' . $page,
                        'label' => (string)$page,
                        'active' => true
                    ],
                    [
                        'url' => $page < (int)ceil($total / $numberOfRecords) ? url()->current() . '?page=' . ($page + 1) : null,
                        'label' => 'Next &raquo;',
                        'active' => false
                    ]
                ],
                'next_page_url' => $page < (int)ceil($total / $numberOfRecords) ? url()->current() . '?page=' . ($page + 1) : null,
                'path' => url()->current(),
                'per_page' => $numberOfRecords,
                'prev_page_url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
                'to' => min($page * $numberOfRecords, $total),
                'total' => $total
            ];

            return $this->helper_controller->send_response('success', "Borrower details fetched", 200, $paginatedData);
    
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function get_oem_dashboard_details(Request $request) {
        try {
            // Default to current year and month if not provided
            date_default_timezone_set('Asia/Kolkata');
            if($request->filled('year')) {
                $year = $request->input('year', date('Y'));
            } else {
                $year = date('Y');
            }

            if($request->filled('month')) {
                $month = $request->input('month', date('F'));
            } else {
                $month = date('F');
            }
    
            // Validate input
            $request->validate([
                'month' => 'nullable|date_format:F',
                'year' => 'nullable|integer',
            ]);

            $find_manufacturer = Manufacturers::where('oem_auth_id', auth()->user()->id)->first();
            if(!$find_manufacturer) {
                return $this->helper_controller->send_response("failed", "Invalid request", 422);
            }

            $manufacturer_name = $find_manufacturer->manufacturer;

            $dealer_ids = ProductModel::whereRaw('LOWER(manufacture_name) LIKE ?', ['%' . strtolower($manufacturer_name) . '%'])
                                        ->distinct()
                                        ->pluck('dealer_id');

            $dealer_unqs = Dealer::whereIn('id', $dealer_ids)->pluck('dealer_unq');
    
            // Convert month name to numeric representation
            $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
    
            // Get all loan details for the specified month and year
            $loanDetailsQuery = LoanBasicDetails::whereYear('created_at', $year)
                                            ->whereMonth('created_at', $monthNumber)
                                            ->whereIn('dealer_id', $dealer_unqs);

            $loanDetails = $loanDetailsQuery->get();
    
            // Initialize counters for steps
            $step1Count = 0;
            $step2Count = 0;
            $step3Count = 0;
            $step4Count = 0;
            $totalApplicationCount = 0;
            $totalDisbursedCount = 0;
    
            // Count steps based on the last JSON value
            foreach ($loanDetails as $loanDetail) {
                if ($loanDetail->loan_application_steps) {
                    $steps = json_decode($loanDetail->loan_application_steps, true);
                    if (is_array($steps) && !empty($steps)) {
                        $lastStep = end($steps);
                        if (isset($lastStep['step'])) {
                            $stepValue = (int)$lastStep['step'];
                            if ($stepValue >= 1 && $stepValue < 8) {
                                $step1Count++;
                            } elseif ($stepValue >= 8 && $stepValue < 26) {
                                $step2Count++;
                            } elseif ($stepValue >= 26 && $stepValue < 27) {
                                $step3Count++;
                            } elseif ($stepValue >= 27) {
                                $step4Count++;
                            }
                            $totalApplicationCount++;
                        }
                    }
                }

                if($loanDetail->status == 'DISBURSED') {
                    $totalDisbursedCount++;
                }
            }
    
            // Get total loan amount
            $totalLoanAmountQuery = LoanBasicDetails::whereYear('created_at', $year)
                                         ->whereMonth('created_at', $monthNumber)
                                         ->whereIn('dealer_id', $dealer_unqs);

            $totalLoanAmount = $totalLoanAmountQuery->sum('loan_amount');
    
            // Get daily data for the month
            $dailyDataQuery = LoanBasicDetails::select(DB::raw('EXTRACT(DAY FROM created_at) as day'),
                                            DB::raw('COUNT(*) as total'),
                                            DB::raw('SUM(loan_amount) as loan_amount'));

            $dailyData = $dailyDataQuery->whereYear('created_at', $year)
                                       ->whereMonth('created_at', $monthNumber)
                                       ->whereIn('dealer_id', $dealer_unqs)
                                       ->groupBy(DB::raw('EXTRACT(DAY FROM created_at)'))
                                       ->orderBy(DB::raw('EXTRACT(DAY FROM created_at)'))
                                       ->get()->toArray();
    
            // Format daily data to ensure days with no data default to 0
            $formattedDailyData = $this->formatDailyData($dailyData, $monthNumber, $year);
    
            // Count steps daywise
            $dailyStepCounts = [
                'step1' => array_fill(0, count($formattedDailyData['total']), 0),
                'step2' => array_fill(0, count($formattedDailyData['total']), 0),
                'step3' => array_fill(0, count($formattedDailyData['total']), 0),
                'step4' => array_fill(0, count($formattedDailyData['total']), 0),
                'disbursed' => array_fill(0, count($formattedDailyData['total']), 0),
            ];
    
            foreach ($loanDetails as $loanDetail) {
                $day = Carbon::parse($loanDetail->created_at)->day - 1;
                if ($loanDetail->loan_application_steps) {
                    $steps = json_decode($loanDetail->loan_application_steps, true);
                    if (is_array($steps) && !empty($steps)) {
                        $lastStep = end($steps);
                        if (isset($lastStep['step'])) {
                            $stepValue = (int)$lastStep['step'];
                            if ($stepValue >= 1 && $stepValue < 8) {
                                $dailyStepCounts['step1'][$day]++;
                            } elseif ($stepValue >= 8 && $stepValue < 26) {
                                $dailyStepCounts['step2'][$day]++;
                            } elseif ($stepValue >= 26 && $stepValue < 27) {
                                $dailyStepCounts['step3'][$day]++;
                            } elseif ($stepValue >= 27) {
                                $dailyStepCounts['step4'][$day]++;
                            }
                        }
                    }
                }
                if($loanDetail->status == 'DISBURSED') {
                    $dailyStepCounts['disbursed'][$day]++;
                }
            }
    
            return response()->json([
                'total_application' => $totalApplicationCount,
                'total_loan_amount' => $totalLoanAmount,
                'step1_count' => $step1Count,
                'step2_count' => $step2Count,
                'step3_count' => $step3Count,
                'step4_count' => $step4Count,
                'total_disbursed_application' => $totalDisbursedCount,
                'daywise_totalApplication' => $formattedDailyData['total'],
                'daywise_step1Application' => $dailyStepCounts['step1'],
                'daywise_step2Application' => $dailyStepCounts['step2'],
                'daywise_step3Application' => $dailyStepCounts['step3'],
                'daywise_step4Application' => $dailyStepCounts['step4'],
                'daywise_disbursed_application' => $dailyStepCounts['disbursed'],
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching the dashboard details.',
                'message' => $e->getMessage()
            ], 400);
        }
    }
    

    /**
     * Format daily data to ensure days with no data default to 0.
     *
     * @param array $dailyData
     * @param int $monthNumber
     * @param int $year
     * @return array
     */

    private function formatDailyData(array $dailyData, int $month, int $year) {
        $daysInMonth = Carbon::createFromDate($year, $month)->daysInMonth;
        $formattedDailyData = [
            'total' => array_fill(0, $daysInMonth, 0),
            'loan_amount' => array_fill(0, $daysInMonth, 0.0),
        ];
    
        foreach ($dailyData as $data) {
            $day = $data['day'] - 1; // Convert 1-based day to 0-based index
            $formattedDailyData['total'][$day] = (int)$data['total'];
            $formattedDailyData['loan_amount'][$day] = (float)$data['loan_amount'];
        }
    
        return $formattedDailyData;
    }

    public function get_oem_yearly_count(Request $request) {
        try {
            // Retrieve year input, default to null if not provided
            date_default_timezone_set('Asia/Kolkata');
            if($request->filled('year')) {
                $year = $request->input('year', date('Y'));
            } else {
                $year = date('Y');
            }

            // Validate input
            $request->validate([
                'year' => 'nullable|integer',
            ]);

            $find_manufacturer = Manufacturers::where('oem_auth_id', auth()->user()->id)->first();
            if(!$find_manufacturer) {
                return $this->helper_controller->send_response("failed", "Invalid request", 422);
            }

            $manufacturer_name = $find_manufacturer->manufacturer;

            $dealer_ids = ProductModel::whereRaw('LOWER(manufacture_name) LIKE ?', ['%' . strtolower($manufacturer_name) . '%'])
                                        ->distinct()
                                        ->pluck('dealer_id');

            $dealer_unqs = Dealer::whereIn('id', $dealer_ids)->pluck('dealer_unq');
    
            // Get all loan details for the specified year or all years if no year is provided
            $loanDetailsQuery = LoanBasicDetails::whereIn('dealer_id', $dealer_unqs);

            if ($year) {
                $loanDetailsQuery->whereYear('created_at', $year);
            }
            $loanDetails = $loanDetailsQuery->get();
    
            // Initialize counters for steps and total applications
            $step1Count = 0;
            $step2Count = 0;
            $step3Count = 0;
            $step4Count = 0;
            $totalApplicationCount = 0;
            $totalDisbursedCount = 0;
    
            // Count steps based on the last JSON value
            foreach ($loanDetails as $loanDetail) {
                if ($loanDetail->loan_application_steps) {
                    $steps = json_decode($loanDetail->loan_application_steps, true);
                    if (is_array($steps) && !empty($steps)) {
                        $lastStep = end($steps);
                        if (isset($lastStep['step'])) {
                            $stepValue = (int)$lastStep['step'];
                            if ($stepValue >= 1 && $stepValue < 8) {
                                $step1Count++;
                            } elseif ($stepValue >= 8 && $stepValue < 26) {
                                $step2Count++;
                            } elseif ($stepValue >= 26 && $stepValue < 27) {
                                $step3Count++;
                            } elseif ($stepValue >= 27) {
                                $step4Count++;
                            }
                            $totalApplicationCount++;
                        }
                    }
                }

                if($loanDetail->status == 'DISBURSED') {
                    $totalDisbursedCount++;
                }
            }
    
            // Get total loan amount for the specified year or all years
            $totalLoanAmountQuery = LoanBasicDetails::whereIn('dealer_id', $dealer_unqs);
            if ($year) {
                $totalLoanAmountQuery->whereYear('created_at', $year);
            }
            
            $totalLoanAmount = $totalLoanAmountQuery->sum('loan_amount');
            $totalRejectedAppplications = $totalLoanAmountQuery->where('status', 'REJECTED')->count();
            $total_in_loan_pipeline = $totalApplicationCount - $totalRejectedAppplications;
    
            return response()->json([
                'total_application' => $totalApplicationCount,
                'total_loan_amount' => $totalLoanAmount,
                'step1_count' => $step1Count,
                'step2_count' => $step2Count,
                'step3_count' => $step3Count,
                'step4_count' => $step4Count,
                'loan_pipeline' => $total_in_loan_pipeline,
                'rejected_application' => $totalRejectedAppplications,
                'approved_application' => $totalDisbursedCount,
                'disbursed_application' => $totalDisbursedCount,
            ], 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknwon error occurred: " . $e->getMessage(), 422);
        }
    }

    public function get_oem_product_list(Request $request) {
        try {
            // Define default values
            $page = $request->get('page', 1);
            $numberOfRecords = $request->get('number_of_records', 10);
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $maxLoanAmount = $request->get('max_loan_amount');
            $minLoanTenure = $request->get('min_loan_tenure');
            $maxLoanTenure = $request->get('max_loan_tenure');
            $productType = $request->get('product_type');

            $dealerId = $request->get('dealer_id');
            $modelUnq = $request->get('model_unq');
            $name = $request->get('name');
            $batteryType = $request->get('battery_type');
            $exShowroomPrice = $request->get('ex_showroom_price');
            $loanToValueRatio = $request->get('loan_to_value_ratio');
            $interest = $request->get('interest');

            // Validation
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1|max:100',
                'start_date' => 'date|nullable',
                'end_date' => 'date|nullable|after_or_equal:start_date',
                'max_loan_amount' => 'numeric|nullable',
                'min_loan_tenure' => 'integer|nullable',
                'max_loan_tenure' => 'integer|nullable',
                'dealer_id' => 'integer|nullable',
                'product_type' => ['nullable', 'regex:/^[a-zA-Z\s\-\(\)]+$/'], // Allow letters, spaces, hyphen, parentheses
                // 'dealer_id' => ['nullable', 'regex:/^[a-zA-Z\s\-]+$/'],         // Allow letters, spaces, hyphen
                'model_unq' => ['nullable', 'regex:/^[a-zA-Z\s\-]+$/'],         // Allow letters, spaces, hyphen
                'name' => ['nullable', 'regex:/^[a-zA-Z\s\-\(\)]+$/'],          // Allow letters, spaces, hyphen, parentheses
                'battery_type' => ['nullable', 'regex:/^[a-zA-Z\s\-\(\)]+$/'],  // Allow letters, spaces, hyphen, parentheses
                'ex_showroom_price' => 'numeric|nullable',
                'loan_to_value_ratio' => 'numeric|nullable',
                'interest' => 'numeric|nullable',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $find_manufacturer = Manufacturers::where('oem_auth_id', auth()->user()->id)->first();
            if(!$find_manufacturer) {
                return $this->helper_controller->send_response("failed", "Invalid request", 422);
            }

            $manufacturer_name = $find_manufacturer->manufacturer;

            // Build the query
            $query = DB::table('product_models')
                ->join('dealers', 'product_models.dealer_id', '=', 'dealers.id')
                ->where('dealers.is_deleted', false)
                ->whereRaw('LOWER(product_models.manufacture_name) LIKE ?', ["%".strtolower($manufacturer_name)."%"])
                ->select(
                    'dealers.dealer_unq',
                    'dealers.name as dealer_name',
                    'product_models.model_unq',
                    'product_models.manufacture_name',
                    'product_models.name',
                    'product_models.battery_type',
                    'product_models.product_type',
                    'product_models.ex_showroom_price',
                    'product_models.loan_to_value_ratio',
                    'product_models.min_loan_amount',
                    'product_models.max_loan_amount',
                    'product_models.min_loan_tenure',
                    'product_models.max_loan_tenure',
                    'product_models.interest',
                    'product_models.status'
                );

            // Apply filters if provided
            if ($startDate) {
                $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
                $query->where('product_models.created_at', '>=', $startDate);
            }
            if ($endDate) {
                $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
                $query->where('product_models.created_at', '<=', $endDate);
            }
            if ($maxLoanAmount) {
                $query->where('product_models.max_loan_amount', $maxLoanAmount);
            }
            if ($minLoanTenure) {
                $query->where('product_models.min_loan_tenure', $minLoanTenure);
            }
            if ($maxLoanTenure) {
                $query->where('product_models.max_loan_tenure', $maxLoanTenure);
            }
            if ($productType) {
                $query->where('product_models.product_type', $productType);
            }

            // Apply additional filters
            if ($dealerId) {
                // $query->where('dealers.dealer_unq', $dealerId);
                $query->where('dealers.id', $dealerId);
            }
            if ($modelUnq) {
                $query->whereRaw('LOWER(product_models.model_unq) LIKE ?', ["%".strtolower($modelUnq)."%"]);
            }
            if ($name) {
                $query->whereRaw('LOWER(product_models.name) LIKE ?', ["%".strtolower($name)."%"]);
            }
            if ($batteryType) {
                $query->whereRaw('LOWER(product_models.battery_type) LIKE ?', ["%".strtolower($batteryType)."%"]);
            }
            if ($exShowroomPrice) {
                $query->where('product_models.ex_showroom_price', $exShowroomPrice);
            }
            if ($loanToValueRatio) {
                $query->where('product_models.loan_to_value_ratio', $loanToValueRatio);
            }
            
            if ($interest) {
                $query->where('product_models.interest', $interest);
            }

            // Paginate the results
            // $products = $query->where('product_models.is_deleted', false)->orderBy('product_models.id', 'desc')->paginate($numberOfRecords, ['*'], 'page', $page);
            $products = $query->orderBy('product_models.id', 'desc')->paginate($numberOfRecords, ['*'], 'page', $page);

            // return response()->json($products, 200);
            return $this->helper_controller->send_response('success', "Product list fetched successfully", 200, $products);
            
        } catch (Exception $e) {
            // Handle errors
            return $this->helper_controller->send_response('success', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function get_oem_own_products(Request $request) {
        $find_manufacturer = Manufacturers::where('oem_auth_id', auth()->user()->id)->first();
        if(!$find_manufacturer) {
            return $this->helper_controller->send_response("failed", "Invalid request", 422);
        }

        $manufacturer_name = $find_manufacturer->manufacturer;

        // $products = ProductModel::select('name', 'product_type', 'battery_type')
        //                         ->whereRaw('LOWER(manufacture_name) LIKE ?', ['%' . strtolower($manufacturer_name) . '%'])
        //                         ->distinct()
        //                         ->get();
        $oem_id = $find_manufacturer->id;

        $products = OemProducts::where('oem_id', $oem_id)
                                ->get();

        return $this->helper_controller->send_response('success', "OEM's products fetched successfully", 200, $products);
    }    
}
