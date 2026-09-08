<?php

namespace App\Services;

use App\Models\Dealer;
use App\Models\DealerUserMap;
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;

class DealerMappingService
{
    // =========================================================================
    // Mapping CRUD
    // =========================================================================

    /**
     * Map a dealer to a user. If the mapping already exists (even inactive),
     * it is restored and updated.
     */
    public function mapDealerToUser(
        string $dealerUnq,
        int    $userId,
        string $visibilityMode,
        int    $createdBy
    ): DealerUserMap {
        // Verify both entities exist and are active
        $dealer = Dealer::where('dealer_unq', $dealerUnq)
            ->where('is_deleted', false)
            ->firstOrFail();

        $user = AdminUser::where('id', $userId)
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->firstOrFail();

        return DB::transaction(function () use ($dealerUnq, $userId, $visibilityMode, $createdBy) {
            return DealerUserMap::updateOrCreate(
                ['dealer_unq' => $dealerUnq, 'user_id' => $userId],
                [
                    'status'          => 'active',
                    'visibility_mode' => $visibilityMode,
                    'created_by'      => $createdBy,
                    'updated_by'      => $createdBy,
                ]
            );
        });
    }

    /**
     * Update visibility mode or status of an existing mapping.
     */
    public function updateMapping(
        int    $mappingId,
        array  $data,
        int    $updatedBy
    ): DealerUserMap {
        return DB::transaction(function () use ($mappingId, $data, $updatedBy) {
            $mapping = DealerUserMap::findOrFail($mappingId);

            $mapping->update(array_merge($data, ['updated_by' => $updatedBy]));

            return $mapping->fresh();
        });
    }

    /**
     * Deactivate a specific dealer→user mapping.
     * The user (and their ancestors in inherit mode) will lose visibility.
     */
    public function deactivateMapping(int $mappingId, int $updatedBy): DealerUserMap
    {
        return DB::transaction(function () use ($mappingId, $updatedBy) {
            $mapping = DealerUserMap::findOrFail($mappingId);

            $mapping->update([
                'status'     => 'inactive',
                'updated_by' => $updatedBy,
            ]);

            return $mapping->fresh();
        });
    }

    /**
     * Activate a previously deactivated mapping.
     */
    public function activateMapping(int $mappingId, int $updatedBy): DealerUserMap
    {
        return DB::transaction(function () use ($mappingId, $updatedBy) {
            $mapping = DealerUserMap::findOrFail($mappingId);

            $mapping->update([
                'status'     => 'active',
                'updated_by' => $updatedBy,
            ]);

            return $mapping->fresh();
        });
    }

    /**
     * Remove a mapping permanently.
     */
    public function deleteMapping(int $mappingId): bool
    {
        return DB::transaction(function () use ($mappingId) {
            $mapping = DealerUserMap::findOrFail($mappingId);
            return $mapping->delete();
        });
    }

    // =========================================================================
    // Query helpers
    // =========================================================================

    /**
     * List all users mapped to a given dealer (with active filter option).
     */
    public function getMappedUsers(string $dealerUnq, bool $activeOnly = true): \Illuminate\Database\Eloquent\Builder
    {
        return DealerUserMap::with(['user', 'user.userTypeRelation'])
            ->where('dealer_unq', $dealerUnq)
            ->when($activeOnly, fn($q) => $q->where('status', 'active'));
    }

    /**
     * List all dealers mapped to a given user (with active filter option).
     */
    public function getMappedDealers(int $userId, bool $activeOnly = true): \Illuminate\Database\Eloquent\Builder
    {
        return DealerUserMap::with(['dealer'])
            ->where('user_id', $userId)
            ->when($activeOnly, fn($q) => $q->where('status', 'active'));
    }
}
