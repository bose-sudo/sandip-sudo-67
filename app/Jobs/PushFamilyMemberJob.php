<?php

namespace App\Jobs;

use App\Models\BorrowerKyc;
use App\Services\Graviton\GravitonFamilyMemberPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushFamilyMemberJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public int $borrowerId,
        public string $role
    ) {}

    public function handle(GravitonFamilyMemberPushService $service): void
    {
        $borrower = BorrowerKyc::find($this->borrowerId);

        if (!$borrower) {
            Log::warning("Borrower {$this->borrowerId} not found");
            return;
        }

        if (empty($borrower->graviton_client_id)) {
            Log::warning("Borrower {$this->borrowerId} has no graviton_client_id");
            return;
        }

        $service->push($borrower, $this->role);
    }
}