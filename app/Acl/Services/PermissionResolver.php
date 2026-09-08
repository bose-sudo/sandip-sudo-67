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

    public function resolve(Authenticatable $user, string $slug): bool
    {
        $resolved = $this->getResolvedPermissions($user);

        if (isset($resolved['overrides'][$slug])) {
            return (bool) $resolved['overrides'][$slug];
        }

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

            // Direct overrides
            $overrides = [];
            $direct    = $user->directPermissions()->get();
            foreach ($direct as $permission) {
                $overrides[$permission->slug] = (bool) $permission->pivot->allow;
            }

            // Role permissions via user_type column
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
                $allowed = $allowed->reject(fn ($s) => $s === $slug);
            }
        }

        return $allowed->unique()->values()->toArray();
    }

    /**
     * Returns all route_names the user can access.
     * Covers role permissions + direct overrides (allow=true) - denied overrides.
     * Queries DB directly — does NOT use getAllPermissions() cache
     * because that cache only holds role-based permissions,
     * missing any slugs that came from direct overrides.
     */
    public function getAllowedRoutes(Authenticatable $user): array
    {
        $allowedSlugs = $this->getAllowedSlugs($user);

        if (empty($allowedSlugs)) {
            return [];
        }

        return Permission::active()
            ->whereIn('permissions.slug', $allowedSlugs)
            ->whereNotNull('permissions.route_name')
            ->pluck('permissions.route_name')
            ->unique()
            ->values()
            ->toArray();
    }

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

    protected function getSlugForRoute(string $routeName): ?string
    {
        return $this->getAllPermissions()->firstWhere('route_name', $routeName)?->slug;
    }

    /**
     * Lightweight global cache — used ONLY for route → slug lookup.
     * Do NOT use for getAllowedRoutes — missing direct override slugs.
     */
    protected function getAllPermissions(): Collection
    {
        return Cache::remember($this->globalPermissionCacheKey(), $this->cacheTtl(), function () {
            return Permission::active()->get(['id', 'slug', 'route_name']);
        });
    }

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

    public function clearCacheForRole(Role $role): void
    {
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