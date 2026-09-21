<?php

namespace App\Console\Commands;

use App\Jobs\PushFamilyMemberJob;
use App\Models\BorrowerKyc;
use App\Models\CoapplicantKyc;
use App\Models\Guarantor;
use App\Services\Graviton\GravitonReferenceHelperService;
use Illuminate\Console\Command;

class GravitonPushFamilyMembersCommand extends Command
{
    protected $signature   = 'graviton:push-family-members {borrower_id?}';
    protected $description = 'Push co-applicants and guarantors to Graviton';

    public function handle(): int
    {
        $borrowerId = $this->argument('borrower_id');

        if ($borrowerId) {
            $borrower = BorrowerKyc::find($borrowerId);
            if (!$borrower) {
                $this->error("Borrower {$borrowerId} not found.");
                return self::FAILURE;
            }
            $this->dispatchForBorrower($borrower);
            return self::SUCCESS;
        }

        $borrowers = BorrowerKyc::whereNotNull('graviton_client_id')->get();

        if ($borrowers->isEmpty()) {
            $this->warn('No borrowers with graviton_client_id.');
            return self::SUCCESS;
        }

        $total = $borrowers->count();
        $this->info("Processing {$total} borrowers...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($borrowers as $borrower) {
            $this->dispatchForBorrower($borrower);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Dispatched for {$total} borrowers.");
        return self::SUCCESS;
    }

    protected function dispatchForBorrower(BorrowerKyc $borrower): void
    {
        GravitonReferenceHelperService::clearCache();

        if (CoapplicantKyc::where('borrower_id', $borrower->borrower_id)->exists()) {
            PushFamilyMemberJob::dispatch($borrower->id, 'coapplicant');
            $this->line("  → Co-Applicant job for borrower {$borrower->id}");
        }

        if (Guarantor::where('borrower_id', $borrower->borrower_id)->exists()) {
            PushFamilyMemberJob::dispatch($borrower->id, 'guarantor');
            $this->line("  → Guarantor job for borrower {$borrower->id}");
        }
    }
}