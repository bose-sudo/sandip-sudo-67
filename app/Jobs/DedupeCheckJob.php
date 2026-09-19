<?php

namespace App\Jobs;

use App\Models\BorrowerKyc;
use App\Services\Graviton\GravitonDedupeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DedupeCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $borrowerId) {}

    public function handle(GravitonDedupeService $service): void
    {
        $borrower = BorrowerKyc::find($this->borrowerId);

        if (!$borrower) {
            Log::warning("Borrower {$this->borrowerId} not found");
            return;
        }

        $service->checkForBorrower($borrower);
    }
}