<?php

namespace App\Acl\Traits;

use App\Acl\Models\Permission;
use App\Acl\Models\Role;
use App\Acl\Services\PermissionResolver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasPermissions
{
    // ─── Relationships ────────────────────────────────────────────────────────

    /**
     * A user belongs to one role (user_type) via admin_users.user_type = user_types.id
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'user_type', 'id');
    }

    /**
     * Direct permission overrides per user.
     */
    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'user_permissions',
            'user_id',
            'permission_id',
            'id',
            'id'
        )->withPivot(['allow', 'reason', 'granted_by'])
         ->withTimestamps();
    }

    // ─── Core Permission Checks ───────────────────────────────────────────────

    public function hasPermission(string $slug): bool
    {
        return app(PermissionResolver::class)->resolve($this, $slug);
    }

    public function hasPermissionForRoute(string $routeName): bool
    {
        return app(PermissionResolver::class)->resolveByRoute($this, $routeName);
    }

    public function hasAnyPermission(array $slugs): bool
    {
        foreach ($slugs as $slug) {
            if ($this->hasPermission($slug)) {
                return true;
            }
        }
        return false;
    }

    public function hasAllPermissions(array $slugs): bool
    {
        foreach ($slugs as $slug) {
            if (! $this->hasPermission($slug)) {
                return false;
            }
        }
        return true;
    }

    // ─── Role checks by ID ────────────────────────────────────────────────────

    /**
     * Check if user belongs to a role by ID.
     * Usage: $user->hasRole(3)
     */
    public function hasRole(int $roleId): bool
    {
        return (int) $this->user_type === $roleId;
    }

    /**
     * Check if user belongs to any of the given role IDs.
     * Usage: $user->hasAnyRole([1, 3])
     */
    public function hasAnyRole(array $roleIds): bool
    {
        return in_array((int) $this->user_type, $roleIds, true);
    }

    // ─── Role Management ──────────────────────────────────────────────────────

    /**
     * Assign a role by updating admin_users.user_type.
     */
    public function assignRole(int $roleId): void
    {
        $this->user_type = $roleId;
        $this->save();
        $this->clearPermissionCache();
    }

    // ─── Direct Permission Overrides ──────────────────────────────────────────

    public function grantPermission(string $slug, ?string $reason = null, ?int $grantedBy = null): void
    {
        $permission = Permission::where('slug', $slug)->firstOrFail();
        $this->directPermissions()->syncWithoutDetaching([
            $permission->id => [
                'allow'      => true,
                'reason'     => $reason,
                'granted_by' => $grantedBy,
            ],
        ]);
        $this->clearPermissionCache();
    }

    public function denyPermission(string $slug, ?string $reason = null, ?int $grantedBy = null): void
    {
        $permission = Permission::where('slug', $slug)->firstOrFail();
        $this->directPermissions()->syncWithoutDetaching([
            $permission->id => [
                'allow'      => false,
                'reason'     => $reason,
                'granted_by' => $grantedBy,
            ],
        ]);
        $this->clearPermissionCache();
    }

    public function revokeDirectPermission(string $slug): void
    {
        $permission = Permission::where('slug', $slug)->firstOrFail();
        $this->directPermissions()->detach($permission->id);
        $this->clearPermissionCache();
    }

    // ─── Cache ────────────────────────────────────────────────────────────────

    public function clearPermissionCache(): void
    {
        app(PermissionResolver::class)->clearCacheForUser($this->id);
    }

    public function getPermissionSlugs(): array
    {
        return app(PermissionResolver::class)->getAllowedSlugs($this);
    }
}