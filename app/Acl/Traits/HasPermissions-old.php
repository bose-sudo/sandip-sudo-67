<?php

namespace App\Acl\Traits;

use App\Acl\Models\Permission;
use App\Acl\Models\Role;
use App\Acl\Services\PermissionResolver;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Add this trait to your User model.
 *
 * use App\Acl\Traits\HasPermissions;
 *
 * class User extends Authenticatable {
 *     use HasPermissions;
 * }
 */
trait HasPermissions
{
    // ─── Relationships ────────────────────────────────────────────────────────

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withTimestamps();
    }

    /**
     * Direct permission overrides (allow or deny).
     */
    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')
            ->withPivot(['allow', 'reason', 'granted_by'])
            ->withTimestamps();
    }

    // ─── Core Permission Checks ───────────────────────────────────────────────

    /**
     * Primary check: resolves with priority order.
     * 1. Direct user override → 2. Role permissions → 3. Deny
     */
    public function hasPermission(string $slug): bool
    {
        return app(PermissionResolver::class)->resolve($this, $slug);
    }

    /**
     * Check by route name instead of slug.
     */
    public function hasPermissionForRoute(string $routeName): bool
    {
        return app(PermissionResolver::class)->resolveByRoute($this, $routeName);
    }

    /**
     * Check if user has ANY of the given permission slugs.
     */
    public function hasAnyPermission(array $slugs): bool
    {
        foreach ($slugs as $slug) {
            if ($this->hasPermission($slug)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has ALL of the given permission slugs.
     */
    public function hasAllPermissions(array $slugs): bool
    {
        foreach ($slugs as $slug) {
            if (! $this->hasPermission($slug)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if user has a role by slug.
     */
    public function hasRole(string $roleSlug): bool
    {
        return app(PermissionResolver::class)->userRoles($this)->contains('slug', $roleSlug);
    }

    /**
     * Check if user has any of given roles.
     */
    public function hasAnyRole(array $roleSlugs): bool
    {
        $userRoles = app(PermissionResolver::class)->userRoles($this)->pluck('slug');
        return collect($roleSlugs)->intersect($userRoles)->isNotEmpty();
    }

    // ─── Role Management ──────────────────────────────────────────────────────

    public function assignRole(string|Role $role): void
    {
        $role = is_string($role) ? Role::where('slug', $role)->firstOrFail() : $role;
        $this->roles()->syncWithoutDetaching($role->id);
        $this->clearPermissionCache();
    }

    public function revokeRole(string|Role $role): void
    {
        $role = is_string($role) ? Role::where('slug', $role)->firstOrFail() : $role;
        $this->roles()->detach($role->id);
        $this->clearPermissionCache();
    }

    public function syncRoles(array $roleSlugs): void
    {
        $ids = Role::whereIn('slug', $roleSlugs)->pluck('id');
        $this->roles()->sync($ids);
        $this->clearPermissionCache();
    }

    // ─── Direct Permission Overrides ──────────────────────────────────────────

    /**
     * Grant a direct permission override (allow).
     */
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

    /**
     * Deny a direct permission override (deny even if role allows it).
     */
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

    /**
     * Remove a direct permission override (falls back to role-based).
     */
    public function revokeDirectPermission(string $slug): void
    {
        $permission = Permission::where('slug', $slug)->firstOrFail();
        $this->directPermissions()->detach($permission->id);
        $this->clearPermissionCache();
    }

    // ─── Cache Management ─────────────────────────────────────────────────────

    /**
     * Clear all cached permissions for this user.
     * Call this after any role/permission change.
     */
    public function clearPermissionCache(): void
    {
        app(PermissionResolver::class)->clearCacheForUser($this->id);
    }

    /**
     * Get flat list of all resolved permission slugs for this user.
     */
    public function getPermissionSlugs(): array
    {
        return app(PermissionResolver::class)->getAllowedSlugs($this);
    }
}
