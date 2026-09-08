<?php

namespace App\Http\Controllers;

use App\Models\ResidentTypes;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;

class ResidentTypesController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function create(Request $request)
    {
        try {
            // Validate the input
            $request->validate([
                'resident_type' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            ]);
    
            // Convert the resident_type input to lowercase for case-insensitive comparison
            $residentTypeInput = strtolower($request->resident_type);
    
            // Check if the resident type already exists (case-insensitive)
            $existingResidentType = ResidentTypes::whereRaw('LOWER(resident_type) = ?', [$residentTypeInput])->first();
    
            if ($existingResidentType) {
                return $this->helper_controller->send_response('failed', 'Resident Type already exists', 400);
            }
    
            // Create a new resident type record
            $residentType = new ResidentTypes();
            $residentType->resident_type = $request->resident_type;
            $residentType->save();
    
            return $this->helper_controller->send_response('success', 'Resident Type created successfully', 200, $residentType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function read($id)
    {
        try {
            $residentType = ResidentTypes::find($id);
            if(!$residentType) {
                return $this->helper_controller->send_response('failed', 'Resident type not found', 400);
            }
            return $this->helper_controller->send_response('success', 'Resident Type details fetched', 200, $residentType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            // Validate the input
            $request->validate([
                'resident_type' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            ]);
    
            // Convert the resident_type input to lowercase for case-insensitive comparison
            $residentTypeInput = strtolower($request->resident_type);
    
            // Check if the resident type already exists (case-insensitive), excluding the current record
            $existingResidentType = ResidentTypes::whereRaw('LOWER(resident_type) = ?', [$residentTypeInput])
                ->where('id', '!=', $id)  // Exclude the current record from the check
                ->first();
    
            if ($existingResidentType) {
                return $this->helper_controller->send_response('failed', 'Resident Type already exists', 400);
            }
    
            // Find and update the existing resident type record
            $residentType = ResidentTypes::find($id);
            if(!$residentType) {
                return $this->helper_controller->send_response('failed', 'Resident type not found', 400);
            }
            $residentType->resident_type = $request->resident_type;
            $residentType->save();
    
            return $this->helper_controller->send_response('success', 'Resident Type updated successfully', 200, $residentType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function delete($id)
    {
        try {
            $residentType = ResidentTypes::find($id);
            if(!$residentType) {
                return $this->helper_controller->send_response('failed', 'Resident type not found', 400);
            }
            $residentType->is_deleted = true;
            $residentType->deleted_at = Carbon::now()->format('Y-m-d H:i:s');
            $residentType->save();

            return $this->helper_controller->send_response('success', 'Resident Type deleted successfully', 200, $residentType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
}
