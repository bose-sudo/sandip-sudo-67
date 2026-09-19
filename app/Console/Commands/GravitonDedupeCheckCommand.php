<?php

namespace App\Console\Commands;

use App\Jobs\DedupeCheckJob;
use App\Models\BorrowerKyc;
use Illuminate\Console\Command;

class GravitonDedupeCheckCommand extends Command
{
    protected $signature   = 'graviton:dedupe-check {borrower_id? : Optional borrower ID}';
    protected $description = 'Check duplicates in Graviton for borrowers';

    public function handle(): int
    {
        $borrowerId = $this->argument('borrower_id');

        // Specific borrower
        if ($borrowerId) {
            $borrower = BorrowerKyc::find($borrowerId);
            if (!$borrower) {
                $this->error("Borrower {$borrowerId} not found.");
                return self::FAILURE;
            }
            DedupeCheckJob::dispatch($borrower->id);
            $this->info("✅ Dedupe check dispatched for borrower {$borrowerId}");
            return self::SUCCESS;
        }

        // All borrowers — has mobile & pan
        $ids = BorrowerKyc::whereNotNull('mobile')
            ->whereNotNull('pan')
            ->where('mobile', '!=', '')
            ->where('pan', '!=', '')
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->warn('No borrowers with valid mobile & PAN.');
            return self::SUCCESS;
        }

        $total = $ids->count();
        $this->info("Found {$total} borrowers. Dispatching dedupe jobs...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($ids as $id) {
            DedupeCheckJob::dispatch($id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Dispatched dedupe checks for {$total} borrowers.");

        return self::SUCCESS;
    }
}