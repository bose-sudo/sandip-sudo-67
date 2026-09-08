<?php

namespace App\Acl\Http\Controllers\Api;

use App\Acl\Services\MenuBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class HeaderMenuController extends Controller
{
    public function __construct(protected MenuBuilderService $menuBuilder) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user()
            ?? auth('admin')->user();

        if (! $user) {
            return response()->json([
                'status'  => 'failed',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $menus = $this->menuBuilder->getHeaderMenu($user);

        return response()->json([
            'success' => true,
            'data'    => [
                'menus' => $menus,
            ],
        ]);
    }
}