<?php

namespace App\Http\Controllers;

use App\Models\BorrowerEditRequest;
use App\Models\BorrowerEditRequestItem;
use App\Models\BorrowerKyc;
use App\Models\Occupation;
use App\Models\ResidentTypes;
use App\Services\BorrowerEditApplyService;
use App\Services\BorrowerEditRequestService;
use App\Services\MakerCheckerService;
use App\Support\BorrowerEditFieldRegistry as Registry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class BorrowerEditRequestController extends Controller
{
    private const ROUTE_USED = BorrowerEditRequestService::ROUTE_USED;

    /** Relations every response row needs. */
    private const ROW_RELATIONS = [
        'borrower:id,borrower_id,status',
        'maker:id,name',
        'reviewer1:id,name',
        'reviewer2:id,name',
    ];

    public function __construct(
        private readonly HelperController           $helper_controller,
        private readonly MakerCheckerService        $makerChecker,
        private readonly BorrowerEditRequestService $editService,
        private readonly BorrowerEditApplyService   $applyService,
    ) {}

    // =====================================================================
    // 1. Editable data + per-section flags
    // =====================================================================
    public function get_editable_details($borrower_id = null)
    {
        try {
            if (!$borrower_id) {
                return $this->helper_controller->send_response('failed', 'Borrower id is required', 422);
            }

            $loan     = $this->editService->loadBorrower((int) $borrower_id);
            $snapshot = $this->editService->editableSnapshot($loan->id);

            $pending = BorrowerEditRequest::where('borrower_id', $loan->id)
                ->where('row_status', 'A')
                ->where('completion_status', 'pending')
                ->value('ber_id');

            return $this->helper_controller->send_response('success', 'Borrower editable details fetched', 200, [
                'borrower_primary'    => $loan->id,
                'borrower_id'         => $loan->borrower_id,
                'borrower_name'       => $this->borrowerName($loan->id),
                'loan_status'         => $loan->status,
                'is_edit_allowed'     => $loan->status === BorrowerEditRequestService::EDITABLE_STATUS,
                'has_pending_request' => (bool) $pending,
                'pending_ber_id'      => $pending,
                'flags'               => $snapshot['flags'],
                'sections'            => $snapshot['sections'],
            ]);
        } catch (RuntimeException $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // =====================================================================
    // 2. Create edit request  (maker)
    // =====================================================================
    public function create(Request $request)
    {
        try {
            $rules = array_merge([
                'borrower_id' => 'required|integer|exists:loan_basic_details,id',
                'reason'      => 'required|string|max:500',
                'sections'    => 'required|array|min:1',
            ], Registry::validationRules());

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $authId = (int) Auth::id();

            $authCheck = $this->makerChecker->authorizeMaker(self::ROUTE_USED, $authId);
            if (!$authCheck['authorized']) {
                return $this->helper_controller->send_response('failed', $authCheck['message'], 422);
            }
            $authorization = $authCheck['authorization'];

            $loan = $this->editService->loadBorrower((int) $request->borrower_id);
            $this->editService->assertEditableStatus($loan);

            $openExists = BorrowerEditRequest::where('borrower_id', $loan->id)
                ->where('row_status', 'A')
                ->where('completion_status', 'pending')
                ->exists();

            if ($openExists) {
                return $this->helper_controller->send_response(
                    'failed',
                    'An edit request is already in progress for this borrower. Please wait for it to be completed.',
                    422
                );
            }

            $sections = array_intersect_key(
                $request->input('sections', []),
                array_flip(Registry::sectionKeys())
            );

            $items  = $this->editService->buildItems($loan->id, $sections);
            $client = BorrowerEditRequestService::clientContext($request);

            DB::beginTransaction();

            $editRequest = $this->editService->persist([
                'borrower_id'        => $loan->id,
                'maker_id'           => $authId,
                'reason'             => $request->reason,
                'requested_at'       => now(),
                'r1_id'              => $authorization->r1_id,
                'r1_approval_status' => 'pending',
                'r2_id'              => $authorization->r2_id,
                'r2_approval_status' => $authorization->r2_id ? 'pending' : null,
                'completion_status'  => 'pending',
                'maker_ip'           => $client['ip'],
                'maker_user_agent'   => $client['user_agent'],
                'row_status'         => 'A',
                'created_by'         => $authId,
            ], $items);

            DB::commit();

            return $this->helper_controller->send_response(
                'success',
                'Borrower edit request created successfully and sent for approval',
                200,
                $this->row($editRequest->load(self::ROW_RELATIONS), $authId)
            );
        } catch (RuntimeException $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // =====================================================================
    // 3. Single request
    // =====================================================================
    public function edit(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'ber_id' => 'required|integer|exists:borrower_edit_requests,ber_id',
            ]);
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $editRequest = BorrowerEditRequest::with(self::ROW_RELATIONS)
                ->where('ber_id', $request->ber_id)
                ->where('row_status', '!=', 'D')
                ->first();

            if (!$editRequest) {
                return $this->helper_controller->send_response('failed', 'Request not found', 422);
            }

            return $this->helper_controller->send_response(
                'success',
                'Request retrieved successfully',
                200,
                $this->row($editRequest, (int) Auth::id())
            );
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // =====================================================================
    // 4. List of edit requests
    // =====================================================================
    public function list(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page'               => 'nullable|integer|min:1',
                'limit'              => 'nullable|integer|min:1|max:100',
                'borrower_id'        => 'nullable|integer',
                'borrower_unq'       => 'nullable|string|max:60',
                'start_date'         => 'nullable|date',
                'end_date'           => 'nullable|date|after_or_equal:start_date',
                'status'             => 'nullable|in:pending,approved,rejected,failed',
                'r1_approval_status' => 'nullable|in:pending,on hold,approved,rejected',
                'r2_approval_status' => 'nullable|in:pending,on hold,approved,rejected',
                'row_status'         => 'nullable|in:A,I',
            ]);
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $authId = (int) Auth::id();
            $page   = (int) $request->input('page', 1);
            $limit  = (int) $request->input('limit', 10);

            $query = BorrowerEditRequest::with(self::ROW_RELATIONS);

            // Maker sees their own; R1/R2 see the ones assigned to them.
            $query->where(function ($q) use ($authId) {
                $q->where('maker_id', $authId)
                  ->orWhere('r1_id', $authId)
                  ->orWhere('r2_id', $authId);
            });

            $request->filled('row_status')
                ? $query->where('row_status', $request->row_status)
                : $query->where('row_status', '!=', 'D');

            if ($request->filled('borrower_id')) {
                $query->where('borrower_id', $request->borrower_id);
            }

            if ($request->filled('borrower_unq')) {
                $query->whereHas('borrower', fn ($b) => $b->whereRaw(
                    'LOWER(borrower_id) LIKE ?',
                    ['%' . strtolower($request->borrower_unq) . '%']
                ));
            }

            if ($request->filled('start_date')) {
                $query->where('requested_at', '>=', Carbon::parse($request->start_date, 'Asia/Kolkata')->startOfDay());
            }
            if ($request->filled('end_date')) {
                $query->where('requested_at', '<=', Carbon::parse($request->end_date, 'Asia/Kolkata')->endOfDay());
            }

            if ($request->filled('status')) {
                $query->where('completion_status', match ($request->status) {
                    'approved' => 'completed',
                    default    => $request->status,
                });
            }

            if ($request->filled('r1_approval_status')) {
                $query->where('r1_approval_status', $request->r1_approval_status);
            }
            if ($request->filled('r2_approval_status')) {
                $query->where('r2_approval_status', $request->r2_approval_status);
            }

            $paginated = $query->orderByDesc('requested_at')
                ->paginate($limit, ['*'], 'page', $page);

            // Borrower names for the whole page in one query.
            $names = BorrowerKyc::whereIn('borrower_id', $paginated->getCollection()->pluck('borrower_id'))
                ->orderBy('id')
                ->pluck('name', 'borrower_id')   // later row wins = latest
                ->all();

            $paginated->getCollection()->transform(
                fn ($item) => $this->row($item, $authId, $names)
            );

            return $this->helper_controller->send_response('success', 'Edit requests fetched successfully', 200, $paginated);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // =====================================================================
    // 5. See Changes — sections → fields, ready to foreach
    // =====================================================================
    public function get_changes(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'ber_id' => 'required|integer|exists:borrower_edit_requests,ber_id',
            ]);
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $editRequest = BorrowerEditRequest::with(['items', 'borrower:id,borrower_id', 'maker:id,name'])
                ->where('ber_id', $request->ber_id)
                ->where('row_status', '!=', 'D')
                ->first();

            if (!$editRequest) {
                return $this->helper_controller->send_response('failed', 'Request not found', 422);
            }

            return $this->helper_controller->send_response('success', 'Changes fetched successfully', 200, [
                'ber_id'        => $editRequest->ber_id,
                'borrower_id'   => $editRequest->borrower->borrower_id ?? '',
                'borrower_name' => $this->borrowerName($editRequest->borrower_id),
                'reason'        => $editRequest->reason ?? '',
                'requested_by'  => $editRequest->maker->name ?? '',
                'requested_at'  => $this->ist($editRequest->requested_at),
                'sections'      => $this->editService->changeSheet($editRequest->items),
            ]);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // =====================================================================
    // 6. R1 / R2 review
    // =====================================================================
    public function r1UpdateStatus(Request $request)
    {
        return $this->review($request, 'r1');
    }

    public function r2UpdateStatus(Request $request)
    {
        return $this->review($request, 'r2');
    }

    private function review(Request $request, string $level)
    {
        $statusField = "{$level}_approval_status";
        $reasonField = "{$level}_reason";

        try {
            $validator = Validator::make($request->all(), [
                'ber_id'     => 'required|integer|exists:borrower_edit_requests,ber_id',
                $statusField => 'required|in:on hold,approved,rejected,reject',
                $reasonField => "required_if:{$statusField},on hold,rejected,reject|nullable|string|max:500",
            ]);
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $status = $request->input($statusField) === 'reject' ? 'rejected' : $request->input($statusField);
            $authId = (int) Auth::id();

            DB::beginTransaction();

            $editRequest = BorrowerEditRequest::where('ber_id', $request->ber_id)
                ->where('row_status', 'A')
                ->lockForUpdate()
                ->first();

            if (!$editRequest) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Request not found', 422);
            }

            if ($editRequest->completion_status !== 'pending') {
                DB::rollBack();
                return $this->helper_controller->send_response(
                    'failed',
                    "This request is already {$editRequest->completion_status}",
                    422
                );
            }

            if ($level === 'r2' && $editRequest->r1_approval_status !== 'approved') {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'R1 must approve before R2 can review', 422);
            }

            $authorization = $this->makerChecker->authorizationForReviewer(
                self::ROUTE_USED,
                (int) $editRequest->maker_id,
                $authId,
                $level
            );

            if (!$authorization) {
                DB::rollBack();
                return $this->helper_controller->send_response(
                    'failed',
                    'You are not authorized as ' . strtoupper($level) . ' for this request',
                    422
                );
            }

            $editRequest->{$statusField}           = $status;
            $editRequest->{"{$level}_id"}          = $authId;
            $editRequest->{"{$level}_approved_at"} = now();
            $editRequest->{$reasonField}           = $request->input($reasonField);
            $editRequest->{"{$level}_ip"}          = $request->ip();
            $editRequest->updated_by               = $authId;

            // Rejected — terminal, nothing is written to the borrower tables.
            if ($status === 'rejected') {
                $editRequest->completion_status = 'rejected';
                $editRequest->completed_at      = now();
                $editRequest->save();

                DB::commit();

                return $this->helper_controller->send_response(
                    'success',
                    strtoupper($level) . ' rejected the request. No changes were applied.',
                    200,
                    $this->row($editRequest->load(self::ROW_RELATIONS), $authId)
                );
            }

            $editRequest->save();

            // Apply only on the final approval in the chain.
            $isFinal = $level === 'r2' || ($level === 'r1' && !$authorization->r2_id);

            if ($status !== 'approved' || !$isFinal) {
                DB::commit();

                $msg = $status === 'on hold'
                    ? strtoupper($level) . ' put the request on hold'
                    : strtoupper($level) . ' approved. Awaiting R2 approval.';

                return $this->helper_controller->send_response(
                    'success',
                    $msg,
                    200,
                    $this->row($editRequest->load(self::ROW_RELATIONS), $authId)
                );
            }

            $appliedCount = $this->applyService->apply($editRequest, $authId);

            $editRequest->completion_status = 'completed';
            $editRequest->completed_at      = now();
            $editRequest->save();

            DB::commit();

            return $this->helper_controller->send_response(
                'success',
                "Request approved. {$appliedCount} field(s) updated successfully.",
                200,
                $this->row($editRequest->load(self::ROW_RELATIONS), $authId)
            );
        } catch (RuntimeException $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // =====================================================================
    // 7. Delete (maker only, before any review)
    // =====================================================================
    public function delete(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'ber_id' => 'required|integer|exists:borrower_edit_requests,ber_id',
            ]);
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $authId = (int) Auth::id();

            DB::beginTransaction();

            $editRequest = BorrowerEditRequest::where('ber_id', $request->ber_id)
                ->lockForUpdate()
                ->first();

            if (!$editRequest) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Request not found', 422);
            }

            if ((int) $editRequest->maker_id !== $authId) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Only the maker can delete this request', 422);
            }

            $reviewed = !in_array($editRequest->r1_approval_status, [null, 'pending'], true)
                || !in_array($editRequest->r2_approval_status, [null, 'pending'], true);

            if ($reviewed) {
                DB::rollBack();
                return $this->helper_controller->send_response(
                    'failed',
                    'Cannot delete a request once R1 or R2 has reviewed it',
                    422
                );
            }

            $editRequest->row_status = 'D';
            $editRequest->updated_by = $authId;
            $editRequest->save();

            DB::commit();

            return $this->helper_controller->send_response('success', 'Request deleted successfully', 200);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // =====================================================================
    // 8. Row status change
    // =====================================================================
    public function statusChange(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'ber_id'     => 'required|integer|exists:borrower_edit_requests,ber_id',
                'row_status' => 'required|in:A,I',
            ]);
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();

            $editRequest = BorrowerEditRequest::where('ber_id', $request->ber_id)
                ->where('row_status', '!=', 'D')
                ->lockForUpdate()
                ->first();

            if (!$editRequest) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Request not found', 422);
            }

            $editRequest->row_status = $request->row_status;
            $editRequest->updated_by = Auth::id();
            $editRequest->save();

            DB::commit();

            return $this->helper_controller->send_response(
                'success',
                'Request status changed successfully',
                200,
                $this->row($editRequest->load(self::ROW_RELATIONS), (int) Auth::id())
            );
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // =====================================================================
    // 9. Change history / audit log
    // =====================================================================
    public function history(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page'         => 'nullable|integer|min:1',
                'limit'        => 'nullable|integer|min:1|max:100',
                'borrower_id'  => 'nullable|integer',
                'ber_id'       => 'nullable|integer',
                'section'      => 'nullable|string|max:60',
                'start_date'   => 'nullable|date',
                'end_date'     => 'nullable|date|after_or_equal:start_date',
                'applied_only' => 'nullable|boolean',
            ]);
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $page  = (int) $request->input('page', 1);
            $limit = (int) $request->input('limit', 20);

            $query = BorrowerEditRequestItem::query()
                ->join('borrower_edit_requests as ber', 'ber.ber_id', '=', 'borrower_edit_request_items.ber_id')
                ->leftJoin('loan_basic_details as lbd', 'lbd.id', '=', 'borrower_edit_request_items.borrower_id')
                ->leftJoin('admin_users as maker', 'maker.id', '=', 'ber.maker_id')
                ->leftJoin('admin_users as applier', 'applier.id', '=', 'borrower_edit_request_items.applied_by')
                ->select([
                    'borrower_edit_request_items.beri_id',
                    'borrower_edit_request_items.ber_id',
                    'borrower_edit_request_items.borrower_id',
                    'lbd.borrower_id as borrower_unq',
                    'borrower_edit_request_items.section',
                    'borrower_edit_request_items.field_key',
                    'borrower_edit_request_items.column_name',
                    'borrower_edit_request_items.old_value',
                    'borrower_edit_request_items.applied_old_value',
                    'borrower_edit_request_items.new_value',
                    'borrower_edit_request_items.apply_status',
                    'borrower_edit_request_items.applied_at',
                    'ber.reason',
                    'ber.requested_at',
                    'ber.maker_ip',
                    'ber.completion_status',
                    'maker.name as maker_name',
                    'applier.name as applied_by_name',
                ]);

            if ($request->filled('borrower_id')) {
                $query->where('borrower_edit_request_items.borrower_id', $request->borrower_id);
            }
            if ($request->filled('ber_id')) {
                $query->where('borrower_edit_request_items.ber_id', $request->ber_id);
            }
            if ($request->filled('section')) {
                $query->where('borrower_edit_request_items.section', $request->section);
            }
            if ($request->boolean('applied_only')) {
                $query->where('borrower_edit_request_items.apply_status', 'applied');
            }
            if ($request->filled('start_date')) {
                $query->where('ber.requested_at', '>=', Carbon::parse($request->start_date, 'Asia/Kolkata')->startOfDay());
            }
            if ($request->filled('end_date')) {
                $query->where('ber.requested_at', '<=', Carbon::parse($request->end_date, 'Asia/Kolkata')->endOfDay());
            }

            $paginated = $query->orderByDesc('borrower_edit_request_items.beri_id')
                ->paginate($limit, ['*'], 'page', $page);

            // Labels + readable values resolved for the whole page at once.
            $described = $this->editService->flatChanges($paginated->getCollection());

            $paginated->getCollection()->transform(function ($row) use ($described) {
                $d = $described[$row->beri_id] ?? [];

                return [
                    'beri_id'       => $row->beri_id,
                    'ber_id'        => $row->ber_id,
                    'borrower_id'   => $row->borrower_unq ?? '',
                    'section_label' => $d['section_label'] ?? $row->section,
                    'label'         => $d['label']         ?? $row->column_name,
                    'old_value'     => $d['old_value']     ?? (string) $row->old_value,
                    'new_value'     => $d['new_value']     ?? (string) $row->new_value,
                    'reason'        => $row->reason ?? '',
                    'changed_by'    => $row->maker_name ?? '',
                    'applied_by'    => $row->applied_by_name ?? '',
                    'requested_at'  => $row->requested_at ? date('d-m-Y H:i:s', strtotime($row->requested_at)) : '',
                    'applied_at'    => $row->applied_at ? date('d-m-Y H:i:s', strtotime($row->applied_at)) : '',
                    'ip_address'    => $row->maker_ip ?? '',
                    'status'        => $row->apply_status ?? '',
                ];
            });

            return $this->helper_controller->send_response('success', 'Edit history fetched successfully', 200, $paginated);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** Flat row used by list, edit, create and every review response. */
    private function row(BorrowerEditRequest $r, int $authId, array $names = []): array
    {
        return [
            'ber_id'           => $r->ber_id,
            'borrower_primary' => $r->borrower_id,
            'borrower_id'      => $r->borrower->borrower_id ?? '',
            'borrower_name'    => $names[$r->borrower_id] ?? $this->borrowerName($r->borrower_id),
            'reason'           => $r->reason ?? '',

            'requested_by'     => $r->maker->name ?? '',
            'requested_at'     => $this->ist($r->requested_at),

            'r1_name'          => $r->reviewer1->name ?? '',
            'r1_status'        => $r->r1_approval_status ?? '',
            'r1_remarks'       => $r->r1_reason ?? '',
            'r1_status_time'   => $this->ist($r->r1_approved_at),

            'r2_name'          => $r->reviewer2->name ?? '',
            'r2_status'        => $r->r2_approval_status ?? '',
            'r2_remarks'       => $r->r2_reason ?? '',
            'r2_status_time'   => $this->ist($r->r2_approved_at),

            'overall_status'   => $r->completion_status ?? '',
            'completed_at'     => $this->ist($r->completed_at),

            'enable_r1_buttons' => (int) $r->r1_id === $authId
                && $r->completion_status === 'pending'
                && in_array($r->r1_approval_status, ['pending', 'on hold'], true),

            'enable_r2_buttons' => (int) $r->r2_id === $authId
                && $r->completion_status === 'pending'
                && $r->r1_approval_status === 'approved'
                && in_array($r->r2_approval_status, ['pending', 'on hold'], true),
        ];
    }

    private function borrowerName($borrowerPrimaryId): string
    {
        if (!$borrowerPrimaryId) {
            return '';
        }

        return (string) (BorrowerKyc::where('borrower_id', $borrowerPrimaryId)
            ->orderByDesc('id')
            ->value('name') ?? '');
    }

    private function ist($value): string
    {
        return $value
            ? Carbon::parse($value)->setTimezone('Asia/Kolkata')->format('d-m-Y H:i:s')
            : '';
    }
}