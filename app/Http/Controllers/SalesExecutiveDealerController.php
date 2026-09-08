<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\SalesExecutiveDealerGrouping;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SalesExecutiveDealerController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    // 1. List executives with count of active dealers
    public function index(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'executive_id' => 'nullable|exists:admin_users,id',
                'number_of_records' => 'nullable|integer|min:1|max:100',
                'page'              => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $perPage = $request->number_of_records ?? 10;

            $query = DB::table('sales_executive_dealer_groupings as seg')
                ->join('admin_users as au', 'seg.executive_id', '=', 'au.id')
                ->select('seg.executive_id', 'au.name as executive_name', DB::raw('COUNT(*) as total_active_dealers'), 'au.email', 'au.mobile')
                ->where('seg.status', 'active')
                ->when($request->executive_id, function ($q) use ($request) {
                    $q->where('seg.executive_id', $request->executive_id);
                })
                ->orderBy('seg.executive_id', 'asc')
                ->groupBy('seg.executive_id', 'au.name', 'au.email', 'au.mobile');

            $result = $query->paginate($perPage);

            return $this->helper_controller->send_response('success', "Data fetched successfully", 200, $result);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred " . $e->getMessage(), 422);
        }
    }

    // 2. Get dealers by executive_id
    public function getDealersByExecutive($executive_id)
    {
        try {
            $dealers = SalesExecutiveDealerGrouping::where('executive_id', $executive_id)
                ->where('status', 'active')
                ->with([
                    'dealer:id,name,dealer_unq',
                    'modified_by_user:id,name' // optional: if you want modifier name
                ])
                ->orderBy('id', 'asc')
                ->get()
                ->map(function ($group) {
                    return [
                        'id'          => $group->id ?? null,
                        'executive_id' => $group->executive_id ?? null,
                        'dealer_id'   => $group->dealer_id ?? null,
                        'name'        => $group->dealer->name ?? null,
                        'status'      => $group->status ?? null,
                        'dealer_unq'  => $group->dealer->dealer_unq ?? null,
                        'created_at'  => date('d-m-Y H:i:s', strtotime($group->created_at)),
                        'updated_at'  => date('d-m-Y H:i:s', strtotime($group->updated_at)),
                        'modified_by' => $group->modified_by,
                        'modified_by_name' => $group->modified_by_user->name ?? null, // optional
                    ];
                });

            return $this->helper_controller->send_response('success', "Dealer data fetched successfully", 200, $dealers);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred " . $e->getMessage(), 422);
        }
    }

    // 3. Create a grouping
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'executive_id' => 'required|exists:admin_users,id',
            'dealer_ids'   => 'required|array|min:1',
            'dealer_ids.*' => 'required|exists:dealers,id',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();
        try {
            $executiveId = $request->executive_id;
            $dealerIds = $request->dealer_ids;
            $userId = auth()->id();
            $ip = $this->helper_controller->get_user_ip();

            // Check for existing active mappings to prevent duplicates
            // $existing = SalesExecutiveDealerGrouping::where('executive_id', $executiveId)
            //     ->whereIn('dealer_id', $dealerIds)
            //     ->where('status', 'active')
            //     ->pluck('dealer_id')
            //     ->toArray();
            $existing = SalesExecutiveDealerGrouping::whereIn('dealer_id', $dealerIds)
                ->where('status', 'active')
                ->pluck('dealer_id')
                ->toArray();

            $newMappings = [];
            foreach ($dealerIds as $dealerId) {
                if (!in_array($dealerId, $existing)) {
                    $newMappings[] = [
                        'executive_id' => $executiveId,
                        'dealer_id'    => $dealerId,
                        'status'       => 'active',
                        'modified_by'  => $userId,
                        'ip'           => $ip,
                        'created_at'   => date('Y-m-d H:i:s'),
                        'updated_at'   => date('Y-m-d H:i:s'),
                    ];
                }
            }

            if (!empty($newMappings)) {
                SalesExecutiveDealerGrouping::insert($newMappings);
            }

            DB::commit();

            return $this->helper_controller->send_response('success', 'Dealers grouped successfully', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred " . $e->getMessage(), 422);
        }
    }


    // 4. Update a grouping
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'executive_id' => 'required|exists:admin_users,id',
            'dealer_id'    => 'required|exists:dealers,id',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();
        try {
            $record = SalesExecutiveDealerGrouping::find($id);
            if (!$record) {
                return $this->helper_controller->send_response('failed', "Group not found", 422);
            }

            // Prevent assigning dealer to multiple active executives
            $alreadyAssigned = SalesExecutiveDealerGrouping::where('dealer_id', $request->dealer_id)
                ->where('status', 'active')
                ->where('id', '!=', $id) // exclude current record
                ->exists();

            if ($alreadyAssigned) {
                return $this->helper_controller->send_response('failed', "This dealer is already actively assigned to another executive", 422);
            }

            $record->executive_id = $request->executive_id;
            $record->dealer_id    = $request->dealer_id;
            $record->modified_by  = auth()->id();
            $record->ip           = $this->helper_controller->get_user_ip();
            $record->save();

            DB::commit();
            return $this->helper_controller->send_response('success', "Group Updated successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred " . $e->getMessage(), 422);
        }
    }

    // 5. Delete (soft: update status)
    public function destroy($id)
    {
        try {
            $record = SalesExecutiveDealerGrouping::find($id);
            if (!$record) {
                return $this->helper_controller->send_response('failed', "Group not found", 422);
            }

            $record->status = 'deleted';
            $record->modified_by = auth()->id();
            $record->save();

            return $this->helper_controller->send_response('success', "Group deleted successfully", 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred " . $e->getMessage(), 422);
        }
    }

    public function salesExecutiveScreener()
    {
        try {
            $executives = AdminUser::select('admin_users.id', 'admin_users.name')
                ->where('admin_users.is_active', true)
                ->leftJoin('user_types', 'admin_users.user_type', '=', 'user_types.id')
                ->whereRaw("LOWER(user_types.user_type) LIKE ?", ['%sales%'])
                ->distinct()
                ->get();

            return $this->helper_controller->send_response('success', "Sales executives fetched successfully", 200, $executives);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred " . $e->getMessage(), 422);
        }
    }
}
