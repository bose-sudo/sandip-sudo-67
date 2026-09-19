<?php

namespace App\Jobs;

use App\Models\BorrowerKyc;
use App\Services\Graviton\GravitonLoanApplicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushLoanApplicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $borrowerId) {}

    public function handle(GravitonLoanApplicationService $service): void
    {
        $borrower = BorrowerKyc::find($this->borrowerId);

        if (!$borrower) {
            Log::warning("Borrower {$this->borrowerId} not found");
            return;
        }

        if ($borrower->dedupe_status !== 'no_match') {
            Log::info("Skipping borrower {$this->borrowerId} — dedupe_status: {$borrower->dedupe_status}");
            return;
        }

        $service->pushLoanApplication($borrower);
    }
}