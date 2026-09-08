<?php

namespace App\Acl\Services;

use App\Acl\Models\Permission;
use App\Acl\Models\Role;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PermissionResolver
{
    // ─── Cache Keys ───────────────────────────────────────────────────────────

    protected function userPermissionCacheKey(int $userId): string
    {
        return config('acl.cache.prefix', 'acl') . ":user:{$userId}:permissions";
    }

    protected function userRoleCacheKey(int $userId): string
    {
        return config('acl.cache.prefix', 'acl') . ":user:{$userId}:role";
    }

    protected function globalPermissionCacheKey(): string
    {
        return config('acl.cache.prefix', 'acl') . ':all_permissions';
    }

    protected function cacheTtl(): int
    {
        return config('acl.cache.ttl', 3600);
    }

    // ─── Core Resolution ──────────────────────────────────────────────────────

    public function resolve(Authenticatable $user, string $slug): bool
    {
        $resolved = $this->getResolvedPermissions($user);

        // 1. Direct override takes priority
        if (isset($resolved['overrides'][$slug])) {
            return (bool) $resolved['overrides'][$slug];
        }

        // 2. Role-based
        return in_array($slug, $resolved['role_permissions'], true);
    }

    public function resolveByRoute(Authenticatable $user, string $routeName): bool
    {
        $slug = $this->getSlugForRoute($routeName);

        if (! $slug) {
            return config('acl.allow_unregistered_routes', false);
        }

        return $this->resolve($user, $slug);
    }

    public function getResolvedPermissions(Authenticatable $user): array
    {
        if (! $user->id) {
            return ['overrides' => [], 'role_permissions' => []];
        }

        $cacheKey = $this->userPermissionCacheKey($user->id);

        return Cache::remember($cacheKey, $this->cacheTtl(), function () use ($user) {

            // ── Direct overrides ──────────────────────────────────────────────
            $overrides = [];
            $direct    = $user->directPermissions()->get();
            foreach ($direct as $permission) {
                $overrides[$permission->slug] = (bool) $permission->pivot->allow;
            }

            // ── Role permissions via user_type column ─────────────────────────
            // admin_users.user_type = user_types.id = role_permission.role_id
            $roleId = $user->user_type;

            if (! $roleId) {
                return ['overrides' => $overrides, 'role_permissions' => []];
            }

            $rolePermissions = Permission::active()
                ->join('role_permission', 'permissions.id', '=', 'role_permission.permission_id')
                ->where('role_permission.role_id', $roleId)
                ->pluck('permissions.slug')
                ->unique()
                ->toArray();

            return [
                'overrides'        => $overrides,
                'role_permissions' => $rolePermissions,
            ];
        });
    }

    public function getAllowedSlugs(Authenticatable $user): array
    {
        $resolved = $this->getResolvedPermissions($user);
        $allowed  = collect($resolved['role_permissions']);

        foreach ($resolved['overrides'] as $slug => $allow) {
            if ($allow) {
                $allowed->push($slug);
            } else {
                $allowed = $allowed->reject(fn($s) => $s === $slug);
            }
        }

        return $allowed->unique()->values()->toArray();
    }

    public function getAllowedRoutes(Authenticatable $user): array
    {
        // Get allowed slugs (already correctly resolved with overrides applied)
        $allowedSlugs = $this->getAllowedSlugs($user);

        if (empty($allowedSlugs)) {
            return [];
        }

        // Map slugs → route_names from the global permission cache
        return $this->getAllPermissions()
            ->whereIn('slug', $allowedSlugs)
            ->whereNotNull('route_name')
            ->pluck('route_name')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Get the user's role model (cached).
     * Uses admin_users.user_type as the FK.
     */
    public function userRole(Authenticatable $user): ?Role
    {
        if (! $user->user_type) {
            return null;
        }

        $cacheKey = $this->userRoleCacheKey($user->id);

        return Cache::remember($cacheKey, $this->cacheTtl(), function () use ($user) {
            return Role::find($user->user_type);
        });
    }

    // ─── Route → Slug Mapping ─────────────────────────────────────────────────

    protected function getSlugForRoute(string $routeName): ?string
    {
        return $this->getAllPermissions()->firstWhere('route_name', $routeName)?->slug;
    }

    protected function getAllPermissions(): Collection
    {
        return Cache::remember($this->globalPermissionCacheKey(), $this->cacheTtl(), function () {
            return Permission::active()->get(['id', 'slug', 'route_name']);
        });
    }

    // ─── Cache Invalidation ───────────────────────────────────────────────────

    public function clearCacheForUser(int $userId): void
    {
        Cache::forget($this->userPermissionCacheKey($userId));
        Cache::forget($this->userRoleCacheKey($userId));
    }

    public function clearGlobalCache(): void
    {
        Cache::forget($this->globalPermissionCacheKey());
    }

    public function clearAllCache(): void
    {
        $this->clearGlobalCache();
        if (config('acl.cache.use_tags', false)) {
            Cache::tags([config('acl.cache.prefix', 'acl')])->flush();
        }
    }

    /**
     * Clear cache for ALL users that belong to a given role.
     * Call this after changing a role's permissions.
     */
    public function clearCacheForRole(Role $role): void
    {
        // Direct query — no relationship ambiguity
        $userIds = DB::table('admin_users')
            ->where('user_type', $role->id)
            ->where('is_active', true)
            ->pluck('id');

        foreach ($userIds as $userId) {
            $this->clearCacheForUser($userId);
        }

        $this->clearGlobalCache();
    }
}
