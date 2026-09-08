<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\DealerUserMap;
use App\Models\UserHierarchyClosure;
use App\Models\UserRelationship;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UserHierarchyService
{
    // =========================================================================
    // Single Relationship Management
    // =========================================================================

    public function addRelationship(int $parentId, int $childId, int $createdBy): UserRelationship
    {
        if ($this->wouldCreateCycle($parentId, $childId)) {
            throw new \RuntimeException(
                'Cannot create relationship: the child user is already an ancestor of the parent.'
            );
        }

        if ($parentId === $childId) {
            throw new \RuntimeException('A user cannot be their own parent.');
        }

        return DB::transaction(function () use ($parentId, $childId, $createdBy) {
            return UserRelationship::updateOrCreate(
                ['parent_user_id' => $parentId, 'child_user_id' => $childId],
                ['is_active' => true, 'created_by' => $createdBy]
            );
        });
    }

    public function removeRelationship(int $parentId, int $childId): bool
    {
        return DB::transaction(function () use ($parentId, $childId) {
            $relationship = UserRelationship::where('parent_user_id', $parentId)
                ->where('child_user_id', $childId)
                ->firstOrFail();

            return $relationship->delete();
        });
    }

    public function deactivateRelationship(int $parentId, int $childId, int $updatedBy): bool
    {
        return (bool) UserRelationship::where('parent_user_id', $parentId)
            ->where('child_user_id', $childId)
            ->update(['is_active' => false]);
    }

    public function activateRelationship(int $parentId, int $childId, int $updatedBy): bool
    {
        $exists = UserRelationship::where('parent_user_id', $parentId)
            ->where('child_user_id', $childId)
            ->exists();

        if (!$exists) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Relationship not found.');
        }

        return (bool) UserRelationship::where('parent_user_id', $parentId)
            ->where('child_user_id', $childId)
            ->update(['is_active' => true]);
    }

    // =========================================================================
    // Bulk Child Management
    // =========================================================================

    /**
     * Add multiple children to a single parent in one call.
     *
     * Each child is processed independently — one failure does not block the
     * rest. Returns a per-child result summary so the caller can surface
     * exactly which ones succeeded and which failed (and why).
     *
     * @param  int   $parentId
     * @param  int[] $childIds
     * @param  int   $createdBy
     * @return array{ success: list<array>, failed: list<array> }
     */
    public function bulkAddChildren(int $parentId, array $childIds, int $createdBy): array
    {
        $success = [];
        $failed  = [];

        foreach ($childIds as $childId) {
            try {
                $relationship = $this->addRelationship($parentId, $childId, $createdBy);
                $success[]    = [
                    'child_user_id' => $childId,
                    'relationship'  => $relationship,
                ];
            } catch (\RuntimeException $e) {
                $failed[] = [
                    'child_user_id' => $childId,
                    'reason'        => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'child_user_id' => $childId,
                    'reason'        => 'Unexpected error. Please try again.',
                ];
            }
        }

        return compact('success', 'failed');
    }

    /**
     * Apply a single action to multiple children of a given parent.
     *
     * @param  int    $parentId
     * @param  int[]  $childIds
     * @param  string $action    remove | activate | deactivate
     * @param  int    $updatedBy
     * @return array{ success: list<array>, failed: list<array> }
     */
    public function bulkManageChildren(int $parentId, array $childIds, string $action, int $updatedBy): array
    {
        $success = [];
        $failed  = [];

        foreach ($childIds as $childId) {
            try {
                match ($action) {
                    'remove' => $this->removeRelationship($parentId, $childId),

                    'activate' => UserRelationship::where('parent_user_id', $parentId)
                                    ->where('child_user_id', $childId)
                                    ->firstOrFail()
                                    ->update(['is_active' => true]),

                    'deactivate' => $this->deactivateRelationship($parentId, $childId, $updatedBy),
                };

                $success[] = ['child_user_id' => $childId];
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $failed[] = [
                    'child_user_id' => $childId,
                    'reason'        => 'Relationship not found.',
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'child_user_id' => $childId,
                    'reason'        => 'Unexpected error. Please try again.',
                ];
            }
        }

        return compact('success', 'failed');
    }

    // =========================================================================
    // Subtree Queries
    // =========================================================================

    public function getDescendantIds(int $userId): Collection
    {
        return UserHierarchyClosure::where('ancestor_id', $userId)->pluck('descendant_id');
    }

    public function getAncestorIds(int $userId): Collection
    {
        return UserHierarchyClosure::where('descendant_id', $userId)->pluck('ancestor_id');
    }

    public function getDescendants(int $userId): \Illuminate\Database\Eloquent\Builder
    {
        $descendantIds = $this->getDescendantIds($userId);

        return AdminUser::whereIn('id', $descendantIds)
            ->where('is_active', true)
            ->where('is_deleted', false);
    }

    // =========================================================================
    // Dealer Visibility Resolution
    // =========================================================================

    public function getVisibleDealerUnqs(int $userId): Collection
    {
        $subtreeUserIds = $this->getDescendantIds($userId);

        $inheritDealers = DealerUserMap::query()
            ->whereIn('user_id', $subtreeUserIds)
            ->where('status', 'active')
            ->where('visibility_mode', 'inherit')
            ->pluck('dealer_unq');

        $strictDealers = DealerUserMap::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where('visibility_mode', 'strict')
            ->pluck('dealer_unq');

        return $inheritDealers->merge($strictDealers)->unique()->values();
    }

    // =========================================================================
    // Cycle Detection
    // =========================================================================

    private function wouldCreateCycle(int $parentId, int $childId): bool
    {
        return UserHierarchyClosure::where('ancestor_id', $childId)
            ->where('descendant_id', $parentId)
            ->exists();
    }
}