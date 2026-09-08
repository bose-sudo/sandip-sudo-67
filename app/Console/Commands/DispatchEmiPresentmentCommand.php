<?php
// app/Console/Commands/DispatchEmiPresentmentCommand.php

namespace App\Console\Commands;

use App\Jobs\ProcessPresentmentJob;
use App\Models\BorrowerRepaymentSchedule;
use App\Models\LoanBasicDetails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchEmiPresentmentCommand extends Command
{
    protected $signature   = 'emi:dispatch-hourly {--force : Bypass day/hour restrictions}';
    protected $description = 'Dispatch EMI presentment jobs in hourly chunks (runs on configured day/window).';

    public function handle(): int
    {
        $tz             = config('emi.timezone', 'Asia/Kolkata');
        $now            = now($tz);
        $presentmentDay = (int) config('emi.presentment_day');
        $startHour      = (int) config('emi.presentment_start');
        $endHour        = (int) config('emi.presentment_end');
        $chunkSize      = (int) config('emi.presentment_chunk');
        $force          = $this->option('force');

        // ── Guard: day check ──────────────────────────────────────────────────
        if (!$force && (int) $now->format('j') !== $presentmentDay) {
            $this->info("[emi:dispatch-hourly] Not presentment day (configured={$presentmentDay}, today={$now->format('j')} IST). Exiting.");
            return self::SUCCESS;
        }

        // ── Guard: hour window check ──────────────────────────────────────────
        $currentHour = (int) $now->format('G');
        if (!$force && ($currentHour < $startHour || $currentHour >= $endHour)) {
            $this->info("[emi:dispatch-hourly] Outside window ({$startHour}:00–{$endHour}:00 IST). Current={$currentHour}:xx. Exiting.");
            return self::SUCCESS;
        }

        $this->info("[emi:dispatch-hourly] Started at {$now->toDateTimeString()} IST");
        Log::info('[emi:dispatch-hourly] Run started', [
            'ist_time' => $now->toDateTimeString(),
            'day'      => $now->format('j'),
            'hour'     => $currentHour,
            'chunk'    => $chunkSize,
        ]);

        // ── Atomically mark pending → queued, then collect IDs ────────────────
        $scheduleIds = DB::transaction(function () use ($chunkSize, $now) {

            // ── Only pending presentments due today ───────────────────────
            // ── CRITICAL: Only disbursed loans ────────────────────────────
            // ── Only select schedule IDs ──────────────────────────────────
            $schedules = BorrowerRepaymentSchedule::query()
                ->lockForUpdate()
                ->join('loan_basic_details as lbd', 'lbd.id', '=', 'borrower_repayment_schedules.borrower_id')
                ->where('borrower_repayment_schedules.presentment_status', BorrowerRepaymentSchedule::PRESENTMENT_PENDING)
                ->whereDate('borrower_repayment_schedules.due_date', $now->toDateString())
                ->where('lbd.status', 'DISBURSED')
                ->select('borrower_repayment_schedules.id')
                ->limit($chunkSize)
                ->get();

            if ($schedules->isEmpty()) {
                return collect();
            }

            $ids = $schedules->pluck('id')->toArray();

            BorrowerRepaymentSchedule::whereIn('id', $ids)
                ->where('presentment_status', BorrowerRepaymentSchedule::PRESENTMENT_PENDING)
                ->update(['presentment_status' => BorrowerRepaymentSchedule::PRESENTMENT_QUEUED]);

            return collect($ids);
        });

        if ($scheduleIds->isEmpty()) {
            $this->info('[emi:dispatch-hourly] No pending EMIs found for today. Exiting.');
            Log::info('[emi:dispatch-hourly] Nothing to dispatch.');
            return self::SUCCESS;
        }

        // ── Dispatch jobs ─────────────────────────────────────────────────────
        foreach ($scheduleIds as $id) {
            ProcessPresentmentJob::dispatch($id);
        }

        $count = $scheduleIds->count();
        $this->info("[emi:dispatch-hourly] Dispatched {$count} jobs.");
        Log::info("[emi:dispatch-hourly] Dispatched {$count} jobs.", ['ids' => $scheduleIds->toArray()]);

        return self::SUCCESS;
    }
}