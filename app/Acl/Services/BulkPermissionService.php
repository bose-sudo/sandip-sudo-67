<?php

namespace App\Acl\Services;

use App\Acl\Models\Permission;
use App\Acl\Models\PermissionAuditLog;
use App\Acl\Models\Role;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BulkPermissionService
{
    public function __construct(
        protected PermissionResolver $resolver,
        protected MenuBuilderService $menuBuilder,
    ) {}

    // ══════════════════════════════════════════════════════════════════════════
    // USER-LEVEL BULK OVERRIDES
    // ══════════════════════════════════════════════════════════════════════════

    public function bulkUpdateUserPermissions(
        Authenticatable $targetUser,
        array $permissionIds,
        string $action,
        Authenticatable $admin,
        ?string $reason = null,
    ): array {
        return DB::transaction(function () use ($targetUser, $permissionIds, $action, $admin, $reason) {

            $previousSlugs = $targetUser->directPermissions()
                ->pluck('slug', 'permissions.id')
                ->toArray();

            $permissions = Permission::whereIn('id', $permissionIds)
                ->where('permissions.is_active', true)
                ->get();

            $foundIds      = $permissions->pluck('id')->toArray();
            $missingIds    = array_values(array_diff($permissionIds, $foundIds));
            $affectedSlugs = $permissions->pluck('slug')->toArray();

            match ($action) {
                'grant'  => $this->applyGrant($targetUser, $permissions, $admin->id, $reason),
                'deny'   => $this->applyDeny($targetUser, $permissions, $admin->id, $reason),
                'revoke' => $this->applyRevoke($targetUser, $permissions),
            };

            $newSlugs = $targetUser->directPermissions()
                ->pluck('slug', 'permissions.id')
                ->toArray();

            PermissionAuditLog::create([
                'subject_type'     => 'user',
                'subject_id'       => $targetUser->id,
                'action'           => $action === 'revoke' ? 'revoked' : ($action === 'deny' ? 'denied' : 'granted'),
                'permission_slugs' => $affectedSlugs,
                'allow'            => $action === 'deny' ? false : ($action === 'grant' ? true : null),
                'performed_by'     => $admin->id,
                'reason'           => $reason,
                'previous_state'   => $previousSlugs,
                'new_state'        => $newSlugs,
            ]);

            $this->resolver->clearCacheForUser($targetUser->id);
            $this->menuBuilder->clearMenuCacheForUser($targetUser->id);

            return [
                'affected_count' => count($affectedSlugs),
                'affected_slugs' => $affectedSlugs,
                'skipped_ids'    => $missingIds,
                'action'         => $action,
            ];
        });
    }

    private function applyGrant(Authenticatable $user, Collection $permissions, int $adminId, ?string $reason): void
    {
        $syncData = $permissions->mapWithKeys(fn ($p) => [
            $p->id => ['allow' => true, 'reason' => $reason, 'granted_by' => $adminId],
        ])->toArray();

        $user->directPermissions()->syncWithoutDetaching($syncData);
    }

    private function applyDeny(Authenticatable $user, Collection $permissions, int $adminId, ?string $reason): void
    {
        $syncData = $permissions->mapWithKeys(fn ($p) => [
            $p->id => ['allow' => false, 'reason' => $reason, 'granted_by' => $adminId],
        ])->toArray();

        $user->directPermissions()->syncWithoutDetaching($syncData);
    }

    private function applyRevoke(Authenticatable $user, Collection $permissions): void
    {
        $user->directPermissions()->detach($permissions->pluck('id')->toArray());
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ROLE-LEVEL BULK CHANGES
    // ══════════════════════════════════════════════════════════════════════════

    public function syncRolePermissions(Role $role, array $permissionIds, Authenticatable $admin, ?string $reason = null): array
    {
        return DB::transaction(function () use ($role, $permissionIds, $admin, $reason) {

            $previousSlugs = $role->permissions()->pluck('permissions.slug')->toArray();

            $validIds = Permission::whereIn('id', $permissionIds)
                ->where('permissions.is_active', true)
                ->pluck('permissions.id')
                ->toArray();

            $role->permissions()->sync($validIds);

            $newSlugs = $role->permissions()->pluck('permissions.slug')->toArray();
            $added    = array_values(array_diff($newSlugs, $previousSlugs));
            $removed  = array_values(array_diff($previousSlugs, $newSlugs));

            if (! empty($added) || ! empty($removed)) {
                PermissionAuditLog::create([
                    'subject_type'     => 'role',
                    'subject_id'       => $role->id,
                    'action'           => 'granted',
                    'permission_slugs' => $newSlugs,
                    'performed_by'     => $admin->id,
                    'reason'           => $reason,
                    'previous_state'   => $previousSlugs,
                    'new_state'        => $newSlugs,
                ]);
            }

            $this->resolver->clearCacheForRole($role);
            $this->clearMenuCacheForRoleUsers($role);

            return [
                'added'       => $added,
                'removed'     => $removed,
                'total_now'   => count($newSlugs),
                'skipped_ids' => array_values(array_diff($permissionIds, $validIds)),
            ];
        });
    }

    public function attachRolePermissions(Role $role, array $permissionIds, Authenticatable $admin, ?string $reason = null): array
    {
        return DB::transaction(function () use ($role, $permissionIds, $admin, $reason) {

            $previousSlugs    = $role->permissions()->pluck('permissions.slug')->toArray();
            $validPermissions = Permission::whereIn('id', $permissionIds)->where('permissions.is_active', true)->get();

            $role->permissions()->syncWithoutDetaching($validPermissions->pluck('id')->toArray());

            $newSlugs = $role->permissions()->pluck('permissions.slug')->toArray();
            $added    = array_values(array_diff($newSlugs, $previousSlugs));

            if (! empty($added)) {
                PermissionAuditLog::create([
                    'subject_type'     => 'role',
                    'subject_id'       => $role->id,
                    'action'           => 'granted',
                    'permission_slugs' => $added,
                    'performed_by'     => $admin->id,
                    'reason'           => $reason,
                    'previous_state'   => $previousSlugs,
                    'new_state'        => $newSlugs,
                ]);

                $this->resolver->clearCacheForRole($role);
                $this->clearMenuCacheForRoleUsers($role);
            }

            return [
                'added'       => $added,
                'total_now'   => count($newSlugs),
                'skipped_ids' => array_values(array_diff($permissionIds, $validPermissions->pluck('id')->toArray())),
            ];
        });
    }

    public function detachRolePermissions(Role $role, array $permissionIds, Authenticatable $admin, ?string $reason = null): array
    {
        return DB::transaction(function () use ($role, $permissionIds, $admin, $reason) {

            $previousSlugs    = $role->permissions()->pluck('permissions.slug')->toArray();
            $validPermissions = Permission::whereIn('id', $permissionIds)->where('permissions.is_active', true)->get();

            $role->permissions()->detach($validPermissions->pluck('id')->toArray());

            $newSlugs = $role->permissions()->pluck('permissions.slug')->toArray();
            $removed  = array_values(array_diff($previousSlugs, $newSlugs));

            if (! empty($removed)) {
                PermissionAuditLog::create([
                    'subject_type'     => 'role',
                    'subject_id'       => $role->id,
                    'action'           => 'revoked',
                    'permission_slugs' => $removed,
                    'performed_by'     => $admin->id,
                    'reason'           => $reason,
                    'previous_state'   => $previousSlugs,
                    'new_state'        => $newSlugs,
                ]);

                $this->resolver->clearCacheForRole($role);
                $this->clearMenuCacheForRoleUsers($role);
            }

            return [
                'removed'     => $removed,
                'total_now'   => count($newSlugs),
                'skipped_ids' => array_values(array_diff($permissionIds, $validPermissions->pluck('id')->toArray())),
            ];
        });
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Clear menu cache for all users belonging to a role.
     * Uses direct DB query on admin_users — avoids Role::users() BelongsToMany
     * which references the wrong table name in PostgreSQL.
     */
    private function clearMenuCacheForRoleUsers(Role $role): void
    {
        $userIds = DB::table('admin_users')
            ->where('user_type', $role->id)
            ->where('is_active', true)
            ->pluck('id');

        foreach ($userIds as $userId) {
            $this->menuBuilder->clearMenuCacheForUser($userId);
        }
    }
}   