<?php

namespace App\Http\Controllers;

use App\Mail\Mailer;
use App\Models\AdminUser;
use App\Models\LenderServiceProvider;
use App\Models\UserDepartments;
use App\Models\UserType;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class LenderServiceProviderController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helperController)
    {
        $this->helper_controller = $helperController;
    }

    public function index(Request $request)
    {
        // Validation rules
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255|regex:/^[a-zA-Z0-9\s\&\.\-]+$/',
            'mobile' => 'nullable|digits_between:1,10',
            'alternative_number' => 'nullable|digits_between:1,10',
            'email' => 'nullable|email|max:255',
            'pan' => 'nullable|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',
            'aadhar' => 'nullable|digits_between:1,12',
            'gst' => 'nullable|regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/',
            'number_of_records' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1'
        ]);
        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            // Pagination parameters
            $numberOfRecords = $request->input('number_of_records', 10); // Default to 10 records per page
            $page = $request->input('page', 1);
            
            // Filters
            $name = $request->input('name');
            $mobile = $request->input('mobile');
            $alternativeNumber = $request->input('alternative_number');
            $email = $request->input('email');
            $pan = $request->input('pan');
            $aadhar = $request->input('aadhar');
            $gst = $request->input('gst');
            
            // Query builder with filters
            $query = LenderServiceProvider::with(['user:id,name']);
            
            if ($name) {
                $name = strtolower($name);
                $query->whereRaw('LOWER(name) LIKE ?', ['%' . $name . '%']);
            }
            
            if ($email) {
                $email = strtolower($email);
                $query->whereRaw('LOWER(email) LIKE ?', ['%'. $email .'%']);
            }
            
            if ($mobile) {
                $query->where('mobile', 'LIKE', "%$mobile%");
            }
            
            if ($alternativeNumber) {
                $query->where('alternative_number', 'LIKE', "%$alternativeNumber%");
            }
            
            if ($pan) {
                $query->where('pan', 'LIKE', "%$pan%");
            }
            
            if ($aadhar) {
                $query->where('aadhar', 'LIKE', "%$aadhar%");
            }
            
            if ($gst) {
                $query->where('gst', 'LIKE', "%$gst%");
            }
            
            // Handle pagination
            $lenderServiceProviders = $query->orderBy('id', 'desc')->paginate($numberOfRecords, ['*'], 'page', $page);
            
            // Check if no results found
            if (!$lenderServiceProviders || $lenderServiceProviders->count() < 1) {
                return $this->helper_controller->send_response('failed', 'No records found', 200);
            }
            
            // Return success response with paginated data
            return $this->helper_controller->send_response('success', 'Lender Service Providers fetched successfully', 200, $lenderServiceProviders);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function create(Request $request)
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|regex:/^[a-zA-Z0-9\s\&\.\-]+$/',
                'mobile' => [
                    'required',
                    'digits:10',
                    function ($attribute, $value, $fail) {
                        $existsInTable1 = DB::table('admin_users')->where('mobile', $value)->exists();
                        $existsInTable2 = DB::table('lender_service_providers')->where('mobile', $value)->exists();
            
                        if ($existsInTable1 || $existsInTable2) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'alternative_number' => 'nullable|digits:10|different:mobile',
                'email' => [
                    'required',
                    'email',
                    'max:255',
                    function ($attribute, $value, $fail) {
                        $existsInTable1 = DB::table('admin_users')->where('email', $value)->exists();
                        $existsInTable2 = DB::table('lender_service_providers')->where('email', $value)->exists();
            
                        if ($existsInTable1 || $existsInTable2) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'password' => 'required|string|min:8',
                'pan' => [
                    'nullable',
                    'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',
                    function ($attribute, $value, $fail) {
                        $existsInTable = DB::table('lender_service_providers')->where('pan', $value)->exists();
            
                        if ($existsInTable) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'aadhar' => [
                    'nullable',
                    'digits:12',
                    function ($attribute, $value, $fail) {
                        $existsInTable = DB::table('lender_service_providers')->where('aadhar', $value)->exists();
            
                        if ($existsInTable) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'pincode' => 'nullable|digits:6',
                'company_type' => 'required|string|max:100',
                'gst' => [
                    'nullable',
                    'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/',
                    function ($attribute, $value, $fail) {
                        $existsInTable = DB::table('lender_service_providers')->where('gst', $value)->exists();
            
                        if ($existsInTable) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'gst_certificate' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'lender_lsp_agreement' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'finayo_lsp_agreement' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'aoa' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'moa' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'certificate_of_incorporation' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'address_proof_doc' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

        
            $lsp_unq = 'LSP-' . str_pad(LenderServiceProvider::max('id') + 1, 4, '0', STR_PAD_LEFT);

            $department = UserDepartments::where('department', 'Lender Service Provider')->first();
            $department_id = isset($department) ? $department->id : null;
            
            $user_types = UserType::where('user_type', 'Lender Service Provider')->first();
            $user_types_id = isset($user_types) ? $user_types->id : null;

            $adminUser = AdminUser::create([
                'name' => $request->name,
                'mobile' => $request->mobile,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'department' => $department_id,
                'user_type' => $user_types_id,
            ]);

            $lsp = new LenderServiceProvider();

            $lsp->lsp_unq               = $lsp_unq;
            $lsp->name                  = $request->name;
            $lsp->mobile                = $request->mobile;
            $lsp->alternative_number    = $request->alternative_number ?? '';
            $lsp->email                 = $request->email ?? '';
            $lsp->pan                   = $request->pan ?? '';
            $lsp->aadhar                = $request->aadhar ?? '';
            $lsp->landmark              = $request->landmark ?? '';
            $lsp->city                  = $request->city ?? '';
            $lsp->pincode               = $request->pincode ?? '';
            $lsp->address_line_1        = $request->address_line_1 ?? '';
            $lsp->address_line_2        = $request->address_line_2 ?? '';
            $lsp->company_type          = $request->company_type ?? '';
            $lsp->gst                   = $request->gst ?? '';
            $lsp->user_id               = $request->user()->id;
            $lsp->lsp_auth_id           = $adminUser->id;

            // Handle file uploads
            $fileFields = [
                'gst_certificate',
                'lender_lsp_agreement',
                'finayo_lsp_agreement',
                'aoa',
                'moa',
                'certificate_of_incorporation',
                'address_proof_doc'
            ];

            foreach ($fileFields as $fileKey) {
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    $filename = $lsp_unq . '/' . $fileKey . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs('LenderServiceProviders', $filename, 'public');
                    $lsp->$fileKey = 'storage/' . $path;
                }
            }

            $lsp->save();

            $emailData = [
                'name' => $request->name,
                'admin_url' => 'http://uat.finayo.tech',
                'user_id' => $request->email,
                'password' => $request->password,
            ];
            $viewName = 'emails.example';
            $subject = "Finayo Login Credential";
            
            Mail::to($request->email)->send(new Mailer($viewName, $emailData, $subject));
            
            DB::commit(); // Commit the transaction

            return $this->helper_controller->send_response('success', 'Lender Service Provider created successfully', 200, $lsp);

        } catch (Exception $e) {
            DB::rollBack(); // Rollback the transaction
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function update(Request $request)
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer',
                'name' => 'required|string|max:255|regex:/^[a-zA-Z0-9\s\&\.\-]+$/',
                'mobile' => [
                    'required',
                    'digits:10',
                    function ($attribute, $value, $fail) use ($request) {
                        $existsInTable1 = DB::table('lender_service_providers')->where('mobile', $value)
                                            ->where('id', '<>', $request->id)->exists();

                        if ($existsInTable1) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'alternative_number' => 'nullable|digits:10|different:mobile',
                'email' => [
                    'required',
                    'email',
                    'max:255',
                    function ($attribute, $value, $fail) use ($request) {
                        $existsInTable1 = DB::table('lender_service_providers')->where('email', $value)
                                            ->where('id', '<>', $request->id)->exists();

                        if ($existsInTable1) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'password' => 'nullable|string|min:8',
                'pan' => [
                    'nullable',
                    'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',
                    function ($attribute, $value, $fail) use ($request) {
                        $existsInTable = DB::table('lender_service_providers')->where('pan', $value)
                                        ->where('id', '<>', $request->id)->exists();

                        if ($existsInTable) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'aadhar' => [
                    'nullable',
                    'digits:12',
                    function ($attribute, $value, $fail) use ($request) {
                        $existsInTable = DB::table('lender_service_providers')->where('aadhar', $value)
                                        ->where('id', '<>', $request->id)->exists();

                        if ($existsInTable) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'pincode' => 'nullable|digits:6',
                'company_type' => 'required|string|max:100',
                'gst' => [
                    'nullable',
                    'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/',
                    function ($attribute, $value, $fail) use ($request) {
                        $existsInTable = DB::table('lender_service_providers')->where('gst', $value)
                                        ->where('id', '<>', $request->id)->exists();

                        if ($existsInTable) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'gst_certificate' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'lender_lsp_agreement' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'finayo_lsp_agreement' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'aoa' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'moa' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'certificate_of_incorporation' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'address_proof_doc' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $lsp = LenderServiceProvider::find($request->id);

            if (!$lsp || $lsp->count() < 1) {
                return $this->helper_controller->send_response('failed', 'Lender Service Provider not found', 404);
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

            $lsp->name              = $request->name ?? $lsp->name;
            $lsp->mobile            = $request->mobile ?? $lsp->mobile;
            $lsp->alternative_number = $request->alternative_number ?? $lsp->alternative_number;
            $lsp->email             = $request->email ?? $lsp->email;
            $lsp->pan               = $request->pan ?? $lsp->pan;
            $lsp->aadhar            = $request->aadhar ?? $lsp->aadhar;
            $lsp->landmark          = $request->landmark ?? $lsp->landmark;
            $lsp->city              = $request->city ?? $lsp->city;
            $lsp->pincode           = $request->pincode ?? $lsp->pincode;
            $lsp->address_line_1    = $request->address_line_1 ?? $lsp->address_line_1;
            $lsp->address_line_2    = $request->address_line_2 ?? $lsp->address_line_2;
            $lsp->company_type      = $request->company_type ?? $lsp->company_type;
            $lsp->gst               = $request->gst ?? $lsp->gst;
            $lsp->is_deleted        = $request->is_deleted ?? $lsp->is_deleted;
            $lsp->user_id           = $request->user()->id;

            // Handle file uploads
            $fileFields = [
                'gst_certificate',
                'lender_lsp_agreement',
                'finayo_lsp_agreement',
                'aoa',
                'moa',
                'certificate_of_incorporation',
                'address_proof_doc'
            ];

            foreach ($fileFields as $fileKey) {
                if ($request->hasFile($fileKey)) {
                    $file       = $request->file($fileKey);
                    $filename   = $lsp->lsp_unq . '/' . $fileKey . '.' . $file->getClientOriginalExtension();
                    $path       = $file->storeAs('LenderServiceProviders', $filename, 'public');
                    $lsp->$fileKey = 'storage/' . $path;
                } else {
                    // Use existing file path from DB if not provided
                    $lsp->$fileKey = $lsp->$fileKey;
                }
            }

            $adminUser = AdminUser::find($lsp->lsp_auth_id);
            if($adminUser) {
                $adminUser->name = $request->name;
                $adminUser->mobile = $request->mobile;
                $adminUser->email = $request->email;
                $adminUser->save();
            } else{
                $department = UserDepartments::where('department', 'Lender Service Provider')->first();
                $department_id = isset($department) ? $department->id : null;
                
                $user_types = UserType::where('user_type', 'Lender Service Provider')->first();
                $user_types_id = isset($user_types) ? $user_types->id : null;

                $adminUser = AdminUser::create([
                    'name' => $request->name,
                    'mobile' => $request->mobile,
                    'email' => $request->email,
                    'password' => isset($request->password) ? Hash::make($request->password) : '',
                    'department' => $department_id,
                    'user_type' => $user_types_id,
                ]);
                $lsp->lsp_auth_id = $adminUser->id;

                $emailData = [
                    'name' => $request->name,
                    'admin_url' => 'http://uat.finayo.tech',
                    'user_id' => $request->email,
                    'password' => $request->password,
                ];
                $viewName = 'emails.example';
                $subject = "Finayo Login Credential";
            
                Mail::to($request->email)->send(new Mailer($viewName, $emailData, $subject));
            }

            $lsp->save();

            DB::commit(); // Commit the transaction
            return $this->helper_controller->send_response('success', 'Lender Service Provider updated successfully', 200, $lsp);

        } catch (Exception $e) {
            DB::rollBack(); // Rollback the transaction
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function view($id)
    {
        try {
            $lenderservice_provider = LenderServiceProvider::with(['user:id,name'])->find($id);

            if (!$lenderservice_provider || $lenderservice_provider->count() < 1) {
                return $this->helper_controller->send_response('success', 'Lender Service Provider not found', 200);
            }

            return $this->helper_controller->send_response('success', 'Lender Service Provider fetched successfully', 200, $lenderservice_provider);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function delete($id)
    {
        $lenderservice_provider = LenderServiceProvider::find($id);

        if (!$lenderservice_provider || $lenderservice_provider->count() < 1) {
            return $this->helper_controller->send_response('success', 'Lender Service Provider not found', 200);
        }

        try {
            $lenderservice_provider->is_deleted = true;
            $lenderservice_provider->save();

            return $this->helper_controller->send_response('success', 'Lender Service Provider deleted successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function update_status($id)
    {
        DB::beginTransaction();
        try {
            $lsp = LenderServiceProvider::find($id);
            if(!$lsp) {
                return $this->helper_controller->send_response('failed', "Lender service provider not found", 422);
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
            
            DB::commit(); // Commit the transaction
            return $this->helper_controller->send_response('success', 'Lender service provider status updated', 200);
        } catch (Exception $e) {
            DB::rollBack(); // Rollback the transaction
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }
}
