<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\UserType;
use App\Services\FullAccessService;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FullAccessController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FullAccessService $service) {}

    // =========================================================================
    // User-type config
    // =========================================================================

    /**
     * GET /api/full-access/user-types
     * All user types with is_full_access flag on each.
     * Query params: search, is_full_access, per_page, page
     */
    public function listUserTypes(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'search'         => ['sometimes', 'string', 'min:1', 'max:100'],
            'is_full_access' => ['sometimes', 'boolean'],
            'per_page'       => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'           => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $perPage = (int) $request->input('per_page', 15);
            $page    = (int) $request->input('page', 1);

            $fullAccessSub = DB::table('full_access_user_types')
                ->select('user_type_id', 'granted_by', 'created_at as granted_at');

            $query = UserType::query()
                ->select(
                    'user_types.id',
                    'user_types.user_type',
                    'user_types.role',
                    'user_types.is_active',
                    DB::raw('CASE WHEN fa.user_type_id IS NOT NULL THEN true ELSE false END AS is_full_access'),
                    'fa.granted_at',
                    'fa.granted_by',
                )
                ->leftJoinSub($fullAccessSub, 'fa', 'fa.user_type_id', '=', 'user_types.id')
                ->where('user_types.is_deleted', false);

            if ($request->filled('search')) {
                $term = '%' . $request->search . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('user_types.user_type', 'ilike', $term)
                      ->orWhere('user_types.role', 'ilike', $term);
                });
            }

            if ($request->has('is_full_access')) {
                $isFullAccess = filter_var($request->is_full_access, FILTER_VALIDATE_BOOLEAN);
                $query->when(
                    $isFullAccess,
                    fn ($q) => $q->whereNotNull('fa.user_type_id'),
                    fn ($q) => $q->whereNull('fa.user_type_id'),
                );
            }

            $paginated = $query->orderBy('user_types.user_type')
                ->paginate($perPage, ['*'], 'page', $page);

            $items = collect($paginated->items())->map(function ($type) {
                if ($type->granted_by) {
                    $type->granted_by_user = AdminUser::find($type->granted_by, ['id', 'name', 'email']);
                }
                return $type;
            });

            return $this->send_response('success', 'User types fetched successfully.', 200, [
                'user_types' => $items,
                'meta'       => $this->buildMeta($paginated),
            ]);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to fetch user types. Please try again.', 422);
        }
    }

    /**
     * POST /api/full-access/user-types
     * Grant full access to one or more user types.
     */
    public function grantUserTypes(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_type_ids'   => ['required', 'array', 'min:1'],
            'user_type_ids.*' => ['integer', 'exists:user_types,id'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $results = [];
            foreach ($request->user_type_ids as $userTypeId) {
                $results[] = $this->service->grantToUserType((int) $userTypeId, Auth::id());
            }

            return $this->send_response('success', 'Full access granted to selected user type(s).', 200, [
                'granted' => $results,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'One or more user types not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to grant access. Please try again.', 422);
        }
    }

    /**
     * DELETE /api/full-access/user-types/{userTypeId}
     * Remove type from config. Existing users keep their flag.
     */
    public function revokeUserType(int $userTypeId): JsonResponse
    {
        try {
            $this->service->revokeFromUserType($userTypeId);

            return $this->send_response(
                'success',
                'User type removed from full access config. Existing users are unaffected.',
            );
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to revoke. Please try again.', 422);
        }
    }

    /**
     * DELETE /api/full-access/user-types/{userTypeId}/bulk-revoke
     * Remove type from config AND revoke all its users.
     */
    public function revokeUserTypeAndUsers(int $userTypeId): JsonResponse
    {
        try {
            $result = $this->service->revokeFromUserTypeAndUsers($userTypeId);

            return $this->send_response(
                'success',
                "Full access revoked from user type and {$result['users_revoked']} user(s).",
                200,
                $result,
            );
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to bulk revoke. Please try again.', 422);
        }
    }

    // =========================================================================
    // Individual user flag — list
    // =========================================================================

    /**
     * GET /api/full-access/users
     * All admin users with is_all_borrower_accessible on each row.
     * Query params: search, user_type_id, is_active, is_full_access,
     *               sort_by, sort_order, per_page, page
     */
    public function listUsers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'search'         => ['sometimes', 'string', 'min:2', 'max:100'],
            'user_type_id'   => ['sometimes', 'integer', 'exists:user_types,id'],
            'is_active'      => ['sometimes', 'boolean'],
            'is_full_access' => ['sometimes', 'boolean'],
            'sort_by'        => ['sometimes', 'in:name,is_active,is_all_borrower_accessible'],
            'sort_order'     => ['sometimes', 'in:asc,desc'],
            'per_page'       => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'           => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $perPage   = (int) $request->input('per_page', 15);
            $page      = (int) $request->input('page', 1);
            $sortBy    = $request->input('sort_by', 'name');
            $sortOrder = $request->input('sort_order', 'asc');

            $query = AdminUser::query()
                ->select('id', 'name', 'email', 'mobile', 'user_type', 'is_active', 'is_all_borrower_accessible')
                ->with('userTypeRelation:id,user_type,role')
                ->where('is_deleted', false);

            if ($request->filled('search')) {
                $term = '%' . $request->search . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'ilike', $term)
                      ->orWhere('email', 'ilike', $term)
                      ->orWhere('mobile', 'ilike', $term);
                });
            }

            if ($request->filled('user_type_id')) {
                $query->where('user_type', (int) $request->user_type_id);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->has('is_full_access')) {
                $query->where(
                    'is_all_borrower_accessible',
                    filter_var($request->is_full_access, FILTER_VALIDATE_BOOLEAN)
                );
            }

            $paginated = $query->orderBy($sortBy, $sortOrder)
                ->paginate($perPage, ['*'], 'page', $page);

            return $this->send_response('success', 'Users fetched successfully.', 200, [
                'users' => $paginated->items(),
                'meta'  => $this->buildMeta($paginated),
            ]);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to fetch users. Please try again.', 422);
        }
    }

    // =========================================================================
    // Single user grant / revoke
    // =========================================================================

    public function grantUser(int $userId): JsonResponse
    {
        try {
            $user = $this->service->grantToUser($userId);

            return $this->send_response('success', 'Full borrower access granted.', 200, [
                'user' => $user->only('id', 'name', 'email', 'is_all_borrower_accessible'),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'User not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to grant access. Please try again.', 422);
        }
    }

    public function revokeUser(int $userId): JsonResponse
    {
        try {
            $user = $this->service->revokeFromUser($userId);

            return $this->send_response('success', 'Full borrower access revoked. User now follows hierarchy rules.', 200, [
                'user' => $user->only('id', 'name', 'email', 'is_all_borrower_accessible'),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'User not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to revoke access. Please try again.', 422);
        }
    }

    // =========================================================================
    // Bulk user grant / revoke
    // =========================================================================

    /**
     * POST /api/full-access/users/bulk
     *
     * Apply one action (grant | revoke) to multiple users at once.
     * Each user is processed independently — partial success is possible.
     *
     * Body:
     *   user_ids  int[]   required, min:1 max:100
     *   action    string  required — grant | revoke
     */
    public function bulkManageUsers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_ids'   => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['integer', 'exists:admin_users,id'],
            'action'     => ['required', 'string', 'in:grant,revoke'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $userIds = array_values(array_unique($request->user_ids));

        try {
            $result = $this->service->bulkManageUsers($userIds, $request->action);

            $actionLabel = $request->action === 'grant' ? 'granted' : 'revoked';
            $succeeded   = count($result['success']);
            $failedCnt   = count($result['failed']);

            $message = $failedCnt === 0
                ? "Full access {$actionLabel} for all {$succeeded} user(s)."
                : "Full access {$actionLabel} for {$succeeded} user(s), {$failedCnt} failed.";

            return $this->send_response('success', $message, 200, [
                'action'  => $request->action,
                'success' => $result['success'],
                'failed'  => $result['failed'],
                'summary' => [
                    'total'     => count($userIds),
                    'succeeded' => $succeeded,
                    'failed'    => $failedCnt,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Bulk operation failed. Please try again.', 422);
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function buildMeta(LengthAwarePaginator $paginated): array
    {
        return [
            'current_page' => $paginated->currentPage(),
            'per_page'     => $paginated->perPage(),
            'total'        => $paginated->total(),
            'last_page'    => $paginated->lastPage(),
            'from'         => $paginated->firstItem(),
            'to'           => $paginated->lastItem(),
        ];
    }
}