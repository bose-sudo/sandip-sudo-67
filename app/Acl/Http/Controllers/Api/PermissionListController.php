<?php

namespace App\Acl\Http\Controllers\Api;

use App\Acl\Models\Module;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class PermissionListController extends Controller
{
    /**
     * GET /api/acl/admin/permissions
     *
     * Returns all active permissions grouped by module → menu.
     * Used to populate the admin permission assignment form.
     *
     * Response structure:
     * [
     *   {
     *     "module_id": 1,
     *     "module_name": "Employee Management",
     *     "menus": [
     *       {
     *         "menu_id": 3,
     *         "menu_name": "Employees",
     *         "permissions": [
     *           { "permission_id": 1, "permission_name": "List Employees", "slug": "employee.list" },
     *           { "permission_id": 2, "permission_name": "Create Employee", "slug": "employee.create" }
     *         ]
     *       },
     *       {
     *         "menu_id": null,
     *         "menu_name": "No Menu",
     *         "permissions": [
     *           { "permission_id": 9, "permission_name": "Export Report", "slug": "report.export" }
     *         ]
     *       }
     *     ]
     *   }
     * ]
     */
    public function index(): JsonResponse
    {
        // Load modules → menus (menu_items) → permissions in one query
        $modules = Module::active()
            ->with([
                'permissions' => function ($q) {
                    $q->active()
                    //   ->where('permissions.type', 'sidebar')
                      ->orderBy('permissions.name')
                      ->with([
                          'menu:id,name',  // only need id + name from menu_items
                      ]);
                },
            ])
            ->orderBy('modules.sort_order')
            ->get(['modules.id', 'modules.name']);

        $result = [];

        foreach ($modules as $module) {
            // Group this module's permissions by menu
            $byMenu = $module->permissions->groupBy(fn ($p) => $p->menu_id ?? '__none__');

            $menus = [];

            foreach ($byMenu as $menuKey => $permissions) {
                $firstPerm = $permissions->first();
                $menuId    = $menuKey === '__none__' ? null : (int) $menuKey;
                $menuName  = $menuKey === '__none__' ? 'No Menu' : ($firstPerm->menu?->name ?? 'No Menu');

                $menus[] = [
                    'menu_id'     => $menuId,
                    'menu_name'   => $menuName,
                    'permissions' => $permissions->map(fn ($p) => [
                        'permission_id'   => $p->id,
                        'permission_name' => $p->name,
                        'slug'            => $p->slug,
                        'route_name'      => $p->route_name,
                    ])->values(),
                ];
            }

            // Sort menus: named menus first, "No Menu" last
            usort($menus, fn ($a, $b) => ($a['menu_id'] === null) - ($b['menu_id'] === null));

            $result[] = [
                'module_id'   => $module->id,
                'module_name' => $module->name,
                'menus'       => $menus,
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }
}