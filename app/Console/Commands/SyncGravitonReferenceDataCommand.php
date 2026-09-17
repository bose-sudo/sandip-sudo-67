<?php

namespace App\Console\Commands;

use App\Jobs\SyncGravitonReferenceDataJob;
use App\Models\BorrowerKyc;
use Illuminate\Console\Command;

class SyncGravitonReferenceDataCommand extends Command
{
    protected $signature   = 'graviton:sync-reference {--borrower_id= : Optional specific borrower ID}';
    protected $description = 'Sync Graviton reference data for all borrowers (or a specific one)';

    public function handle(): int
    {
        $borrowerId = $this->option('borrower_id');

        // ─────────────────────────────────────────────
        // If specific borrower_id provided — only that one
        // ─────────────────────────────────────────────
        if ($borrowerId) {
            $borrower = BorrowerKyc::find($borrowerId);

            if (!$borrower) {
                $this->error("Borrower {$borrowerId} not found.");
                return self::FAILURE;
            }

            SyncGravitonReferenceDataJob::dispatch($borrower->id);
            $this->info("Dispatched sync for borrower {$borrowerId}");
            return self::SUCCESS;
        }

        // ─────────────────────────────────────────────
        // Otherwise — ALL borrowers
        // ─────────────────────────────────────────────
        $this->info('Starting Graviton reference data sync for all borrowers...');

        $total = BorrowerKyc::count();

        if ($total === 0) {
            $this->warn('No borrowers found in database.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} borrowers. Dispatching jobs...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        BorrowerKyc::chunk(50, function ($borrowers) use ($bar) {
            foreach ($borrowers as $borrower) {
                SyncGravitonReferenceDataJob::dispatch($borrower->id);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Dispatched sync jobs for {$total} borrowers.");

        return self::SUCCESS;
    }
}