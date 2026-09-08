<?php

namespace App\Http\Controllers;

use App\Models\BorrowerAccountDetails;
use App\Models\LoanBasicDetails;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class BorrowerAccountDetailsController extends Controller
{
    protected $helper_controller;
    public function __construct(HelperController $helper_controller) {
        $this->helper_controller = $helper_controller;
    }
    
    public function grant_enach_skip_permission(Request $request)
    {
        // Validate input
        $validator = Validator::make($request->all(), [
            'borrower_id' => 'required|exists:loan_basic_details,id',
            'remarks' => 'required|string|max:500|regex:/^[a-zA-Z0-9\s\-,\.\(\)]+$/', // Safe input
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $borrowerAccount = BorrowerAccountDetails::where('borrower_id', $request->borrower_id)->first();

        if (!$borrowerAccount) {
            return $this->helper_controller->send_response('failed', 'Borrower account not found', 404);
        }

        DB::beginTransaction();
        try {
            $borrowerAccount->is_enach_step_skippable = true;
            $borrowerAccount->skip_permission_by = auth()->id();
            $borrowerAccount->skip_permission_granted_at = date('Y-m-d H:i:s');
            $borrowerAccount->skip_permission_remarks = $request->remarks;
            $borrowerAccount->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Skip permission granted successfully.', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 500);
        }
    }

    public function revoke_enach_skip_permission(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'borrower_id' => 'required|exists:loan_basic_details,id',
            'remarks' => 'required|string|max:500|regex:/^[a-zA-Z0-9\s\-,\.\(\)]+$/', // Safe input
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $borrowerAccount = BorrowerAccountDetails::where('borrower_id', $request->borrower_id)->first();

        if (!$borrowerAccount) {
            return $this->helper_controller->send_response('failed', 'Borrower account not found', 404);
        }

        // Check if skip permission was already granted
        if (!$borrowerAccount->is_enach_step_skippable) {
            return $this->helper_controller->send_response('failed', 'Skip permission is not granted yet.', 422);
        }

        DB::beginTransaction();
        try {
            $borrowerAccount->is_enach_step_skippable = false;
            $borrowerAccount->skip_permission_by = auth()->id();
            $borrowerAccount->skip_permission_granted_at = date('Y-m-d H:i:s');
            $borrowerAccount->skip_permission_remarks = $request->remarks;
            $borrowerAccount->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Skip permission revoked successfully.', 200, $borrowerAccount);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 500);
        }
    }

    public function grant_aa_skip_permission(Request $request)
    {
        // Validate input
        $validator = Validator::make($request->all(), [
            'borrower_id'   => 'required|exists:loan_basic_details,id',
            'remarks'       => 'required|string|max:422|regex:/^[a-zA-Z0-9\s\-,\.\(\)]+$/', // Safe input
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $loan_details = LoanBasicDetails::find($request->borrower_id);

        if (!$loan_details) {
            return $this->helper_controller->send_response('failed', 'Borrower not found', 404);
        }

        DB::beginTransaction();
        try {
            $loan_details->is_aa_skippable       = true;
            $loan_details->aa_skip_enabled_by    = auth()->id();
            $loan_details->aa_skip_enabled_at    = date('Y-m-d H:i:s');
            $loan_details->aa_skip_reason        = $request->remarks;
            $loan_details->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Skip permission granted successfully.', 200);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 500);
        }
    }

    public function revoke_aa_skip_permission(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'borrower_id'   => 'required|exists:loan_basic_details,id',
            'remarks'       => 'required|string|max:500|regex:/^[a-zA-Z0-9\s\-,\.\(\)]+$/', // Safe input
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $loan_details = LoanBasicDetails::find($request->borrower_id);

        if (!$loan_details) {
            return $this->helper_controller->send_response('failed', 'Borrower not found', 404);
        }

        // Check if skip permission was already granted
        if (!$loan_details->is_aa_skippable) {
            return $this->helper_controller->send_response('failed', 'Skip permission is not granted yet.', 422);
        }

        DB::beginTransaction();
        try {
            $loan_details->is_aa_skippable       = false;
            $loan_details->aa_skip_enabled_by    = auth()->id();
            $loan_details->aa_skip_enabled_at    = date('Y-m-d H:i:s');
            $loan_details->aa_skip_reason        = $request->remarks;
            $loan_details->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Skip permission revoked successfully.', 200);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }
}
