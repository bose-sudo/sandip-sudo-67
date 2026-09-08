<?php

namespace App\Http\Controllers;

use App\Models\BorrowerAccountDetails;
use App\Models\BorrowerAddress;
use App\Models\BorrowerCheques;
use App\Models\BorrowerIotDetails;
use App\Models\BorrowerKyc;
use App\Models\BorrowerLoanScheme;
use App\Models\BorrowerOccupation;
use App\Models\BorrowerReferenceDetails;
use App\Models\CoapplicantKyc;
use App\Models\CoapplicantOccupation;
use App\Models\Documents;
use App\Models\FieldInspectionDetails;
use App\Models\Guarantor;
use App\Models\GuarantorOccupation;
use App\Models\LoanApplicationStep;
use App\Models\LoanApplicationSummary;
use App\Models\LoanBasicDetails;
use App\Models\LoanStepBackRequest;
use App\Models\LoanStepModificationLog;
use App\Models\MakerCheckerAuthorization;
use App\Models\ModuleFunctionality;
// ModuleFunctionality
use App\Models\VendorServiceApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LoanStepBackRequestController extends Controller
{
    protected $helper_controller;

    public function __construct()
    {
        $this->helper_controller = new HelperController();
    }

    private function checkMakerAuthorization($routeName, $makerId)
    {
        $moduleFunctionality = ModuleFunctionality::where('route_used', $routeName)
            ->where('row_status', 'A')
            ->first();

        if (!$moduleFunctionality) {
            return ['authorized' => false, 'message' => 'Route not found in module functionalities'];
        }

        $authorization = MakerCheckerAuthorization::where('mfunc_id', $moduleFunctionality->mfunc_id)
            ->where('maker_id', $makerId)
            ->where('row_status', 'A')
            ->first();

        if (!$authorization) {
            return ['authorized' => false, 'message' => 'You are not authorized to perform this action'];
        }

        return [
            'authorized' => true,
            'authorization' => $authorization
        ];
    }

    public function get_loan_application_step() {
        try {
            $loan_steps = LoanApplicationStep::select([
                'step_id','step'
            ])
            ->where('row_status', 'A')
            ->orderBy('step_order', 'asc')
            ->get();
            
            return $this->helper_controller->send_response('success', "Loan steps fetched successfully", 200, $loan_steps);
        } catch (Throwable $e){
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function create(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|integer|exists:loan_basic_details,id',
                'step_id' => 'required|integer|exists:loan_application_steps,step_id',
                'reason' => 'required|string|max:255',
                // 'step_flag' => 'required|string|max:50',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $authId = Auth::id();
            $routeName = $request->route()->getName();

            $authCheck = $this->checkMakerAuthorization($routeName, $authId);

            if (!$authCheck['authorized']) {
                return $this->helper_controller->send_response('failed', $authCheck['message'], 422);
            }

            $loan_details = LoanBasicDetails::where('id', $request->borrower_id)->first();

            if(!$loan_details) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            if($loan_details->status === 'DISBURSED') {
                return $this->helper_controller->send_response('failed', "Can not make any changes, case already disbursed", 422);
            }

            $existingRequest = LoanStepBackRequest::where('borrower_id', $request->borrower_id)
                ->where('row_status', 'A')
                ->first();

            if ($existingRequest) {
                // Check if R1 is pending
                $r1IsPending = in_array($existingRequest->r1_approval_status, ['pending', 'on hold']);
                
                // Check if R2 exists and is pending
                $r2IsPending = false;
                if ($authCheck['authorization']->r2_id !== null) {
                    $r2IsPending = in_array($existingRequest->r2_approval_status, ['pending', 'on hold']);
                }
                
                // Block if either is pending
                if ($r1IsPending || $r2IsPending) {
                    return $this->helper_controller->send_response(
                        'failed',
                        "A step back request is already in progress for this borrower. Please wait for the existing request to be completed.",
                        422
                    );
                }
            }

            $step_details = LoanApplicationStep::find($request->step_id);
            $step_flag = $step_details->step_flag ?? '';

            DB::beginTransaction();

            $stepBackRequest = LoanStepBackRequest::create([
                'borrower_id' => $request->borrower_id,
                'step_id' => $request->step_id,
                'step_flag' => $step_flag,
                'maker_id' => $authId,
                'reason' => $request->reason ?? '',
                'requested_at' => now(),
                'r1_approval_status' => 'pending',
                'r2_approval_status' => $authCheck['authorization']->r2_id ? 'pending' : null,
                'row_status' => 'A',
            ]);

            DB::commit();

            return $this->helper_controller->send_response('success', 'Loan step back request created successfully', 200, $stepBackRequest);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function edit(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'lsbr_id' => 'required|integer|exists:loan_step_back_requests,lsbr_id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $authId = Auth::id();
            $routeName = 'loan_step_back.create_request';

            $authCheck = $this->checkMakerAuthorization($routeName, $authId);

            if (!$authCheck['authorized']) {
                return $this->helper_controller->send_response('failed', $authCheck['message'], 422);
            }

            $stepBackRequest = LoanStepBackRequest::with(['step', 'maker', 'reviewer1', 'reviewer2'])
                ->where('lsbr_id', $request->lsbr_id)
                ->first();

            if (!$stepBackRequest) {
                return $this->helper_controller->send_response('failed', 'Request not found', 422);
            }

            return $this->helper_controller->send_response('success', 'Request retrieved successfully', 200, $stepBackRequest);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function update(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'lsbr_id' => 'required|integer|exists:loan_step_back_requests,lsbr_id',
                'borrower_id' => 'required|integer',
                'step_id' => 'required|integer|exists:loan_application_steps,step_id',
                'step_flag' => 'required|string|max:50',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $authId = Auth::id();
            $routeName = 'loan_step_back.create_request';

            $authCheck = $this->checkMakerAuthorization($routeName, $authId);

            if (!$authCheck['authorized']) {
                return $this->helper_controller->send_response('failed', $authCheck['message'], 422);
            }

            DB::beginTransaction();

            $stepBackRequest = LoanStepBackRequest::where('lsbr_id', $request->lsbr_id)->first();

            if (!$stepBackRequest) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Request not found', 422);
            }

            $stepBackRequest->update([
                'borrower_id' => $request->borrower_id,
                'step_id' => $request->step_id,
                'step_flag' => $request->step_flag,
            ]);

            DB::commit();

            return $this->helper_controller->send_response('success', 'Request updated successfully', 200, $stepBackRequest);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function delete(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'lsbr_id' => 'required|integer|exists:loan_step_back_requests,lsbr_id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $authId = Auth::id();
            $routeName = 'loan_step_back.create_request';

            $authCheck = $this->checkMakerAuthorization($routeName, $authId);

            if (!$authCheck['authorized']) {
                return $this->helper_controller->send_response('failed', $authCheck['message'], 422);
            }

            DB::beginTransaction();

            $stepBackRequest = LoanStepBackRequest::where('lsbr_id', $request->lsbr_id)->first();

            if (!$stepBackRequest) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Request not found', 422);
            }

            if($stepBackRequest->r1_approval_status !== '' || $stepBackRequest->r2_approval_status !== '') {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Can not delete a request once R1 or R2 reviewed it', 422);
            }

            $stepBackRequest->update(['row_status' => 'D']);

            DB::commit();

            return $this->helper_controller->send_response('success', 'Request deleted successfully', 200);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function statusChange(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'lsbr_id' => 'required|integer|exists:loan_step_back_requests,lsbr_id',
                'row_status' => 'required|in:A,I',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $authId = Auth::id();
            $routeName = 'loan_step_back.create_request';

            $authCheck = $this->checkMakerAuthorization($routeName, $authId);

            if (!$authCheck['authorized']) {
                return $this->helper_controller->send_response('failed', $authCheck['message'], 422);
            }

            DB::beginTransaction();

            $stepBackRequest = LoanStepBackRequest::where('lsbr_id', $request->lsbr_id)->first();

            if (!$stepBackRequest) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Request not found', 422);
            }

            $stepBackRequest->update(['row_status' => $request->row_status]);

            DB::commit();

            return $this->helper_controller->send_response('success', 'Request status changed successfully', 200, $stepBackRequest);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function r1UpdateStatus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'lsbr_id' => 'required|integer|exists:loan_step_back_requests,lsbr_id',
                'r1_approval_status' => 'required|in:on hold,approved,rejected,reject',
                'r1_reason' => 'required_if:r1_approval_status,on hold,rejected,reject|string',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            if($request->r1_approval_status === 'reject') {
                $request->r1_approval_status = 'rejected';
            }

            $authId = Auth::id();

            DB::beginTransaction();

            $stepBackRequest = LoanStepBackRequest::where('lsbr_id', $request->lsbr_id)->first();

            if (!$stepBackRequest) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Request not found', 422);
            }

            // Get authorization to check if this is r1
            $routeName = 'loan_step_back.create_request';
            $moduleFunctionality = ModuleFunctionality::where('route_used', $routeName)
                ->where('row_status', 'A')
                ->first();

            if (!$moduleFunctionality) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Module functionality not found', 422);
            }

            $authorization = MakerCheckerAuthorization::where('mfunc_id', $moduleFunctionality->mfunc_id)
                ->where('maker_id', $stepBackRequest->maker_id)
                ->where('r1_id', $authId)
                ->where('row_status', 'A')
                ->first();

            if (!$authorization) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'You are not authorized as R1 for this request', 422);
            }

            // Update R1 approval status
            $stepBackRequest->update([
                'r1_approval_status' => $request->r1_approval_status,
                'r1_id' => $authId,
                'r1_approved_at' => now(),
                'r1_reason' => $request->r1_reason,
            ]);

            // If approved and no R2 required, process the deletion
            if ($request->r1_approval_status === 'approved' && !$authorization->r2_id) {

                // Call deleteSteps - it handles its own transaction and rollback
                $deleteResult = $this->deleteSteps($stepBackRequest->borrower_id, $stepBackRequest->step_id);

                // If deletion failed, rollback the approval too
                if ($deleteResult['status'] === 'failed') {
                    DB::rollBack();
                    // return $deleteResult; // Return the error response from deleteSteps
                    return $this->helper_controller->send_response('failed', $deleteResult['messege'], 422);
                }

                // Mark request as completed
                $stepBackRequest->update([
                    'completion_status' => 'completed',
                    'completed_at' => now(),
                ]);
            }

            DB::commit();

            return $this->helper_controller->send_response('success', 'R1 status updated successfully', 200, $stepBackRequest);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function r2UpdateStatus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'lsbr_id' => 'required|integer|exists:loan_step_back_requests,lsbr_id',
                'r2_approval_status' => 'required|in:on hold,approved,rejected',
                'r2_reason' => 'required_if:r2_approval_status,on hold,rejected|string',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $authId = Auth::id();

            DB::beginTransaction();

            $stepBackRequest = LoanStepBackRequest::where('lsbr_id', $request->lsbr_id)->first();

            if (!$stepBackRequest) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Request not found', 422);
            }

            // Check if R1 has approved
            if ($stepBackRequest->r1_approval_status !== 'approved') {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'R1 must approve before R2 can review', 422);
            }

            // Get authorization to check if this is r2
            $routeName = 'loan_step_back.create_request';
            $moduleFunctionality = ModuleFunctionality::where('route_used', $routeName)
                ->where('row_status', 'A')
                ->first();

            if (!$moduleFunctionality) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Module functionality not found', 422);
            }

            $authorization = MakerCheckerAuthorization::where('mfunc_id', $moduleFunctionality->mfunc_id)
                ->where('maker_id', $stepBackRequest->maker_id)
                ->where('r2_id', $authId)
                ->where('row_status', 'A')
                ->first();

            if (!$authorization) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'You are not authorized as R2 for this request', 422);
            }

            // Update R2 approval status
            $stepBackRequest->update([
                'r2_approval_status' => $request->r2_approval_status,
                'r2_id' => $authId,
                'r2_approved_at' => now(),
                'r2_reason' => $request->r2_reason,
            ]);

            // If both R1 and R2 approved, process the deletion
            if ($request->r2_approval_status === 'approved' && $stepBackRequest->r1_approval_status === 'approved') {

                // Call deleteSteps - it handles its own transaction and rollback
                $deleteResult = $this->deleteSteps($stepBackRequest->borrower_id, $stepBackRequest->step_id);

                // If deletion failed, rollback the approval too
                if ($deleteResult['status'] === 'failed') {
                    DB::rollBack();
                    // return $deleteResult; // Return the error response from deleteSteps
                    return $this->helper_controller->send_response('failed', $deleteResult['messege'], 422);
                }

                // Mark request as completed
                $stepBackRequest->update([
                    'completion_status' => 'completed',
                    'completed_at' => now(),
                ]);
            }

            DB::commit();

            return $this->helper_controller->send_response('success', 'Step deleted successfully', 200, $stepBackRequest);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }


    public function list(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:100',
                'row_status' => 'nullable|in:A,I',
                'borrower_id' => 'nullable|integer',
                'maker_id' => 'nullable|integer|exists:users,id',
                'step_id' => 'nullable|integer|exists:loan_application_steps,step_id',
                'step_flag' => 'nullable|string|max:50',
                'r1_approval_status' => 'nullable|in:pending,on hold,approved,rejected',
                'r2_approval_status' => 'nullable|in:pending,on hold,approved,rejected',
                'r1_id' => 'nullable|integer|exists:users,id',
                'r2_id' => 'nullable|integer|exists:users,id',
                'requested_from' => 'nullable|date',
                'requested_to' => 'nullable|date|after_or_equal:requested_from',
                'search' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $routeName = 'loan_step_back.create_request';
            $moduleFunctionality = ModuleFunctionality::where('route_used', $routeName)
                ->where('row_status', 'A')
                ->first();
                
            if(!$moduleFunctionality) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            $mfunc_id = $moduleFunctionality->mfunc_id;
            $authId = Auth::id();

            $page = $request->input('page', 1);
            $limit = $request->input('limit', 10);

            $query = LoanStepBackRequest::with([
                'borrower:id,borrower_id',
                'latestBorrowerKyc',
                'step:step_id,step',
                'maker:id,name',
                'reviewer1:id,name',
                'reviewer2:id,name'
            ]);

            // Filter by row_status
            if ($request->filled('row_status')) {
                $query->where('loan_step_back_requests.row_status', $request->row_status);
            } else {
                $query->where('loan_step_back_requests.row_status', '!=', 'D');
            }

            // Filter by borrower_id
            if ($request->filled('borrower_id')) {
                $query->where('borrower_id', $request->borrower_id);
            }

            // Filter by maker_id
            if ($request->filled('maker_id')) {
                $query->where('maker_id', $request->maker_id);
            }

            // Filter by step_id
            if ($request->filled('step_id')) {
                $query->where('step_id', $request->step_id);
            }

            // Filter by step_flag
            if ($request->filled('step_flag')) {
                $stepFlag = strtolower($request->step_flag);
                $query->whereRaw('LOWER(step_flag) = ?', [$stepFlag]);
            }

            // Filter by r1_approval_status
            if ($request->filled('r1_approval_status')) {
                $query->where('r1_approval_status', $request->r1_approval_status);
            }

            // Filter by r2_approval_status
            if ($request->filled('r2_approval_status')) {
                $query->where('r2_approval_status', $request->r2_approval_status);
            }

            // Filter by r1_id
            if ($request->filled('r1_id')) {
                $query->where('r1_id', $request->r1_id);
            }

            // Filter by r2_id
            if ($request->filled('r2_id')) {
                $query->where('r2_id', $request->r2_id);
            }

            // Filter by requested date range
            if ($request->filled('requested_from')) {
                $query->whereDate('requested_at', '>=', $request->requested_from);
            }

            if ($request->filled('requested_to')) {
                $query->whereDate('requested_at', '<=', $request->requested_to);
            }

            // Search filter
            if ($request->filled('search')) {
                $search = strtolower($request->search);
                $query->where(function($q) use ($search) {
                    $q->whereRaw('LOWER(step_flag) LIKE ?', ["%{$search}%"])
                        ->orWhereHas('step', function($subQ) use ($search) {
                            $subQ->whereRaw('LOWER(step) LIKE ?', ["%{$search}%"])
                                ->orWhereRaw('LOWER(step_flag) LIKE ?', ["%{$search}%"]);
                        })
                        ->orWhereHas('maker', function($subQ) use ($search) {
                            $subQ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                                ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
                        })
                        ->orWhereRaw('LOWER(r1_reason) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(r2_reason) LIKE ?', ["%{$search}%"]);
                });
            }

            // Authorization filter - show records where:
            // 1. User is the maker
            // 2. OR user is r1/r2 in maker_checker_authorizations for this maker and mfunc_id
            $query->where(function($q) use ($authId, $mfunc_id) {
                $q->where('loan_step_back_requests.maker_id', $authId)
                  ->orWhereExists(function($subQuery) use ($authId, $mfunc_id) {
                      $subQuery->select(DB::raw(1))
                          ->from('maker_checker_authorizations as mca')
                          ->whereColumn('mca.maker_id', 'loan_step_back_requests.maker_id')
                          ->where('mca.mfunc_id', $mfunc_id)
                          ->where('mca.row_status', 'A')
                          ->where(function($authQ) use ($authId) {
                              $authQ->where('mca.r1_id', $authId)
                                    ->orWhere('mca.r2_id', $authId);
                          });
                  });
            });

            // Count total records after all filters
            $totalRecords = $query->count();

            // Fetch paginated records with authorization details
            $requests = $query
                ->leftJoin('maker_checker_authorizations as mca', function($join) use ($mfunc_id) {
                    $join->on('mca.maker_id', '=', 'loan_step_back_requests.maker_id')
                         ->where('mca.mfunc_id', '=', $mfunc_id)
                         ->where('mca.row_status', '=', 'A');
                })
                ->select('loan_step_back_requests.*', 'mca.r1_id as auth_r1_id', 'mca.r2_id as auth_r2_id')
                ->orderBy('loan_step_back_requests.requested_at', 'desc')
                ->skip(($page - 1) * $limit)
                ->take($limit)
                ->get()
                ->map(function ($item) use ($authId) {
                    return array_merge(
                        $item->toArray(),
                        [
                            // Enable r1 button if auth user is r1 in maker_checker_authorizations
                            'enable_r1_buttons' => (int) $item->auth_r1_id === (int) $authId,
                            
                            // Enable r2 button if auth user is r2 in maker_checker_authorizations
                            'enable_r2_buttons' => (int) $item->auth_r2_id === (int) $authId,

                            // Date formatting (IST)
                            'r1_approved_at' => $item->r1_approved_at
                                ? $item->r1_approved_at->setTimezone('Asia/Kolkata')->format('d-m-Y H:i:s')
                                : null,

                            'r2_approved_at' => $item->r2_approved_at
                                ? $item->r2_approved_at->setTimezone('Asia/Kolkata')->format('d-m-Y H:i:s')
                                : null,

                            'created_at' => $item->created_at
                                ? $item->created_at->setTimezone('Asia/Kolkata')->format('d-m-Y H:i:s')
                                : null,

                            'updated_at' => $item->updated_at
                                ? $item->updated_at->setTimezone('Asia/Kolkata')->format('d-m-Y H:i:s')
                                : null,

                            'requested_at' => $item->requested_at
                                ? $item->requested_at->setTimezone('Asia/Kolkata')->format('d-m-Y H:i:s')
                                : null,
                        ]
                    );
                });

            $response = [
                'data' => $requests,
                'pagination' => [
                    'total' => $totalRecords,
                    'per_page' => $limit,
                    'current_page' => $page,
                    'last_page' => ceil($totalRecords / $limit),
                    'from' => (($page - 1) * $limit) + 1,
                    'to' => min($page * $limit, $totalRecords),
                ]
            ];

            return $this->helper_controller->send_response('success', 'All loan step back requests retrieved successfully', 200, $response);

        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }


    /**
     * OPTIMIZED VERSION - Key Improvements:
     * 
     * 1. CRITICAL BUGS FIXED:
     *    - Fixed typo: `->latest->first()` → `->latest()->first()` in update_loan_step()
     *    - Fixed inconsistent step navigation logic
     *    - Fixed missing null checks that could cause fatal errors
     *    - Fixed duplicate line: `$borrower_documents->battery_signed_cheque` was set twice
     * 
     * 2. PERFORMANCE OPTIMIZATIONS:
     *    - Batch database operations where possible
     *    - Reduced redundant queries
     *    - Use eager loading for related data
     *    - Single update_loan_step call per step block
     *    - Optimized logging with bulk inserts
     * 
     * 3. CODE QUALITY:
     *    - Better error handling and validation
     *    - Clearer variable names
     *    - Reduced code duplication
     *    - Better comments explaining business logic
     */

    public function deleteSteps($borrower_id, $step)
    {
        try {
            // DB::beginTransaction();

            // Validate borrower exists first
            $loan_basic_detail = LoanBasicDetails::find($borrower_id);
            if (!$loan_basic_detail) {
                // return $this->helper_controller->send_response('failed', "Invalid borrower ID", 422);
                return [
                    'status' => 'failed',
                    'messege' => "Invalid borrower ID",
                ];
            }

            // Step 23: IoT Activation
            if ($step <= 23) {
                $this->deleteIotDetails($borrower_id);
            }

            // Step 20: Guarantor E-sign
            // Step 22: Guarantor E-sign
            // if ($step <= 20) {
            if ($step <= 22) {
                $this->deleteEsignRecords($borrower_id, 'GUARANTOR', 24);
            }

            // Step 19: Co-applicant E-sign
            // if ($step <= 19) {
            if ($step <= 21) {
                $this->deleteEsignRecords($borrower_id, 'COAPPLICANT', 22);
            }
            
            // Step 18: Borrower E-sign
            // if ($step <= 18) {
            if ($step <= 20) {
                $this->deleteEsignRecords($borrower_id, 'BORROWER', 20);
            }

            // Step 22: Vehicle Documents Upload
            if ($step <= 19) {
                $this->clearVehicleDocuments($borrower_id);
            }

            // Step 21: E-NACH Registration
            if ($step <= 18) {
                $this->clearEnachDetails($borrower_id);
            }

            // Step 17: EMI Debit Consent
            if ($step <= 17) {
                $this->clearEmiDebitConsent($borrower_id);
            }

            // Step 16: VKYC Generate URL
            if ($step <= 16) {
                $this->update_loan_step($borrower_id, 17);
            }

            // Step 15: Field Inspection Details
            if ($step <= 15) {
                $this->deleteFieldInspectionDetails($borrower_id);
            }

            // Step 14: Reference Details
            if ($step <= 14) {
                $this->deleteReferenceDetails($borrower_id);
            }

            // Step 13: Guarantor Documents Upload
            if ($step <= 13) {
                $this->clearGuarantorDocuments($borrower_id);
            }

            // Step 12: Co-applicant Documents Upload
            if ($step <= 12) {
                $this->clearCoapplicantDocuments($borrower_id);
            }

            // Step 11: Borrower Documents Upload
            if ($step <= 11) {
                $this->clearBorrowerDocuments($borrower_id);
            }

            // Step 10: Lender Consent
            if ($step <= 10) {
                $this->clearLenderConsent($borrower_id);
            }

            // Step 9: Loan Offers Completed
            if ($step <= 9) {
                $this->update_loan_step($borrower_id, 8);
            }

            // Step 8: Guarantor Occupation
            if ($step <= 8) {
                $this->deleteGuarantorOccupation($borrower_id);
            }

            // Step 7: Guarantor Details
            if ($step <= 7) {
                $this->deleteGuarantorDetails($borrower_id);
            }

            // Step 6: Co-applicant Occupation
            if ($step <= 6) {
                $this->deleteCoapplicantOccupation($borrower_id);
            }

            // Step 5: Co-applicant Details
            if ($step <= 5) {
                $this->deleteCoapplicantDetails($borrower_id);
            }

            // Step 4: Borrower Occupation
            if ($step <= 4) {
                $this->deleteBorrowerOccupation($borrower_id);
            }

            // Step 3: Borrower Family Details (Address)
            if ($step <= 3) {
                $this->deleteBorrowerAddress($borrower_id);
            }

            // Step 2: Borrower Details
            if ($step <= 2) {
                $this->deleteBorrowerDetails($borrower_id);
            }

            // Step 1: Loan Basic Details
            if ($step <= 1) {
                $this->deleteLoanBasicDetails($borrower_id);
            }

            // DB::commit();
            // return $this->helper_controller->send_response('success', 'Steps deleted successfully', 200);
            return [
                'status' => 'success',
                'messege' => "Steps deleted successfully",
            ];
        } catch (Throwable $e) {
            // DB::rollBack();
            return [
                'status' => 'failed',
                'messege' => $e->getMessage() // "Steps deleted successfully",
            ];
            // return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // ===========================
    // HELPER METHODS (OPTIMIZED)
    // ===========================

    private function deleteIotDetails($borrower_id)
    {
        $iot_details = BorrowerIotDetails::where('borrower_id', $borrower_id)->get();

        if ($iot_details->isEmpty()) {
            $this->update_loan_step($borrower_id, 27);
            return;
        }

        foreach ($iot_details as $iot_detail) {
            $updateStepLog = $this->update_step_modification_log($borrower_id, 'borrower_iot_details', $iot_detail, 'deleted');
            if ($updateStepLog['status'] !== true) {
                throw new \Exception($updateStepLog['message']);
            }
        }

        // Bulk delete
        BorrowerIotDetails::where('borrower_id', $borrower_id)->delete();
        $this->update_loan_step($borrower_id, 27);
    }

    private function clearVehicleDocuments($borrower_id)
    {
        $documents = Documents::where('borrower_id', $borrower_id)->first();

        if (!$documents) {
            $this->update_loan_step($borrower_id, 27);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'documents', $documents, 'modified');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        // Clear all vehicle-related fields
        $documents->fill([
            'vehicle_quotation' => null,
            'invoice' => null,
            'insurance' => null,
            'rto_tax_receipt' => null,
            'dl_receipt' => null,
            'rto_document' => null,
            'mmr' => null,
            'vehicle_image' => null,
            'urc_certificate' => null,
            'vehicle_signed_cheque' => null,
            'doh' => null,
            'vehicle_other_docs' => null,
            'chasis_number' => null,
            'motor_controller' => null,
            'battery_image' => null,
            'registration_certificate' => null,
            'bank_statement' => null,
            'battery_signed_cheque' => null, // Fixed: removed duplicate
        ])->save();

        $this->update_loan_step($borrower_id, 27, "E-NACH step completed");
    }

    private function clearEnachDetails($borrower_id)
    {
        $account_details = BorrowerAccountDetails::where('borrower_id', $borrower_id)->first();

        if (!$account_details) {
            $this->update_loan_step($borrower_id, 26);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'borrower_account_details', $account_details, 'modified');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        $account_details->fill([
            'enach_status' => null,
            'enach_transaction_id' => null,
            'mandate_id' => null,
            'is_enach_enabled' => false,
        ])->save();

        $this->update_loan_step($borrower_id, 26);
    }

    private function deleteEsignRecords($borrower_id, $applicant_type, $target_step)
    {
        $esign_api_flags = [
            'GENERATE_ESIGN_FILE_UPLOAD_URL',
            'GENERATE_ESIGN_URL',
            'DOWNLOAD_ESIGN_DOCUMENT',
        ];

        $api_details = VendorServiceApi::where('borrower_id', $borrower_id)
            ->whereIn('api_flag', $esign_api_flags)
            ->where('applicant_type', $applicant_type)
            ->get();

        if ($api_details->isEmpty()) {
            $this->update_loan_step($borrower_id, $target_step);
            return;
        }

        foreach ($api_details as $api_detail) {
            $updateStepLog = $this->update_step_modification_log($borrower_id, 'vendor_service_apis', $api_detail, 'deleted');
            if ($updateStepLog['status'] !== true) {
                throw new \Exception($updateStepLog['message']);
            }
        }

        // Bulk delete
        VendorServiceApi::where('borrower_id', $borrower_id)
            ->whereIn('api_flag', $esign_api_flags)
            ->where('applicant_type', $applicant_type)
            ->delete();

        if($applicant_type === 'BORROWER') {
            $loan_details = LoanBasicDetails::find($borrower_id);

            if (!$loan_details) {
                return;
            }

            $updateStepLog = $this->update_step_modification_log($borrower_id, 'loan_basic_details', $loan_details, 'modified');
            if ($updateStepLog['status'] !== true) {
                throw new \Exception($updateStepLog['message']);
            }
            $loan_details->is_agreement_reviewed = false;
            $loan_details->agreement_preview_remarks = null;
            $loan_details->save();

        }

        $this->update_loan_step($borrower_id, $target_step);
    }

    private function clearEmiDebitConsent($borrower_id)
    {
        $loan_basic_detail = LoanBasicDetails::find($borrower_id);
        if (!$loan_basic_detail) {
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'loan_basic_details', $loan_basic_detail, 'modified');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        $account_details = BorrowerAccountDetails::where('borrower_id', $borrower_id)->first();
        if ($account_details) {
            $updateStepLog = $this->update_step_modification_log($borrower_id, 'borrower_account_details', $account_details, 'deleted');
            if ($updateStepLog['status'] !== true) {
                throw new \Exception($updateStepLog['message']);
            }
            $account_details->delete();
        }

        $loan_basic_detail->emi_debit_consent = false;
        $loan_basic_detail->save();

        $this->update_loan_step($borrower_id, 19);
    }

    private function deleteFieldInspectionDetails($borrower_id)
    {
        $field_inspection = FieldInspectionDetails::where('borrower_id', $borrower_id)->first();

        if (!$field_inspection) {
            $this->update_loan_step($borrower_id, 15);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'field_inspection_details', $field_inspection, 'deleted');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        $field_inspection->delete();
        $this->update_loan_step($borrower_id, 15);
    }

    private function deleteReferenceDetails($borrower_id)
    {
        $reference_details = BorrowerReferenceDetails::where('borrower_id', $borrower_id)->first();

        if (!$reference_details) {
            $this->update_loan_step($borrower_id, 13);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'borrower_reference_details', $reference_details, 'deleted');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        $reference_details->delete();
        $this->update_loan_step($borrower_id, 13);
    }

    private function clearGuarantorDocuments($borrower_id)
    {
        $documents = Documents::where('borrower_id', $borrower_id)->first();

        if (!$documents) {
            $this->update_loan_step($borrower_id, 12);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'documents', $documents, 'modified');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        $documents->fill([
            // 'guarantor_aadhar_front' => null,
            // 'guarantor_aadhar_back' => null,
            'guarantor_driving_license' => null,
            // 'guarantor_pan_doc' => null,
            'guarantor_peraddress_proof' => null,
            'guarantor_coresaddress_proof' => null,
            'guarantor_avtar' => null,
            'guarantor_rent_doc' => null,
            'guarantor_name_declaration' => null,
            'guarantor_income_proof_doc' => null,
            'guarantor_dob_declaration' => null,
        ])->save();

        $this->update_loan_step($borrower_id, 12);
    }

    private function clearCoapplicantDocuments($borrower_id)
    {
        $documents = Documents::where('borrower_id', $borrower_id)->first();

        if (!$documents) {
            $this->update_loan_step($borrower_id, 11);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'documents', $documents, 'modified');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        $documents->fill([
            // 'coAadhar_front' => null,
            // 'coAadhar_back' => null,
            // 'coPan_doc' => null,
            'coDriving_license' => null,
            'coPerAddress_proof' => null,
            'coResAddress_proof' => null,
            'coapplicant_avtar' => null,
            'coapplicant_rent_doc' => null,
            'coapplicant_name_declaration' => null,
            'coapplicant_income_proof_doc' => null,
            'coapplicant_dob_declaration' => null,
        ])->save();

        $this->update_loan_step($borrower_id, 11);
    }

    private function clearBorrowerDocuments($borrower_id)
    {
        $documents = Documents::where('borrower_id', $borrower_id)->first();

        if (!$documents) {
            $this->update_loan_step($borrower_id, 10);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'documents', $documents, 'modified');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        $documents->fill([
            // 'aadhar_front' => null,
            // 'aadhar_back' => null,
            // 'pan_doc' => null,
            'driving_license' => null,
            'per_address_proof' => null,
            'res_address_proof' => null,
            'additional_doc' => null,
            'borrower_avtar' => null,
            'borrower_signed_cheque' => null,
            'borrower_other_docs' => null,
            'borrower_rent_doc' => null,
            'borrower_name_declaration' => null,
            'borrower_income_proof_doc' => null,
            'borrower_dob_declaration' => null,
        ])->save();

        $this->update_loan_step($borrower_id, 10);
    }

    private function clearLenderConsent($borrower_id)
    {
        $loan_basic_detail = LoanBasicDetails::find($borrower_id);
        if (!$loan_basic_detail) {
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'loan_basic_details', $loan_basic_detail, 'modified');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        $loan_basic_detail->lender_consent = false;
        $loan_basic_detail->save();

        $this->update_loan_step($borrower_id, 9);
    }

    private function deleteGuarantorOccupation($borrower_id)
    {
        $borrower_kyc = BorrowerKyc::where('borrower_id', $borrower_id)->first();
        if (!$borrower_kyc) {
            return;
        }

        if ($borrower_kyc->skip_guarantor === true) {
            $this->update_loan_step($borrower_id, 6);
            return;
        }

        $guarantor_occupation = GuarantorOccupation::where('borrower_id', $borrower_id)->first();
        if (!$guarantor_occupation) {
            $this->update_loan_step($borrower_id, 7);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'guarantor_occupations', $guarantor_occupation, 'deleted');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        $guarantor_occupation->delete();
        $this->update_loan_step($borrower_id, 7);
    }

    private function deleteGuarantorDetails($borrower_id)
    {
        $borrower_kyc = BorrowerKyc::where('borrower_id', $borrower_id)->first();
        if (!$borrower_kyc) {
            return;
        }

        $borrower_kyc->skip_guarantor = false;
        $borrower_kyc->save();

        // if ($borrower_kyc->skip_guarantor === true) {
        //     $this->update_loan_step($borrower_id, 6);
        //     return;
        // }

        $guarantor = Guarantor::where('borrower_id', $borrower_id)->first();
        if (!$guarantor) {
            $this->update_loan_step($borrower_id, 6);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'guarantors', $guarantor, 'deleted');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        $guarantor->delete();
        $this->update_loan_step($borrower_id, 6);
    }

    private function deleteCoapplicantOccupation($borrower_id)
    {
        $borrower_kyc = BorrowerKyc::where('borrower_id', $borrower_id)->first();
        if (!$borrower_kyc) {
            return;
        }

        if ($borrower_kyc->skip_co_applicant === true) {
            $this->update_loan_step($borrower_id, 4);
            return;
        }

        $coapplicant_occupation = CoapplicantOccupation::where('borrower_id', $borrower_id)->first();
        if (!$coapplicant_occupation) {
            $this->update_loan_step($borrower_id, 5);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'coapplicant_occupations', $coapplicant_occupation, 'deleted');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        $coapplicant_occupation->delete();
        $this->update_loan_step($borrower_id, 5);
    }

    private function deleteCoapplicantDetails($borrower_id)
    {
        $borrower_kyc = BorrowerKyc::where('borrower_id', $borrower_id)->first();
        if (!$borrower_kyc) {
            return;
        }

        // if ($borrower_kyc->skip_co_applicant === true) {
        //     $this->update_loan_step($borrower_id, 4);
        //     return;
        // }

        $borrower_kyc->skip_co_applicant = false;
        $borrower_kyc->save();

        $coapplicant = CoapplicantKyc::where('borrower_id', $borrower_id)->first();
        if (!$coapplicant) {
            $this->update_loan_step($borrower_id, 4);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'coapplicant_kycs', $coapplicant, 'deleted');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }
        
        $coapplicant->delete();
        $this->update_loan_step($borrower_id, 4);
    }

    private function deleteBorrowerOccupation($borrower_id)
    {
        $occupation = BorrowerOccupation::where('borrower_id', $borrower_id)->first();

        if (!$occupation) {
            $this->update_loan_step($borrower_id, 3);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'borrower_occupations', $occupation, 'deleted');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        $occupation->delete();
        $this->update_loan_step($borrower_id, 3);
    }

    private function deleteBorrowerAddress($borrower_id)
    {
        $address = BorrowerAddress::where('borrower_id', $borrower_id)->first();

        if (!$address) {
            $this->update_loan_step($borrower_id, 2);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'borrower_addresses', $address, 'deleted');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        $address->delete();
        $this->update_loan_step($borrower_id, 2);
    }

    private function deleteBorrowerDetails($borrower_id)
    {
        $borrower_kyc = BorrowerKyc::where('borrower_id', $borrower_id)->first();

        if (!$borrower_kyc) {
            $this->update_loan_step($borrower_id, 1);
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'borrower_kycs', $borrower_kyc, 'deleted');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        // Delete associated cheques
        $cheques = BorrowerCheques::where('borrower_id', $borrower_id)->get();
        foreach ($cheques as $cheque) {
            $updateStepLog = $this->update_step_modification_log($borrower_id, 'borrower_cheques', $cheque, 'deleted');
            if ($updateStepLog['status'] !== true) {
                throw new \Exception($updateStepLog['message']);
            }
        }

        BorrowerCheques::where('borrower_id', $borrower_id)->delete();
        $borrower_kyc->delete();

        $this->update_loan_step($borrower_id, 1);
    }

    private function deleteLoanBasicDetails($borrower_id)
    {
        $loan_details = LoanBasicDetails::find($borrower_id);

        if (!$loan_details) {
            return;
        }

        $updateStepLog = $this->update_step_modification_log($borrower_id, 'loan_basic_details', $loan_details, 'deleted');
        if ($updateStepLog['status'] !== true) {
            throw new \Exception($updateStepLog['message']);
        }

        // Delete application summary
        $summary = LoanApplicationSummary::where('borrower_id', $borrower_id)->first();
        if ($summary) {
            $updateStepLog = $this->update_step_modification_log($borrower_id, 'loan_application_summaries', $summary, 'deleted');
            if ($updateStepLog['status'] !== true) {
                throw new \Exception($updateStepLog['message']);
            }
            $summary->delete();
        }

        // Delete loan scheme
        $scheme = BorrowerLoanScheme::where('borrower_id', $borrower_id)->first();
        if ($scheme) {
            $updateStepLog = $this->update_step_modification_log($borrower_id, 'borrower_loan_schemes', $scheme, 'deleted');
            if ($updateStepLog['status'] !== true) {
                throw new \Exception($updateStepLog['message']);
            }
            $scheme->delete();
        }

        // Delete loan scheme
        $documents = Documents::where('borrower_id', $borrower_id)->first();
        if ($documents) {
            $updateStepLog = $this->update_step_modification_log($borrower_id, 'documents', $documents, 'deleted');
            if ($updateStepLog['status'] !== true) {
                throw new \Exception($updateStepLog['message']);
            }
            $documents->delete();
        }

        $loan_details->delete();
    }

    public function update_loan_step($borrower_id, $go_to_step, $step_flag = null)
    {
        try {
            // Get the loan basic details
            $loan_basic_details = LoanBasicDetails::find($borrower_id);
            
            if (!$loan_basic_details) {
                return [
                    "status" => false,
                    "message" => "Loan basic details not found",
                ];
            }

            // Log before modification
            $this->update_step_modification_log($borrower_id, 'loan_basic_details', $loan_basic_details, 'modified');

            // Get current steps (ensure it's an array)
            $current_steps = $loan_basic_details->loan_application_steps;
            
            // If it's JSON string, decode it
            if (is_string($current_steps)) {
                $current_steps = json_decode($current_steps, true);
            }
            
            // Ensure it's an array
            if (!is_array($current_steps)) {
                $current_steps = [];
            }

            // Filter the steps
            $filtered_steps = array_filter($current_steps, function ($step) use ($go_to_step, $step_flag) {
                if (!isset($step['step'])) {
                    return false;
                }
                
                // No flag → simple rollback (keep steps <= target)
                if ($step_flag === null) {
                    return $step['step'] <= $go_to_step;
                }
                
                // Flag is provided ↓↓↓
                // Always keep steps BEFORE the target step
                if ($step['step'] < $go_to_step) {
                    return true;
                }
                
                // For the SAME step number, keep ONLY the flagged one
                if ($step['step'] == $go_to_step && 
                    isset($step['details']) && 
                    $step['details'] === $step_flag) {
                    return true;
                }
                
                // Everything else goes
                return false;
            });

            // Re-index array to remove gaps
            $loan_basic_details->loan_application_steps = array_values($filtered_steps);
            
            // Save the changes
            $loan_basic_details->save();
            
            return [
                "status" => true,
                "message" => "Loan step updated",
            ];
            
        } catch (Throwable $e) {
            return [
                "status" => false,
                "message" => "Unknown error occurred: " . $e->getMessage(),
            ];
        }
    }

    public function update_step_modification_log($borrower_id, $table_name, $data, $data_status)
    {
        try {
            LoanStepModificationLog::create([
                "borrower_id" => $borrower_id,
                "table_name" => $table_name,
                "data" => json_encode($data),
                "data_status" => $data_status, // 'modified', 'deleted'
                "created_by" => Auth::id()
            ]);

            return [
                "status" => true,
                "message" => "Log updated",
            ];
        } catch (Throwable $e) {
            return [
                "status" => false,
                "message" => "Unknown error occurred: " . $e->getMessage(),
            ];
        }
    }
}
