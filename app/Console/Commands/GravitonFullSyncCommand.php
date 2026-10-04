<?php

namespace App\Console\Commands;

use App\Models\BorrowerKyc;
use App\Models\GravitonApplicationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GravitonFullSyncCommand extends Command
{
    protected $signature = 'graviton:full-sync
                            {--borrower-kyc-id= : borrower_kycs.id}
                            {--borrower-id= : borrower_kycs.borrower_id}
                            {--limit=10 : Max records per run}
                            {--delay=1 : Delay in minutes between steps}
                            {--sync : Run synchronously}
                            {--force : Force re-submit}';

    protected $description = 'Run all Graviton sync steps (skip matched borrowers)';

    protected array $stats = [
        'total'   => 0,
        'success' => 0,
        'failed'  => 0,
        'matched' => 0,
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════╗');
        $this->info('║         🚀 GRAVITON FULL SYNC STARTED                  ║');
        $this->info('╚════════════════════════════════════════════════════════╝');

        $kycId      = $this->option('borrower-kyc-id');
        $borrowerId = $this->option('borrower-id');
        $limit      = (int) $this->option('limit');
        $delay      = (int) $this->option('delay');
        $sync       = $this->option('sync');
        $force      = $this->option('force');

        // ───────────────────────────────────────
        // Query
        // ───────────────────────────────────────
        $query = BorrowerKyc::query()
            ->whereNotNull('graviton_loan_id')
            ->whereNotNull('graviton_client_id');

        if ($kycId) {
            $query->where('id', $kycId);
        } elseif ($borrowerId) {
            $query->where('borrower_id', $borrowerId);
        } else {
            $query->orderBy('id', 'asc');
        }

        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('graviton_credit_report_resource_id')
                  ->orWhere('graviton_credit_report_status', 'failed');
            });
        }

        $borrowers = $query->limit($limit)->get();

        if ($borrowers->isEmpty()) {
            $this->warn('⚠️  No borrowers to process');
            return self::SUCCESS;
        }

        $this->stats['total'] = $borrowers->count();
        $this->info("📋 Found {$borrowers->count()} borrower(s)");
        $this->info("⏱️  Delay: {$delay} minutes between steps");
        $this->newLine();

        // ───────────────────────────────────────
        // Loop
        // ───────────────────────────────────────
        foreach ($borrowers as $index => $borrower) {
            // ✅ Dedupe check
            if (!$this->isDedupeApproved($borrower)) {
                $this->stats['matched']++;
                $this->warn("  ⏭️  Borrower {$borrower->id} — dedupe_status='{$borrower->dedupe_status}' — SKIPPING");
                continue;
            }

            try {
                $this->processBorrower($borrower, $index + 1, $borrowers->count(), $delay, $sync, $force);
                $this->stats['success']++;
            } catch (\Throwable $e) {
                $this->stats['failed']++;
                $this->error("  ❌ Borrower {$borrower->id} FAILED: {$e->getMessage()}");
                $this->warn("  ⏭️  Skipping — moving to next");
                Log::error('Full sync borrower failed', [
                    'borrower_kyc_id' => $borrower->id,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        // ───────────────────────────────────────
        // Summary
        // ───────────────────────────────────────
        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════╗');
        $this->info('║         ✅ GRAVITON FULL SYNC COMPLETED                ║');
        $this->info('╚════════════════════════════════════════════════════════╝');
        $this->info("📊 Summary:");
        $this->info("   Total:             {$this->stats['total']}");
        $this->info("   Success:           {$this->stats['success']}");
        $this->info("   Failed:            {$this->stats['failed']}");
        $this->info("   Matched (skipped): {$this->stats['matched']}");

        return self::SUCCESS;
    }

    /**
     * ✅ Dedupe approved check
     */
    protected function isDedupeApproved(BorrowerKyc $borrower): bool
    {
        return $borrower->dedupe_status === 'no_match';
    }

    /**
     * ✅ Process single borrower
     */
    protected function processBorrower(
        BorrowerKyc $borrower,
        int $index,
        int $total,
        int $delay,
        bool $sync,
        bool $force
    ): void {
        $this->newLine();
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("  👤 BORROWER {$index}/{$total} — borrower_kycs.id={$borrower->id}");
        $this->info("  dedupe_status: {$borrower->dedupe_status}");
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        // ═══════════════════════════════════════
        // STEP 1: Sync Reference Data
        // ═══════════════════════════════════════
        $this->safeStep('1/7', 'Sync Graviton Reference Data', function () use ($borrower) {
            $this->call('graviton:sync-reference', ['--borrower_id' => $borrower->id]);
        });
        $this->wait($delay);

        // ═══════════════════════════════════════
        // STEP 2: Dedupe Check
        // ═══════════════════════════════════════
        $this->safeStep('2/7', 'Dedupe Check', function () use ($borrower) {
            $this->call('graviton:dedupe-check', ['borrower_id' => $borrower->id]);
        });
        $this->wait($delay);

        // ═══════════════════════════════════════
        // STEP 3: Push Loan Applications
        // ═══════════════════════════════════════
        $this->safeStep('3/7', 'Push Loan Applications', function () use ($borrower) {
            $this->call('graviton:push-loan-applications', ['borrower_id' => $borrower->id]);
        });
        $this->wait($delay);

        // ═══════════════════════════════════════
        // STEP 4: Push Family Members
        // ═══════════════════════════════════════
        $this->safeStep('4/7', 'Push Family Members', function () use ($borrower) {
            $this->call('graviton:push-family-members', ['borrower_id' => $borrower->id]);
        });
        $this->wait($delay);

        // ═══════════════════════════════════════
        // STEP 5: Applicant Credit Report
        // ═══════════════════════════════════════
        $this->safeStep('5/7', 'Sync Applicant Credit Report', function () use ($borrower, $sync, $force) {
            $this->call('graviton:sync-applicant-report', array_filter([
                '--borrower-id' => $borrower->id,
                '--limit'       => 1,
                '--sync'        => $sync,
                '--force'       => $force,
            ]));
        });
        $this->wait($delay);

        // ═══════════════════════════════════════
        // STEP 6: Co-applicant Credit Report
        // ═══════════════════════════════════════
        $this->safeStep('6/7', 'Sync Co-applicant Credit Report', function () use ($borrower, $sync, $force) {
            $this->syncFamilyMemberReports('coapplicant', $borrower->id, $sync, $force);
        });
        $this->wait($delay);

        // ═══════════════════════════════════════
        // STEP 7: Guarantor Credit Report
        // ═══════════════════════════════════════
        $this->safeStep('7/7', 'Sync Guarantor Credit Report', function () use ($borrower, $sync, $force) {
            $this->syncFamilyMemberReports('guarantor', $borrower->id, $sync, $force);
        });

        $this->info("  ✅ Borrower {$borrower->id} — DONE");
    }

    /**
     * ✅ Safe step — try/catch
     */
    protected function safeStep(string $num, string $title, callable $callback): void
    {
        $this->step($num, $title);

        try {
            $callback();
            $this->info("  ✅ Step {$num} — success");
        } catch (\Throwable $e) {
            $this->warn("  ⚠️  Step {$num} FAILED: {$e->getMessage()}");
            $this->warn("  ⏭️  Skipping step {$num} — moving to next");
            Log::warning('Full sync step skipped', [
                'step'  => $num,
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ✅ Family member wise credit report sync
     */
    protected function syncFamilyMemberReports(
        string $role,
        int $borrowerKycId,
        bool $sync,
        bool $force
    ): void {
        $logs = GravitonApplicationLog::where('step', "{$role}_push")
            ->where('status', 'success')
            ->whereNotNull('resource_id')
            ->where('borrower_id', $borrowerKycId)
            ->get();

        if ($logs->isEmpty()) {
            $this->warn("  ⚠️  No {$role} resource IDs found — skipping");
            return;
        }

        $this->info("  📋 Found {$logs->count()} {$role}(s)");

        foreach ($logs as $log) {
            $familyMemberId = $log->resource_id;
            $this->line("  → borrower_kycs.id={$borrowerKycId}, familyMemberId={$familyMemberId}");

            try {
                $command = $role === 'coapplicant'
                    ? 'graviton:sync-coapplicant-report'
                    : 'graviton:sync-guarantor-report';

                $this->call($command, array_filter([
                    '--borrower-id'      => $borrowerKycId,
                    '--family-member-id' => $familyMemberId,
                    '--limit'            => 1,
                    '--sync'             => $sync,
                    '--force'            => $force,
                ]));

                $this->info("    ✅ Queued/Submitted");
            } catch (\Throwable $e) {
                $this->warn("    ⚠️  Failed: {$e->getMessage()}");
                $this->warn("    ⏭️  Skipping familyMemberId={$familyMemberId}");
                Log::warning('Family member credit report failed — skipped', [
                    'borrower_kyc_id'  => $borrowerKycId,
                    'family_member_id' => $familyMemberId,
                    'role'             => $role,
                    'error'            => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * ✅ Wait for N minutes
     */
    protected function wait(int $minutes): void
    {
        if ($minutes <= 0) return;
        $seconds = $minutes * 60;
        $this->line("  ⏳ Waiting {$minutes} minute(s)...");
        sleep($seconds);
    }

    /**
     * ✅ Step header
     */
    protected function step(string $num, string $title): void
    {
        $this->newLine();
        $this->info("  ┌─────────────────────────────────────────────────┐");
        $this->info("  │  STEP {$num}: {$title}");
        $this->info("  └─────────────────────────────────────────────────┘");
    }
}