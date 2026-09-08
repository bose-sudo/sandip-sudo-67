<?php

namespace App\Http\Controllers;

use App\Models\Relations;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;

class RelationsController extends Controller
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
                'relation' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            ]);
    
            // Convert the relation input to lowercase for case-insensitive comparison
            $relationInput = strtolower($request->relation);
    
            // Check if the relation already exists (case-insensitive)
            $existingRelation = Relations::whereRaw('LOWER(relation) = ?', [$relationInput])->first();
    
            if ($existingRelation) {
                return $this->helper_controller->send_response('failed', 'Relation already exists', 400);
            }
    
            // Create a new relation record
            $relation = new Relations();
            $relation->relation = $request->relation;
            $relation->save();
    
            return $this->helper_controller->send_response('success', 'Relation created successfully', 200, $relation);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function read($id)
    {
        try {
            $relation = Relations::find($id);
            if(!$relation) {
                return $this->helper_controller->send_response('failed', 'Relation not found', 400);
            }
            return $this->helper_controller->send_response('success', 'Relation details fetched', 200, $relation);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            // Validate the input
            $request->validate([
                'relation' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            ]);
    
            // Convert the relation input to lowercase for case-insensitive comparison
            $relationInput = strtolower($request->relation);
    
            // Check if the relation already exists (case-insensitive), excluding the current record
            $existingRelation = Relations::whereRaw('LOWER(relation) = ?', [$relationInput])
                ->where('id', '!=', $id)  // Exclude the current record from the check
                ->first();
    
            if ($existingRelation) {
                return $this->helper_controller->send_response('failed', 'Relation already exists', 400);
            }
    
            // Find and update the existing relation record
            $relation = Relations::find($id);
            if(!$relation) {
                return $this->helper_controller->send_response('failed', 'Relation not found', 400);
            }
            $relation->relation = $request->relation;
            $relation->save();
    
            return $this->helper_controller->send_response('success', 'Relation updated successfully', 200, $relation);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function delete($id)
    {
        try {
            $relation = Relations::find($id);
            if(!$relation) {
                return $this->helper_controller->send_response('failed', 'Relation not found', 400);
            }
            $relation->is_deleted = true;
            $relation->deleted_at = Carbon::now()->format('Y-m-d H:i:s');
            $relation->save();

            return $this->helper_controller->send_response('success', 'Relation deleted successfully', 200, $relation);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
}
