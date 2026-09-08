<?php

namespace App\Http\Controllers;

use App\Models\AppActivityLog;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ActivityLogController extends Controller
{
    protected $helper_controller;
    
    public function __construct(HelperController $helper_controller) {
        $this->helper_controller = $helper_controller;
    }

    /**
     * Get only those executive ids along with their name which are present in the app_activity_logs table
     * Created on: 09-11-2024
     * Mir Akib Javed
     */
    public function get_executive_screener_for_app_log() {
        try {
            $result = AppActivityLog::join('lsp_executive_details', 'app_activity_logs.user_id', '=', 'lsp_executive_details.id')
                        ->where('user_type', 'EXECUTIVE')
                        ->select('lsp_executive_details.id', 'lsp_executive_details.name')
                        ->distinct()
                        ->get();

            return $this->helper_controller->send_response('success', "Executive list fetched successfully", 200, $result);
        } catch (Exception $e) {
            return $this->helper_controller->send_response("failed", "Unknown error occurred", 422, ['error' => $e->getMessage()]);
        }
    }

    /**
     * API to get app log [B2b + B2C]
     * Created on: 09-11-2024
     * Mir Akib Javed
     */

    public function get_app_log(Request $request) {
        /**
         * This api will have following filters:
         * -------------------------------------
         * Page number, number of records, start_date, end_date, borrower_id, executive id
         */

        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'start_date' => 'nullable|date|before_or_equal:today|after_or_equal:2024-01-01',
                'end_date' => 'nullable|date|before_or_equal:today|after_or_equal:start_date',
                'borrower_id' => 'nullable|string|regex:/^[\w\-]+$/',
                'executive_id' => 'nullable|integer|exists:lsp_executive_details,id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response("failed", $validator->errors()->first(), 422);
            }

            // Extract request filters
            $page = $request->input('page', 1);
            $numberOfRecords = $request->input('number_of_records', 10);
            
            // Begin a DB transaction if needed
            DB::beginTransaction();
            
            $query = AppActivityLog::select(
                        [
                            'app_activity_logs.*', 'loan_basic_details.borrower_id as borrower_unq'
                        ])
                        ->join('loan_basic_details', 'app_activity_logs.borrower_id', 'loan_basic_details.id');
            
            // Apply filters
            if ($request->filled('start_date')) {
                $query->whereDate('created_at', '>=', $request->input('start_date'));
            }
            if ($request->filled('end_date')) {
                $query->whereDate('created_at', '<=', $request->input('end_date'));
            }
            if ($request->filled('borrower_id')) {
                $borrowerId = $request->input('borrower_id');
                if (is_numeric($borrowerId)) {
                    $query->where('app_activity_logs.borrower_id', $borrowerId)
                        ->orWhereRaw('LOWER(loan_basic_details.borrower_id) LIKE ?', ['%' . $borrowerId . '%']);
                } else {
                    $borrowerId = strtolower($request->borrower_id);
                    $query->WhereRaw('LOWER(loan_basic_details.borrower_id) LIKE ?', ['%' . $borrowerId . '%']);

                }
            }
            if ($request->filled('executive_id')) {
                $executiveId = $request->input('executive_id');
                $query->where('user_type', "EXECUTIVE")->where('user_id', $executiveId);
            }

            // Paginate results
            // $query->orderBy('app_activity_logs.id', 'desc');
            
            $logs = $query->paginate($numberOfRecords, ['*'], 'page', $page)
                        ->through(function ($activityLog) {
                            if($activityLog->user_type == 'BORROWER') {
                                $application_type = "B2C";
                            } else {
                                $application_type = "B2B";
                            }
                            return [
                                'borrower_id' => $activityLog->borrower_unq,
                                'user_type' => ucfirst(strtolower($activityLog->user_type)),
                                'action' => ucfirst($activityLog->action),
                                'details' => $activityLog->details,
                                'created_by' => $activityLog->user_name,
                                'application_type' => $application_type,
                                'created_at' => date('d-m-Y H:i:s', strtotime($activityLog->created_at)),
                                'ip_address' => $activityLog->ip_address,
                            ];
                        });

            // Commit the transaction
            DB::commit();
            return $this->helper_controller->send_response("success", "App log fetched successfully", 200, $logs);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response("failed", "Unknown error occurred", 422, ['error' => $e->getMessage()]);
        }
    }
    
}
