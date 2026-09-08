<?php

namespace App\Http\Controllers;

use App\Models\VehicleTypes;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;

class VehicleTypesController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function vehicle_type_screener() {
        $records = VehicleTypes::select(['id', 'vehicle_type'])->where('is_deleted', false)->orderBy('vehicle_type', 'asc')->get();
        return $this->helper_controller->send_response('success', 'Vehicle type fetched successfully', 200, $records);
    }

    public function create(Request $request)
    {
        try {
            // Validate the input
            $request->validate([
                'vehicle_type' => 'required|regex:/^[a-zA-Z0-9\s,\.()-]+$/|max:255',
            ]);
    
            // Convert the vehicle_type input to lowercase for case-insensitive comparison
            $vehicleTypeInput = strtolower($request->vehicle_type);
    
            // Check if the vehicle type already exists (case-insensitive)
            $existingVehicleType = VehicleTypes::whereRaw('LOWER(vehicle_type) = ?', [$vehicleTypeInput])->first();
    
            if ($existingVehicleType) {
                return $this->helper_controller->send_response('failed', 'Vehicle Type already exists', 400);
            }
    
            // Create a new vehicle type record
            $vehicleType = new VehicleTypes();
            $vehicleType->vehicle_type = $request->vehicle_type;
            $vehicleType->save();
    
            return $this->helper_controller->send_response('success', 'Vehicle Type created successfully', 200, $vehicleType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function read($id)
    {
        try {
            $vehicleType = VehicleTypes::find($id);
            if(!$vehicleType) {
                return $this->helper_controller->send_response('failed', 'Vehicle type not found', 400);
            }
            return $this->helper_controller->send_response('success', 'Vehicle Type details fetched', 200, $vehicleType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            // Validate the input
            $request->validate([
                'vehicle_type' => 'required|regex:/^[a-zA-Z0-9\s,\.()-]+$/|max:255',
            ]);
    
            // Convert the vehicle_type input to lowercase for case-insensitive comparison
            $vehicleTypeInput = strtolower($request->vehicle_type);
    
            // Check if the vehicle type already exists (case-insensitive), excluding the current record
            $existingVehicleType = VehicleTypes::whereRaw('LOWER(vehicle_type) = ?', [$vehicleTypeInput])
                ->where('id', '!=', $id)  // Exclude the current record from the check
                ->first();
    
            if ($existingVehicleType) {
                return $this->helper_controller->send_response('failed', 'Vehicle Type already exists', 400);
            }
    
            // Find and update the existing vehicle type record
            $vehicleType = VehicleTypes::find($id);
            if(!$vehicleType) {
                return $this->helper_controller->send_response('failed', 'Vehicle type not found', 400);
            }
            $vehicleType->vehicle_type = $request->vehicle_type;
            $vehicleType->save();
    
            return $this->helper_controller->send_response('success', 'Vehicle Type updated successfully', 200, $vehicleType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function delete($id)
    {
        try {
            $vehicleType = VehicleTypes::find($id);
            if(!$vehicleType) {
                return $this->helper_controller->send_response('failed', 'Vehicle type not found', 400);
            }
            $vehicleType->is_deleted = true;
            $vehicleType->deleted_at = Carbon::now()->format('Y-m-d H:i:s');
            $vehicleType->save();

            return $this->helper_controller->send_response('success', 'Vehicle Type deleted successfully', 200, $vehicleType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
}
