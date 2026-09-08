<?php

namespace App\Acl\Services;

use App\Acl\Models\Menu;
use App\Acl\Models\Module;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

class MenuBuilderService
{
    public function __construct(protected PermissionResolver $resolver) {}

    protected function cacheTtl(): int
    {
        return config('acl.cache.ttl', 3600);
    }

    protected function menuCacheKey(int $userId, string $type): string
    {
        return config('acl.cache.prefix', 'acl') . ":user:{$userId}:menu:{$type}";
    }

    public function getSidebarMenu(Authenticatable $user): array
    {
        return $this->buildMenu($user, 'sidebar');
    }

    public function getHeaderMenu(Authenticatable $user): array
    {
        return $this->buildMenu($user, 'header');
    }

    public function clearMenuCacheForUser(int $userId): void
    {
        Cache::forget($this->menuCacheKey($userId, 'sidebar'));
        Cache::forget($this->menuCacheKey($userId, 'header'));
    }

    protected function buildMenu(Authenticatable $user, string $type): array
    {
        $cacheKey = $this->menuCacheKey($user->id, $type);

        return Cache::remember($cacheKey, $this->cacheTtl(), function () use ($user, $type) {

            $allowedSlugs = array_flip($this->resolver->getAllowedSlugs($user));

            $modules = Module::active()
                ->with([
                    'menus' => function ($q) use ($type) {
                        $q->where('menu_items.is_active', true)
                          ->where('menu_items.type', $type)       // sidebar or header menus only
                          ->whereNull('menu_items.parent_id')
                          ->orderBy('menu_items.sort_order')
                          ->with([
                              'children' => function ($q) use ($type) {
                                  $q->where('menu_items.is_active', true)
                                    ->where('menu_items.type', $type)
                                    ->orderBy('menu_items.sort_order')
                                    ->with('permissions'); // Menu::permissions() already filters type=sidebar
                              },
                              'permissions', // Menu::permissions() already filters type=sidebar
                          ]);
                    },
                ])
                ->orderBy('sort_order')
                ->get();

            $result = [];

            foreach ($modules as $module) {
                $builtMenus = $this->buildMenusForModule($module->menus, $allowedSlugs);

                if (empty($builtMenus)) {
                    continue;
                }

                $result[] = [
                    'module_id'   => $module->id,
                    'module_name' => $module->name,
                    'module_slug' => $module->slug,
                    'module_icon' => $module->icon,
                    'menus'       => $builtMenus,
                ];
            }

            return $result;
        });
    }

    protected function buildMenusForModule($menus, array $allowedSlugs): array
    {
        $result = [];
        foreach ($menus as $menu) {
            $built = $this->buildMenuNode($menu, $allowedSlugs);
            if ($built !== null) {
                $result[] = $built;
            }
        }
        return $result;
    }

    protected function buildMenuNode(Menu $menu, array $allowedSlugs): ?array
    {
        // Case 1: Has child menus
        if ($menu->children->isNotEmpty()) {
            $subMenus = $this->buildSubMenusFromChildren($menu->children, $allowedSlugs);
            if (empty($subMenus)) return null;

            return [
                'id'          => $menu->id,
                'name'        => $menu->name,
                'slug'        => $menu->slug,
                'icon'        => $menu->icon,
                'route_name'  => $menu->route_name,
                'is_dropdown' => true,
                'sub_menus'   => $subMenus,
            ];
        }

        // Case 2: Has sidebar permissions directly linked
        if ($menu->permissions->isNotEmpty()) {
            $subMenus = $this->buildSubMenusFromPermissions($menu->permissions, $allowedSlugs);
            if (empty($subMenus)) return null;

            return [
                'id'          => $menu->id,
                'name'        => $menu->name,
                'slug'        => $menu->slug,
                'icon'        => $menu->icon,
                'route_name'  => $menu->route_name,
                'is_dropdown' => is_null($menu->route_name),
                'sub_menus'   => $subMenus,
            ];
        }

        // Case 3: Direct link with no children and no permissions
        if ($menu->route_name) {
            return [
                'id'          => $menu->id,
                'name'        => $menu->name,
                'slug'        => $menu->slug,
                'icon'        => $menu->icon,
                'route_name'  => $menu->route_name,
                'is_dropdown' => false,
                'sub_menus'   => [],
            ];
        }

        return null;
    }

    protected function buildSubMenusFromChildren($children, array $allowedSlugs): array
    {
        $subMenus = [];

        foreach ($children as $child) {
            if ($child->permissions->isNotEmpty()) {
                $canSee = false;
                foreach ($child->permissions as $permission) {
                    if (isset($allowedSlugs[$permission->slug])) {
                        $canSee = true;
                        break;
                    }
                }
                if (! $canSee) continue;
            }

            $subMenus[] = [
                'id'         => $child->id,
                'name'       => $child->name,
                'slug'       => $child->slug,
                'icon'       => $child->icon,
                'route_name' => $child->route_name,
            ];
        }

        return $subMenus;
    }

    protected function buildSubMenusFromPermissions($permissions, array $allowedSlugs): array
    {
        // No type check needed — Menu::permissions() relationship already
        // guarantees only type='sidebar' permissions reach here
        $subMenus = [];

        foreach ($permissions as $permission) {
            if (! isset($allowedSlugs[$permission->slug])) continue;

            $subMenus[] = [
                'permission_id' => $permission->id,
                'name'          => $permission->name,
                'slug'          => $permission->slug,
                'route_name'    => $permission->route_name,
                'icon'          => null,
            ];
        }

        return $subMenus;
    }

    protected function safeRoute(string $routeName): ?string
    {
        try {
            return route($routeName);
        } catch (\Exception) {
            return null;
        }
    }
}