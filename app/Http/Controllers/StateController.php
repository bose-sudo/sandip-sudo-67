<?php

namespace App\Http\Controllers;

use App\Models\StateMaster;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StateController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    /**
     * Display a listing of the states.
     */
    public function state_screener()
    {
        $states = StateMaster::select(['id', 'state'])->where('status', '<>', 'deleted')->orderBy('state', 'asc')->get();
        return $this->helper_controller->send_response('success', 'States fetched successfully.', 200, $states);
    }

    /**
     * Display a listing of the states with filters.
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'state' => 'nullable|string|max:255|regex:/^[a-zA-Z\s]+$/',
            'page' => 'nullable|integer|min:1',
            'number_of_records' => 'nullable|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $query = StateMaster::where('status', '<>', 'deleted');

        if ($request->filled('state')) {
            $query->whereRaw('LOWER(state) LIKE ?', ['%' . strtolower($request->state) . '%']);
        }

        $query->orderBy('state', 'asc');

        
        $numberOfRecords = $request->get('number_of_records', 10);
            $page = $request->get('page', 1);

        $states = $query->paginate($numberOfRecords, ['*'], 'page', $page);

        return $this->helper_controller->send_response('success', 'States fetched successfully.', 200, $states);
    }

    /**
     * Store a newly created state.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'state' => 'required|string|max:255|regex:/^[a-zA-Z\s]+$/|unique:state_masters,state',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $state = new StateMaster();
        $state->state = $request->state;
        $state->user_id = auth()->id();
        $state->ip = $this->helper_controller->get_user_ip();
        $state->save();

        return $this->helper_controller->send_response('success', 'State created successfully.', 201, $state);
    }

    /**
     * Show a specific state.
     */
    public function show($id)
    {
        $state = StateMaster::find($id);
        if (!$state) {
            return $this->helper_controller->send_response('failed', 'State not found.', 422);
        }
        return $this->helper_controller->send_response('success', 'State fetched successfully.', 200, $state);
    }

    /**
     * Update an existing state.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'state' => 'required|string|max:255|regex:/^[a-zA-Z\s]+$/|unique:state_masters,state,' . $id,
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $state = StateMaster::find($id);
        if (!$state) {
            return $this->helper_controller->send_response('failed', 'State not found.', 422);
        }

        $state->state = $request->state;
        $state->user_id = auth()->id();
        $state->ip = $this->helper_controller->get_user_ip();
        $state->save();

        return $this->helper_controller->send_response('success', 'State updated successfully.', 200, $state);
    }

    /**
     * Remove a state.
     */
    public function destroy($id)
    {
        $state = StateMaster::find($id);
        if (!$state) {
            return $this->helper_controller->send_response('failed', 'State not found.', 422);
        }
        $state->status = 'deleted';
        $state->user_id = auth()->id();
        $state->ip = $this->helper_controller->get_user_ip();
        $state->save();
        return $this->helper_controller->send_response('success', 'State deleted successfully.', 200);
    }

    /**
     * Change the status of a state.
     */
    public function changeStatus($id)
    {
        $state = StateMaster::find($id);
        if (!$state) {
            return $this->helper_controller->send_response('failed', 'State not found.', 422);
        }

        if ($state->status === 'active') {
            $state->status = 'inactive';
        } elseif ($state->status === 'inactive') {
            $state->status = 'active';
        }

        $state->user_id = auth()->id();
        $state->ip = $this->helper_controller->get_user_ip();
        $state->save();

        return $this->helper_controller->send_response('success', 'State status updated successfully.', 200, $state);
    }

    /**
     * Seed all Indian states.
     */
    public function seedStates()
    {
        $states = [
            'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana',
            'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya',
            'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
            'Uttar Pradesh', 'Uttarakhand', 'West Bengal'
        ];

        DB::beginTransaction();
        try {
            foreach ($states as $state) {
                StateMaster::updateOrCreate(
                    ['state' => $state],
                    ['status' => 'active', 'user_id' => auth()->id() ?? null, 'ip' => $this->helper_controller->get_user_ip()]
                );
            }
            DB::commit();
            return $this->helper_controller->send_response('success', 'All states added successfully.', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Error seeding states: ' . $e->getMessage(), 422);
        }
    }
}
