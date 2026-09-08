<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentHeadResource;
use App\Models\PaymentHead;
use App\Models\PaymentHeadGroup;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentHeadController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/payment-heads
     *
     * Filters: status (A|I), calculation_type (fixed|percentage), search (name/code)
     * Pagination: page, limit (default 20)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status'           => 'nullable|in:A,I',
                'calculation_type' => 'nullable|in:fixed,percentage',
                'search'           => 'nullable|string|max:100',
                'page'             => 'nullable|integer|min:1',
                'limit'            => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            $limit = (int) $request->input('limit', 20);

            $query = PaymentHead::query()
                ->when($request->filled('status'),           fn ($q) => $q->where('row_status', $request->status))
                ->when(! $request->filled('status'),         fn ($q) => $q->where('row_status', PaymentHead::STATUS_ACTIVE))
                ->when($request->filled('calculation_type'), fn ($q) => $q->where('calculation_type', $request->calculation_type))
                ->when($request->filled('search'), function ($q) use ($request) {
                    $term = '%' . $request->search . '%';
                    $q->where(fn ($s) => $s->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term));
                })
                ->orderBy('name');

            $heads = $query->paginate($limit, ['*'], 'page', $request->input('page', 1));

            return $this->send_response('success', 'Payment heads fetched successfully.', 200, [
                'data'       => PaymentHeadResource::collection($heads->items()),
                'pagination' => [
                    'total'        => $heads->total(),
                    'per_page'     => $heads->perPage(),
                    'current_page' => $heads->currentPage(),
                    'last_page'    => $heads->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            // Log::error('PaymentHead@index', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->send_response('failed', 'Failed to fetch payment heads.', 422);
        }
    }

    public function screener() {
        try {

            $payment_heads = PaymentHead::select(['id', 'name'])
                ->where('row_status', 'A')
                ->get();

            return $this->send_response('success', "Payment heads fetched successfully.", 200, $payment_heads);

        } catch (\Exception $e) {

            return $this->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);

        }
    }

    /**
     * POST /api/payment-heads
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name'             => 'required|string|max:150',
                'code'             => 'required|string|max:50|regex:/^[A-Z0-9_]+$/|unique:payment_heads,code',
                'description'      => 'nullable|string|max:422',
                'calculation_type' => 'required|in:fixed,percentage',
                'default_value'    => 'required|numeric|min:0',
                'percentage_basis' => 'required_if:calculation_type,percentage|nullable|in:outstanding_principal,overdue_emi,total_outstanding',
                'is_per_annum'     => 'boolean',
                'is_gst_applicable'=> 'boolean',
                'gst_rate'         => 'required_if:is_gst_applicable,true|nullable|numeric|min:0|max:100',
            ], [
                'code.regex'                  => 'Code must be uppercase letters, numbers, and underscores only (e.g. BOUNCE).',
                'code.unique'                 => 'A payment head with this code already exists.',
                'percentage_basis.required_if'=> 'Percentage basis is required when calculation type is percentage.',
                'gst_rate.required_if'        => 'GST rate is required when GST is applicable.',
            ]);

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            $isGst = filter_var($request->input('is_gst_applicable', false), FILTER_VALIDATE_BOOLEAN);
            $isPerAnnum = filter_var($request->input('is_per_annum', false), FILTER_VALIDATE_BOOLEAN);

            $head = DB::transaction(fn () => PaymentHead::create([
                'name'              => $request->name,
                'code'              => strtoupper(trim($request->code)),
                'description'       => $request->description,
                'calculation_type'  => $request->calculation_type,
                'default_value'     => $request->default_value,
                'percentage_basis'  => $request->calculation_type === 'percentage' ? $request->percentage_basis : null,
                'is_per_annum'      => $isPerAnnum,
                'is_gst_applicable' => $isGst,
                'gst_rate'          => $isGst ? $request->gst_rate : 0.00,
                'is_system'         => false,
                'row_status'        => PaymentHead::STATUS_ACTIVE,
            ]));

            return $this->send_response('success', 'Payment head created successfully.', 201,
                new PaymentHeadResource($head)
            );
        } catch (\Throwable $e) {
            // Log::error('PaymentHead@store', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->send_response('failed', 'Failed to create payment head.', 422);
        }
    }

    /**
     * GET /api/payment-heads/{id}
     *
     * Loads groups so the response shows which groups this head belongs to.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $head = PaymentHead::with('groups')->find($id);

            if (! $head) {
                return $this->send_response('failed', 'Payment head not found.', 404);
            }

            return $this->send_response('success', 'Payment head fetched successfully.', 200,
                new PaymentHeadResource($head)
            );
        } catch (\Throwable $e) {
            // Log::error('PaymentHead@show', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->send_response('failed', 'Failed to fetch payment head.', 422);
        }
    }

    /**
     * PUT /api/payment-heads/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $head = PaymentHead::find($id);

            if (! $head) {
                return $this->send_response('failed', 'Payment head not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'name'              => 'sometimes|required|string|max:150',
                'code'              => 'sometimes|required|string|max:50|regex:/^[A-Z0-9_]+$/|unique:payment_heads,code,' . $id,
                'description'       => 'nullable|string|max:422',
                'calculation_type'  => 'sometimes|required|in:fixed,percentage',
                'default_value'     => 'sometimes|required|numeric|min:0',
                'percentage_basis'  => 'nullable|in:outstanding_principal,overdue_emi,total_outstanding',
                'is_per_annum'      => 'boolean',
                'is_gst_applicable' => 'boolean',
                'gst_rate'          => 'nullable|numeric|min:0|max:100',
                'row_status'        => 'sometimes|required|in:A,I',
            ], [
                'code.regex'  => 'Code must be uppercase letters, numbers, and underscores only.',
                'code.unique' => 'A payment head with this code already exists.',
            ]);

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            // System heads: block core field changes
            if ($head->is_system) {
                $coreFields = ['name', 'code', 'calculation_type', 'percentage_basis', 'is_per_annum'];
                $attempted  = array_intersect($coreFields, array_keys($request->only($coreFields)));

                if (! empty($attempted)) {
                    return $this->send_response(
                        'error',
                        'System payment heads do not allow modification of: ' . implode(', ', $attempted),
                        422
                    );
                }
            }

            DB::transaction(function () use ($request, $head) {
                $data     = $request->only(['name', 'description', 'default_value', 'percentage_basis', 'row_status']);
                $calcType = $request->input('calculation_type', $head->calculation_type);

                if ($request->has('code')) {
                    $data['code'] = strtoupper(trim($request->code));
                }
                if ($request->has('calculation_type')) {
                    $data['calculation_type'] = $calcType;
                }
                if ($request->has('is_per_annum')) {
                    $data['is_per_annum'] = filter_var($request->is_per_annum, FILTER_VALIDATE_BOOLEAN);
                }

                // If flipped to fixed, clear percentage-only fields
                if ($calcType === 'fixed') {
                    $data['percentage_basis'] = null;
                    $data['is_per_annum']     = false;
                }

                if ($request->has('is_gst_applicable')) {
                    $gstApplicable          = filter_var($request->is_gst_applicable, FILTER_VALIDATE_BOOLEAN);
                    $data['is_gst_applicable'] = $gstApplicable;
                    $data['gst_rate']          = $gstApplicable ? ($request->gst_rate ?? $head->gst_rate) : 0.00;
                } elseif ($request->has('gst_rate') && $head->is_gst_applicable) {
                    $data['gst_rate'] = $request->gst_rate;
                }

                $head->update($data);
            });

            return $this->send_response('success', 'Payment head updated successfully.', 200,
                new PaymentHeadResource($head->fresh()->load('groups'))
            );
        } catch (\Throwable $e) {
            // Log::error('PaymentHead@update', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->send_response('failed', 'Failed to update payment head.', 422);
        }
    }

    /**
     * DELETE /api/payment-heads/{id}
     *
     * Sets row_status = D. Blocked if head is still attached to active groups.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $head = PaymentHead::find($id);

            if (! $head) {
                return $this->send_response('failed', 'Payment head not found.', 404);
            }

            if ($head->is_system) {
                return $this->send_response('failed', 'System payment heads cannot be deleted.', 422);
            }

            // Block deletion if head is used in any active group
            $activeGroupCount = $head->groups()
                ->where('row_status', PaymentHeadGroup::STATUS_ACTIVE)
                ->count();

            if ($activeGroupCount > 0) {
                return $this->send_response(
                    'error',
                    'This payment head is part of ' . $activeGroupCount . ' active group(s). Remove it from those groups before deleting.',
                    422
                );
            }

            DB::transaction(fn () => $head->update(['row_status' => PaymentHead::STATUS_DELETED]));

            return $this->send_response('success', 'Payment head deleted successfully.', 200);
        } catch (\Throwable $e) {
            // Log::error('PaymentHead@destroy', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->send_response('failed', 'Failed to delete payment head.', 422);
        }
    }

    /**
     * POST /api/payment-heads/{id}/toggle-status
     * Toggles A <-> I.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        try {
            $head = PaymentHead::find($id);

            if (! $head) {
                return $this->send_response('failed', 'Payment head not found.', 404);
            }

            DB::transaction(function () use ($head) {
                $newStatus = $head->row_status === PaymentHead::STATUS_ACTIVE
                    ? PaymentHead::STATUS_INACTIVE
                    : PaymentHead::STATUS_ACTIVE;

                $head->update(['row_status' => $newStatus]);
            });

            $fresh = $head->fresh()->load('groups');
            $label = $fresh->row_status === PaymentHead::STATUS_ACTIVE ? 'activated' : 'deactivated';

            return $this->send_response('success', "Payment head {$label} successfully.", 200,
                new PaymentHeadResource($fresh)
            );
        } catch (\Throwable $e) {
            // Log::error('PaymentHead@toggleStatus', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->send_response('failed', 'Failed to toggle payment head status.', 422);
        }
    }
}