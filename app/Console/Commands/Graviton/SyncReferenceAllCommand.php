<?php

namespace App\Console\Commands\Graviton;

use App\Jobs\SyncGravitonReferenceJob;
use App\Services\Graviton\GravitonTemplateSyncService;
use Illuminate\Console\Command;

class SyncReferenceAllCommand extends Command
{
    protected $signature   = 'graviton:sync-reference-all {--sync : Run synchronously instead of dispatching job}';
    protected $description = 'Sync ALL missing-field reference data from Graviton templates';

    public function handle(GravitonTemplateSyncService $service): int
    {
        // ═══════════════════════════════════════════════════
        // Option 1: Direct sync (--sync flag)
        // ═══════════════════════════════════════════════════
        if ($this->option('sync')) {
            $this->info('🔄 Running synchronously...');

            try {
                $result = $service->syncAll();

                $this->table(
                    ['Template', 'Records'],
                    [
                        ['Family Member', $result['family_member']],
                        ['Loan',          $result['loan']],
                    ]
                );

                $this->info('✅ Sync completed.');
                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error('❌ ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        // ═══════════════════════════════════════════════════
        // Option 2: Job dispatch (default — async)
        // ═══════════════════════════════════════════════════
        SyncGravitonReferenceJob::dispatch('all');
        $this->info('✅ Job dispatched to queue.');
        $this->line('   Check with: php artisan queue:work');
        return self::SUCCESS;
    }
}