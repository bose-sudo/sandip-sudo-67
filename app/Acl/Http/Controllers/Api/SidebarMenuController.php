<?php

namespace App\Acl\Http\Controllers\Api;

use App\Acl\Services\MenuBuilderService;
use App\Acl\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SidebarMenuController extends Controller
{
    public function __construct(
        protected MenuBuilderService $menuBuilder,
        protected PermissionResolver $resolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Try the configured guard first, fall back to default
        $user = $request->user()
            ?? auth('admin')->user();

        if (! $user) {
            return response()->json([
                'status'  => 'failed',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $menus       = $this->menuBuilder->getSidebarMenu($user);
        // $slugs = $this->resolver->getAllowedSlugs($user);
        $permissions = $this->resolver->getAllowedRoutes($user);

        return response()->json([
            'status' => 'success',
            'data'    => [
                'modules' => $menus,
                'permissions' => $permissions,
            ],
        ]);
    }
}