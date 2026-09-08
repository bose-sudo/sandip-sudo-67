<?php

namespace App\Http\Controllers;

use App\Models\AccessRole;
use App\Models\MenuItem;
use App\Models\Module;
use App\Models\SubMenuItem;
use Exception;
use Illuminate\Http\Request;

class AccessRoleController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function getAllMenuGroups()
    {
        try {
            $menuGroups = Module::with('menuItems.subMenuItems')->get();
            return $this->helper_controller->send_response('success', 'All menu groups retrieved successfully', 200, $menuGroups);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'user_type_id' => 'required|exists:user_types,id',
                'submenus' => 'required|array',
                'submenus.*.id' => 'required|exists:sub_menu_items,id',
                'submenus.*.permissions' => 'array',
                'submenus.*.permissions.*' => 'string|in:create,read,update,delete,import,export'
            ]);

            foreach ($request->submenus as $submenu) {
                foreach ($submenu['permissions'] as $permission) {
                    AccessRole::updateOrCreate(
                        [
                            'user_type_id' => $request->user_type_id,
                            'submenu_id' => $submenu['id'],
                            'permission' => $permission
                        ]
                    );
                }
            }

            return $this->helper_controller->send_response('success', 'Access roles created successfully', 201);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }

    public function update(Request $request)
    {
        try {
            $request->validate([
                'user_type_id' => 'required|exists:user_types,id',
                'submenus' => 'required|array',
                'submenus.*.id' => 'nullable|exists:sub_menu_items,id',
                'submenus.*.permissions' => 'array',
                'submenus.*.permissions.*' => 'string|in:create,read,update,delete,import,export'
            ]);

            // Delete existing roles for this user_type_id
            AccessRole::where('user_type_id', $request->user_type_id)
                    ->delete();

            foreach ($request->submenus as $submenu) {
                foreach ($submenu['permissions'] as $permission) {
                    AccessRole::create([
                        'user_type_id' => $request->user_type_id,
                        'submenu_id' => $submenu['id'] ?? 0,
                        'permission' => $permission
                    ]);
                }
            }

            return $this->helper_controller->send_response('success', 'Access roles updated successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }

    public function show($user_type_id)
    {
        try {
            // Validate that the user_type_id exists
            // $request->validate([
            //     'user_type_id' => 'required|exists:user_types,id',
            // ]);

            // Fetch all access roles for the specified user_type_id
            // $user = auth()->user();
            // $user_type_id = $user->user_type;
            $accessRoles = AccessRole::where('user_type_id', $user_type_id)
                                     ->with(['submenu.menuItem.module'])
                                     ->get()
                                     ->groupBy('submenu.menuItem.module.name');

            $formattedRoles = $accessRoles->map(function ($roles, $moduleName) {
                return [
                    'module' => $moduleName,
                    'menu_items' => $roles->groupBy('submenu.menuItem.name')->map(function ($submenuRoles, $menuItemName) {
                        return [
                            'menu_item' => $menuItemName,
                            'submenus' => $submenuRoles->map(function ($role) {
                                return [
                                    'submenu_id' => $role->submenu->id ?? '',
                                    'submenu' => $role->submenu->name ?? '',
                                    'permissions' => $role->permission ?? '',
                                ];
                            })->values()
                        ];
                    })->values()
                ];
            })->values();

            return $this->helper_controller->send_response('success', 'Access roles retrieved successfully', 200, $formattedRoles);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }

    // public function getSidebarMenu(Request $request)
    // {
    //     try {
    //         $user = auth()->user();
    //         $userTypeId = $user->user_type; // Assuming user_type is stored in the authenticated user model

    //         // Fetch access roles for the user type
    //         $accessRoles = AccessRole::where('user_type_id', $userTypeId)
    //             ->with('submenu')
    //             ->get();

    //         // Prepare an array of submenu IDs that the user has access to
    //         $submenuIds = $accessRoles->pluck('submenu_id')->toArray();

    //         // Fetch menu items that are linked to these submenus
    //         $submenus = SubMenuItem::whereIn('id', $submenuIds)->get();
    //         $menuItemIds = $submenus->pluck('menu_id')->unique()->toArray();
            
    //         // Fetch menu items
    //         $menuItems = MenuItem::whereIn('id', $menuItemIds)->get();
    //         $modules = Module::all(); // Optionally, fetch all modules if needed

    //         // Prepare the menu structure
    //         $menu = $modules->map(function ($module) use ($menuItems, $submenus) {
    //             $menus = $menuItems->where('module_id', $module->id)->map(function ($menuItem) use ($submenus) {
    //                 $submenu = $submenus->where('menu_id', $menuItem->id)->map(function ($submenu) {
    //                     return [
    //                         'id' => $submenu->id,
    //                         'name' => $submenu->name,
    //                         // 'permissions' => AccessRole::where('submenu_id', $submenu->id)
    //                         //                           ->where('user_type_id', auth()->guard('admin')->user()->user_type)
    //                         //                           ->pluck('permission')
    //                         //                           ->toArray()
    //                     ];
    //                 });
    //                 return [
    //                     'id' => $menuItem->id,
    //                     'name' => $menuItem->name,
    //                     'submenus' => $submenu
    //                 ];
    //             });
    //             return [
    //                 'id' => $module->id,
    //                 'name' => $module->name,
    //                 'menus' => $menus
    //             ];
    //         });

    //         return $this->helper_controller->send_response('success', 'Sidebar menu fetched successfully', 200, $menu);

    //     } catch (Exception $e) {
    //         return $this->helper_controller->send_response('error', $e->getMessage(), 422);
    //     }
    // }

    public function getSidebarMenu(Request $request)
    {
        try {
            $user = auth()->user();
            $userTypeId = $user->user_type; // Assuming user_type is stored in the authenticated user model

            // Fetch access roles for the user type
            $accessRoles = AccessRole::where('user_type_id', $userTypeId)
                ->with('submenu')
                ->get();

            // Prepare an array of submenu IDs that the user has access to
            $submenuIds = $accessRoles->pluck('submenu_id')->toArray();

            // Fetch menu items that are linked to these submenus
            $submenus = SubMenuItem::whereIn('id', $submenuIds)->get();
            $menuItemIds = $submenus->pluck('menu_id')->unique()->toArray();
            
            // Fetch menu items
            $menuItems = MenuItem::whereIn('id', $menuItemIds)->get();
            $modules = Module::all(); // Optionally, fetch all modules if needed

            // Prepare the menu structure
            $menu = $modules->map(function ($module) use ($menuItems, $submenus) {
                $menus = $menuItems->where('module_id', $module->id)->map(function ($menuItem) use ($submenus) {
                    $submenu = $submenus->where('menu_id', $menuItem->id)->map(function ($submenu) {
                        return [
                            'id' => $submenu->id,
                            'name' => $submenu->name,
                            'path' => $submenu->path,
                            // 'permissions' => AccessRole::where('submenu_id', $submenu->id)
                            //                           ->where('user_type_id', auth()->guard('admin')->user()->user_type)
                            //                           ->pluck('permission')
                            //                           ->toArray()
                        ];
                    })->values()->toArray();
                    return [
                        'id' => $menuItem->id,
                        'name' => $menuItem->name,
                        'submenus' => $submenu
                    ];
                })->values()->toArray();
                return [
                    'id' => $module->id,
                    'name' => $module->name,
                    'menus' => $menus
                ];
            })->values()->toArray();

            return $this->helper_controller->send_response('success', 'Sidebar menu fetched successfully', 200, $menu);

        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }

    public function getSubMenuPermissions($submenuId)
    {
        try {
            $user = auth()->user();
            $userTypeId = $user->user_type;

            // Fetch permissions for the specific submenu and user type
            $accessRoles = AccessRole::where('user_type_id', $userTypeId)
                                     ->where('submenu_id', $submenuId)
                                     ->pluck('permission')
                                     ->toArray();

            if (!$accessRoles) {
                return $this->helper_controller->send_response('not_found', 'No permissions found for this submenu', 422);
            }

            // Return the permissions for the submenu
            return $this->helper_controller->send_response('success', 'Permissions retrieved successfully', 200, $accessRoles);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }
}
