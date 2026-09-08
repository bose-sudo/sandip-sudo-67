<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentHeadGroupResource;
use App\Models\PaymentHead;
use App\Models\PaymentHeadGroup;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentHeadGroupController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/payment-head-groups
     *
     * Filters: status (A|I), search (name/code)
     * Pagination: page, limit (default 20)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'nullable|in:A,I',
                'search' => 'nullable|string|max:100',
                'page'   => 'nullable|integer|min:1',
                'limit'  => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return $this->send_response('error', $validator->errors()->first(), 422);
            }

            $limit = (int) $request->input('limit', 20);

            $query = PaymentHeadGroup::with('heads')
                ->when($request->filled('status'),  fn ($q) => $q->where('row_status', $request->status))
                ->when(! $request->filled('status'), fn ($q) => $q->where('row_status', PaymentHeadGroup::STATUS_ACTIVE))
                ->when($request->filled('search'), function ($q) use ($request) {
                    $term = '%' . $request->search . '%';
                    $q->where(fn ($s) => $s->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term));
                })
                ->orderBy('name');

            $groups = $query->paginate($limit, ['*'], 'page', $request->input('page', 1));

            return $this->send_response('success', 'Payment head groups fetched successfully.', 200, [
                'data'       => PaymentHeadGroupResource::collection($groups->items()),  // <-- was PaymentHeadResource
                'pagination' => [
                    'total'        => $groups->total(),
                    'per_page'     => $groups->perPage(),
                    'current_page' => $groups->currentPage(),
                    'last_page'    => $groups->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            // Log::error('PaymentHeadGroup@index', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->send_response('error', 'Failed to fetch payment head groups.', 500);
        }
    }

    /**
     * POST /api/payment-head-groups
     *
     * Body: { name, code, description?, head_ids: [1,2,3] }
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name'        => 'required|string|max:150',
                'code'        => 'required|string|max:50|regex:/^[A-Z0-9_]+$/|unique:payment_head_groups,code',
                'description' => 'nullable|string|max:500',
                'head_ids'    => 'required|array|min:1',
                'head_ids.*'  => 'required|integer|exists:payment_heads,id',
            ], [
                'code.regex'        => 'Code must be uppercase letters, numbers, and underscores only (e.g. LATE_FINE).',
                'code.unique'       => 'A group with this code already exists.',
                'head_ids.required' => 'At least one payment head is required.',
                'head_ids.*.exists' => 'One or more selected payment heads do not exist.',
            ]);

            if ($validator->fails()) {
                return $this->send_response('error', $validator->errors()->first(), 422);
            }

            // Validate all heads are Active
            $headIds     = array_unique($request->head_ids);
            $activeCount = PaymentHead::whereIn('id', $headIds)
                ->where('row_status', PaymentHead::STATUS_ACTIVE)
                ->count();

            if ($activeCount !== count($headIds)) {
                return $this->send_response('error', 'One or more selected payment heads are inactive or deleted.', 422);
            }

            $group = DB::transaction(function () use ($request, $headIds) {
                $group = PaymentHeadGroup::create([
                    'name'        => $request->name,
                    'code'        => strtoupper(trim($request->code)),
                    'description' => $request->description,
                    'row_status'  => PaymentHeadGroup::STATUS_ACTIVE,
                ]);

                $now    = now();
                $userId = auth()->id();
                $ip     = $request->ip();

                $items = array_map(fn ($headId) => [
                    'payment_head_group_id' => $group->id,
                    'payment_head_id'       => $headId,
                    'created_by'            => $userId,
                    'created_by_ip'         => $ip,
                    'updated_by'            => $userId,
                    'updated_by_ip'         => $ip,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ], $headIds);

                DB::table('payment_head_group_items')->insert($items);

                return $group;
            });

            return $this->send_response(
                'success',
                'Payment head group created successfully.',
                201,
                new PaymentHeadGroupResource($group->load('heads'))
            );
        } catch (\Throwable $e) {
            // Log::error('PaymentHeadGroup@store', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->send_response('error', 'Failed to create payment head group.', 500);
        }
    }

    /**
     * GET /api/payment-head-groups/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $group = PaymentHeadGroup::with('heads')->find($id);

            if (! $group) {
                return $this->send_response('error', 'Payment head group not found.', 404);
            }

            return $this->send_response('success', 'Payment head group fetched successfully.', 200,
                new PaymentHeadGroupResource($group)
            );
        } catch (\Throwable $e) {
            // Log::error('PaymentHeadGroup@show', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->send_response('error', 'Failed to fetch payment head group.', 500);
        }
    }

    /**
     * PUT /api/payment-head-groups/{id}
     *
     * Supports partial updates. head_ids if provided replaces all existing items.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $group = PaymentHeadGroup::find($id);

            if (! $group) {
                return $this->send_response('error', 'Payment head group not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'name'        => 'sometimes|required|string|max:150',
                'code'        => 'sometimes|required|string|max:50|regex:/^[A-Z0-9_]+$/|unique:payment_head_groups,code,' . $id,
                'description' => 'nullable|string|max:500',
                'row_status'  => 'sometimes|required|in:A,I',
                'head_ids'    => 'sometimes|required|array|min:1',
                'head_ids.*'  => 'required|integer|exists:payment_heads,id',
            ], [
                'code.regex'        => 'Code must be uppercase letters, numbers, and underscores only.',
                'code.unique'       => 'A group with this code already exists.',
                'head_ids.*.exists' => 'One or more selected payment heads do not exist.',
            ]);

            if ($validator->fails()) {
                return $this->send_response('error', $validator->errors()->first(), 422);
            }

            if ($request->has('head_ids')) {
                $headIds     = array_unique($request->head_ids);
                $activeCount = PaymentHead::whereIn('id', $headIds)
                    ->where('row_status', PaymentHead::STATUS_ACTIVE)
                    ->count();

                if ($activeCount !== count($headIds)) {
                    return $this->send_response('error', 'One or more selected payment heads are inactive or deleted.', 422);
                }
            }

            DB::transaction(function () use ($request, $group) {
                $data = $request->only(['name', 'description', 'row_status']);

                if ($request->has('code')) {
                    $data['code'] = strtoupper(trim($request->code));
                }
                if (! empty($data)) {
                    $group->update($data);
                }

                if ($request->has('head_ids')) {
                    $headIds = array_unique($request->head_ids);
                    $now     = now();
                    $userId  = auth()->id();
                    $ip      = request()->ip();

                    DB::table('payment_head_group_items')
                        ->where('payment_head_group_id', $group->id)
                        ->delete();

                    $items = array_map(fn ($headId) => [
                        'payment_head_group_id' => $group->id,
                        'payment_head_id'       => $headId,
                        'created_by'            => $userId,
                        'created_by_ip'         => $ip,
                        'updated_by'            => $userId,
                        'updated_by_ip'         => $ip,
                        'created_at'            => $now,
                        'updated_at'            => $now,
                    ], $headIds);

                    DB::table('payment_head_group_items')->insert($items);
                }
            });

            return $this->send_response('success', 'Payment head group updated successfully.', 200,
                new PaymentHeadGroupResource($group->fresh()->load('heads'))
            );
        } catch (\Throwable $e) {
            // Log::error('PaymentHeadGroup@update', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->send_response('error', 'Failed to update payment head group.', 500);
        }
    }

    /**
     * DELETE /api/payment-head-groups/{id}
     *
     * Sets row_status = D. Pivot rows kept for audit trail.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $group = PaymentHeadGroup::find($id);

            if (! $group) {
                return $this->send_response('error', 'Payment head group not found.', 404);
            }

            DB::transaction(fn () => $group->update(['row_status' => PaymentHeadGroup::STATUS_DELETED]));

            return $this->send_response('success', 'Payment head group deleted successfully.', 200);
        } catch (\Throwable $e) {
            // Log::error('PaymentHeadGroup@destroy', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->send_response('error', 'Failed to delete payment head group.', 500);
        }
    }

    /**
     * POST /api/payment-head-groups/{id}/toggle-status
     * Toggles A <-> I only.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        try {
            $group = PaymentHeadGroup::find($id);

            if (! $group) {
                return $this->send_response('error', 'Payment head group not found.', 404);
            }

            DB::transaction(function () use ($group) {
                $newStatus = $group->row_status === PaymentHeadGroup::STATUS_ACTIVE
                    ? PaymentHeadGroup::STATUS_INACTIVE
                    : PaymentHeadGroup::STATUS_ACTIVE;

                $group->update(['row_status' => $newStatus]);
            });

            $fresh = $group->fresh()->load('heads');
            $label = $fresh->row_status === PaymentHeadGroup::STATUS_ACTIVE ? 'activated' : 'deactivated';

            return $this->send_response('success', "Payment head group {$label} successfully.", 200,
                new PaymentHeadGroupResource($fresh)
            );
        } catch (\Throwable $e) {
            // Log::error('PaymentHeadGroup@toggleStatus', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->send_response('error', 'Failed to toggle payment head group status.', 500);
        }
    }
}