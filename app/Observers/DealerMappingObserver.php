<?php

namespace App\Observers;

use App\Models\DealerUserMap;
use App\Services\BorrowerVisibilityService;
use Illuminate\Support\Facades\Log;

/**
 * Busts the cached visible-dealer list whenever a dealer↔user mapping
 * is created, updated, or deleted.
 *
 * Why bust ancestors too?
 *   In 'inherit' mode, a dealer mapped to a Sales Exec is also visible to
 *   their RSM, Sales Manager, etc. When that mapping changes, every ancestor's
 *   cached list is stale — not just the directly mapped user's.
 */
class DealerMappingObserver
{
    public function __construct(
        private readonly BorrowerVisibilityService $visibilityService
    ) {}

    public function created(DealerUserMap $mapping): void
    {
        $this->bust($mapping);
    }

    public function updated(DealerUserMap $mapping): void
    {
        $this->bust($mapping);
    }

    public function deleted(DealerUserMap $mapping): void
    {
        $this->bust($mapping);
    }

    // -------------------------------------------------------------------------

    private function bust(DealerUserMap $mapping): void
    {
        try {
            // Bust the directly mapped user
            $this->visibilityService->bustDealerCache($mapping->user_id);

            // Bust all ancestors (they inherit visibility changes)
            if ($mapping->visibility_mode === 'inherit') {
                $this->visibilityService->bustDealerCacheForAncestors($mapping->user_id);
            }
        } catch (\Throwable $e) {
            // Cache busting failure must never break the main request
            Log::warning('DealerMappingObserver: cache bust failed', [
                'mapping_id' => $mapping->id,
                'user_id'    => $mapping->user_id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
