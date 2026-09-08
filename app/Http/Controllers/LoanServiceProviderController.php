<?php

namespace App\Http\Controllers;

use App\Mail\Mailer;
use App\Models\AdminUser;
use App\Models\LenderServiceProvider;
use App\Models\LoanServiceProvider;
use App\Models\LspCompanyDocuments;
use App\Models\UserDepartments;
use App\Models\UserType;
use DateTime;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class LoanServiceProviderController extends Controller
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
                'lsp_id' => 'nullable|string',
                'name' => 'nullable|string|regex:/^[a-zA-Z\s]+$/',
                'date_of_incorporation' => 'nullable|date_format:Y-m-d|before_or_equal:today',
                'mobile' => 'nullable|digits:10',
                'alternative_number' => 'nullable|digits:10|different:mobile',
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
            $query = LoanServiceProvider::with(['state:id,state'])->select(['loan_service_providers.*', 'lsp_company_documents.company_type'])
                        ->join('lsp_company_documents', 'loan_service_providers.id', '=', 'lsp_company_documents.lsp_id')
                        ->where('is_deleted', false);

            // Apply filters if they exist
            if ($request->filled('lsp_id')) {
                $query->whereRaw('LOWER(lsp_unq) LIKE ?', ['%' . strtolower($request->lsp_id) . '%']);
            }

            if ($request->filled('name')) {
                // Case-insensitive filter for name
                $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($request->name) . '%']);
            }

            if ($request->filled('date_of_incorporation')) {
                $query->whereDate('date_of_incorporation', $request->date_of_incorporation);
            }

            if ($request->filled('mobile')) {
                $query->where('mobile', $request->mobile);
            }

            if ($request->filled('alternative_number')) {
                $query->where('alternative_number', $request->alternative_number);
            }

            if ($request->filled('email')) {
                // Case-insensitive filter for email
                $query->whereRaw('LOWER(email) LIKE ?', ['%' . strtolower($request->email) . '%']);
            }

            if ($request->filled('pan')) {
                $query->where('loan_service_providers.pan', 'like', '%' . $request->pan . '%');
            }

            // Paginate results
            $lsp = $query->orderBy('id', 'desc')
                        ->paginate($number_of_records, ['*'], 'page', $page);

            DB::commit();

            // Send response with paginated results
            return $this->helper_controller->send_response('success', 'LSP fetched successfully', 200, $lsp);
        } catch (Exception $e) {
            DB::rollBack(); // Rollback in case of any error
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 500);
        }
    }

    public function lsp_screener() {
        $lsp = LoanServiceProvider::select(['id', 'name'])->where('is_deleted', false)->orderBy('name', 'asc')->get();
        return $this->helper_controller->send_response('success', 'LSP fetched successfully', 200, $lsp);
    }

    public function read($id)
    {
        try { 
            $result = [];
            $lsp = LoanServiceProvider::where('is_deleted', false)
                ->with(['lsp_directors', 'company_documents', 'state:id,state']) // Assuming these relationships are defined
                ->select([
                    'loan_service_providers.*', // Select all columns from the loan service provider table
                    DB::raw('pan as pan_number'), // Rename pan to pan_number
                    DB::raw('aadhar as aadhar_number'), // Rename aadhar to aadhar_number
                ])
                ->find($id);
            if (!$lsp) {
                return $this->helper_controller->send_response('failed', 'LSP not found', 422);
            }

            // Convert lsp object to array
            $lsp = $lsp->toArray();

            $result = [
                'id' => $lsp['id'],
                'lsp_unq' => $lsp['lsp_unq'] ?? '',
                'name' => $lsp['name'] ?? '',
                'is_active' => $lsp['is_active'] ?? '',
                'modified_by' => $lsp['modified_by'] ?? '',
                'created_at' => $lsp['created_at'] ?? '',
                'date_of_incorporation' => $lsp['date_of_incorporation'] ?? '',
                'mobile' => $lsp['mobile'] ?? '',
                'alternative_number' => $lsp['alternative_number'] ?? '',
                'email' => $lsp['email'] ?? '',
                'pan' => $lsp['pan'] ?? '',
                'aadhar' => $lsp['aadhar'] ?? '',
                'pan_number' => $lsp['pan_number'] ?? '',
                'aadhar_number' => $lsp['aadhar_number'] ?? '',
                'landmark' => $lsp['landmark'] ?? '',
                'city' => $lsp['city'] ?? '',
                'pincode' => $lsp['pincode'] ?? '',
                'address_line_1' => $lsp['address_line_1'] ?? '',
                'address_line_2' => $lsp['address_line_2'] ?? '',
                'cibil_report' => $lsp['cibil_report'] ?? '',
                'trade_certificate' => $lsp['trade_certificate'] ?? '',
                'gst_certificate' => $lsp['gst_certificate'] ?? '',
                'security_cheque_1' => $lsp['security_cheque_1'] ?? '',
                'security_cheque_2' => $lsp['security_cheque_2'] ?? '',
                'security_cheque_3' => $lsp['security_cheque_3'] ?? '',
                'security_cheque_4' => $lsp['security_cheque_4'] ?? '',
                'office_premise_img' => $lsp['office_premise_img'] ?? '',
                'lsp_finayo_agreement' => $lsp['lsp_finayo_agreement'] ?? '',
                'state_id' => $lsp['state']['id'] ?? '',
                'state' => $lsp['state']['state'] ?? '',
                'company_type' => $lsp['company_documents']['company_type'] ?? '',
                'pan' => $lsp['company_documents']['pan'] ?? '',
                'aadhar' => $lsp['company_documents']['aadhar'] ?? '',
                'itr_file' => $lsp['company_documents']['itr_file'] ?? '',
                'bank_statement' => $lsp['company_documents']['bank_statement'] ?? '',
                'rent_agreement' => $lsp['company_documents']['rent_agreement'] ?? '',
                'utility_bill' => $lsp['company_documents']['utility_bill'] ?? '',
                'partnership_deed' => $lsp['company_documents']['partnership_deed'] ?? '',
                'balance_sheet' => $lsp['company_documents']['balance_sheet'] ?? '',
                'certificate_of_incorporation' => $lsp['company_documents']['certificate_of_incorporation'] ?? '',
                'factory_address_proof' => $lsp['company_documents']['factory_address_proof'] ?? '',
                'aoa' => $lsp['company_documents']['aoa'] ?? '',
                'moa' => $lsp['company_documents']['moa'] ?? '',
                // 'company_documents' => $lsp['company_documents'] ?? null,
                'lsp_directors' => $lsp['lsp_directors'] ?? null,
            ];

            return $this->helper_controller->send_response('success', 'LSP fetched successfully', 200, $result);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }

    public function store_lsp(Request $request) {

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
            }

            if (!isset($inputs['mobile']) || empty($inputs['mobile'])) {
                $errors['mobile'] = "Mobile number is required";
                return $this->helper_controller->send_response('failed', $errors['mobile'], 422);
                $errorCount++;
            } elseif (!preg_match('/^\d{10}$/', $inputs['mobile'])) {
                $errors['mobile'] = "Mobile number must be 10 digits";
                return $this->helper_controller->send_response('failed', $errors['mobile'], 422);
                $errorCount++;
            } elseif (LoanServiceProvider::where('mobile', $inputs['mobile'])->exists()) {
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
                } elseif (LoanServiceProvider::where('mobile', $inputs['alternative_number'])
                                ->orWhere('alternative_number', $inputs['alternative_number'])
                                ->exists()
                    ) {
                        $errors['alternative_number'] = "Alternaative mobile number already exists";
                    return $this->helper_controller->send_response('failed',     $errors['alternative_number'], 422);
                    $errorCount++;
                }
            } else {
                $inputs['date_of_incorporation'] = null;
            }

            if (!isset($inputs['email']) || empty($inputs['email'])) {
                $errors['email'] = "Email is required";
                return $this->helper_controller->send_response('failed', $errors['email'], 422);
                $errorCount++;
            } elseif (!filter_var($inputs['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = "Invalid email format";
                return $this->helper_controller->send_response('failed', $errors['email'], 422);
                $errorCount++;
            } elseif (LoanServiceProvider::where('email', $inputs['email'])->exists()) {
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
            } elseif (LoanServiceProvider::where('pan', $inputs['pan_number'])->exists()) {
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
                // 'trade_certificate' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'lsp_loi' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
            
                // 'lsp_image_1' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                
                'security_cheque_1' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'security_cheque_2' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'security_cheque_3' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'security_cheque_4' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
            
                'office_premise_img' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                
                'pan' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'gst_certificate' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'utility_bill' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                
                'itr_file' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'bank_statement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'rent_agreement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'lsp_finayo_agreement' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',

                'state' => 'required|integer|exists:state_masters,id',
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
            
            $lsp_create = new LoanServiceProvider();
            $lsp_create->name = $inputs['name'] ?? '';
            $lsp_create->date_of_incorporation = $inputs['date_of_incorporation'] ?? null;
            $lsp_create->mobile = $inputs['mobile'] ?? '';
            $lsp_create->alternative_number = $inputs['alternative_number'] ?? '';
            $lsp_create->email = $inputs['email'] ?? '';
            $lsp_create->pan = $inputs['pan_number'] ?? '';
            $lsp_create->aadhar = $inputs['aadhar_number'] ?? '';
            $lsp_create->landmark = $inputs['landmark'] ?? '';
            $lsp_create->city = $inputs['city'] ?? '';
            $lsp_create->pincode = $inputs['pincode'] ?? '';
            $lsp_create->address_line_1 = $inputs['address_line_1'] ?? '';
            $lsp_create->address_line_2 = $inputs['address_line_2'] ?? '';
            $lsp_create->state = $inputs['state'] ?? null;
            if($lsp_create->save()) {
                $department = UserDepartments::where('department', 'Loan Service Provider')->first();
                $department_id = isset($department) ? $department->id : null;

                $user_types = UserType::where('user_type', 'Loan Service Provider')->first();
                $user_types_id = isset($user_types) ? $user_types->id : null;

                $adminUser = AdminUser::create([
                    'name' => $inputs['name'],
                    'mobile' => $inputs['mobile'],
                    'email' => $inputs['email'],
                    'password' => Hash::make($inputs['password']),
                    'department' => $department_id,
                    'user_type' => $user_types_id,
                ]);

                $lsp_unq = 'FIN-LSP-' . $lsp_create->id;
                $lsp_create->lsp_unq = $lsp_unq;
                $lsp_create->lsp_auth_id = $adminUser->id;
                $lsp_create->save();

                $base_dir = 'LSP/' . $lsp_unq;
                $file_mappings = [
                    // 'trade_certificate',
                    // 'lsp_image_1',
                    'security_cheque_1',
                    'security_cheque_2',
                    'security_cheque_3',
                    'security_cheque_4',
                    'office_premise_img',
                    'gst_certificate',
                    'lsp_finayo_agreement',
                ];
            
                $filePaths = []; // Array to store file paths

                foreach ($file_mappings as $fileKey) {
                    if (isset($inputs[$fileKey]) && $inputs[$fileKey]->isValid()) {
                        $filename = $fileKey . '.' . $inputs[$fileKey]->getClientOriginalExtension();
                        $path = $inputs[$fileKey]->storeAs($base_dir, $filename, 'public');
                        $filePaths[$fileKey] = 'storage/' . $path;

                        // Save the file path in the LoanServiceProvider model
                        $lsp_create->$fileKey = $filePaths[$fileKey];
                    }
                }
            
                // Save the LSP record again with the file paths
                $lsp_create->save();
                
                // Lender Company Document Need to create
                $lsp_company_documents = new LspCompanyDocuments();
                $lsp_company_documents->lsp_id =  $lsp_create->id;
                $lsp_company_documents->company_type =  $inputs['company_type'];
                
                $base_dir = 'LSP/' . $lsp_create->lsp_unq . '/company_documents';
                // Initialize an array to map document fields to the corresponding file inputs
                $document_mappings = [];

                if ($inputs['company_type'] == 'PARTNERSHIP' || $inputs['company_type'] == "PROPRIETORSHIP") {
                    $document_mappings = [
                        'pan',
                        'utility_bill',
                        'itr_file',
                        'bank_statement',
                        'rent_agreement',
                        'partnership_deed' => 'partnership_deed',
                    ];
                } elseif ($inputs['company_type'] == 'PRIVATE LIMITED') {
                    $document_mappings = [
                        'pan',
                        'utility_bill',
                        'itr_file',
                        'bank_statement',
                        'rent_agreement',
                        'balance_sheet' => 'balance_sheet',
                        'aoa' => 'aoa',
                        'moa' => 'moa',
                        'certificate_of_incorporation' => 'certificate_of_incorporation',
                        // 'factory_address_proof' => 'factory_address_proof',
                    ];
                }

                // Loop through each document field and store the files
                foreach ($document_mappings as $fileKey) {
                    if (isset($inputs[$fileKey]) && $inputs[$fileKey]->isValid()) {
                        $filename = $fileKey . '.' . $inputs[$fileKey]->getClientOriginalExtension();
                        $path = $inputs[$fileKey]->storeAs($base_dir, $filename, 'public');
                        $lsp_company_documents->$fileKey = 'storage/' . $path;
                    }
                }

                $lsp_company_documents->aadhar = '';
                $lsp_company_documents->save();

                $emailData = [
                    'name' => $request->name,
                    'admin_url' => 'http://uat.finayo.tech',
                    'user_id' => $request->email,
                    'password' => $request->password
                ];
                $viewName = 'emails.example';
                $subject = "Finayo Login Credential";
            
                // Mail::to($request->email)->send(new Mailer($viewName, $emailData, $subject));
    
                DB::commit(); // Commit the transaction

                return $this->helper_controller->send_response('success', 'LSP created successfully', 201, $lsp_create);
            } else {

                DB::rollBack(); // Rollback the transaction
                return $this->helper_controller->send_response('success', 'Failed to create LSP', 422);
            }
        } catch (Exception $e) {
            DB::rollBack(); // Rollback the transaction
            return $this->helper_controller->send_response('failed', "An unknown error occured: ". $e->getMessage(), 422);
        }
    }

    public function update_lsp(Request $request) {
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
                // 'date_of_incorporation' => 'nullable|date_format:Y-m-d|before_or_equal:today',
                'mobile' => 'required|digits:10|unique:loan_service_providers,mobile,' . $id,
                'alternative_number' => 'nullable|digits:10|different:mobile|unique:loan_service_providers,alternative_number,' . $id,
                'email' => 'required|email|unique:loan_service_providers,email,' . $id,
                'password' => 'nullable|string|min:8',
                'pan_number' => 'required|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|unique:loan_service_providers,pan,' . $id,
                // 'aadhar_number' => 'nullable|digits:12',
                'landmark' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-]+$/',
                'company_type' => 'required|in:PARTNERSHIP,PROPRIETORSHIP,PRIVATE LIMITED',
                'city' => 'required|regex:/^[a-zA-Z\s]+$/',
                'pincode' => 'required|digits:6',
                'address_line_1' => 'required|regex:/^[a-zA-Z0-9\s,.\-]+$/',
                'address_line_2' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-]+$/',

                // 'trade_certificate' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'lsp_loi' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
            
                // 'lsp_image_1' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                
                'security_cheque_1' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'security_cheque_2' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'security_cheque_3' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'security_cheque_4' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
            
                'office_premise_img' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',

                'lsp_finayo_agreement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'pan' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'itr_file' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'bank_statement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'gst_certificate' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'rent_agreement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'utility_bill' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'moa' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'aoa' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'certificate_of_incorporation' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'balance_sheet' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                'partnership_deed' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'icat_certificate' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'manufacturing_license' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',

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
        
            // Find existing LSP
            $lsp = LoanServiceProvider::find($id);
        
            if (!$lsp) {
                return $this->helper_controller->send_response('failed', 'LSP not found', 404);
            }

            $emailExists = AdminUser::where('id', '<>', $lsp->lsp_auth_id)
                            ->where('email', $request->email)
                            ->exists();

            if ($emailExists) {
                return $this->helper_controller->send_response('failed', 'User already exists with this email', 404);
            }
            
            $mobileExists = AdminUser::where('id', '<>', $lsp->lsp_auth_id)
                            ->where('mobile', $request->mobile)
                            ->exists();

            if ($mobileExists) {
                return $this->helper_controller->send_response('failed', 'User already exists with this mobile number', 404);
            }
        
            // Update LSP details
            $lsp->name = $inputs['name'] ?? '';
            $lsp->date_of_incorporation = $inputs['date_of_incorporation'] ?? null;
            $lsp->mobile = $inputs['mobile'] ?? '';
            $lsp->alternative_number = $inputs['alternative_number'] ?? $lsp->alternative_number;
            $lsp->email = $inputs['email'] ?? '';
            $lsp->pan = $inputs['pan_number'] ?? '';
            $lsp->aadhar = $inputs['aadhar_number'] ?? $lsp->aadhar;
            $lsp->landmark = $inputs['landmark'] ?? '';
            $lsp->city = $inputs['city'] ?? '';
            $lsp->pincode = $inputs['pincode'] ?? '';
            $lsp->address_line_1 = $inputs['address_line_1'] ?? '';
            $lsp->address_line_2 = $inputs['address_line_2'] ?? '';
            $lsp->state = $inputs['state'] ?? $lsp->state;
            $lsp->save();

            $adminUser = AdminUser::find($lsp->lsp_auth_id);
            if($adminUser) {
                $adminUser->name = $inputs['name'];
                $adminUser->mobile = $inputs['mobile'];
                $adminUser->email = $inputs['email'];
                $adminUser->save();
            } else{
                $department = UserDepartments::where('department', 'Loan Service Provider')->first();
                $department_id = isset($department) ? $department->id : null;
                
                $user_types = UserType::where('user_type', 'Loan Service Provider')->first();
                $user_types_id = isset($user_types) ? $user_types->id : null;

                $adminUser = AdminUser::create([
                    'name' => $inputs['name'],
                    'mobile' => $inputs['mobile'],
                    'email' => $inputs['email'],
                    'password' => isset($inputs['password']) ? Hash::make($inputs['password']) : '',
                    'department' => $department_id,
                    'user_type' => $user_types_id,
                ]);
                $lsp->lsp_auth_id = $adminUser->id;

                $emailData = [
                    'name' => $inputs['name'],
                    'admin_url' => 'https://uat.finayo.tech',
                    'user_id' => $inputs['email'],
                    'password' => $inputs['password']
                ];
                $viewName = 'emails.credential';
                $subject = "Finayo Login Credential";
            
                // Mail::to($inputs['email'])->send(new Mailer($viewName, $emailData, $subject));
            }
        
            $lsp_unq = 'FIN-LSP-' . $lsp->id;
        
            $base_dir = 'LSP/' . $lsp_unq;
            $file_mappings = [
                // 'trade_certificate',
                // 'lsp_image_1',
                'security_cheque_1',
                'security_cheque_2',
                'security_cheque_3',
                'security_cheque_4',
                'office_premise_img',
                'gst_certificate',
                'lsp_finayo_agreement',
            ];
        
            // Update file fields if new files are provided
            foreach ($file_mappings as $fileKey) {
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    if ($file->isValid()) {
                        $filename = $fileKey . '.' . $file->getClientOriginalExtension();
                        $path = $file->storeAs($base_dir, $filename, 'public');
                        $lsp->$fileKey = 'storage/' . $path;
                    }
                }   else {
                    if (isset($lsp->$fileKey)) {
                        $lsp->$fileKey = $lsp->$fileKey;
                    }
                }
            }
        
            $lsp->save();
        
            // Handle company-specific documents
            $lsp_company_documents = LspCompanyDocuments::where('lsp_id', $lsp->id)->first();
        
            if (!$lsp_company_documents) {
                $lsp_company_documents = new LspCompanyDocuments();
                $lsp_company_documents->lsp_id = $lsp->id;
            }
        
            $company_documents_dir = 'LSP/' . $lsp_unq . '/company_documents';
            $document_mappings = [];
        
            if ($inputs['company_type'] == 'PARTNERSHIP' || $inputs['company_type'] == 'PROPRIETORSHIP') {
                $document_mappings = [
                    'pan',
                    'utility_bill',
                    'itr_file',
                    'bank_statement',
                    'rent_agreement',
                    'partnership_deed' => 'partnership_deed',
                ];
            } elseif ($inputs['company_type'] == 'PRIVATE LIMITED') {
                $document_mappings = [
                    'pan',
                    'utility_bill',
                    'itr_file',
                    'bank_statement',
                    'rent_agreement',
                    'balance_sheet' => 'balance_sheet',
                    'aoa' => 'aoa',
                    'moa' => 'moa',
                    'certificate_of_incorporation' => 'certificate_of_incorporation',
                    // 'factory_address_proof' => 'factory_address_proof',
                ];
            } else {
                return $this->helper_controller->send_response('failed', "Invalid company type. Valid types are [PARTNERSHIP, PROPRIETORSHIP, PRIVATE LIMITED]", 422);
            }
        
            foreach ($document_mappings as $fileKey) {
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    if ($file->isValid()) {
                        $filename = $fileKey . '.' . $file->getClientOriginalExtension();
                        $path = $file->storeAs($company_documents_dir, $filename, 'public');
                        $lsp_company_documents->$fileKey = 'storage/' . $path;
                    }
                } else {
                    // If no new file is uploaded, retain the old file
                    if (isset($lsp_company_documents->$fileKey)) {
                        $lsp_company_documents->$fileKey = $lsp_company_documents->$fileKey;
                    }
                }
            }
            
            $lsp_company_documents->aadhar = '';
        
            $lsp_company_documents->save();
        
            DB::commit();
            return $this->helper_controller->send_response('success', 'LSP record updated successfully', 200, $lsp);
        } catch (Exception $e) {

            DB::rollBack(); // Rollback the transaction
            return $this->helper_controller->send_response('failed', 'An error occurred: '. $e->getMessage(), 422);
        }
    }

    public function delete($id)
    {
        $loan_service_provider = LoanServiceProvider::find($id);

        if (!$loan_service_provider || $loan_service_provider->count() < 1) {
            return $this->helper_controller->send_response('success', 'Loan Service Provider not found', 200);
        }

        try {
            date_default_timezone_set('Asia/kolkata');
            $loan_service_provider->is_deleted = true;
            $loan_service_provider->delete_date_time = date('Y-m-d H:i:s');
            $loan_service_provider->save();

            return $this->helper_controller->send_response('success', 'Loan Service Provider deleted successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occured", 422);
        }
    }

    public function update_status($id)
    {
        try {
            $lsp = LoanServiceProvider::find($id);
            if(!$lsp) {
                return $this->helper_controller->send_response('failed', "Loan service provider not found", 422);
            }

            if($lsp->is_active == true) {
                $status = false;
            } else {
                $status = true;
            }
            $lsp->is_active = $status;
            $lsp->save();

            $userDetails = AdminUser::find($lsp->lsp_auth_id);
            if($userDetails) {
                $userDetails->is_active = $status;
                $userDetails->save();
            }
            
            return $this->helper_controller->send_response('success', 'Loan service provider status updated', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occured", 422);
        }
    }
}
