<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\DealerUserMap;
use App\Models\LoanBasicDetails;
use App\Models\UserHierarchyClosure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves which borrowers (loan_basic_details rows) a given admin user
 * is permitted to see, based on:
 *
 *  FAST PATH  — is_all_borrower_accessible = true
 *               → skip all hierarchy logic, return all active borrowers
 *
 *  NORMAL PATH — hierarchy + dealer mapping + visibility mode
 *    1. User's full descendant subtree  (closure table)
 *    2. Dealers mapped to that subtree  (dealer_user_map, inherit mode)
 *    3. Dealers mapped directly to user (dealer_user_map, strict mode)
 *    4. loan_basic_details WHERE dealer_id IN (visible dealers)
 *    5. Optional extra filters
 */
class BorrowerVisibilityService
{
    private const DEALER_CACHE_TTL = 300; // seconds — 0 to disable

    public function __construct(
        private readonly UserHierarchyService $hierarchyService
    ) {}

    // =========================================================================
    // Primary entry point
    // =========================================================================

    /**
     * Returns a base Eloquent Builder scoped to borrowers the given user can see.
     * Callers chain additional where/orderBy/paginate on top.
     */
    public function getBorrowerQuery(int $userId, array $filters = []): Builder
    {
        $user = AdminUser::findOrFail($userId);

        $query = $user->hasFullBorrowerAccess()
            ? $this->buildFullAccessQuery()
            : $this->buildHierarchyQuery($userId);

        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * Check whether a user has full access — simple flag read.
     * Callers can use this to conditionally skip other visibility checks.
     */
    public function isFullAccess(int $userId): bool
    {
        return (bool) AdminUser::where('id', $userId)->value('is_all_borrower_accessible');
    }

    // =========================================================================
    // Query builders
    // =========================================================================

    /**
     * Full-access path: no hierarchy logic, return all active borrowers.
     */
    private function buildFullAccessQuery(): Builder
    {
        return LoanBasicDetails::query()->where('is_active', true);
    }

    /**
     * Normal hierarchy path: scope to visible dealers only.
     */
    private function buildHierarchyQuery(int $userId): Builder
    {
        $visibleDealerUnqs = $this->resolveVisibleDealerUnqs($userId);

        return LoanBasicDetails::query()
            ->whereIn('dealer_id', $visibleDealerUnqs)
            ->where('is_active', true);
    }

    // =========================================================================
    // Dealer resolution (cached for normal-path users only)
    // =========================================================================

    public function resolveVisibleDealerUnqs(int $userId): Collection
    {
        $cacheKey = "visible_dealers:user:{$userId}";

        if (self::DEALER_CACHE_TTL > 0) {
            return Cache::remember($cacheKey, self::DEALER_CACHE_TTL, fn () =>
                $this->computeVisibleDealerUnqs($userId)
            );
        }

        return $this->computeVisibleDealerUnqs($userId);
    }

    public function bustDealerCache(int $userId): void
    {
        Cache::forget("visible_dealers:user:{$userId}");
    }

    public function bustDealerCacheForAncestors(int $userId): void
    {
        $ancestorIds = UserHierarchyClosure::where('descendant_id', $userId)->pluck('ancestor_id');

        foreach ($ancestorIds as $ancestorId) {
            $this->bustDealerCache($ancestorId);
        }
    }

    // =========================================================================
    // Core computation
    // =========================================================================

    private function computeVisibleDealerUnqs(int $userId): Collection
    {
        // Step 1 — full subtree (includes self via depth=0 self-reference)
        $subtreeUserIds = UserHierarchyClosure::where('ancestor_id', $userId)
            ->pluck('descendant_id');

        // Step 2a — inherit mode: visible to $userId and all ancestors
        $inheritDealers = DealerUserMap::whereIn('user_id', $subtreeUserIds)
            ->where('status', 'active')
            ->where('visibility_mode', 'inherit')
            ->pluck('dealer_unq');

        // Step 2b — strict mode: visible to $userId only
        $strictDealers = DealerUserMap::where('user_id', $userId)
            ->where('status', 'active')
            ->where('visibility_mode', 'strict')
            ->pluck('dealer_unq');

        return $inheritDealers->merge($strictDealers)->unique()->values();
    }

    // =========================================================================
    // Filter application
    // =========================================================================

    private function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['dealer_id'])) {
            $query->where('dealer_id', $filters['dealer_id']);
        }

        if (!empty($filters['status'])) {
            $query->whereIn('status', (array) $filters['status']);
        }

        if (!empty($filters['executive_id'])) {
            $query->where('executive_id', (int) $filters['executive_id']);
        }

        if (!empty($filters['lender_id'])) {
            $query->where('lender_id', (int) $filters['lender_id']);
        }

        if (!empty($filters['loan_amount_min'])) {
            $query->where('loan_amount', '>=', (float) $filters['loan_amount_min']);
        }

        if (!empty($filters['loan_amount_max'])) {
            $query->where('loan_amount', '<=', (float) $filters['loan_amount_max']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('loan_basic_details.created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('loan_basic_details.created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->leftJoin('users as borrower_user', 'borrower_user.id', '=', 'loan_basic_details.user_id')
                ->where(function (Builder $q) use ($term) {
                    $q->where('borrower_user.name', 'ilike', $term)
                      ->orWhere('borrower_user.mobile', 'ilike', $term)
                      ->orWhere('loan_basic_details.id', 'like', ltrim($term, '%'));
                });
        }
    }
}
