<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\UserHierarchyClosure;
use App\Services\UserHierarchyService;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserHierarchyController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly UserHierarchyService $hierarchyService) {}

    // =========================================================================
    // Single relationship CRUD
    // =========================================================================

    public function addRelationship(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'parent_user_id' => ['required', 'integer', 'exists:admin_users,id'],
            'child_user_id'  => ['required', 'integer', 'exists:admin_users,id', 'different:parent_user_id'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $relationship = $this->hierarchyService->addRelationship(
                parentId:  (int) $request->parent_user_id,
                childId:   (int) $request->child_user_id,
                createdBy: Auth::id()
            );

            return $this->send_response('success', 'Relationship created successfully.', 200, [
                'relationship' => $relationship,
            ]);
        } catch (\RuntimeException $e) {
            return $this->send_response('failed', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to create relationship. Please try again.', 422);
        }
    }

    public function removeRelationship(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'parent_user_id' => ['required', 'integer', 'exists:admin_users,id'],
            'child_user_id'  => ['required', 'integer', 'exists:admin_users,id'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $this->hierarchyService->removeRelationship(
                parentId: (int) $request->parent_user_id,
                childId:  (int) $request->child_user_id,
            );

            return $this->send_response('success', 'Relationship removed successfully.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'Relationship not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to remove relationship. Please try again.', 422);
        }
    }

    public function deactivateRelationship(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'parent_user_id' => ['required', 'integer', 'exists:admin_users,id'],
            'child_user_id'  => ['required', 'integer', 'exists:admin_users,id'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $this->hierarchyService->deactivateRelationship(
                parentId:  (int) $request->parent_user_id,
                childId:   (int) $request->child_user_id,
                updatedBy: Auth::id()
            );

            return $this->send_response('success', 'Relationship deactivated successfully.');
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to deactivate relationship. Please try again.', 422);
        }
    }

    // =========================================================================
    // Activate relationship
    // =========================================================================

    /**
     * PATCH /api/user-hierarchy/relationships/activate
     * Re-activate a previously deactivated relationship.
     */
    public function activateRelationship(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'parent_user_id' => ['required', 'integer', 'exists:admin_users,id'],
            'child_user_id'  => ['required', 'integer', 'exists:admin_users,id'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $this->hierarchyService->activateRelationship(
                parentId:  (int) $request->parent_user_id,
                childId:   (int) $request->child_user_id,
                updatedBy: Auth::id()
            );

            return $this->send_response('success', 'Relationship activated successfully.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'Relationship not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to activate relationship. Please try again.', 422);
        }
    }

    // =========================================================================
    // Bulk child management
    // =========================================================================

    /**
     * POST /api/user-hierarchy/bulk-children
     *
     * Add multiple children under a single parent in one request.
     * Each child is processed independently — partial success is possible.
     *
     * Body:
     *   parent_user_id  int     required
     *   child_user_ids  int[]   required, min:1 max:50
     */
    public function bulkAddChildren(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'parent_user_id'   => ['required', 'integer', 'exists:admin_users,id'],
            'child_user_ids'   => ['required', 'array', 'min:1', 'max:50'],
            'child_user_ids.*' => ['integer', 'exists:admin_users,id'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        // Remove duplicates and guard against self-assignment
        $childIds = array_values(array_unique(
            array_filter($request->child_user_ids, fn ($id) => $id !== (int) $request->parent_user_id)
        ));

        if (empty($childIds)) {
            return $this->send_response('failed', 'No valid child user IDs provided.', 422);
        }

        try {
            $result = $this->hierarchyService->bulkAddChildren(
                parentId:  (int) $request->parent_user_id,
                childIds:  $childIds,
                createdBy: Auth::id()
            );

            $succeeded = count($result['success']);
            $failedCnt = count($result['failed']);

            $message = $failedCnt === 0
                ? "All {$succeeded} children added successfully."
                : "{$succeeded} added, {$failedCnt} failed.";

            return $this->send_response('success', $message, 200, [
                'parent_user_id' => (int) $request->parent_user_id,
                'success'        => $result['success'],
                'failed'         => $result['failed'],
                'summary'        => [
                    'total'     => count($childIds),
                    'succeeded' => $succeeded,
                    'failed'    => $failedCnt,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Bulk operation failed. Please try again.', 422);
        }
    }

    /**
     * PATCH /api/user-hierarchy/bulk-children
     *
     * Apply one action to multiple children of a given parent.
     * One action per call — keeps intent explicit and auditable.
     *
     * Body:
     *   parent_user_id  int     required
     *   child_user_ids  int[]   required, min:1 max:50
     *   action          string  required — remove | activate | deactivate
     */
    public function bulkManageChildren(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'parent_user_id'   => ['required', 'integer', 'exists:admin_users,id'],
            'child_user_ids'   => ['required', 'array', 'min:1', 'max:50'],
            'child_user_ids.*' => ['integer', 'exists:admin_users,id'],
            'action'           => ['required', 'string', 'in:remove,activate,deactivate'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $childIds = array_values(array_unique($request->child_user_ids));

        try {
            $result = $this->hierarchyService->bulkManageChildren(
                parentId:  (int) $request->parent_user_id,
                childIds:  $childIds,
                action:    $request->action,
                updatedBy: Auth::id()
            );

            $actionLabel = match ($request->action) {
                'remove'     => 'removed',
                'activate'   => 'activated',
                'deactivate' => 'deactivated',
            };

            $succeeded = count($result['success']);
            $failedCnt = count($result['failed']);

            $message = $failedCnt === 0
                ? "All {$succeeded} children {$actionLabel} successfully."
                : "{$succeeded} {$actionLabel}, {$failedCnt} failed.";

            return $this->send_response('success', $message, 200, [
                'parent_user_id' => (int) $request->parent_user_id,
                'action'         => $request->action,
                'success'        => $result['success'],
                'failed'         => $result['failed'],
                'summary'        => [
                    'total'     => count($childIds),
                    'succeeded' => $succeeded,
                    'failed'    => $failedCnt,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Bulk operation failed. Please try again.', 422);
        }
    }

    // =========================================================================
    // List APIs
    // =========================================================================

    /**
     * GET /api/user-hierarchy/parents
     *
     * Paginated list of users who have at least one child.
     * Each row: direct_child_count, total_descendant_count.
     *
     * Query params:
     *   search, user_type_id, is_active, min_child_count
     *   sort_by (name|direct_child_count|total_descendant_count), sort_order
     *   per_page, page
     */
    public function parentList(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'search'          => ['sometimes', 'string', 'min:2', 'max:100'],
            'user_type_id'    => ['sometimes', 'integer', 'exists:user_types,id'],
            'is_active'       => ['sometimes', 'boolean'],
            'min_child_count' => ['sometimes', 'integer', 'min:1'],
            'sort_by'         => ['sometimes', 'in:name,direct_child_count,total_descendant_count'],
            'sort_order'      => ['sometimes', 'in:asc,desc'],
            'per_page'        => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'            => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $perPage   = (int) $request->input('per_page', 15);
            $page      = (int) $request->input('page', 1);
            $sortBy    = $request->input('sort_by', 'direct_child_count');
            $sortOrder = $request->input('sort_order', 'desc');

            $directChildSub = DB::table('user_relationships')
                ->select('parent_user_id', DB::raw('count(*) as direct_child_count'))
                ->where('is_active', true)
                ->groupBy('parent_user_id');

            $totalDescendantSub = DB::table('user_hierarchy_closure')
                ->select('ancestor_id', DB::raw('count(*) as total_descendant_count'))
                ->where('depth', '>', 0)
                ->groupBy('ancestor_id');

            $query = AdminUser::query()
                ->select(
                    'admin_users.id',
                    'admin_users.name',
                    'admin_users.email',
                    'admin_users.mobile',
                    'admin_users.user_type',
                    'admin_users.is_active',
                    'admin_users.is_all_borrower_accessible',
                    DB::raw('COALESCE(dc.direct_child_count, 0)::int    AS direct_child_count'),
                    DB::raw('COALESCE(td.total_descendant_count, 0)::int AS total_descendant_count'),
                )
                ->joinSub($directChildSub, 'dc', 'dc.parent_user_id', '=', 'admin_users.id')
                ->leftJoinSub($totalDescendantSub, 'td', 'td.ancestor_id', '=', 'admin_users.id')
                ->with('userTypeRelation:id,user_type,role')
                ->where('admin_users.is_deleted', false);

            if ($request->filled('search')) {
                $term = '%' . $request->search . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('admin_users.name', 'ilike', $term)
                      ->orWhere('admin_users.email', 'ilike', $term)
                      ->orWhere('admin_users.mobile', 'ilike', $term);
                });
            }

            if ($request->filled('user_type_id')) {
                $query->where('admin_users.user_type', (int) $request->user_type_id);
            }

            if ($request->has('is_active')) {
                $query->where('admin_users.is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->filled('min_child_count')) {
                $query->havingRaw('COALESCE(dc.direct_child_count, 0) >= ?', [(int) $request->min_child_count]);
            }

            $sortColumn = match ($sortBy) {
                'direct_child_count'     => DB::raw('COALESCE(dc.direct_child_count, 0)'),
                'total_descendant_count' => DB::raw('COALESCE(td.total_descendant_count, 0)'),
                default                  => 'admin_users.name',
            };

            $paginated = $query->orderBy($sortColumn, $sortOrder)
                ->paginate($perPage, ['*'], 'page', $page);

            return $this->send_response('success', 'Parent list fetched successfully.', 200, [
                'parents' => $paginated->items(),
                'meta'    => $this->buildMeta($paginated),
            ]);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to fetch parent list. Please try again.', 422);
        }
    }

    /**
     * GET /api/user-hierarchy/parents/{userId}/children
     *
     * Direct children of the given parent, paginated.
     * Query params: search, user_type_id, is_active, sort_by, sort_order, per_page, page
     */
    public function childList(Request $request, int $userId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'search'       => ['sometimes', 'string', 'min:2', 'max:100'],
            'user_type_id' => ['sometimes', 'integer', 'exists:user_types,id'],
            'is_active'    => ['sometimes', 'boolean'],
            'sort_by'      => ['sometimes', 'in:name,is_active'],
            'sort_order'   => ['sometimes', 'in:asc,desc'],
            'per_page'     => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'         => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $parent = AdminUser::where('id', $userId)->where('is_deleted', false)->firstOrFail();

            $perPage   = (int) $request->input('per_page', 15);
            $page      = (int) $request->input('page', 1);
            $sortBy    = $request->input('sort_by', 'name');
            $sortOrder = $request->input('sort_order', 'asc');

            $childIds = UserHierarchyClosure::where('ancestor_id', $userId)
                ->where('depth', 1)
                ->pluck('descendant_id');

            $query = AdminUser::whereIn('id', $childIds)
                ->where('is_deleted', false)
                ->with('userTypeRelation:id,user_type,role')
                ->select('id', 'name', 'email', 'mobile', 'user_type', 'is_active', 'is_all_borrower_accessible');

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

            $paginated = $query->orderBy($sortBy, $sortOrder)
                ->paginate($perPage, ['*'], 'page', $page);

            return $this->send_response('success', 'Children fetched successfully.', 200, [
                'parent'   => $parent->only('id', 'name', 'email', 'mobile'),
                'children' => $paginated->items(),
                'meta'     => $this->buildMeta($paginated),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'User not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to fetch children. Please try again.', 422);
        }
    }

    /**
     * GET /api/user-hierarchy/descendants/{userId}
     */
    public function getDescendants(Request $request, int $userId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'     => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $user    = AdminUser::where('id', $userId)->where('is_deleted', false)->firstOrFail();
            $perPage = (int) $request->input('per_page', 15);
            $page    = (int) $request->input('page', 1);

            $paginated = $this->hierarchyService
                ->getDescendants($userId)
                ->with('userTypeRelation:id,user_type,role')
                ->select('id', 'name', 'email', 'mobile', 'user_type', 'is_active')
                ->orderBy('name')
                ->paginate($perPage, ['*'], 'page', $page);

            return $this->send_response('success', 'Descendants fetched successfully.', 200, [
                'user'        => $user->only('id', 'name', 'email'),
                'descendants' => $paginated->items(),
                'meta'        => $this->buildMeta($paginated),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'User not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to fetch descendants. Please try again.', 422);
        }
    }

    /**
     * GET /api/user-hierarchy/ancestors/{userId}
     */
    public function getAncestors(Request $request, int $userId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'     => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $user        = AdminUser::where('id', $userId)->where('is_deleted', false)->firstOrFail();
            $perPage     = (int) $request->input('per_page', 15);
            $page        = (int) $request->input('page', 1);
            $ancestorIds = $this->hierarchyService->getAncestorIds($userId);

            $paginated = AdminUser::whereIn('id', $ancestorIds)
                ->where('is_deleted', false)
                ->with('userTypeRelation:id,user_type,role')
                ->select('id', 'name', 'email', 'mobile', 'user_type', 'is_active')
                ->orderBy('name')
                ->paginate($perPage, ['*'], 'page', $page);

            return $this->send_response('success', 'Ancestors fetched successfully.', 200, [
                'user'      => $user->only('id', 'name', 'email'),
                'ancestors' => $paginated->items(),
                'meta'      => $this->buildMeta($paginated),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'User not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to fetch ancestors. Please try again.', 422);
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