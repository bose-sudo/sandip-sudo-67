<?php

namespace App\Jobs;

use App\Services\Graviton\GravitonTemplateSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncGravitonReferenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public string $type = 'all',
        public ?int $productId = null
    ) {}

    public function handle(GravitonTemplateSyncService $service): void
    {
        Log::info("Graviton reference sync started: {$this->type}");

        $result = match ($this->type) {
            'family_member' => ['family_member' => $service->syncFamilyMemberFields()],
            'loan'          => ['loan'          => $service->syncLoanFields($this->productId)],
            'all'           => $service->syncAll(),
            default         => throw new \InvalidArgumentException("Unknown: {$this->type}"),
        };

        Log::info("Graviton reference sync done: {$this->type}", $result);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("Graviton reference sync failed: {$this->type}", [
            'error' => $e->getMessage(),
        ]);
    }
}