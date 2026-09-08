<?php

namespace App\Http\Controllers;

use App\Mail\Mailer;
use App\Models\AdminUser;
use App\Models\LenderCharges;
use App\Models\Lenders;
use App\Models\UserDepartments;
use App\Models\UserType;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;


class LenderController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helperController)
    {
        $this->helper_controller = $helperController;
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'number_of_records' => 'integer|min:1',
            'start_date' => 'date|nullable',
            'end_date' => 'date|nullable|after_or_equal:start_date',
            'name' => [
                'nullable',
                'regex:/^[a-zA-Z\s.&]+$/'
            ],
            'company_type' => 'string|nullable',
            'complience_officer_name' => [
                'nullable',
                'regex:/^[a-zA-Z\s]+$/'
            ],
            'complience_officer_mobile' => [
                'nullable',
                'regex:/^[0-9]{10}$/'
            ],
            'complience_officer_email' => 'string|nullable|email'
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $page = $request->get('page', 1);
            $numberOfRecords = $request->get('number_of_records', 10);
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');

            $query = Lenders::with(['cp_state:id,state', 'rg_state:id,state']);

            // Apply date filters
            if ($startDate) {
                $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
                $query->where('created_at', '>=', $startDate);
            }
            if ($endDate) {
                $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
                $query->where('created_at', '<=', $endDate);
            }

            // Apply case-insensitive filters using whereRaw for name, company type, compliance officer details
            if ($request->filled('name')) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($request->input('name')) . '%']);
            }
            if ($request->filled('company_type')) {
                // $query->whereRaw('LOWER(company_type) LIKE ?', ['%' . strtolower($request->input('company_type')) . '%']);
                $query->where('company_type', $request->input('company_type'));
            }
            if ($request->filled('complience_officer_name')) {
                $query->whereRaw('LOWER(complience_officer_name) LIKE ?', ['%' . strtolower($request->input('complience_officer_name')) . '%']);
            }
            if ($request->filled('complience_officer_mobile')) {
                $query->where('complience_officer_mobile', 'like', '%' . $request->input('complience_officer_mobile') . '%');
            }
            if ($request->filled('complience_officer_email')) {
                $query->whereRaw('LOWER(complience_officer_email) LIKE ?', ['%' . strtolower($request->input('complience_officer_email')) . '%']);
            }

            // Paginate results
            $lenders = $query->orderBy('created_at', 'desc')->paginate($numberOfRecords, ['*'], 'page', $page);

            $lenders->getCollection()->transform(function ($lender) {
                $lender->corporate_address_state_name = $lender->cp_state->state ?? null;
                $lender->registered_address_state_name = $lender->rg_state->state ?? null;
                return $lender;
            });

            return $this->helper_controller->send_response('success', 'Lenders retrieved successfully', 200, $lenders);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 500);
        }
    }

    public function lender_screener() {
        $records = Lenders::select(['id', 'name'])->where('is_deleted', false)->orderBy('name', 'asc')->get();
        return $this->helper_controller->send_response('success', 'Manufacturer type fetched successfully', 200, $records);
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try{
            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'regex:/^[a-zA-Z\s.&]+$/'
                ],
                'agreement_doc' => 'required|mimes:jpg,jpeg,png,pdf|max:5120',
                'coi_doc' => 'required|mimes:jpg,jpeg,png,pdf|max:5120',
                'nbfc_certificate' => 'required|mimes:jpg,jpeg,png,pdf|max:5120',
                'company_type' => 'required|string|max:100',
                'complience_officer_name' => [
                    'required',
                    'regex:/^[a-zA-Z\s]+$/'
                ],
                'complience_officer_mobile' => [
                    'required',
                    'regex:/^[0-9]{10}$/',
                    function ($attribute, $value, $fail) {
                        // Check if the mobile number is unique in admin_users table
                        $exists_user = DB::table('admin_users')
                            ->where('mobile', $value)
                            ->exists();

                        $exists = DB::table('lenders')
                            ->where('complience_officer_mobile', $value)
                            ->exists();
                        
                        if ($exists || $exists_user) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'complience_officer_email' => [
                    'required',
                    'string',
                    'email',
                    'max:255',
                    function ($attribute, $value, $fail) {
                        // Check if the mobile number is unique in admin_users table
                        $exists_user = DB::table('admin_users')
                            ->where('email', $value)
                            ->exists();

                        $exists = DB::table('lenders')
                            ->where('complience_officer_email', $value)
                            ->exists();
                        
                        if ($exists || $exists_user) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'password' => 'required|string|min:8',
                'lending_roi' => 'required|numeric|between:0,999999.99',
                'seed_rate' => 'required|numeric|between:0,999999.99',
                'charge_details' => 'nullable|array',
                'charge_details.*.charge_head' => 'nullable|string|max:255',
                'charge_details.*.charge_value' => 'nullable|integer',
                'charge_details.*.finayo_share' => 'nullable|numeric|between:0,999999.99',
                'charge_details.*.nbfc_share' => 'nullable|numeric|between:0,999999.99',
                'token_id' => 'nullable|string|max:100',
                'corporate_address_1' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
                'corporate_address_2' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
                'corporate_address_city' => 'nullable|regex:/^[a-zA-Z0-9\s]+$/',
                'corporate_address_state' => 'nullable|integer|exists:state_masters,id',
                'corporate_address_pin' => 'nullable|digits:6',
                'corporate_address_landmark' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
                'registered_office_address_1' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
                'registered_office_address_2' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
                'registered_office_city' => 'nullable|regex:/^[a-zA-Z0-9\s]+$/',
                'registered_office_state' => 'nullable|integer|exists:state_masters,id',
                'registered_office_pin' => 'nullable|digits:6',
                'registered_office_landmark' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

        
            $data = $request->all();
            $data['user_id'] = auth()->user()->id;
            $data['processing_fee_amount'] = 0;
            $lender = Lenders::create($data);

            $department = UserDepartments::where('department', 'Lender')->first();
            $department_id = isset($department) ? $department->id : null;
            
            $user_types = UserType::where('user_type', 'Lender')->first();
            $user_types_id = isset($user_types) ? $user_types->id : null;

            $adminUser = AdminUser::create([
                'name' => $request->complience_officer_name,
                'mobile' => $request->complience_officer_mobile,
                'email' => $request->complience_officer_email,
                'password' => Hash::make($request->password),
                'department' => $department_id,
                'user_type' => $user_types_id,
            ]);

            $lender->lender_unq = 'FIN-LEND-' . str_pad($lender->id, 3, '0', STR_PAD_LEFT);
            $lender->lender_auth_id = $adminUser->id;
            $lender->corporate_address_1 = $request->corporate_address_1 ?? null;
            $lender->corporate_address_2 = $request->corporate_address_2 ?? null;
            $lender->corporate_address_city = $request->corporate_address_city ?? null;
            $lender->corporate_address_state = $request->corporate_address_state ?? null;
            $lender->corporate_address_pin = $request->corporate_address_pin ?? null;
            $lender->corporate_address_landmark = $request->corporate_address_landmark ?? null;
            $lender->registered_office_address_1 = $request->registered_office_address_1 ?? null;
            $lender->registered_office_address_2 = $request->registered_office_address_2 ?? null;
            $lender->registered_office_city = $request->registered_office_city ?? null;
            $lender->registered_office_state = $request->registered_office_state ?? null;
            $lender->registered_office_pin = $request->registered_office_pin ?? null;
            $lender->registered_office_landmark = $request->registered_office_landmark ?? null;
            $lender->save();

            $fileKeys = ['agreement_doc', 'coi_doc', 'nbfc_certificate'];

            foreach ($fileKeys as $fileKey) {
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    $filename = $lender->lender_unq . '/' . $fileKey . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs('Lenders', $filename, 'public');
                    $data[$fileKey] = 'storage/' . $path;
                }
            }
            $lender->update($data);

            // Handle lender charges
            if (!empty($data['charge_details'])) {
                $userId = auth()->user()->id;
                $ip = $this->helper_controller->get_user_ip();
                $tokenId = !empty($data['token_id']) ? $data['token_id'] : date('dmY') . (LenderCharges::max('id') + 1);

                if (!empty($data['token_id'])) {
                    LenderCharges::where('token_id', $data['token_id'])
                        ->update(['status' => 'HISTORY']);
                }

                foreach ($data['charge_details'] as $charge) {
                    LenderCharges::create([
                        'token_id' => $tokenId,
                        'lender_id' => $lender->id,
                        'charge_head' => $charge['charge_head'] ?? '',
                        'charge_value' => $charge['charge_value'] ?? 0,
                        'finayo_share' => $charge['finayo_share'] ?? 0,
                        'nbfc_share' => $charge['nbfc_share'] ?? 0,
                        'status' => 'ACTIVE',
                        'ip' => $ip,
                        'user_id' => $userId,
                    ]);
                }
            }

            $emailData = [
                'name' => $request->complience_officer_name,
                'admin_url' => 'http://uat.finayo.tech',
                'user_id' => $request->complience_officer_email,
                'password' => $request->password,
            ];
            $viewName = 'emails.example';
            $subject = "Finayo Login Credential";
            
            // send mail
            // Mail::to($request->complience_officer_email)->send(new Mailer($viewName, $emailData, $subject));

            DB::commit(); // Commit the transaction

            return $this->helper_controller->send_response('success', 'Lender and charges created successfully', 200, $lender);
        } catch (Exception $e) {
            DB::rollBack(); // Rollback the transaction
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }


    // public function show($id)
    // {
    //     try {
    //         $lender = Lenders::find($id);
    //         if (!$lender) {
    //             return $this->helper_controller->send_response('failed', 'No lenders found', 200);
    //         }

    //         return $this->helper_controller->send_response('success', 'Lender retrieved successfully', 200, $lender);
    //     } catch (Exception $e) {
    //         return $this->helper_controller->send_response('failed', $e->getMessage(), 500);
    //     }
    // }

    public function show($id)
    {
        try {
            // Retrieve the lender along with their active charges
            $lender = Lenders::with(['charges' => function ($query) {
                $query->where('status', 'ACTIVE')
                    ->select('id', 'lender_id', 'charge_head', 'charge_type', 'charge_value', 'finayo_share', 'nbfc_share');
            }])->find($id);

            if (!$lender) {
                return $this->helper_controller->send_response('failed', 'No lenders found', 200);
            }

            return $this->helper_controller->send_response('success', 'Lender retrieved successfully', 200, $lender);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 500);
        }
    }

    public function update(Request $request)
    {
        DB::beginTransaction();
        try{
            $inputs = $request->all();
            date_default_timezone_set('Asia/Kolkata');
            if(!isset($inputs['id']) || empty($inputs['id'])) {
                return $this->helper_controller->send_response('failed', "Id is required", 422);
            }
            $id = $inputs['id'];

            $validator = Validator::make($request->all(), [
                'id' => 'required|integer',
                'name' => [
                    'nullable',
                    'regex:/^[a-zA-Z\s.&]+$/'
                ],
                'agreement_doc' => 'nullable|mimes:jpg,jpeg,png,pdf|max:5120',
                'coi_doc' => 'nullable|mimes:jpg,jpeg,png,pdf|max:5120',
                'nbfc_certificate' => 'nullable|mimes:jpg,jpeg,png,pdf|max:5120',
                'company_type' => 'nullable|string|max:100',
                'complience_officer_name' => [
                    'nullable',
                    'regex:/^[a-zA-Z\s]+$/'
                ],
                'complience_officer_mobile' => [
                    'nullable',
                    'regex:/^[0-9]{10}$/',
                    function ($attribute, $value, $fail) use($id) {
                        // Check if the mobile number is unique in admin_users table
                        $lender_exists = DB::table('lenders')
                            ->where('complience_officer_mobile', $value)
                            ->where('id', '<>', $id)
                            ->exists();
                        if($lender_exists) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'complience_officer_email' => [
                    'nullable',
                    'string',
                    'email',
                    'max:255',
                    function ($attribute, $value, $fail) use($id) {
                        // Check if the mobile number is unique in admin_users table
                        $lender_exists = DB::table('lenders')
                            ->where('complience_officer_email', $value)
                            ->where('id', '<>', $id)
                            ->exists();
                        if($lender_exists) {

                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
                'password' => 'nullable|string|min:8',
                'lending_roi' => 'nullable|numeric|between:0,999999.99',
                'seed_rate' => 'nullable|numeric|between:0,999999.99',
                'charge_details' => 'nullable|array',
                'charge_details.*.charge_head' => 'nullable|string|max:255',
                'charge_details.*.charge_value' => 'nullable|integer',
                'charge_details.*.finayo_share' => 'nullable|numeric|between:0,999999.99',
                'charge_details.*.nbfc_share' => 'nullable|numeric|between:0,999999.99',
                'token_id' => 'nullable|string|max:100',
                'corporate_address_1' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
                'corporate_address_2' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
                'corporate_address_city' => 'nullable|regex:/^[a-zA-Z0-9\s]+$/',
                'corporate_address_state' => 'nullable|integer|exists:state_masters,id',
                'corporate_address_pin' => 'nullable|digits:6',
                'corporate_address_landmark' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
                'registered_office_address_1' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
                'registered_office_address_2' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
                'registered_office_city' => 'nullable|regex:/^[a-zA-Z0-9\s]+$/',
                'registered_office_state' => 'nullable|integer|exists:state_masters,id',
                'registered_office_pin' => 'nullable|digits:6',
                'registered_office_landmark' => 'nullable|regex:/^[a-zA-Z0-9\s,.\-&]+$/',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $lender = Lenders::find($id);
            if (!$lender) {
                return $this->helper_controller->send_response('failed', 'No lender found', 404);
            }

            $emailExists = AdminUser::where('id', '<>', $lender->lender_auth_id)
                            ->where('email', $request->complience_officer_email)
                            ->exists();

            if ($emailExists) {
                return $this->helper_controller->send_response('failed', 'User already exists with this email', 404);
            }
            
            $mobileExists = AdminUser::where('id', '<>', $lender->lender_auth_id)
                ->where('mobile', $request->complience_officer_mobile)
                ->exists();

            if ($mobileExists) {
                return $this->helper_controller->send_response('failed', 'User already exists with this mobile number', 404);
            }

            $data = $request->all();
            $data['modified_by'] = auth()->user()->id;
            $fileKeys = ['agreement_doc', 'coi_doc', 'nbfc_certificate'];

            foreach ($fileKeys as $fileKey) {
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    $filename = $lender->lender_unq . '/' . $fileKey . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs('Lenders', $filename, 'public');
                    $data[$fileKey] = 'storage/' . $path;
                } else {
                    $data[$fileKey] = $lender->{$fileKey};
                }
            }

            $adminUser = AdminUser::find($lender->lender_auth_id);
            if($adminUser) {
                $adminUser->name = $request->complience_officer_name;
                $adminUser->mobile = $request->complience_officer_mobile;
                $adminUser->email = $request->complience_officer_email;
                $adminUser->save();
            } else{
                $department = UserDepartments::where('department', 'Lender')->first();
                $department_id = isset($department) ? $department->id : null;
                
                $user_types = UserType::where('user_type', 'Lender')->first();
                $user_types_id = isset($user_types) ? $user_types->id : null;

                $adminUser = AdminUser::create([
                    'name' => $request->complience_officer_name,
                    'mobile' => $request->complience_officer_mobile,
                    'email' => $request->complience_officer_email,
                    'password' => isset($request->password) ? Hash::make($request->password) : '',
                    'department' => $department_id,
                    'user_type' => $user_types_id,
                ]);
                $lender->lender_auth_id = $adminUser->id;

                $emailData = [
                    'name' => $request->complience_officer_name,
                    'admin_url' => 'http://uat.finayo.tech',
                    'user_id' => $request->complience_officer_email,
                    'password' => $request->password,
                ];
                $viewName = 'emails.example';
                $subject = "Finayo Login Credential";
            
                // Mail::to($request->complience_officer_email)->send(new Mailer($viewName, $emailData, $subject));
            }

            $lender->update($data);

            // Handle updating lender charges
            if (!empty($data['charge_details'])) {
                $userId = auth()->user()->id;
                $ip = $this->helper_controller->get_user_ip();
                $tokenId = !empty($data['token_id']) ? $data['token_id'] : date('dmY') . (LenderCharges::max('id') + 1);

                if (!empty($data['token_id'])) {
                    LenderCharges::where('token_id', $data['token_id'])
                        ->update(['status' => 'HISTORY']);
                }

                foreach ($data['charge_details'] as $charge) {
                    LenderCharges::create([
                        'token_id' => $tokenId,
                        'lender_id' => $lender->id,
                        'charge_head' => $charge['charge_head'] ?? '',
                        'charge_type' => '',
                        'charge_value' => $charge['charge_value'] ?? 0,
                        'finayo_share' => $charge['finayo_share'] ?? 0,
                        'nbfc_share' => $charge['nbfc_share'] ?? 0,
                        'status' => 'ACTIVE',
                        'ip' => $ip,
                        'user_id' => $userId,
                    ]);
                }
            }

            DB::commit(); // Commit the transaction

            return $this->helper_controller->send_response('success', 'Lender updated successfully', 200, $lender);
        } catch (Exception $e) {

            DB::rollBack(); // Rollback the transaction
            return $this->helper_controller->send_response('failed', $e->getMessage(), 500);
        }
    }


    public function destroy($id)
    {
        try {
            $lender = Lenders::find($id);
            if (!$lender) {
                return $this->helper_controller->send_response('failed', 'No lenders found', 200);
            }

            $lender->is_deleted = 1;
            $lender->delete_date_time = now();
            $lender->save();

            return $this->helper_controller->send_response('success', 'Lender deleted successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 500);
        }
    }

    public function store_lender_charges(Request $request)
    {
        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'lender_id' => 'required|integer',
                'charge_details' => 'required|array',
                'charge_details.*.charge_head' => 'required|string|max:255',
                'charge_details.*.charge_value' => 'required|integer',
                'charge_details.*.finayo_share' => 'required|numeric|between:0,999999.99',
                'charge_details.*.nbfc_share' => 'required|numeric|between:0,999999.99',
                'token_id' => 'nullable|string|max:100'
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $validated = $validator->validated();
            $userId = auth()->user()->id; // assuming you have authentication
            $ip = $this->helper_controller->get_user_ip();

            if (!empty($validated['token_id'])) {
                LenderCharges::where('token_id', $validated['token_id'])
                    ->update(['status' => 'HISTORY']);
                
                // Generate a new token in the format "dmY.primary_key"
                $tokenId = date('dmY') . (LenderCharges::max('id') + 1);
            } else {
                // Generate a unique token for new records
                $tokenId = date('dmY') . (LenderCharges::max('id') + 1);
            }

            // Create new records
            foreach ($validated['charge_details'] as $charge) {
                LenderCharges::create([
                    'token_id' => $tokenId,
                    'lender_id' => $validated['lender_id'],
                    'charge_head' => $charge['charge_head'] ?? '',
                    'charge_type' => '',
                    'charge_value' => $charge['charge_value'] ?? 0,
                    'finayo_share' => $charge['finayo_share'] ?? 0,
                    'nbfc_share' => $charge['nbfc_share'] ?? 0,
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

    public function getActiveCharges($lender_id)
    {
        try {
            $charges = LenderCharges::where('lender_id', $lender_id)
                ->where('status', 'ACTIVE')
                ->with([
                    'lender:id,name'
                ])
                ->get();

            if (!$charges) {
                return $this->helper_controller->send_response('failed', 'No active charges found for this manufacturer.', 200);
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
            $lender = Lenders::find($id);
            if(!$lender) {
                return $this->helper_controller->send_response('failed', "Lender not found", 422);
            }

            if($lender->is_active == true) {
                $status = false;
            } else {
                $status = true;
            }
            $lender->is_active = $status;
            $lender->save();

            $userDetails = AdminUser::find($lender->lender_auth_id);
            if($userDetails) {
                $userDetails->is_active = $status;
                $userDetails->save();
            }
            
            DB::commit(); // Commit the transaction
            return $this->helper_controller->send_response('success', 'Lender status updated', 200);
        } catch (Exception $e) {
            
            DB::rollBack(); // Rollback the transaction
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }
}
