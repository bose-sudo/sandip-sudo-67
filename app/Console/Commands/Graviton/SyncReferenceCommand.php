<?php

namespace App\Console\Commands\Graviton;

use App\Jobs\SyncGravitonReferenceJob;
use App\Services\Graviton\GravitonTemplateSyncService;
use Illuminate\Console\Command;

class SyncReferenceCommand extends Command
{
    protected $signature   = 'graviton:sync-reference {type} {--productId=} {--sync : Run synchronously instead of dispatching job}';
    protected $description = 'Sync one: family_member | loan';

    public function handle(GravitonTemplateSyncService $service): int
    {
        $type      = $this->argument('type');
        $productId = $this->option('productId') ? (int) $this->option('productId') : null;

        // Validate type
        if (!in_array($type, ['family_member', 'loan'])) {
            $this->error("❌ Unknown type: {$type}. Use 'family_member' or 'loan'.");
            return self::FAILURE;
        }

        // ═══════════════════════════════════════════════════
        // Option 1: Direct sync (--sync flag)
        // ═══════════════════════════════════════════════════
        if ($this->option('sync')) {
            $this->info("🔄 Running {$type} synchronously...");

            try {
                $count = match ($type) {
                    'family_member' => $service->syncFamilyMemberFields(),
                    'loan'          => $service->syncLoanFields($productId),
                };

                $this->info("✅ {$type}: {$count} records synced.");
                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error('❌ ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        // ═══════════════════════════════════════════════════
        // Option 2: Job dispatch (default — async)
        // ═══════════════════════════════════════════════════
        SyncGravitonReferenceJob::dispatch($type, $productId);
        $this->info("✅ Job dispatched: {$type}");
        $this->line('   Check with: php artisan queue:work');
        return self::SUCCESS;
    }
}