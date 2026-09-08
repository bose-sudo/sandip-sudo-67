<?php

namespace App\Jobs;

use App\Models\BorrowerRepaymentSchedule;
use App\Models\EnachRequest;
use App\Models\PresentmentLog;
use App\Models\LoanBasicDetails;
use App\Services\PresentmentService;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPresentmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 3;
    public int   $timeout = 60;
    public array $backoff  = [300, 900]; // 5 min, 15 min

    private int $scheduleId;

    public function __construct(int $scheduleId)
    {
        $this->scheduleId = $scheduleId;
        $this->onQueue('presentment');
    }

    public function handle(PresentmentService $presentmentService): void
    {
        $batchHour = now('Asia/Kolkata')->startOfHour();

        DB::transaction(function () use ($presentmentService, $batchHour) {

            // ── 1. Fetch with row-level lock ──────────────────────────────────
            /** @var BorrowerRepaymentSchedule $schedule */
            $schedule = BorrowerRepaymentSchedule::lockForUpdate()
                ->find($this->scheduleId);

            if (!$schedule) {
                Log::warning("[Presentment] schedule_id={$this->scheduleId} not found. Skipping.");
                return;
            }

            // ── 2. Idempotency guard ──────────────────────────────────────────
            if ($schedule->presentment_status === BorrowerRepaymentSchedule::PRESENTMENT_SUCCESS) {
                Log::info("[Presentment] schedule_id={$this->scheduleId} already succeeded. Skipping.");
                return;
            }

            if (!in_array($schedule->presentment_status, [
                BorrowerRepaymentSchedule::PRESENTMENT_QUEUED,
                BorrowerRepaymentSchedule::PRESENTMENT_FAILED,
            ])) {
                Log::warning("[Presentment] schedule_id={$this->scheduleId} unexpected status [{$schedule->presentment_status}]. Skipping.");
                return;
            }

            // ── *. SAFETY: Verify borrower loan is still DISBURSED ────────────
            // This is a second line of defence in case status changed after queuing
            $loan = LoanBasicDetails::where('id', $schedule->borrower_id)
                ->where('status', 'DISBURSED')
                ->first();

            if (!$loan) {
                Log::warning(
                    "[Presentment] schedule_id={$this->scheduleId} " .
                    "borrower_id={$schedule->borrower_id} loan is NOT disbursed. Skipping."
                );

                // Reset back to pending so it doesn't stay stuck as queued
                $schedule->presentment_status = BorrowerRepaymentSchedule::PRESENTMENT_PENDING;
                $schedule->save();

                PresentmentLog::create([
                    'schedule_id'             => $schedule->id,
                    'batch_hour'              => $batchHour,
                    'request_payload'         => ['note' => 'Skipped — loan not disbursed'],
                    'response_payload'        => [],
                    'status'                  => 'failed',
                    'error_message'           => "Loan not in DISBURSED status for borrower_id={$schedule->borrower_id}",
                    'attempt'                 => $schedule->presentment_attempts,
                    'merchant_request_number' => null,
                    'created_at'              => now('Asia/Kolkata'),
                ]);

                // Do NOT re-throw — no point retrying a non-disbursed loan
                return;
            }

            // ── 3. Increment attempt counter immediately ───────────────────────
            $schedule->presentment_attempts  = $schedule->presentment_attempts + 1;
            $schedule->last_presentment_at   = now('Asia/Kolkata');
            $schedule->save();

            // ── 4. Fetch active eNACH mandate for this borrower ───────────────
            $enachRequest = EnachRequest::where('borrower_id', $schedule->borrower_id)
                ->where('is_enach_activated', true)
                ->latest('id')
                ->first();

            if (!$enachRequest) {
                $this->handleFailure(
                    $schedule, $batchHour, [], [],
                    "No active eNACH mandate for borrower_id={$schedule->borrower_id}"
                );
                // No point retrying if there's no mandate — release without re-throw
                return;
            }

            // ── 5. Build presentment payload ──────────────────────────────────
            $payload = [
                'borrower_id'      => $schedule->borrower_id,
                'transaction_id'   => $enachRequest->transaction_id,
                'amount'           => $schedule->emi_amount,
                'presentment_date' => $schedule->due_date->format('Y-m-d'),
                'schedule_id'      => $schedule->id,
                'ip'               => request()->ip(),
            ];

            // ── 6. Call Presentment API ───────────────────────────────────────
            try {
                $result = $presentmentService->present($payload);
            } catch (Exception $e) {
                $this->handleFailure($schedule, $batchHour, $payload, [], $e->getMessage());
                throw $e; // Re-throw so queue applies backoff + retry
            }

            // ── 7. Process result ─────────────────────────────────────────────
            if ($result['success']) {
                $this->handleSuccess($schedule, $batchHour, $result);
            } else {
                $this->handleFailure(
                    $schedule,
                    $batchHour,
                    $result['request_payload'],
                    $result['response_payload'],
                    $result['error']
                );
                throw new Exception(
                    "[Presentment] API failure for schedule_id={$this->scheduleId}: {$result['error']}"
                );
            }
        });
    }

    // ── Private Handlers ──────────────────────────────────────────────────────

    private function handleSuccess(
        BorrowerRepaymentSchedule $schedule,
        Carbon $batchHour,
        array $result
    ): void {
        $schedule->presentment_status      = BorrowerRepaymentSchedule::PRESENTMENT_SUCCESS;
        $schedule->merchant_request_number = $result['merchant_request_number'];
        $schedule->save();

        PresentmentLog::create([
            'schedule_id'             => $schedule->id,
            'batch_hour'              => $batchHour,
            'request_payload'         => $result['request_payload'],
            'response_payload'        => $result['response_payload'],
            'status'                  => 'success',
            'error_message'           => null,
            'attempt'                 => $schedule->presentment_attempts,
            'merchant_request_number' => $result['merchant_request_number'],
            'created_at'              => now('Asia/Kolkata'),
        ]);

        Log::info("[Presentment] SUCCESS schedule_id={$schedule->id} mrn={$result['merchant_request_number']}");
    }

    private function handleFailure(
        BorrowerRepaymentSchedule $schedule,
        Carbon $batchHour,
        array $requestPayload,
        array $responsePayload,
        string $errorMessage
    ): void {
        $schedule->presentment_status = BorrowerRepaymentSchedule::PRESENTMENT_FAILED;
        $schedule->save();

        PresentmentLog::create([
            'schedule_id'             => $schedule->id,
            'batch_hour'              => $batchHour,
            'request_payload'         => $requestPayload ?: ['note' => 'No request built'],
            'response_payload'        => $responsePayload ?: ['note' => 'No response received'],
            'status'                  => 'failed',
            'error_message'           => $errorMessage,
            'attempt'                 => $schedule->presentment_attempts,
            'merchant_request_number' => null,
            'created_at'              => now('Asia/Kolkata'),
        ]);

        Log::error(
            "[Presentment] FAILED schedule_id={$schedule->id} " .
            "attempt={$schedule->presentment_attempts} error={$errorMessage}"
        );
    }

    /**
     * Called by Laravel after all retries are exhausted.
     */
    public function failed(Exception $exception): void
    {
        Log::critical(
            "[Presentment] PERMANENTLY FAILED schedule_id={$this->scheduleId}: " .
            $exception->getMessage()
        );

        BorrowerRepaymentSchedule::where('id', $this->scheduleId)
            ->where('presentment_status', '!=', BorrowerRepaymentSchedule::PRESENTMENT_SUCCESS)
            ->update(['presentment_status' => BorrowerRepaymentSchedule::PRESENTMENT_FAILED]);
    }
}