<?php

namespace App\Jobs;

use App\Models\BorrowerKyc;
use App\Services\Graviton\GravitonCoapplicantCreditReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncCoapplicantCreditReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public int $borrowerKycId,
        public int $familyMemberId,
        public ?string $name = null,
        public string $relationType = 'Co-Applicant'
    ) {}

    public function handle(GravitonCoapplicantCreditReportService $service): void
    {
        $borrower = BorrowerKyc::find($this->borrowerKycId);
        if (!$borrower) return;

        $service->submit($borrower, [
            'name'           => $this->name ?? $borrower->name,
            'familyMemberId' => $this->familyMemberId,
            'relationType'   => $this->relationType,
        ]);
    }
}