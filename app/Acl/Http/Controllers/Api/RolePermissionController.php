<?php

namespace App\Acl\Http\Controllers\Api;

use App\Acl\Http\Requests\BulkRolePermissionRequest;
use App\Acl\Models\Role;
use App\Acl\Services\BulkPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class RolePermissionController extends Controller
{
    public function __construct(protected BulkPermissionService $bulkService) {}

    /**
     * GET /api/acl/admin/roles/{role}/permissions
     */
    public function show(Role $role): JsonResponse
    {
        $permissions = $role->permissions()
            ->orderBy('permissions.name')
            ->get(['permissions.id', 'permissions.name', 'permissions.slug', 'permissions.route_name'])
            ->map(fn ($p) => [
                'permission_id'   => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'route_name' => $p->route_name,
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'role' => [
                    'id'   => $role->id,
                    'name' => $role->user_type, // actual column name in user_types table
                ],
                'permissions'    => $permissions,
                'permission_ids' => $permissions->pluck('permission_id'),
            ],
        ]);
    }

    /**
     * PUT /api/acl/admin/roles/{role}/permissions/sync
     */
    public function sync(BulkRolePermissionRequest $request, Role $role): JsonResponse
    {
        $result = $this->bulkService->syncRolePermissions(
            role:          $role,
            permissionIds: $request->validated('permission_ids'),
            admin:         $request->user() ?? auth('admin')->user(),
            reason:        $request->validated('reason'),
        );

        return response()->json([
            'status'  => 'success',
            'message' => '+' . count($result['added']) . ' / -' . count($result['removed']) . ' permissions updated.',
            'data'    => $result,
        ]);
    }

    /**
     * POST /api/acl/admin/roles/{role}/permissions/attach
     */
    public function attach(BulkRolePermissionRequest $request, Role $role): JsonResponse
    {
        $result = $this->bulkService->attachRolePermissions(
            role:          $role,
            permissionIds: $request->validated('permission_ids'),
            admin:         $request->user() ?? auth('admin')->user(),
            reason:        $request->validated('reason'),
        );

        return response()->json([
            'status'  => 'success',
            'message' => count($result['added']) . ' permission(s) added to role.',
            'data'    => $result,
        ]);
    }

    /**
     * POST /api/acl/admin/roles/{role}/permissions/detach
     */
    public function detach(BulkRolePermissionRequest $request, Role $role): JsonResponse
    {
        $result = $this->bulkService->detachRolePermissions(
            role:          $role,
            permissionIds: $request->validated('permission_ids'),
            admin:         $request->user() ?? auth('admin')->user(),
            reason:        $request->validated('reason'),
        );

        return response()->json([
            'status'  => 'success',
            'message' => count($result['removed']) . ' permission(s) removed from role.',
            'data'    => $result,
        ]);
    }
}