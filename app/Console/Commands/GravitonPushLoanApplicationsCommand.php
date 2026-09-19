<?php

namespace App\Console\Commands;

use App\Jobs\PushLoanApplicationJob;
use App\Models\BorrowerKyc;
use Illuminate\Console\Command;

class GravitonPushLoanApplicationsCommand extends Command
{
    protected $signature   = 'graviton:push-loan-applications {borrower_id? : Optional borrower ID}';
    protected $description = 'Push approved borrowers (no_match) to Graviton newApplicationForm';

    public function handle(): int
    {
        $borrowerId = $this->argument('borrower_id');

        // Specific
        if ($borrowerId) {
            $borrower = BorrowerKyc::find($borrowerId);
            if (!$borrower) {
                $this->error("Borrower {$borrowerId} not found.");
                return self::FAILURE;
            }
            PushLoanApplicationJob::dispatch($borrower->id);
            $this->info("✅ Push dispatched for borrower {$borrowerId}");
            return self::SUCCESS;
        }

        // All no_match borrowers — without graviton_client_id
        $ids = BorrowerKyc::where('dedupe_status', 'no_match')
            ->whereNull('graviton_client_id')
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->warn('No approved borrowers pending.');
            return self::SUCCESS;
        }

        $total = $ids->count();
        $this->info("Found {$total} approved borrowers. Dispatching push jobs...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($ids as $id) {
            PushLoanApplicationJob::dispatch($id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Dispatched push for {$total} borrowers.");

        return self::SUCCESS;
    }
}