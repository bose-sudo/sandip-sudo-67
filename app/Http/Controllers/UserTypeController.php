<?php

namespace App\Http\Controllers;

use App\Models\UserType;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserTypeController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    // Get all user types
    public function index(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'user_type' => 'nullable|regex:/^[a-zA-Z]+$/', // Only alphabetic characters (A-Z, a-z)
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $page = $request->get('page', 1);
            $numberOfRecords = $request->get('number_of_records', 10);
            $userTypeFilter = $request->get('user_type');

            $query = UserType::query();
            $query->where('is_deleted', false);

            if (!empty($userTypeFilter)) {
                $query->whereRaw('LOWER(user_type) LIKE ?', ['%' . strtolower($userTypeFilter) . '%']);
            }

            $userTypes = $query->orderBy('user_type', 'asc')
                                ->paginate($numberOfRecords, ['*'], 'page', $page);


            // return response()->json($userTypes, 200);
            return $this->helper_controller->send_response("success", "User type list fetched", 200, $userTypes);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function user_type_screener(Request $request)
    {
        try {
            // Retrieve pagination parameters
            $page = $request->get('page', 1);
            $numberOfRecords = $request->get('number_of_records', 10);

            // Create query to fetch only 'id' and 'user_type'
            $userTypes = UserType::select('id', 'user_type')
                ->where('is_deleted', false)
                ->get();
                // ->paginate($numberOfRecords, ['id', 'user_type'], 'page', $page);

            // Send response
            return $this->helper_controller->send_response('success', "User type screener list fetched", 200, $userTypes);
        } catch (Exception $e) {
            // Handle exception
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }


    // Get a single user type
    public function read($id)
    {
        try {
            $userType = UserType::find($id);
            if(!$userType) {
                return $this->helper_controller->send_response('failed', 'User type not found', 400);
            }

            if ($userType->is_deleted) {
                return $this->helper_controller->send_response('failed', 'User type not found', 400);
            }
            return $this->helper_controller->send_response('success', 'User type fetched', 200, $userType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // Create a new user type
    public function store(Request $request)
    {
        // Validate the input
        $request->validate([
            'user_type' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            'role' => 'nullable|integer',
        ]);
    
        try {
            // Convert the user_type input to lowercase for case-insensitive comparison
            $userTypeInput = strtolower($request->user_type);
    
            // Check if the user type already exists (case-insensitive)
            $existingUserType = UserType::whereRaw('LOWER(user_type) = ?', [$userTypeInput])->first();
    
            if ($existingUserType) {
                return $this->helper_controller->send_response('failed', 'User type already exists', 400);
            }
    
            // Create a new user type record
            $userType = new UserType();
            $userType->user_type = $request->user_type;
            $userType->role = $request->role ?? null;
            $userType->save();
    
            return $this->helper_controller->send_response('success', 'User type created successfully', 201, $userType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    // Update an existing user type
    public function update(Request $request, $id)
    {
        try {
            // Validate the input
            $request->validate([
                'user_type' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
                'role' => 'nullable|integer',
            ]);
    
            // Convert the user_type input to lowercase for case-insensitive comparison
            $userTypeInput = strtolower($request->user_type);
    
            // Check if the user type already exists (case-insensitive), excluding the current record
            $existingUserType = UserType::whereRaw('LOWER(user_type) = ?', [$userTypeInput])
                ->where('id', '!=', $id)  // Exclude the current record from the check
                ->first();
    
            if ($existingUserType) {
                return $this->helper_controller->send_response('failed', 'User type already exists', 400);
            }
    
            // Find and update the existing user type record
            $userType = UserType::find($id);
            if(!$userType) {
                return $this->helper_controller->send_response('failed', 'User type not found', 400);
            }
            $userType->user_type = $request->user_type;
            $userType->role = $request->role ?? null;
            $userType->save();
    
            return $this->helper_controller->send_response('success', 'User type updated successfully', 200, $userType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    // Soft delete a user type
    public function destroy($id)
    {
        try {
            $userType = UserType::find($id);
            if(!$userType) {
                return $this->helper_controller->send_response('failed', 'User type not found', 400);
            }
            $userType->is_deleted = true;
            $userType->deleted_at = now();
            $userType->save();

            return $this->helper_controller->send_response('success', 'User type deleted successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
}
