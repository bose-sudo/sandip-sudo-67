<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\MakerCheckerAuthorization;
use App\Models\ModuleFunctionality;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Throwable;

class MakerCheckerAuthorizationController extends Controller
{
    protected $helper_controller;

    public function __construct()
    {
        $this->helper_controller = new HelperController();
    }

    public function get_admin_users()
    {
        try {
            $admin_users = AdminUser::join('user_types', 'admin_users.user_type', '=', 'user_types.id')
            ->where('admin_users.is_active', true)
            ->select([
                'admin_users.id',
                // 'admin_users.user_type',
                DB::raw("CONCAT(admin_users.name, ' (', user_types.user_type, ')') as name")
            ])
            ->orderBy('admin_users.name', 'asc')
            ->get();
            return $this->helper_controller->send_response('success', "Admin users list fetched", 200, $admin_users);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function get_module_functionality_list() {
        try {
            $functionalities = ModuleFunctionality::select([
                'mfunc_id', 'name'
            ])->where('row_status', '!=', 'D')->get();

            return $this->helper_controller->send_response('success', "Functionality list fetched", 200, $functionalities);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function get_authorizations() {
        try {
            $authId = Auth::id();
            $routes = MakerCheckerAuthorization::join(
                    'module_functionalities',
                    'maker_checker_authorizations.mfunc_id',
                    '=',
                    'module_functionalities.mfunc_id'
                )
                ->where('maker_checker_authorizations.maker_id', $authId)
                ->where('maker_checker_authorizations.row_status', 'A')
                ->pluck('module_functionalities.route_used');


            return $this->helper_controller->send_response('success', "Routes fetched successfully", 200, $routes);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function create(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'mfunc_id' => 'required|integer|exists:module_functionalities,mfunc_id',
                'maker_id' => 'required|integer|exists:admin_users,id',
                'r1_id' => 'required|integer|exists:admin_users,id',
                'r2_id' => 'nullable|integer|exists:admin_users,id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            // Check if already exists for this maker
            $alreadyExists = MakerCheckerAuthorization::where('mfunc_id', $request->mfunc_id)
                ->where('maker_id', $request->maker_id)
                ->where('row_status', '!=', 'D')
                ->exists();

            if ($alreadyExists) {
                return $this->helper_controller
                ->send_response(
                    'failed',
                    'Authorization already exists for this maker and module functionality',
                    409
                );
            }

            DB::beginTransaction();
            $authId = Auth::id();
            $authorization = MakerCheckerAuthorization::create([
                'mfunc_id'      => $request->mfunc_id,
                'maker_id'      => $request->maker_id,
                'r1_id'         => $request->r1_id,
                'r2_id'         => $request->r2_id,
                'created_by'    => $authId,
                'row_status'    => 'A',
            ]);

            DB::commit();

            return $this->helper_controller->send_response('success', 'Maker checker authorization created successfully', 200, $authorization);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function edit(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'mca_id' => 'required|integer|exists:maker_checker_authorizations,mca_id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $authorization = MakerCheckerAuthorization::with([
                'moduleFunctionality:mfunc_id,name',
                'maker:id,name',
                'reviewer1:id,name',
                'reviewer2:id,name'
            ])
            ->where('mca_id', $request->mca_id)
            ->where('row_status', '!=', 'D')
            ->first();

            if (!$authorization) {
                return $this->helper_controller->send_response('failed', 'Authorization not found', 422);
            }

            return $this->helper_controller->send_response('success', 'Authorization retrieved successfully', 200, $authorization);
        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function update(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'mca_id'    => 'required|integer|exists:maker_checker_authorizations,mca_id',
                'mfunc_id'  => 'required|integer|exists:module_functionalities,mfunc_id',
                'maker_id'  => 'required|integer|exists:admin_users,id',
                'r1_id'     => 'required|integer|exists:admin_users,id',
                'r2_id'     => 'nullable|integer|exists:admin_users,id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();

            $authorization = MakerCheckerAuthorization::where('mca_id', $request->mca_id)
                ->where('row_status', '!=', 'D')
                ->first();

            if (!$authorization) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Authorization not found', 422);
            }

            // Duplicate check (ignore current record)
            $duplicateExists = MakerCheckerAuthorization::where('mfunc_id', $request->mfunc_id)
                ->where('maker_id', $request->maker_id)
                ->where('row_status', '!=', 'D')
                ->where('mca_id', '!=', $request->mca_id)
                ->exists();

            if ($duplicateExists) {
                DB::rollBack();
                return $this->helper_controller
                    ->send_response(
                        'failed',
                        'Authorization already exists for this maker and module functionality',
                        409
                    );
            }

            $authId = Auth::id();
            $authorization->update([
                'mfunc_id'  => $request->mfunc_id,
                'maker_id'  => $request->maker_id,
                'r1_id'     => $request->r1_id,
                'r2_id'     => $request->r2_id,
                'updated_by' => $authId
            ]);

            DB::commit();

            return $this->helper_controller->send_response('success', 'Authorization updated successfully', 200, $authorization);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function delete(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'mca_id' => 'required|integer|exists:maker_checker_authorizations,mca_id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();

            $authorization = MakerCheckerAuthorization::where('mca_id', $request->mca_id)
                ->where('row_status', '!=', 'D')
                ->first();

            if (!$authorization) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Authorization not found', 422);
            }

            $authId = Auth::id();
            $authorization->update([
                'row_status' => 'D',
                'updated_by' => $authId
            ]);

            DB::commit();

            return $this->helper_controller->send_response('success', 'Authorization deleted successfully', 200);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function statusChange(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'mca_id' => 'required|integer|exists:maker_checker_authorizations,mca_id',
                'status' => 'required|in:A,I',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();

            $authorization = MakerCheckerAuthorization::where('mca_id', $request->mca_id)
                ->where('row_status', '!=', 'D')
                ->first();

            if (!$authorization) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Authorization not found', 422);
            }

            $authId = Auth::id();
            $authorization->update([
                'row_status' => $request->status,
                'updated_by' => $authId
            ]);

            DB::commit();

            return $this->helper_controller->send_response('success', 'Authorization status changed successfully', 200, $authorization);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function list(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:100',
                'row_status' => 'nullable|in:A,I',
                'mfunc_id' => 'nullable|integer|exists:module_functionalities,mfunc_id',
                'maker_id' => 'nullable|integer|exists:admin_users,id',
                'r1_id' => 'nullable|integer|exists:admin_users,id',
                'r2_id' => 'nullable|integer|exists:admin_users,id',
                'search' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $page = $request->input('page', 1);
            $limit = $request->input('limit', 10);

            $query = MakerCheckerAuthorization::with([
                'moduleFunctionality:mfunc_id,name',
                'maker:id,name',
                'reviewer1:id,name',
                'reviewer2:id,name'
            ]);

            // Filter by row_status
            if ($request->filled('row_status')) {
                $query->where('row_status', $request->row_status);
            } else {
                $query->where('row_status', '!=', 'D');
            }

            // Filter by mfunc_id
            if ($request->filled('mfunc_id')) {
                $query->where('mfunc_id', $request->mfunc_id);
            }

            // Filter by maker_id
            if ($request->filled('maker_id')) {
                $query->where('maker_id', $request->maker_id);
            }

            // Filter by r1_id
            if ($request->filled('r1_id')) {
                $query->where('r1_id', $request->r1_id);
            }

            // Filter by r2_id
            if ($request->filled('r2_id')) {
                $query->where('r2_id', $request->r2_id);
            }

            // Search filter
            if ($request->filled('search')) {
                $search = strtolower($request->search);
                $query->where(function($q) use ($search) {
                    $q->whereHas('moduleFunctionality', function($subQ) use ($search) {
                        $subQ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(route_used) LIKE ?', ["%{$search}%"]);
                    });
                });
            }

            $totalRecords = $query->count();
            $authorizations = $query->orderBy('created_at', 'desc')
                ->skip(($page - 1) * $limit)
                ->take($limit)
                ->get()
                ->map(function ($item) {
                    return array_merge(
                        $item->toArray(),
                        [
                            'created_at' => $item->created_at
                                ? $item->created_at->setTimezone('Asia/Kolkata')->format('d-m-Y H:i:s')
                                : null,

                            'updated_at' => $item->updated_at
                                ? $item->updated_at->setTimezone('Asia/Kolkata')->format('d-m-Y H:i:s')
                                : null,
                        ]
                    );
                });

            $response = [
                'data' => $authorizations,
                'pagination' => [
                    'total' => $totalRecords,
                    'per_page' => $limit,
                    'current_page' => $page,
                    'last_page' => ceil($totalRecords / $limit),
                    'from' => (($page - 1) * $limit) + 1,
                    'to' => min($page * $limit, $totalRecords),
                ]
            ];

            return $this->helper_controller->send_response('success', 'All maker checker authorizations retrieved successfully', 200, $response);

        } catch (Throwable $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }
}
