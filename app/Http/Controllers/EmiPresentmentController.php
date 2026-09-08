<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPresentmentJob;
use App\Models\BorrowerRepaymentSchedule;
use App\Models\PresentmentLog;
use App\Models\LoanBasicDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EmiPresentmentController extends Controller
{
    /**
     * POST /admin/emi/trigger
     * Manually run a full dispatch batch (bypasses day/hour guard via --force).
     */
    public function triggerManualPresentmentRun(Request $request): JsonResponse
    {
        Log::warning('[Admin] Manual presentment run triggered.', ['user_id' => auth()->id()]);

        Artisan::call('emi:dispatch-hourly', ['--force' => true]);

        return response()->json([
            'status'       => 'dispatched',
            'output'       => Artisan::output(),
            'triggered_by' => auth()->id(),
            'triggered_at' => now('Asia/Kolkata')->toISOString(),
        ]);
    }

    /**
     * POST /admin/emi/retry-failed
     * Retry all failed EMIs, or a specific one via schedule_id.
     */
    public function retryFailedPresentments(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'schedule_id' => 'nullable|integer|exists:borrower_repayment_schedules,id',
            'limit'       => 'nullable|integer|min:1|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'failed', 'errors' => $validator->errors()], 422);
        }

        $params = ['--limit' => $request->input('limit', 500)];
        if ($request->filled('schedule_id')) {
            $params['--schedule_id'] = $request->input('schedule_id');
        }

        Artisan::call('emi:retry-failed', $params);

        return response()->json([
            'status' => 'dispatched',
            'output' => Artisan::output(),
        ]);
    }

    /**
     * POST /admin/emi/trigger-single
     * Immediately queue one specific schedule for presentment.
     */
    // public function triggerSinglePresentment(Request $request): JsonResponse
    // {
    //     $validator = Validator::make($request->all(), [
    //         'schedule_id' => 'required|integer|exists:borrower_repayment_schedules,id',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['status' => 'failed', 'errors' => $validator->errors()], 422);
    //     }

    //     $scheduleId = (int) $request->input('schedule_id');

    //     $updated = DB::transaction(function () use ($scheduleId) {
    //         $schedule = BorrowerRepaymentSchedule::lockForUpdate()
    //             ->where('id', $scheduleId)
    //             ->whereIn('presentment_status', [
    //                 BorrowerRepaymentSchedule::PRESENTMENT_PENDING,
    //                 BorrowerRepaymentSchedule::PRESENTMENT_FAILED,
    //             ])
    //             ->first();

    //         if (!$schedule) {
    //             return false;
    //         }

    //         $schedule->presentment_status = BorrowerRepaymentSchedule::PRESENTMENT_QUEUED;
    //         $schedule->save();

    //         return true;
    //     });

    //     if (!$updated) {
    //         return response()->json([
    //             'status'  => 'skipped',
    //             'message' => 'Schedule not found or is already in success/queued state.',
    //         ], 409);
    //     }

    //     ProcessPresentmentJob::dispatch($scheduleId);

    //     Log::info("[Admin] Single presentment dispatched.", [
    //         'schedule_id' => $scheduleId,
    //         'user_id'     => auth()->id(),
    //     ]);

    //     return response()->json([
    //         'status'      => 'dispatched',
    //         'schedule_id' => $scheduleId,
    //     ]);
    // }
    public function triggerSinglePresentment(Request $request): JsonResponse
    {
        // echo 'hi';
        $validator = Validator::make($request->all(), [
            'schedule_id' => 'required|integer|exists:borrower_repayment_schedules,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'failed', 'errors' => $validator->errors()], 422);
        }

        return response()->json([
            'status'    => 'success',
            'message'   => "Single Presentment Dispatched",
            'data'      => [
                'status'      => 'dispatched',
                'schedule_id' => '',
                'borrower_id' => '',
                'amount'      => 0.00,
                'due_date'    => '',
            ],
        ]);
        die();
        exit();

        $scheduleId = (int) $request->input('schedule_id');

        $result = DB::transaction(function () use ($scheduleId) {

            // ── 1. Fetch and lock the schedule ────────────────────────────────────
            $schedule = BorrowerRepaymentSchedule::lockForUpdate()
                ->where('id', $scheduleId)
                ->whereIn('presentment_status', [
                    BorrowerRepaymentSchedule::PRESENTMENT_PENDING,
                    BorrowerRepaymentSchedule::PRESENTMENT_FAILED,
                ])
                ->first();

            if (!$schedule) {
                return [
                    'ok'      => false,
                    'message' => 'Schedule not found or already in success/queued state.',
                    'code'    => 409,
                ];
            }

            // ── 2. Check loan is DISBURSED ────────────────────────────────────────
            $loan = \App\Models\LoanBasicDetails::where('id', $schedule->borrower_id)
                ->where('status', 'DISBURSED')
                ->first();

            if (!$loan) {
                return [
                    'ok'      => false,
                    'message' => "Loan is not in DISBURSED status. Cannot present.",
                    'code'    => 422,
                ];
            }

            // ── 3. Check active eNACH mandate exists ──────────────────────────────
            $enachRequest = \App\Models\EnachRequest::where('borrower_id', $schedule->borrower_id)
                ->where('is_enach_activated', true)
                ->latest('id')
                ->first();

            if (!$enachRequest) {
                return [
                    'ok'      => false,
                    'message' => "No active eNACH mandate found. Cannot present.",
                    'code'    => 422,
                ];
            }

            // ── 4. Check attempts not exhausted ───────────────────────────────────
            if ($schedule->presentment_attempts >= BorrowerRepaymentSchedule::MAX_PRESENTMENT_ATTEMPTS) {
                return [
                    'ok'      => false,
                    'message' => "Schedule has reached max presentment attempts ({$schedule->presentment_attempts}). Use force-retry if you want to override.",
                    'code'    => 422,
                ];
            }

            // ── 5. All checks passed — mark as queued ─────────────────────────────
            $schedule->presentment_status = BorrowerRepaymentSchedule::PRESENTMENT_QUEUED;
            $schedule->save();

            return [
                'ok'          => true,
                'schedule'    => $schedule,
                'borrower_id' => $schedule->borrower_id,
                'amount'      => $schedule->emi_amount,
                'due_date'    => $schedule->due_date,
            ];
        });

        // ── Handle failed checks ──────────────────────────────────────────────────
        if (!$result['ok']) {
            Log::warning("[Admin] Single presentment blocked.", [
                'schedule_id' => $scheduleId,
                'reason'      => $result['message'],
                'user_id'     => auth()->id(),
            ]);

            return response()->json([
                'status'  => 'failed',
                'message' => $result['message'],
            ], $result['code']);
        }

        // ── Dispatch ──────────────────────────────────────────────────────────────
        ProcessPresentmentJob::dispatch($scheduleId);

        Log::info("[Admin] Single presentment dispatched.", [
            'schedule_id' => $scheduleId,
            'borrower_id' => $result['borrower_id'],
            'amount'      => $result['amount'],
            'due_date'    => $result['due_date'],
            'user_id'     => auth()->id(),
        ]);

        // return response()->json([
        //     'status'      => 'dispatched',
        //     'schedule_id' => $scheduleId,
        //     'borrower_id' => $result['borrower_id'],
        //     'amount'      => $result['amount'],
        //     'due_date'    => $result['due_date'],
        // ]);

        return response()->json([
            'status'    => 'success',
            'message'   => "Single Presentment Dispatched",
            'data'      => [
                'status'      => 'dispatched',
                'schedule_id' => $scheduleId,
                'borrower_id' => $result['borrower_id'],
                'amount'      => $result['amount'],
                'due_date'    => $result['due_date'],
            ],
        ]);
    }

    /**
     * GET /admin/emi/summary
     * Presentment status summary for a given date (default: today IST).
     */
    public function presentmentSummary(Request $request): JsonResponse
    {
        $date = $request->input('date', now('Asia/Kolkata')->toDateString());

        $scheduleSummary = BorrowerRepaymentSchedule::query()
            ->whereDate('due_date', $date)
            ->selectRaw('presentment_status, COUNT(*) as count, SUM(emi_amount) as total_amount')
            ->groupBy('presentment_status')
            ->get();

        $logSummary = PresentmentLog::query()
            ->whereDate('created_at', $date)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'date'             => $date,
                'schedule_summary' => $scheduleSummary,
                'log_summary'      => $logSummary,
            ],
        ]);
    }
}