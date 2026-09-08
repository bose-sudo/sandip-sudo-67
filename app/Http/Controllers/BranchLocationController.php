<?php

namespace App\Http\Controllers;

use App\Models\BranchLocation;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BranchLocationController extends Controller
{
    protected $helper_controller; // assuming you have a helper controller for responses

    public function __construct()
    {
        $this->helper_controller = new HelperController(); // assuming it's globally accessible
    }

    public function get_branch_location_screener(Request $request) {
        $branch_locations = BranchLocation::where('is_deleted', false)->orderBy('location_name', 'asc')->get();

        return $this->helper_controller->send_response('success', 'Branch locations retrieved successfully', 200, $branch_locations);
    }


    // Index (List Locations)
    public function index(Request $request)
    {
        try {
            $query = BranchLocation::where('is_deleted', false);
            
            if ($request->filled('location_name')) {
                $query->whereRaw('LOWER(location_name) LIKE ?', ['%' . strtolower($request->location_name) . '%']);
            }
            
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 10);
            
            $locations = $query->paginate($limit, ['*'], 'page', $page);

            return $this->helper_controller->send_response('success', 'Branch locations retrieved successfully', 200, $locations);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', "An unknown error occurred", 422);
        }
    }

    // Show (View Single Location)
    public function show($id)
    {
        try {
            $location = BranchLocation::find($id);
            
            if (!$location) {
                return $this->helper_controller->send_response('error', 'Location not found', 422);
            }

            return $this->helper_controller->send_response('success', 'Branch location retrieved successfully', 200, $location);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', "An unknown error occurred", 422);
        }
    }

    // Store (Create Location)
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'location_name' => 'required|regex:/^[a-zA-Z\s]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('error', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            $location = BranchLocation::create([
                'location_name' => $request->location_name,
                'updated_by' => auth()->id() ?? null,
            ]);

            DB::commit();
            return $this->helper_controller->send_response('success', 'Branch location created successfully', 200, $location);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('error', "An unknown error occurred", 422);
        }
    }

    // Update (Edit Location)
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'location_name' => 'required|regex:/^[a-zA-Z\s]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('error', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            $location = BranchLocation::find($id);
            
            if (!$location) {
                return $this->helper_controller->send_response('error', 'Location not found', 422);
            }

            $location->update([
                'location_name' => $request->location_name,
                'updated_by' => auth()->id() ?? null, 
            ]);

            DB::commit();
            return $this->helper_controller->send_response('success', 'Branch location updated successfully', 200, $location);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('error', "An unknown error occurred", 422);
        }
    }

    // Delete (Soft Delete Location)
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $location = BranchLocation::find($id);
            
            if (!$location) {
                return $this->helper_controller->send_response('error', 'Location not found', 422);
            }

            $location->update([
                'is_deleted' => true,
                'deleted_at' => now(),
                'is_active' => false,
                'updated_by' => auth()->id() ?? null,
            ]);

            DB::commit();
            return $this->helper_controller->send_response('success', 'Branch location deleted successfully', 200, $location);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('error', "An unknown error occurred", 422);
        }
    }

    // Change Status (Activate/Deactivate)
    public function changeStatus(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $location = BranchLocation::find($id);
            
            if (!$location) {
                return $this->helper_controller->send_response('error', 'Location not found', 422);
            }

            // Toggle the 'is_active' status (if true, set to false; if false, set to true)
            $newStatus = !$location->is_active;

            $location->update([
                'is_active' => $newStatus,
                'updated_by' => auth()->id() ?? null,
            ]);

            DB::commit();
            return $this->helper_controller->send_response('success', 'Branch location status updated successfully', 200, $location);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('error', 'An unknown error occurred', 422);
        }
    }
}
