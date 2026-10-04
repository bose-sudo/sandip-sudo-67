<?php

namespace App\Console\Commands;

use App\Jobs\SyncGuarantorCreditReportJob;
use App\Models\BorrowerKyc;
use Illuminate\Console\Command;

class SyncGuarantorCreditReport extends Command
{
    protected $signature = 'graviton:sync-guarantor-report
                            {--borrower-id= : Specific borrower_id}
                            {--family-member-id= : Family member ID (mandatory)}
                            {--name= : Guarantor name}
                            {--relation=Guarantor : Relation}
                            {--limit=50 : Max records}
                            {--force : Re-submit}
                            {--sync : Run synchronously}';

    protected $description = 'Sync guarantor credit reports';

    public function handle(): int
    {
        $this->info('🚀 Syncing GUARANTOR credit reports...');

        $familyMemberId = (int) $this->option('family-member-id');
        if (!$familyMemberId) {
            $this->error('❌ --family-member-id is mandatory');
            return self::FAILURE;
        }

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
                $extraData = [
                    'name'           => $this->option('name') ?? $borrower->name,
                    'familyMemberId' => $familyMemberId,
                    'relationType'   => $this->option('relation') ?? 'Guarantor',
                ];

                if ($this->option('sync')) {
                    app(\App\Services\Graviton\GravitonGuarantorCreditReportService::class)
                        ->submit($borrower, $extraData);
                } else {
                    SyncGuarantorCreditReportJob::dispatch(
                        $borrower->id, $familyMemberId,
                        $extraData['name'], $extraData['relationType']
                    );
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