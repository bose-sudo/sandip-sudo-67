<?php

namespace App\Http\Controllers;

use App\Models\UserDepartments;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserDepartmentController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function index(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'department' => 'nullable|regex:/^[a-zA-Z]+$/', // Only alphabetic characters (A-Z, a-z)
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $page = $request->get('page', 1);
            $numberOfRecords = $request->get('number_of_records', 10);
            $department = $request->get('department');

            $query = UserDepartments::query();
            $query->where('is_deleted', false);

            if (!empty($department)) {
                $query->whereRaw('LOWER(department) LIKE ?', ['%' . strtolower($department) . '%']);
            }

            $userDepartments = $query->orderBy('department', 'asc')
                                ->paginate($numberOfRecords, ['*'], 'page', $page);
            
            return $this->helper_controller->send_response('success', "User department list fetched", 200, $userDepartments);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function department_screener(Request $request)
    {
        try {
            // Retrieve pagination parameters
            $page = $request->get('page', 1);
            $numberOfRecords = $request->get('number_of_records', 10);

            // Create query to fetch only 'id' and 'department'
            $userDepartments = UserDepartments::select('id', 'department')
                ->where('is_deleted', false)
                ->get();
                // ->paginate($numberOfRecords, ['id', 'department'], 'page', $page);

            // Send response
            return $this->helper_controller->send_response('success', "User department list fetched", 200, $userDepartments);
        } catch (Exception $e) {
            // Handle exception
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }


    // Get a single user department
    public function read($id)
    {
        try {
            $userDepartment = UserDepartments::find($id);
            if(!$userDepartment) {
                return $this->helper_controller->send_response('failed', 'User department not found', 400);
            }

            if ($userDepartment->is_deleted) {
                return $this->helper_controller->send_response('failed', 'Department not found', 400);
            }
            
            return $this->helper_controller->send_response('success', "Department details fetched", 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // Create a new user department
    public function store(Request $request)
    {
        try {
            // Validate the input
            $request->validate([
                'department' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            ]);
    
            // Convert the department input to lowercase for case-insensitive comparison
            $departmentInput = strtolower($request->department);
    
            // Check if the department already exists (case-insensitive)
            $existingDepartment = UserDepartments::whereRaw('LOWER(department) = ?', [$departmentInput])->first();
    
            if ($existingDepartment) {
                return $this->helper_controller->send_response('failed', 'Department already exists', 400);
            }
    
            // Create a new department record
            $userDepartment = new UserDepartments();
            $userDepartment->department = $request->department;
            $userDepartment->save();
    
            return $this->helper_controller->send_response('success', 'User department created successfully', 201, $userDepartment);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }
    

    // Update an existing user department
    public function update(Request $request, $id)
    {
        try {
            // Validate the input
            $request->validate([
                'department' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            ]);
    
            // Convert the department input to lowercase for case-insensitive comparison
            $departmentInput = strtolower($request->department);
    
            // Check if the department already exists (case-insensitive), excluding the current record
            $existingDepartment = UserDepartments::whereRaw('LOWER(department) = ?', [$departmentInput])
                ->where('id', '!=', $id)  // Exclude the current record from the check
                ->first();
    
            if ($existingDepartment) {
                return $this->helper_controller->send_response('failed', 'Department already exists', 400);
            }
    
            // Find and update the existing department record
            $userDepartment = UserDepartments::find($id);
            if(!$userDepartment) {
                return $this->helper_controller->send_response('failed', 'User department not found', 400);
            }
            $userDepartment->department = $request->department;
            $userDepartment->save();
    
            return $this->helper_controller->send_response('success', 'User department updated successfully', 200, $userDepartment);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }
    

    // Soft delete a user department
    public function destroy($id)
    {
        try {
            $userDepartment = UserDepartments::find($id);
            if(!$userDepartment) {
                return $this->helper_controller->send_response('failed', 'User department not found', 400);
            }
            $userDepartment->is_deleted = true;
            $userDepartment->deleted_at = now();
            $userDepartment->save();

            return $this->helper_controller->send_response('success', 'User department deleted successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }
}
