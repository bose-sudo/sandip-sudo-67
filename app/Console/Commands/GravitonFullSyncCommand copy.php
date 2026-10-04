<?php

namespace App\Console\Commands;

use App\Models\BorrowerKyc;
use App\Models\GravitonApplicationLog;
use Illuminate\Console\Command;

class GravitonFullSyncCommand extends Command
{
    protected $signature = 'graviton:full-sync
                            {--borrower-kyc-id= : borrower_kycs.id}
                            {--borrower-id= : borrower_kycs.borrower_id}
                            {--limit=50 : Max records per step}
                            {--sync : Run synchronously (no queue)}
                            {--force : Force re-submit}';

    protected $description = 'Run all Graviton sync steps sequentially (1→7)';

    public function handle(): int
    {
        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════╗');
        $this->info('║         🚀 GRAVITON FULL SYNC STARTED                  ║');
        $this->info('╚════════════════════════════════════════════════════════╝');

        // ───────────────────────────────────────
        // Resolve borrower
        // ───────────────────────────────────────
        $kycId      = $this->option('borrower-kyc-id');
        $borrowerId = $this->option('borrower-id');

        $borrower = null;
        if ($kycId) {
            $borrower = BorrowerKyc::find($kycId);
        } elseif ($borrowerId) {
            $borrower = BorrowerKyc::where('borrower_id', $borrowerId)->first();
        }

        if (!$borrower && ($kycId || $borrowerId)) {
            $this->error('❌ Borrower not found');
            return self::FAILURE;
        }

        if ($borrower) {
            $this->info("📋 Target: borrower_kycs.id={$borrower->id}, borrower_id={$borrower->borrower_id}");
            $this->info("📋 Name: {$borrower->name}");
        } else {
            $this->info('📋 Target: ALL borrowers');
        }

        $limit = (int) $this->option('limit');

        // ═══════════════════════════════════════
        // STEP 1: Sync Reference Data
        // ═══════════════════════════════════════
        $this->step('1/7', 'Sync Graviton Reference Data');
        $this->call('graviton:sync-reference', array_filter([
            '--borrower_id' => $borrower?->id,   // borrower_kycs.id
        ]));

        // ═══════════════════════════════════════
        // STEP 2: Dedupe Check
        // ═══════════════════════════════════════
        $this->step('2/7', 'Dedupe Check');
        $this->call('graviton:dedupe-check', array_filter([
            'borrower_id' => $borrower?->id,     // borrower_kycs.id
        ]));

        // ═══════════════════════════════════════
        // STEP 3: Push Loan Applications
        // ═══════════════════════════════════════
        $this->step('3/7', 'Push Loan Applications');
        $this->call('graviton:push-loan-applications', array_filter([
            'borrower_id' => $borrower?->id,     // borrower_kycs.id
        ]));

        // ═══════════════════════════════════════
        // STEP 4: Push Family Members
        // ═══════════════════════════════════════
        $this->step('4/7', 'Push Family Members (Co-app + Guarantor)');
        $this->call('graviton:push-family-members', array_filter([
            'borrower_id' => $borrower?->id,     // borrower_kycs.id
        ]));

        // ═══════════════════════════════════════
        // STEP 5: Applicant Credit Report
        // ═══════════════════════════════════════
        $this->step('5/7', 'Sync Applicant Credit Report');
        $this->call('graviton:sync-applicant-report', array_filter([
            '--borrower-id' => $borrower?->id,   // borrower_kycs.id
            '--limit'       => $limit,
            '--sync'        => $this->option('sync'),
            '--force'       => $this->option('force'),
        ]));

        // ═══════════════════════════════════════
        // STEP 6: Co-applicant Credit Report
        // ═══════════════════════════════════════
        $this->step('6/7', 'Sync Co-applicant Credit Report');
        $this->syncFamilyMemberReports(
            'coapplicant',
            $borrower?->id,
            $limit,
            $this->option('sync'),
            $this->option('force')
        );

        // ═══════════════════════════════════════
        // STEP 7: Guarantor Credit Report
        // ═══════════════════════════════════════
        $this->step('7/7', 'Sync Guarantor Credit Report');
        $this->syncFamilyMemberReports(
            'guarantor',
            $borrower?->id,
            $limit,
            $this->option('sync'),
            $this->option('force')
        );

        // ───────────────────────────────────────
        // Done
        // ───────────────────────────────────────
        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════╗');
        $this->info('║         ✅ GRAVITON FULL SYNC COMPLETED                ║');
        $this->info('╚════════════════════════════════════════════════════════╝');

        return self::SUCCESS;
    }

    /**
     * ✅ Family member wise credit report sync
     * resource_id (graviton_application_logs) = familyMemberId
     */
    protected function syncFamilyMemberReports(
        string $role,           // 'coapplicant' | 'guarantor'
        ?int $borrowerKycId,    // borrower_kycs.id
        int $limit,
        bool $sync,
        bool $force
    ): void {
        $query = GravitonApplicationLog::query()
            ->where('step', "{$role}_push")
            ->where('status', 'success')
            ->whereNotNull('resource_id');

        if ($borrowerKycId) {
            $query->where('borrower_id', $borrowerKycId);  // borrower_kycs.id
        }

        $logs = $query->limit($limit)->get();

        if ($logs->isEmpty()) {
            $this->warn("  ⚠️  No {$role} resource IDs found");
            return;
        }

        $this->info("  📋 Found {$logs->count()} {$role}(s)");

        $success = 0;
        $failed  = 0;

        foreach ($logs as $log) {
            // ✅ borrower_kycs.id theke borrower find koro
            $borrower = BorrowerKyc::find($log->borrower_id);
            if (!$borrower) {
                $this->warn("  ⚠️  BorrowerKyc not found for id={$log->borrower_id}");
                continue;
            }

            // ✅ resource_id = familyMemberId
            $familyMemberId = $log->resource_id;

            $this->line("  → borrower_kycs.id={$borrower->id}, familyMemberId={$familyMemberId}");

            try {
                $command = $role === 'coapplicant'
                    ? 'graviton:sync-coapplicant-report'
                    : 'graviton:sync-guarantor-report';

                $this->call($command, array_filter([
                    '--borrower-id'      => $borrower->id,      // borrower_kycs.id
                    '--family-member-id' => $familyMemberId,    // resource_id
                    '--limit'            => 1,
                    '--sync'             => $sync,
                    '--force'            => $force,
                ]));

                $this->info("    ✅ Queued/Submitted");
                $success++;

            } catch (\Throwable $e) {
                $this->error("    ❌ Failed: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("  📊 {$role} Summary: Success: {$success}, Failed: {$failed}");
    }

    /**
     * ✅ Step header print
     */
    protected function step(string $num, string $title): void
    {
        $this->newLine();
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info("  STEP {$num}: {$title}");
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}