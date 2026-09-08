<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\FullAccessUserType;
use App\Models\UserType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FullAccessService
{
    // =========================================================================
    // Single user flag
    // =========================================================================

    public function grantToUser(int $userId): AdminUser
    {
        return DB::transaction(function () use ($userId) {
            $user = AdminUser::where('id', $userId)->where('is_deleted', false)->firstOrFail();
            $user->update(['is_all_borrower_accessible' => true]);
            return $user->fresh();
        });
    }

    public function revokeFromUser(int $userId): AdminUser
    {
        return DB::transaction(function () use ($userId) {
            $user = AdminUser::where('id', $userId)->where('is_deleted', false)->firstOrFail();
            $user->update(['is_all_borrower_accessible' => false]);
            return $user->fresh();
        });
    }

    // =========================================================================
    // Bulk user flag management
    // =========================================================================

    /**
     * Grant full access to multiple users in one call.
     *
     * Each user is processed independently. Returns a per-user result summary.
     *
     * @param  int[] $userIds
     * @return array{ success: list<array>, failed: list<array> }
     */
    public function bulkGrantToUsers(array $userIds): array
    {
        $success = [];
        $failed  = [];

        foreach ($userIds as $userId) {
            try {
                $user      = $this->grantToUser($userId);
                $success[] = $user->only('id', 'name', 'email', 'is_all_borrower_accessible');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $failed[] = ['user_id' => $userId, 'reason' => 'User not found.'];
            } catch (\Throwable $e) {
                $failed[] = ['user_id' => $userId, 'reason' => 'Unexpected error. Please try again.'];
            }
        }

        return compact('success', 'failed');
    }

    /**
     * Apply a single action (grant | revoke) to multiple users.
     *
     * @param  int[]  $userIds
     * @param  string $action   grant | revoke
     * @return array{ success: list<array>, failed: list<array> }
     */
    public function bulkManageUsers(array $userIds, string $action): array
    {
        $success = [];
        $failed  = [];

        foreach ($userIds as $userId) {
            try {
                $user = match ($action) {
                    'grant'  => $this->grantToUser($userId),
                    'revoke' => $this->revokeFromUser($userId),
                };

                $success[] = $user->only('id', 'name', 'email', 'is_all_borrower_accessible');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $failed[] = ['user_id' => $userId, 'reason' => 'User not found.'];
            } catch (\Throwable $e) {
                $failed[] = ['user_id' => $userId, 'reason' => 'Unexpected error. Please try again.'];
            }
        }

        return compact('success', 'failed');
    }

    // =========================================================================
    // User-type config
    // =========================================================================

    public function grantToUserType(int $userTypeId, int $grantedBy): FullAccessUserType
    {
        UserType::findOrFail($userTypeId);

        return DB::transaction(function () use ($userTypeId, $grantedBy) {
            $config = FullAccessUserType::updateOrCreate(
                ['user_type_id' => $userTypeId],
                ['granted_by'   => $grantedBy]
            );

            AdminUser::where('user_type', $userTypeId)
                ->where('is_deleted', false)
                ->where('is_all_borrower_accessible', false)
                ->update(['is_all_borrower_accessible' => true]);

            return $config;
        });
    }

    public function revokeFromUserType(int $userTypeId): bool
    {
        return DB::transaction(function () use ($userTypeId) {
            return (bool) FullAccessUserType::where('user_type_id', $userTypeId)->delete();
        });
    }

    public function revokeFromUserTypeAndUsers(int $userTypeId): array
    {
        return DB::transaction(function () use ($userTypeId) {
            FullAccessUserType::where('user_type_id', $userTypeId)->delete();

            $affected = AdminUser::where('user_type', $userTypeId)
                ->where('is_all_borrower_accessible', true)
                ->where('is_deleted', false)
                ->update(['is_all_borrower_accessible' => false]);

            return ['config_removed' => true, 'users_revoked' => $affected];
        });
    }

    // =========================================================================
    // Queries
    // =========================================================================

    public function getFullAccessUserTypes(): Collection
    {
        return FullAccessUserType::with(['userType:id,user_type,role', 'grantedBy:id,name,email'])->get();
    }

    public function getFullAccessUsers(): \Illuminate\Database\Eloquent\Builder
    {
        return AdminUser::where('is_all_borrower_accessible', true)
            ->where('is_deleted', false)
            ->with('userTypeRelation:id,user_type,role');
    }

    public function isUserTypeFullAccess(int $userTypeId): bool
    {
        return FullAccessUserType::where('user_type_id', $userTypeId)->exists();
    }
}