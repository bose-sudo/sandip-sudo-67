<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use App\Jobs\SyncGravitonReferenceDataJob;
use App\Models\BorrowerKyc;
use App\Models\GravitonReferenceData;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */

    protected function schedule(Schedule $schedule): void
    {
        // ─────────────────────────────────────────────────
        // Sync reference data for all borrowers
        // Runs every hour — adjust as needed
        // ─────────────────────────────────────────────────
        $schedule->call(function () {
            // Option A: Sync ALL borrowers
            // BorrowerKyc::chunk(50, function ($borrowers) {
            //     foreach ($borrowers as $b) {
            //         SyncGravitonReferenceDataJob::dispatch($b->id);
            //     }
            // });

            // Option B: Sync ONLY borrowers missing reference data
            $missing = BorrowerKyc::whereDoesntHave('gravitonReferenceData')
                ->orWhereHas('gravitonReferenceData', function ($q) {
                    $q->where('sync_status', 'failed')
                      ->orWhere('last_synced_at', '<', now()->subDays(7));
                })
                ->pluck('id');

            foreach ($missing as $borrowerId) {
                SyncGravitonReferenceDataJob::dispatch($borrowerId);
            }
        })
        ->name('graviton:sync-reference-data')
        ->hourly()
        ->withoutOverlapping()
        ->onOneServer();

        // Graviton dedupe check — 3 AM
        $schedule->command('graviton:dedupe-check')
            ->dailyAt('03:00')
            ->withoutOverlapping();
    }
    // protected function schedule(Schedule $schedule): void
    // {
    //     // $schedule->command('inspire')->hourly();
    // }

    // protected function schedule(Schedule $schedule): void
    // {
    //     // Daily 2 AM — sync reference data
    //     $schedule->command('graviton:sync-reference-all')
    //             ->dailyAt('02:00')
    //             ->withoutOverlapping()
    //             ->onOneServer()
    //             ->runInBackground();
    // }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
