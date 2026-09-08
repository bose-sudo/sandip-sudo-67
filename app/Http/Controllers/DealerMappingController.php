<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\DealerUserMap;
use App\Services\DealerMappingService;
use App\Services\UserHierarchyService;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DealerMappingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DealerMappingService $mappingService,
        private readonly UserHierarchyService $hierarchyService,
    ) {}

    // =========================================================================
    // Map / Unmap — CRUD (unchanged)
    // =========================================================================

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id'                   => ['required', 'integer', 'exists:admin_users,id'],
            'dealers'                   => ['required', 'array', 'min:1'],
            'dealers.*.dealer_unq'      => ['required', 'string', 'exists:dealers,dealer_unq'],
            'dealers.*.visibility_mode' => ['required', Rule::in(['inherit', 'strict'])],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $results = [];
            foreach ($request->dealers as $dealerData) {
                $results[] = $this->mappingService->mapDealerToUser(
                    dealerUnq:      $dealerData['dealer_unq'],
                    userId:         (int) $request->user_id,
                    visibilityMode: $dealerData['visibility_mode'],
                    createdBy:      Auth::id()
                );
            }

            return $this->send_response('success', 'Dealer(s) mapped successfully.', 200, [
                'mappings' => $results,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'Dealer or user not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to create mapping. Please try again.', 422);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'visibility_mode' => ['sometimes', Rule::in(['inherit', 'strict'])],
            'status'          => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $mapping = $this->mappingService->updateMapping(
                mappingId: $id,
                data:      $validator->validated(),
                updatedBy: Auth::id()
            );

            return $this->send_response('success', 'Mapping updated successfully.', 200, [
                'mapping' => $mapping->load('dealer', 'user'),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'Mapping not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to update mapping. Please try again.', 422);
        }
    }

    public function deactivate(int $id): JsonResponse
    {
        try {
            $mapping = $this->mappingService->deactivateMapping($id, Auth::id());

            return $this->send_response('success', 'Mapping deactivated.', 200, ['mapping' => $mapping]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'Mapping not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to deactivate. Please try again.', 422);
        }
    }

    public function activate(int $id): JsonResponse
    {
        try {
            $mapping = $this->mappingService->activateMapping($id, Auth::id());

            return $this->send_response('success', 'Mapping activated.', 200, ['mapping' => $mapping]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'Mapping not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to activate. Please try again.', 422);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->mappingService->deleteMapping($id);

            return $this->send_response('success', 'Mapping deleted successfully.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'Mapping not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to delete. Please try again.', 422);
        }
    }

    // =========================================================================
    // LIST — users with dealer counts (summary view)
    // =========================================================================

    /**
     * GET /api/dealer-mapping/users
     *
     * Paginated list of admin users showing how many dealers are mapped to each.
     * Clicking a row leads to /dealer-mapping/by-user/{userId} for the dealer list.
     *
     * Query params:
     *   search              string   user name / email / mobile
     *   user_type_id        int      filter by user type
     *   is_active           bool     filter by user active status
     *   has_mappings        bool     true = only users WITH mappings, false = only users WITHOUT
     *   visibility_mode     string   inherit|strict — only count mappings of this mode
     *   min_dealer_count    int      only users with >= N active mappings
     *   sort_by             string   name|active_dealer_count|total_dealer_count  (default: active_dealer_count)
     *   sort_order          string   asc|desc  (default: desc)
     *   per_page            int      default 15, max 100
     *   page                int      default 1
     */
    public function userMappingSummary(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'search'           => ['sometimes', 'string', 'min:2', 'max:100'],
            'user_type_id'     => ['sometimes', 'integer', 'exists:user_types,id'],
            'is_active'        => ['sometimes', 'boolean'],
            'has_mappings'     => ['sometimes', 'boolean'],
            'visibility_mode'  => ['sometimes', Rule::in(['inherit', 'strict'])],
            'min_dealer_count' => ['sometimes', 'integer', 'min:1'],
            'sort_by'          => ['sometimes', 'in:name,active_dealer_count,total_dealer_count'],
            'sort_order'       => ['sometimes', 'in:asc,desc'],
            'per_page'         => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'             => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $perPage   = (int) $request->input('per_page', 15);
            $page      = (int) $request->input('page', 1);
            $sortBy    = $request->input('sort_by', 'active_dealer_count');
            $sortOrder = $request->input('sort_order', 'desc');

            // Active dealer count sub-query (optionally filtered by visibility_mode)
            $activeSub = DB::table('dealer_user_map')
                ->select('user_id', DB::raw('count(*) as active_dealer_count'))
                ->where('status', 'active')
                ->when($request->filled('visibility_mode'), fn ($q) =>
                    $q->where('visibility_mode', $request->visibility_mode)
                )
                ->groupBy('user_id');

            // Total dealer count sub-query (all statuses)
            $totalSub = DB::table('dealer_user_map')
                ->select('user_id', DB::raw('count(*) as total_dealer_count'))
                ->groupBy('user_id');

            $query = AdminUser::query()
                ->select(
                    'admin_users.id',
                    'admin_users.name',
                    'admin_users.email',
                    'admin_users.mobile',
                    'admin_users.user_type',
                    'admin_users.is_active',
                    'admin_users.is_all_borrower_accessible',
                    DB::raw('COALESCE(am.active_dealer_count, 0)::int AS active_dealer_count'),
                    DB::raw('COALESCE(tm.total_dealer_count, 0)::int  AS total_dealer_count'),
                )
                ->leftJoinSub($activeSub, 'am', 'am.user_id', '=', 'admin_users.id')
                ->leftJoinSub($totalSub,  'tm', 'tm.user_id', '=', 'admin_users.id')
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

            if ($request->has('has_mappings')) {
                $hasMappings = filter_var($request->has_mappings, FILTER_VALIDATE_BOOLEAN);
                $query->havingRaw(
                    $hasMappings
                        ? 'COALESCE(tm.total_dealer_count, 0) > 0'
                        : 'COALESCE(tm.total_dealer_count, 0) = 0'
                );
            }

            if ($request->filled('min_dealer_count')) {
                $query->havingRaw('COALESCE(am.active_dealer_count, 0) >= ?', [(int) $request->min_dealer_count]);
            }

            $sortColumn = match ($sortBy) {
                'active_dealer_count' => DB::raw('COALESCE(am.active_dealer_count, 0)'),
                'total_dealer_count'  => DB::raw('COALESCE(tm.total_dealer_count, 0)'),
                default               => 'admin_users.name',
            };

            $query->orderBy($sortColumn, $sortOrder);

            $paginated = $query->paginate($perPage, ['*'], 'page', $page);

            return $this->send_response('success', 'User mapping summary fetched successfully.', 200, [
                'users' => $paginated->items(),
                'meta'  => $this->buildMeta($paginated),
            ]);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to fetch mapping summary. Please try again.', 422);
        }
    }

    // =========================================================================
    // LIST — dealer list for a specific user (drill-down)
    // =========================================================================

    /**
     * GET /api/dealer-mapping/by-user/{userId}
     *
     * All dealers mapped to a specific user, with mapping details.
     *
     * Query params:
     *   status           string   active|inactive  (default: active)
     *   visibility_mode  string   inherit|strict
     *   search           string   dealer name or dealer_unq
     *   sort_by          string   dealer_name|visibility_mode|status|created_at  (default: created_at)
     *   sort_order       string   asc|desc  (default: desc)
     *   per_page         int      default 15, max 100
     *   page             int      default 1
     */
    public function byUser(Request $request, int $userId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status'          => ['sometimes', Rule::in(['active', 'inactive'])],
            'visibility_mode' => ['sometimes', Rule::in(['inherit', 'strict'])],
            'search'          => ['sometimes', 'string', 'min:2', 'max:100'],
            'sort_by'         => ['sometimes', 'in:dealer_name,visibility_mode,status,created_at'],
            'sort_order'      => ['sometimes', 'in:asc,desc'],
            'per_page'        => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'            => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $user    = AdminUser::where('id', $userId)->where('is_deleted', false)->firstOrFail();
            $perPage = (int) $request->input('per_page', 15);
            $page    = (int) $request->input('page', 1);
            $sortBy  = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');

            $query = DealerUserMap::with(['dealer:id,dealer_unq,name,city,state,is_active'])
                ->where('user_id', $userId)
                ->when(
                    $request->filled('status'),
                    fn ($q) => $q->where('status', $request->status),
                    fn ($q) => $q->where('status', 'active') // default to active
                )
                ->when($request->filled('visibility_mode'), fn ($q) =>
                    $q->where('visibility_mode', $request->visibility_mode)
                );

            if ($request->filled('search')) {
                $term = '%' . $request->search . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('dealer_unq', 'ilike', $term)
                      ->orWhereHas('dealer', fn ($d) => $d->where('name', 'ilike', $term));
                });
            }

            // Map sort_by alias to actual column
            $sortColumn = $sortBy === 'dealer_name'
                ? DB::raw("(SELECT name FROM dealers WHERE dealers.dealer_unq = dealer_user_map.dealer_unq LIMIT 1)")
                : "dealer_user_map.{$sortBy}";

            $paginated = $query->orderBy($sortColumn, $sortOrder)
                ->paginate($perPage, ['*'], 'page', $page);

            return $this->send_response('success', 'Dealer mappings fetched successfully.', 200, [
                'user'     => $user->only('id', 'name', 'email', 'mobile'),
                'mappings' => $paginated->items(),
                'meta'     => $this->buildMeta($paginated),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->send_response('failed', 'User not found.', 422);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to fetch dealer mappings. Please try again.', 422);
        }
    }

    // =========================================================================
    // LIST — user list for a specific dealer
    // =========================================================================

    /**
     * GET /api/dealer-mapping/by-dealer/{dealerUnq}
     *
     * Query params:
     *   status           string   active|inactive
     *   visibility_mode  string   inherit|strict
     *   per_page         int
     *   page             int
     */
    public function byDealer(Request $request, string $dealerUnq): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status'          => ['sometimes', Rule::in(['active', 'inactive'])],
            'visibility_mode' => ['sometimes', Rule::in(['inherit', 'strict'])],
            'per_page'        => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'            => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $perPage = (int) $request->input('per_page', 15);
            $page    = (int) $request->input('page', 1);

            $query = DealerUserMap::with(['user:id,name,email,mobile,user_type,is_active', 'user.userTypeRelation:id,user_type,role'])
                ->where('dealer_unq', $dealerUnq)
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->when($request->filled('visibility_mode'), fn ($q) => $q->where('visibility_mode', $request->visibility_mode))
                ->orderByDesc('created_at');

            $paginated = $query->paginate($perPage, ['*'], 'page', $page);

            return $this->send_response('success', 'User mappings fetched successfully.', 200, [
                'dealer_unq' => $dealerUnq,
                'mappings'   => $paginated->items(),
                'meta'       => $this->buildMeta($paginated),
            ]);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to fetch user mappings. Please try again.', 422);
        }
    }

    // =========================================================================
    // Visible dealers for a user
    // =========================================================================

    /**
     * GET /api/dealer-mapping/visible-dealers/{userId}
     */
    public function visibleDealers(Request $request, int $userId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'     => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $perPage    = (int) $request->input('per_page', 15);
            $page       = (int) $request->input('page', 1);
            $dealerUnqs = $this->hierarchyService->getVisibleDealerUnqs($userId);

            $paginated = \App\Models\Dealer::whereIn('dealer_unq', $dealerUnqs)
                ->where('is_deleted', false)
                ->select('id', 'dealer_unq', 'name', 'city', 'state', 'is_active')
                ->orderBy('name')
                ->paginate($perPage, ['*'], 'page', $page);

            return $this->send_response('success', 'Visible dealers fetched successfully.', 200, [
                'dealers' => $paginated->items(),
                'meta'    => $this->buildMeta($paginated),
            ]);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Failed to fetch visible dealers. Please try again.', 422);
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