<?php

namespace App\Traits;

use App\Models\LoanApplicationStepLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

trait LogsApplicationStep
{
    /**
     * Log a completed step.
     *
     * @param int    $borrowerId
     * @param int    $stepNumber  Integer step (1–27), used for ordering and revert range
     * @param string $stepDetails The raw "details" string from the JSON step array
     *                            e.g. "Loan Basic Details", "VKYC Generate URL"
     *                            — will be auto-converted to snake_case flag
     * @param string $platform    'b2c' | 'b2b' | 'admin'
     * @param string|null $description
     *
     * Usage:
     *   $this->logStep(1023, 1, 'Loan Basic Details', 'b2c');
     *   $this->logStep(1023, 19, 'VKYC Status', 'admin', 'Manually verified');
     */
    public function logStep(
        int     $borrowerId,
        int     $stepNumber,
        string  $stepDetails,
        string  $platform,
        ?string $description = null
    ): LoanApplicationStepLog {
        $stepFlag = $this->toStepFlag($stepDetails);

        return LoanApplicationStepLog::create([
            'borrower_id'    => $borrowerId,
            'step_number'    => $stepNumber,
            'step_flag'      => $stepFlag,
            'attempt_number' => $this->currentAttemptNumber($borrowerId, $stepFlag),
            'status'         => 'completed',
            'platform'       => $platform,
            'done_by'        => auth()->id(),
            'description'    => $description,
            'done_at'        => Carbon::now('Asia/Kolkata'),
        ]);
    }

    /**
     * Mark all log rows from $fromStepNumber onwards as 'reverted' for this borrower,
     * and open a new attempt for those steps.
     *
     * Call this AFTER deleteSteps() has already cleaned the actual data.
     * The trait is logging-only — data deletion stays in the controller.
     *
     * @param int    $borrowerId
     * @param int    $fromStepNumber  Revert to this step (inclusive) — all steps >= this get reverted
     * @param string $platform
     * @param string|null $description
     *
     * Usage:
     *   // In controller, after deleteSteps():
     *   $this->revertStepLog(1023, 15, 'admin', 'Address mismatch found');
     */
    public function revertStepLog(
        int     $borrowerId,
        int     $fromStepNumber,
        string  $platform,
        ?string $description = null
    ): void {
        // Mark all completed rows from this step onwards as reverted
        LoanApplicationStepLog::where('borrower_id', $borrowerId)
            ->where('step_number', '>=', $fromStepNumber)
            ->where('status', 'completed')
            ->update(['status' => 'reverted']);

        // Log the revert action itself so there's a clear audit trail entry
        // attempt_number for each reverted step_flag will naturally increment
        // on the next logStep() call — no need to pre-seed them here
        LoanApplicationStepLog::create([
            'borrower_id'    => $borrowerId,
            'step_number'    => $fromStepNumber,
            'step_flag'      => 'step_reverted',
            'attempt_number' => 1,
            'status'         => 'completed',
            'platform'       => $platform,
            'done_by'        => auth()->id(),
            'description'    => $description ?? "Reverted from step {$fromStepNumber}",
            'done_at'        => Carbon::now('Asia/Kolkata'),
        ]);
    }

    /**
     * Current attempt number scoped to borrower + step_flag.
     * Step 'loan_basic_details' and 'vkyc_status' each track independently.
     */
    public function currentAttemptNumber(int $borrowerId, string $stepFlag): int
    {
        $max = LoanApplicationStepLog::where('borrower_id', $borrowerId)
            ->where('step_flag', $stepFlag)
            ->max('attempt_number');

        // If a previous attempt exists (even if reverted), next is +1
        return $max ? $max + 1 : 1;
    }

    /**
     * Latest completed log per step_flag for a borrower.
     * Returns the highest attempt_number completed row per step_flag.
     *
     * Use for: checking current application state, per-borrower reporting.
     */
    public function latestStepLogs(int $borrowerId): Builder
    {
        return LoanApplicationStepLog::where('borrower_id', $borrowerId)
            ->where('status', 'completed')
            ->whereIn('id', function ($sub) use ($borrowerId) {
                $sub->selectRaw('MAX(id)')
                    ->from('loan_application_step_logs')
                    ->where('borrower_id', $borrowerId)
                    ->where('status', 'completed')
                    ->whereNotIn('step_flag', ['step_reverted']) // exclude audit markers
                    ->groupBy('step_flag');
            });
    }

    /**
     * Convert "details" string from step JSON to a consistent snake_case flag.
     *
     * "Loan Basic Details"         → "loan_basic_details"
     * "VKYC Generate URL"          → "vkyc_generate_url"
     * "BORROWER Esign Generate URL"→ "borrower_esign_generate_url"
     * "Co-Applicant Details"       → "co_applicant_details"
     * "E-NACH step completed"      → "e_nach_step_completed"
     */
    protected function toStepFlag(string $details): string
    {
        return Str::snake(
            preg_replace('/[^a-zA-Z0-9\s]/', ' ', $details)
        );
    }
}