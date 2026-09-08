<?php

namespace App\Http\Controllers;

use App\Models\MaritalStatus;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;

class MaritalStatusesController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function create(Request $request)
    {
        try {
            $request->validate([
                'status' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            ]);
    
            // Convert the status input to lowercase for case-insensitive comparison
            $statusInput = strtolower($request->status);
    
            // Check if the marital status already exists (case-insensitive)
            $existingStatus = MaritalStatus::whereRaw('LOWER(status) = ?', [$statusInput])->first();
    
            if ($existingStatus) {
                return $this->helper_controller->send_response('failed', 'Marital Status already exists', 400);
            }
    
            // Create a new marital status record
            $maritalStatus = new MaritalStatus();
            $maritalStatus->status = $request->status;
            $maritalStatus->save();
    
            return $this->helper_controller->send_response('success', 'Marital Status created successfully', 200, $maritalStatus);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }

    public function read($id)
    {
        try {
            $maritalStatus = MaritalStatus::find($id);
            if(!$maritalStatus) {
                return $this->helper_controller->send_response('failed', 'Marital status not found', 400);
            }
            return $this->helper_controller->send_response('success', 'Marital Status details fetched', 200, $maritalStatus);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            ]);
    
            // Convert the status input to lowercase for case-insensitive comparison
            $statusInput = strtolower($request->status);
    
            // Check if the marital status already exists (case-insensitive), excluding the current record
            $existingStatus = MaritalStatus::whereRaw('LOWER(status) = ?', [$statusInput])
                                           ->where('id', '!=', $id)
                                           ->first();
    
            if ($existingStatus) {
                return $this->helper_controller->send_response('failed', 'Marital Status already exists', 400);
            }
    
            // Find the marital status record and update it
            $maritalStatus = MaritalStatus::find($id);
            if(!$maritalStatus) {
                return $this->helper_controller->send_response('failed', 'Marital status not found', 400);
            }
            $maritalStatus->status = $request->status;
            $maritalStatus->save();
    
            return $this->helper_controller->send_response('success', 'Marital Status updated successfully', 200, $maritalStatus);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function delete($id)
    {
        try {
            $maritalStatus = MaritalStatus::find($id);
            if(!$maritalStatus) {
                return $this->helper_controller->send_response('failed', 'Marital status not found', 400);
            }
            $maritalStatus->is_deleted = true;
            $maritalStatus->deleted_at = Carbon::now()->format('Y-m-d H:i:s');
            $maritalStatus->save();

            return $this->helper_controller->send_response('success', 'Marital Status deleted successfully', 200, $maritalStatus);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
}
