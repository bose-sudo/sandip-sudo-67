<?php

namespace App\Acl\Http\Controllers\Api;

use App\Acl\Http\Requests\BulkUserPermissionRequest;
use App\Acl\Services\BulkPermissionService;
use App\Acl\Services\PermissionResolver;
use App\Acl\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class UserPermissionController extends Controller
{
    public function __construct(
        protected BulkPermissionService $bulkService,
        protected PermissionResolver    $resolver,
    ) {}

    /**
     * GET /api/acl/admin/users/{userId}/permissions
     *
     * Returns the resolved permission list for a user.
     * Response:
     * {
     *   "status": "success",
     *   "data": {
     *     "user_id": 4,
     *     "permissions": [
     *       { "permission_id": 1, "permission_name": "List Employees" },
     *       { "permission_id": 3, "permission_name": "Create Employee" }
     *     ]
     *   }
     * }
     */
    public function show(int $userId): JsonResponse
    {
        $userModel = config('acl.user_model', \App\Models\AdminUser::class);
        $user      = $userModel::findOrFail($userId);

        $allowedSlugs = $this->resolver->getAllowedSlugs($user);

        $permissions = Permission::active()
            ->whereIn('permissions.slug', $allowedSlugs)
            ->get(['permissions.id', 'permissions.name', 'permissions.slug', 'permissions.route_name'])
            ->map(fn ($p) => [
                'permission_id'   => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'route' => $p->route_name,
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'user_id'     => $user->id,
                'permissions' => $permissions,
                'permission_ids' => $permissions->pluck('permission_id'),
            ],
        ]);
    }

    /**
     * POST /api/acl/admin/users/{userId}/permissions
     */
    public function bulkUpdate(BulkUserPermissionRequest $request, int $userId): JsonResponse
    {
        $userModel  = config('acl.user_model', \App\Models\AdminUser::class);
        $targetUser = $userModel::findOrFail($userId);

        $action = 'grant';
        $result = $this->bulkService->bulkUpdateUserPermissions(
            targetUser:    $targetUser,
            permissionIds: $request->validated('permission_ids'),
            action:        $action, //$request->validated('action'),
            admin:         $request->user() ?? auth('admin')->user(),
            reason:        $request->validated('reason'),
        );

        return response()->json([
            'status'  => 'success',
            'message' => $this->buildMessage($result['action'], $result['affected_count']),
            'data'    => $result,
        ]);
    }

    private function buildMessage(string $action, int $count): string
    {
        return match ($action) {
            'grant'  => "{$count} permission(s) granted to user.",
            'deny'   => "{$count} permission(s) denied for user.",
            'revoke' => "{$count} permission override(s) revoked from user.",
            default  => "Done.",
        };
    }
}