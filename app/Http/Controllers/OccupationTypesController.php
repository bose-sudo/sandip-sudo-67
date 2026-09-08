<?php

namespace App\Http\Controllers;

use App\Models\OccupationTypes;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;

class OccupationTypesController extends Controller
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
                'occupation_type' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            ]);
    
            // Convert the occupation_type input to lowercase for case-insensitive comparison
            $occupationTypeInput = strtolower($request->occupation_type);
    
            // Check if the occupation type already exists (case-insensitive)
            $existingOccupationType = OccupationTypes::whereRaw('LOWER(occupation_type) = ?', [$occupationTypeInput])->first();
    
            if ($existingOccupationType) {
                return $this->helper_controller->send_response('failed', 'Occupation Type already exists', 400);
            }
    
            // Create a new occupation type record
            $occupationType = new OccupationTypes();
            $occupationType->occupation_type = $request->occupation_type;
            $occupationType->save();
    
            return $this->helper_controller->send_response('success', 'Occupation Type created successfully', 200, $occupationType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }

    public function read($id)
    {
        try {
            $occupationType = OccupationTypes::find($id);
            if(!$occupationType) {
                return $this->helper_controller->send_response('failed', 'Occupation type not found', 400);
            }
            return $this->helper_controller->send_response('success', 'Occupation Type details fetched', 200, $occupationType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'occupation_type' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            ]);
    
            // Convert the occupation_type input to lowercase for case-insensitive comparison
            $occupationTypeInput = strtolower($request->occupation_type);
    
            // Check if the occupation type already exists (case-insensitive), excluding the current record
            $existingOccupationType = OccupationTypes::whereRaw('LOWER(occupation_type) = ?', [$occupationTypeInput])
                                                     ->where('id', '!=', $id)
                                                     ->first();
    
            if ($existingOccupationType) {
                return $this->helper_controller->send_response('failed', 'Occupation Type already exists', 400);
            }
    
            // Find the occupation type record and update it
            $occupationType = OccupationTypes::find($id);
            if(!$occupationType) {
                return $this->helper_controller->send_response('failed', 'Occupation type not found', 400);
            }
            $occupationType->occupation_type = $request->occupation_type;
            $occupationType->save();
    
            return $this->helper_controller->send_response('success', 'Occupation Type updated successfully', 200, $occupationType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function delete($id)
    {
        try {
            $occupationType = OccupationTypes::find($id);
            if(!$occupationType) {
                return $this->helper_controller->send_response('failed', 'Occupation type not found', 400);
            }
            $occupationType->is_deleted = true;
            $occupationType->deleted_at = Carbon::now()->format('Y-m-d H:i:s');
            $occupationType->save();

            return $this->helper_controller->send_response('success', 'Occupation Type deleted successfully', 200, $occupationType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
}
