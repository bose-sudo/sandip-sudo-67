<?php

namespace App\Jobs;

use App\Models\BorrowerKyc;
use App\Models\CoapplicantKyc;
use App\Models\Guarantor;
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

        // ✅ graviton_client_id check
        if (empty($borrower->graviton_client_id)) {
            Log::warning("Borrower {$this->borrowerId} has no graviton_client_id");
            return;
        }

        // ✅ graviton_loan_id check (NEW)
        if (empty($borrower->graviton_loan_id)) {
            Log::warning("Borrower {$this->borrowerId} has no graviton_loan_id", [
                'borrower_kyc_id' => $borrower->id,
                'borrower_id'     => $borrower->borrower_id,
                'role'            => $this->role,
            ]);
            return;
        }

        // ✅ Family member data check (NEW)
        if ($this->role === 'coapplicant') {
            if (!CoapplicantKyc::where('borrower_id', $borrower->borrower_id)->exists()) {
                Log::warning("No coapplicant data for borrower {$this->borrowerId}");
                return;
            }
        } elseif ($this->role === 'guarantor') {
            if (!Guarantor::where('borrower_id', $borrower->borrower_id)->exists()) {
                Log::warning("No guarantor data for borrower {$this->borrowerId}");
                return;
            }
        }

        $service->push($borrower, $this->role);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PushFamilyMemberJob FAILED', [
            'borrower_kyc_id' => $this->borrowerId,
            'role'            => $this->role,
            'error'           => $e->getMessage(),
        ]);
    }
}