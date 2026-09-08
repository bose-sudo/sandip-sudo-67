<?php

namespace App\Http\Controllers;

use App\Models\LenderLoanSetting;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LenderLoanSettingController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helperController)
    {
        $this->helper_controller = $helperController;
    }

    /**
     * Get Lender Loan Setting
     */
    public function show($lender_id)
    {
        try {
            $setting = LenderLoanSetting::with(['lender:id,name', 'modifiedBy:id,name'])
                            ->where('lender_id', $lender_id)
                            ->first();
            
            if (!$setting) {
                return $this->helper_controller->send_response('success', 'Settings not found', 200);
            }


            return $this->helper_controller->send_response('success', 'Lender loan settings retrieved', 200, $setting);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    /**
     * Store or Update Lender Loan Setting
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'lender_id' => 'required|integer|exists:lenders,id|unique:lender_loan_settings,lender_id',
                'co_applicant_step' => 'required|in:required,optional,not_required',
                'guarantor_step' => 'required|in:required,optional,not_required',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $setting = new LenderLoanSetting();
            $setting->lender_id = $request->lender_id;
            $setting->co_applicant_step = $request->co_applicant_step;
            $setting->guarantor_step = $request->guarantor_step;
            $setting->modified_by = auth()->id();
            $setting->ip = $this->helper_controller->get_user_ip();
            $setting->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Lender loan settings created successfully', 201, $setting);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    /**
     * Update Lender Loan Setting
     */
    public function update(Request $request, $lender_id)
    {
        DB::beginTransaction();
        try {
            $setting = LenderLoanSetting::where('lender_id', $lender_id)->first();
            
            if (!$setting) {
                return $this->helper_controller->send_response('failed', 'Setting not found', 422);
            }

            $validator = Validator::make($request->all(), [
                'co_applicant_step' => 'required|in:required,optional,not_required',
                'guarantor_step' => 'required|in:required,optional,not_required',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $setting->co_applicant_step = $request->co_applicant_step;
            $setting->guarantor_step = $request->guarantor_step;
            $setting->modified_by = auth()->id();
            $setting->ip = $this->helper_controller->get_user_ip();
            $setting->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Lender loan settings updated successfully', 200, $setting);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    /**
     * Delete Lender Loan Setting
     */
    public function destroy($lender_id)
    {
        DB::beginTransaction();
        try {
            $setting = LenderLoanSetting::where('lender_id', $lender_id)->first();
            
            if (!$setting) {
                return $this->helper_controller->send_response('failed', 'Settings not found', 422);
            }

            $setting->delete();
            DB::commit();
            return $this->helper_controller->send_response('success', 'Lender loan settings deleted successfully', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }
}
