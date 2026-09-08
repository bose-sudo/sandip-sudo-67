<?php

namespace App\Http\Controllers;

use App\Models\OemProductSpecification;
// use App\Models\Oems;
use App\Models\Manufacturers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OemProductSpecificationController extends Controller
{
    private $helper_controller;
    public function __construct(HelperController $helper_controller) {
        $this->helper_controller = $helper_controller;
    }

    // INDEX METHOD
    public function index(Request $request)
    {
        // Validate filters
        $validator = Validator::make($request->all(), [
            'oem_id' => 'nullable|integer|exists:oems,id',
            'motor' => ['nullable', 'regex:/^[a-zA-Z0-9\s\-(),.]+$/'],
            'battery' => ['nullable', 'regex:/^[a-zA-Z0-9\s\-(),.]+$/'],
            'limit' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1', // Validate page number
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $query = OemProductSpecification::query();

            // Apply filters
            if ($request->filled('oem_id')) {
                $query->where('oem_id', $request->oem_id);
            }
            if ($request->filled('motor')) {
                $query->where('motor', 'like', '%' . $request->motor . '%');
            }
            if ($request->filled('battery')) {
                $query->where('battery', 'like', '%' . $request->battery . '%');
            }

            // Pagination settings
            $perPage = $request->get('limit', 10); // Default to 10 records per page
            $page = $request->get('page', 1);

            // Paginated response
            $data = $query->orderBy('id', 'desc')->paginate($perPage, ['*'], 'page', $page);

            return $this->helper_controller->send_response('success', "Data retrieved successfully", 200, $data);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    // STORE METHOD
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'oem_id' => 'required|integer|exists:manufacturers,id',
            'motor' => ['required', 'regex:/^[a-zA-Z0-9\s\-(),.]+$/'],
            'battery' => ['required', 'regex:/^[a-zA-Z0-9\s\-(),.]+$/'],
            'controller' => ['required', 'regex:/^[a-zA-Z0-9\s\-(),.]+$/'],
            'tyre' => ['required', 'regex:/^[a-zA-Z0-9\s\-(),.]+$/'],
            'performance' => ['nullable', 'regex:/^[a-zA-Z0-9\s\-(),.]+$/'],
            'brochure' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB max size
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }
        
        DB::beginTransaction();
        try {
            $oem_details = Manufacturers::find($request->oem_id);
            if(!$oem_details) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }
            $oem_unq = $oem_details->oem_unq;

            $brochurePath = null;

            if ($request->hasFile('brochure')) {
                // Generate unique directory path
                $file_url_path = 'OemSpecification/' . $oem_unq;
                $brochure = $request->file('brochure');
                
                // Define file path and name
                $brochurePath = $brochure->storeAs(
                    $file_url_path,
                    'brochure_file.' . $brochure->getClientOriginalExtension(),
                    'public' // Assuming a 'public' disk is set up
                );
            }

            $specification = OemProductSpecification::create([
                'oem_id' => $request->oem_id,
                'motor' => $request->motor,
                'battery' => $request->battery,
                'controller' => $request->controller,
                'tyre' => $request->tyre,
                'performance' => $request->performance,
                'brochure' => 'storage/' . $brochurePath,
                'created_by' => auth()->id(),
                'ip' => $request->ip(),
            ]);

            DB::commit();
            return $this->helper_controller->send_response('success', "Specification added successfully", 200, $specification);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    // SHOW METHOD
    public function show($id) {
        $specification = OemProductSpecification::find($id);
        if(!$specification) {
            return $this->helper_controller->send_response('failed', "Data not found", 422);
        }
        
        return $this->helper_controller->send_response('success', "Data retrieved successfully", 200, $specification);
    }

    // UPDATE METHOD
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            // 'oem_id' => 'nullable|integer|exists:manufacturers,id',
            'motor' => ['nullable', 'regex:/^[a-zA-Z0-9\s\-(),.]+$/'],
            'battery' => ['nullable', 'regex:/^[a-zA-Z0-9\s\-(),.]+$/'],
            'controller' => ['nullable', 'regex:/^[a-zA-Z0-9\s\-(),.]+$/'],
            'tyre' => ['nullable', 'regex:/^[a-zA-Z0-9\s\-(),.]+$/'],
            'performance' => ['nullable', 'regex:/^[a-zA-Z0-9\s\-(),.]+$/'],
            'brochure' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB max size
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();
        try {
            
            $specification = OemProductSpecification::find($id);
            if (!$specification) {
                return $this->helper_controller->send_response('failed', "Data not found", 422);
            }
            

            $brochurePath = $specification->brochure;
            $oem_details = Manufacturers::find($specification->oem_id);
            $oem_unq = $oem_details->oem_unq;

            // Update fields
            // $specification->oem_id = $request->oem_id ?? $specification->oem_id;
            $specification->motor = $request->motor ?? $specification->motor;
            $specification->battery = $request->battery ?? $specification->battery;
            $specification->controller = $request->controller ?? $specification->controller;
            $specification->tyre = $request->tyre ?? $specification->tyre;
            $specification->performance = $request->performance ?? $specification->performance;

            // Handle brochure file upload
            if ($request->hasFile('brochure')) {
                // Generate unique directory path
                $file_url_path = 'OemSpecification/' . $oem_unq;
                $brochure = $request->file('brochure');
                
                // Define file path and name
                $brochurePath = $brochure->storeAs(
                    $file_url_path,
                    'brochure_file.' . $brochure->getClientOriginalExtension(),
                    'public' // Assuming a 'public' disk is set up
                );
                $specification->brochure = 'storage/' . $brochurePath;
            } else {
                $specification->brochure = $brochurePath;
            }

            // Save changes
            $specification->save();

            DB::commit();

            return $this->helper_controller->send_response('success', "Specification updated successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    // DELETE METHOD
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $specification = OemProductSpecification::findOrFail($id);
            $specification->delete();
            DB::commit();

            return $this->helper_controller->send_response('success', "Specification deleted successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred: ". $e->getMessage(), 422);
        }
    }
}
