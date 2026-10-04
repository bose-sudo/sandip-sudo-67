<?php

namespace App\Jobs;

use App\Models\BorrowerKyc;
use App\Services\Graviton\GravitonApplicantCreditReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncApplicantCreditReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public int $borrowerKycId) {}

    public function handle(GravitonApplicantCreditReportService $service): void
    {
        $borrower = BorrowerKyc::find($this->borrowerKycId);
        if (!$borrower) return;

        $service->submit($borrower, ['name' => $borrower->name]);
    }
}