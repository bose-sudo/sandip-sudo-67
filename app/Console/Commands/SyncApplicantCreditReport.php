<?php

namespace App\Console\Commands;

use App\Jobs\SyncApplicantCreditReportJob;
use App\Models\BorrowerKyc;
use Illuminate\Console\Command;

class SyncApplicantCreditReport extends Command
{
    protected $signature = 'graviton:sync-applicant-report
                            {--borrower-id= : Specific borrower_id}
                            {--limit=50 : Max records}
                            {--force : Re-submit}
                            {--sync : Run synchronously}';

    protected $description = 'Sync applicant credit reports';

    public function handle(): int
    {
        $this->info('🚀 Syncing APPLICANT credit reports...');

        $query = BorrowerKyc::query()
            ->whereNotNull('graviton_loan_id')
            ->whereNotNull('graviton_client_id');

        if ($borrowerId = $this->option('borrower-id')) {
            // $query->where('borrower_id', $borrowerId);
            $query->where('id', $borrowerId);
        }

        if (!$this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('graviton_credit_report_resource_id')
                  ->orWhere('graviton_credit_report_status', 'failed');
            });
        }

        $borrowers = $query->limit((int) $this->option('limit'))->get();
        $this->info("📋 Found {$borrowers->count()} borrower(s)");

        $success = 0; $failed = 0;
        foreach ($borrowers as $borrower) {
            try {
                if ($this->option('sync')) {
                    app(\App\Services\Graviton\GravitonApplicantCreditReportService::class)
                        ->submit($borrower, ['name' => $borrower->name]);
                } else {
                    SyncApplicantCreditReportJob::dispatch($borrower->id);
                }
                $this->info("  ✅ borrower_kyc.id={$borrower->id}");
                $success++;
            } catch (\Throwable $e) {
                $this->error("  ❌ {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("📊 Success: {$success}, Failed: {$failed}");
        return self::SUCCESS;
    }
}