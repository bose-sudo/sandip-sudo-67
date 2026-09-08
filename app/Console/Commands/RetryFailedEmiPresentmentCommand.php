<?php
// app/Console/Commands/RetryFailedEmiPresentmentCommand.php

namespace App\Console\Commands;

use App\Jobs\ProcessPresentmentJob;
use App\Models\BorrowerRepaymentSchedule;
use App\Models\LoanBasicDetails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetryFailedEmiPresentmentCommand extends Command
{
    protected $signature = 'emi:retry-failed
                            {--schedule_id= : Retry a specific schedule only}
                            {--limit=500    : Max records per run}';

    protected $description = 'Re-dispatch failed EMI presentments that have not exceeded max attempts.';

    public function handle(): int
    {
        $specificId = $this->option('schedule_id');
        $limit      = (int) $this->option('limit');

        $this->info('[emi:retry-failed] Starting...');

        $scheduleIds = DB::transaction(function () use ($specificId, $limit) {

            // ── Only disbursed loans ──────────────────────────────────────────
            // $query = BorrowerRepaymentSchedule::lockForUpdate()
            //     ->join('loan_basic_details as lbd', 'lbd.id', '=', 'borrower_repayment_schedules.borrower_id')
            //     ->where('borrower_repayment_schedules.presentment_status', BorrowerRepaymentSchedule::PRESENTMENT_FAILED)
            //     ->where('borrower_repayment_schedules.presentment_attempts', '<', BorrowerRepaymentSchedule::MAX_PRESENTMENT_ATTEMPTS)
            //     ->where('lbd.status', 'DISBURSED')
            //     ->select('borrower_repayment_schedules.id');
            $query = BorrowerRepaymentSchedule::query()
                ->lockForUpdate()
                ->join('loan_basic_details as lbd', 'lbd.id', '=', 'borrower_repayment_schedules.borrower_id')
                ->where('borrower_repayment_schedules.presentment_status', BorrowerRepaymentSchedule::PRESENTMENT_FAILED)
                ->where('borrower_repayment_schedules.presentment_attempts', '<', BorrowerRepaymentSchedule::MAX_PRESENTMENT_ATTEMPTS)
                ->whereDate('borrower_repayment_schedules.due_date', now()->toDateString())
                ->where('lbd.status', 'DISBURSED')
                ->select('borrower_repayment_schedules.id');

            if ($specificId) {
                $query->where('id', (int) $specificId);
            }

            $schedules = $query->limit($limit)->get(['id']);

            if ($schedules->isEmpty()) {
                return collect();
            }

            $ids = $schedules->pluck('id')->toArray();

            BorrowerRepaymentSchedule::whereIn('id', $ids)
                ->update(['presentment_status' => BorrowerRepaymentSchedule::PRESENTMENT_QUEUED]);

            return collect($ids);
        });

        if ($scheduleIds->isEmpty()) {
            $this->info('[emi:retry-failed] No retryable EMIs found.');
            return self::SUCCESS;
        }

        foreach ($scheduleIds as $id) {
            ProcessPresentmentJob::dispatch($id);
        }

        $count = $scheduleIds->count();
        $this->info("[emi:retry-failed] Re-dispatched {$count} jobs.");
        Log::info("[emi:retry-failed] Retried {$count} EMIs.", ['ids' => $scheduleIds->toArray()]);

        return self::SUCCESS;
    }
}