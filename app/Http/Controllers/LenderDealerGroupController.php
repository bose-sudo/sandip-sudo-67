<?php

namespace App\Http\Controllers;

use App\Models\LenderDealerGroup;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LenderDealerGroupController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lender'        => 'nullable|regex:/^[a-zA-Z0-9\-]+$/',
            'dealer'        => 'nullable|regex:/^[a-zA-Z0-9\-]+$/',
            'start_date'    => 'nullable|date|before_or_equal:today',
            'end_date'      => 'nullable|date|before_or_equal:today',
            'status'        => 'nullable|in:active,inactive,deleted',
            'number_of_records'      => 'nullable|integer|min:1|max:100', // Pagination limit
            'page'          => 'nullable|integer|min:1', // Page number
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $query = LenderDealerGroup::with(['lender', 'dealer', 'modifiedBy']);

        // Filters
        if ($request->filled('lender')) {
            $query->whereHas('lender', function ($q) use ($request) {
                $q->whereRaw('LOWER(lender_unq) = ?', [strtolower($request->lender)]);
            });
        }

        if ($request->filled('dealer')) {
            $query->whereHas('dealer', function ($q) use ($request) {
                $q->whereRaw('LOWER(dealer_unq) = ?', [strtolower($request->dealer)]);
            });
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Pagination
        $perPage = $request->get('number_of_records', 10); // Default 10 records per page
        $page = $request->get('page', 1); // Default to first page

        // Apply pagination and fetch results
        $beneficiaries = $query->orderBy('id', 'desc')->paginate($perPage, ['*'], 'page', $page);

        $response_data = $beneficiaries->map(function ($item) {
            $dealer = $item->dealer;
            return [
                "id"            => $item->id,
                "lender_name"   => $item->lender ? $item->lender->name : '',
                "lender_unq"    => $item->lender ? $item->lender->lender_unq : '',
                "dealer_name"   => $dealer ? $dealer->name : '',
                "dealer_unq"    => $dealer ? $dealer->dealer_unq : '',
                "status"        => $item->status,
                "remarks"       => $item->remarks,
                "modified_by"   => $item->modifiedBy ? $item->modifiedBy->name : null,
                "ip"            => $item->ip,
                "created_at"    => date('d-m-Y H:i:s', strtotime($item->created_at)),
                "updated_at"    => date('d-m-Y H:i:s', strtotime($item->updated_at)),
            ];
        });

        $data = [
            'data'          => $response_data,
            'total'         => $beneficiaries->total(),
            'from'          => $beneficiaries->firstItem(),
            'to'            => $beneficiaries->lastItem(),
            'current_page'  => $beneficiaries->currentPage(),
            'last_page'     => $beneficiaries->lastPage(),
            'number_of_records'      => $beneficiaries->perPage(),
        ];

        return $this->helper_controller->send_response('success', "Beneficiary list fetched", 200, $data);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lender_id'     => 'required|exists:lenders,id',
            'dealer_id'     => 'required|array',
            'dealer_id.*'   => 'required|exists:dealers,id',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();
        try {
            foreach ($request->dealer_id as $dealerId) {
                $exists = LenderDealerGroup::where('lender_id', $request->lender_id)
                    ->where('dealer_id', $dealerId)
                    ->exists();

                if (!$exists) {
                    $group              = new LenderDealerGroup();
                    $group->lender_id   = $request->lender_id;
                    $group->dealer_id   = $dealerId;
                    $group->status      = 'active';
                    $group->modified_by = auth()->id();
                    $group->ip          = $this->helper_controller->get_user_ip();
                    $group->save();
                }
            }

            DB::commit();
            return $this->helper_controller->send_response('success', 'Group created successfully', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function edit($id)
    {
        $group = LenderDealerGroup::find($id);

        if (!$group) {
            return $this->helper_controller->send_response('failed', 'Group not found', 422);
        }

        return $this->helper_controller->send_response('success', 'Group fetched successfully', 200, $group);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'lender_id' => 'required|exists:lenders,id',
            'dealer_id' => 'required|exists:dealers,id',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();
        try {
            $group = LenderDealerGroup::find($id);

            if (!$group) {
                return $this->helper_controller->send_response('failed', 'Group not found', 422);
            }

            $group->lender_id   = $request->lender_id;
            $group->dealer_id   = $request->dealer_id;
            $group->modified_by = auth()->id();
            $group->ip          = $this->helper_controller->get_user_ip();
            $group->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Group updated successfully', 200, $group);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $group = LenderDealerGroup::find($id);

            if (!$group) {
                return $this->helper_controller->send_response('failed', 'Group not found', 422);
            }

            $group->status      = 'deleted';
            $group->modified_by = auth()->id();
            $group->ip          = $this->helper_controller->get_user_ip();
            $group->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Group deleted successfully', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function changeStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'remarks' => 'required|regex:/^[a-zA-Z0-9,\\-\\(\\)\\s]+$/|max:255',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();
        try {
            $group = LenderDealerGroup::find($id);

            if (!$group) {
                return $this->helper_controller->send_response('failed', 'Group not found', 422);
            }

            $group->status      = $group->status === 'active' ? 'inactive' : 'active';
            $group->remarks     = $request->remarks;
            $group->modified_by = auth()->id();
            $group->ip          = $this->helper_controller->get_user_ip();
            $group->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Status updated successfully', 200, $group);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }
}
