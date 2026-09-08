<?php

namespace App\Http\Controllers;

use App\Models\BatteryTypes;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;

class BatteryTypesController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function battery_type_screener() {
        $records = BatteryTypes::select(['id', 'battery_type'])->where('is_deleted', false)->orderBy('battery_type', 'asc')->get();
        return $this->helper_controller->send_response('success', 'Battery type fetched successfully', 200, $records);
    }

    public function create(Request $request)
    {
        try {
            // Validate the input
            $request->validate([
                'battery_type' => 'required|regex:/^[a-zA-Z0-9\s,\.]+$/|max:255',
            ]);
    
            // Convert the battery_type input to lowercase for case-insensitive comparison
            $batteryTypeInput = strtolower($request->battery_type);
    
            // Check if the battery type already exists (case-insensitive)
            $existingBatteryType = BatteryTypes::whereRaw('LOWER(battery_type) = ?', [$batteryTypeInput])->first();
    
            if ($existingBatteryType) {
                return $this->helper_controller->send_response('failed', 'Battery Type already exists', 400);
            }
    
            // Create a new battery type record
            $batteryType = new BatteryTypes();
            $batteryType->battery_type = $request->battery_type;
            $batteryType->save();
    
            return $this->helper_controller->send_response('success', 'Battery Type created successfully', 200, $batteryType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function read($id)
    {
        try {
            $batteryType = BatteryTypes::find($id);
            if(!$batteryType) {
                return $this->helper_controller->send_response('failed', 'Battery Type not found', 400);
            }
            return $this->helper_controller->send_response('success', 'Battery Type details fetched', 200, $batteryType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            // Validate the input
            $request->validate([
                'battery_type' => 'required|regex:/^[a-zA-Z0-9\s,\.]+$/|max:255',
            ]);
    
            // Convert the battery_type input to lowercase for case-insensitive comparison
            $batteryTypeInput = strtolower($request->battery_type);
    
            // Check if the battery type already exists (case-insensitive), excluding the current record
            $existingBatteryType = BatteryTypes::whereRaw('LOWER(battery_type) = ? AND id <> ?', [$batteryTypeInput, $id])->first();
    
            if ($existingBatteryType) {
                return $this->helper_controller->send_response('failed', 'Battery Type already exists', 400);
            }
            
            // Find and update the existing battery type record
            $batteryType = BatteryTypes::find($id);
            if(!$batteryType) {
                return $this->helper_controller->send_response('failed', 'Battery Type not found', 400);
            }
            $batteryType->battery_type = $request->battery_type;
            $batteryType->save();
    
            return $this->helper_controller->send_response('success', 'Battery Type updated successfully', 200, $batteryType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function delete($id)
    {
        try {
            $batteryType = BatteryTypes::find($id);
            if(!$batteryType) {
                return $this->helper_controller->send_response('failed', 'Battery Type not found', 400);
            }
            $batteryType->is_deleted = true;
            $batteryType->deleted_at = Carbon::now()->format('Y-m-d H:i:s');
            $batteryType->save();

            return $this->helper_controller->send_response('success', 'Battery Type deleted successfully', 200, $batteryType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
}
