<?php

namespace App\Http\Controllers;

use App\Mail\Mailer;
use App\Models\AdminUser;
use App\Models\LenderDirectors;
use App\Models\UserDepartments;
use App\Models\UserType;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class LenderDirectorController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helperController)
    {
        $this->helper_controller = $helperController;
    }

    public function index(Request $request)
    {
        try {
            $lenderId = $request->lender_id;

            if (!$lenderId) {
                return $this->helper_controller->send_response('failed', 'Lender ID is required', 422);
            }

            $lenderDirectors = LenderDirectors::where('status', '<>', 'deleted')->where('lender_id', $lenderId)->get();
            if(!$lenderDirectors) {
                return $this->helper_controller->send_response('success', 'No records found', 200);
            }

            return $this->helper_controller->send_response('success', 'Data fetched successfully', 200, $lenderDirectors);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred. ". $e->getMessage(), 422);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lender_id' => 'required|integer',
            'name' => 'required|string|regex:/^[a-zA-Z\s]+$/|max:255',
            'email' => 'required|email|max:255',
            'mobile' => 'required|digits:10',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        // Check if email or mobile already exists
        $existingUser = AdminUser::whereRaw('LOWER(email) = ?', [strtolower($request->email)])->orWhere('mobile', $request->mobile)->first();
        if ($existingUser) {
            return $this->helper_controller->send_response('failed', 'User already exists', 422);
        }

        // Lender Executive
        try {
            DB::beginTransaction();
            $lenderDirector = LenderDirectors::create([
                'lender_id' => $request->lender_id,
                'name' => $request->name,
                'email' => $request->email,
                'mobile' => $request->mobile,
                'user_id' => auth()->id(),
            ]);

            $department = UserDepartments::where('department', 'Lender Executive')->first();
            $department_id = isset($department) ? $department->id : null;
            
            $user_types = UserType::where('user_type', 'Lender Executive')->first();
            $user_types_id = isset($user_types) ? $user_types->id : null;

            $password = 'Hello@1234';

            $adminUser = AdminUser::create([
                'name' => $request->name,
                'mobile' => $request->mobile,
                'email' => $request->email,
                'is_active' => true,
                'password' => Hash::make($password),
                'department' => $department_id,
                'user_type' => $user_types_id,
            ]);

            $lenderDirector->auth_id = $adminUser->id;
            $lenderDirector->save();

            $emailData = [
                'name' => $request->name,
                'admin_url' => 'http://uat.finayo.tech',
                'user_id' => $request->email,
                'password' => $password,
            ];
            $viewName = 'emails.example';
            $subject = "Login Credential";
            
            Mail::to($request->email)->send(new Mailer($viewName, $emailData, $subject));

            DB::commit();
            return $this->helper_controller->send_response('success', 'Lender Director created successfully', 200, $lenderDirector);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred. ". $e->getMessage(), 422);
        }
    }

    public function show($id)
    {
        try {
            $lenderDirector = LenderDirectors::find($id);
            if(!$lenderDirector) {
                return $this->helper_controller->send_response('success', 'No records found', 200);
            }

            return $this->helper_controller->send_response('success', 'Data fetched successfully', 200, $lenderDirector);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred. ". $e->getMessage(), 422);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|regex:/^[a-zA-Z\s]+$/|max:255',
            'email' => 'required|email|max:255',
            'mobile' => 'required|digits:10',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        
        try {
            DB::beginTransaction();
            $lenderDirector = LenderDirectors::find($id);
            if(!$lenderDirector) {
                DB::rollBack();
                return $this->helper_controller->send_response('success', 'No records found', 200);
            }
            
            // Check if email or mobile already exists for another user
            if (AdminUser::where(function ($query) use ($request) {
                $query->whereRaw('LOWER(email) = ?', [strtolower($request->email)])->orWhere('mobile', $request->mobile);
            })->where('id', '<>', $lenderDirector->auth_id)->exists()) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'User already exists', 422);
            }

            $lenderDirector->name = $request->name;
            $lenderDirector->email = $request->email;
            $lenderDirector->mobile = $request->mobile;
            $lenderDirector->user_id = auth()->user()->id;

            $adminUser = AdminUser::find($lenderDirector->auth_id);
            if($adminUser) {
                $adminUser->name = $request->name;
                $adminUser->mobile = $request->mobile;
                $adminUser->email = $request->email;
                $adminUser->save();
            } else{
                $department = UserDepartments::where('department', 'Lender Executive')->first();
                $department_id = isset($department) ? $department->id : null;
                
                $user_types = UserType::where('user_type', 'Lender Executive')->first();
                $user_types_id = isset($user_types) ? $user_types->id : null;

                $password = 'Hello@1234';
                $adminUser = AdminUser::create([
                    'name' => $request->name,
                    'mobile' => $request->mobile,
                    'email' => $request->email,
                    'is_active' => true,
                    'password' => Hash::make($password),
                    'department' => $department_id,
                    'user_type' => $user_types_id,
                ]);
                $lenderDirector->auth_id = $adminUser->id;

                $emailData = [
                    'name' => $request->name,
                    'admin_url' => 'http://uat.finayo.tech',
                    'user_id' => $request->email,
                    'password' => $request->password,
                ];
                $viewName = 'emails.example';
                $subject = "Login Credential";
            
                Mail::to($request->email)->send(new Mailer($viewName, $emailData, $subject));
            }

            DB::commit();
            return $this->helper_controller->send_response('success', 'Lender Director updated successfully', 200, $lenderDirector);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred. ". $e->getMessage(), 422);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $lenderDirector = LenderDirectors::find($id);
            if(!$lenderDirector) {
                return $this->helper_controller->send_response('failed', 'No records found', 422);
            }

            $adminUser = AdminUser::find($lenderDirector->id);
            if($adminUser) {

                $adminUser->is_active = false;
                $adminUser->is_deleted = true;
                $adminUser->remarks = 'User deleted from lender authority list';
                $adminUser->updated_by = auth()->id();
                $adminUser->deleted_at = date('Y-m-d H:i:s');
                $adminUser->save();
            }

            $lenderDirector->status = 'deleted';
            $lenderDirector->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Lender Director deleted successfully', 200);
        } catch (Exception $e) {

            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred. ". $e->getMessage(), 422);
        }
    }
}
