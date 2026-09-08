<?php

namespace App\Http\Controllers;

use App\Models\Module;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModuleController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function index()
    {
        try {
            $modules = Module::with('menuItems.subMenuItems')->get();
            return $this->helper_controller->send_response('success', 'Modules retrieved successfully', 200, $modules);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|unique:modules,name',
                'priority' => 'nullable|integer|unique:modules,priority',
            ]);
    
            // Start a database transaction
            DB::beginTransaction();
    
            // If priority is not set, default to largest existing priority + 1
            if (!$request->has('priority')) {
                $maxPriority = Module::max('priority');
                $request->merge(['priority' => $maxPriority + 1]);
            } else {
                // If priority is set and exists, increment priorities of all subsequent records
                $priority = $request->input('priority');
                Module::where('priority', '>=', $priority)->increment('priority');
            }
    
            // Create the module
            $module = Module::create($request->all());
    
            // Commit the transaction
            DB::commit();
    
            return $this->helper_controller->send_response('success', 'Module created successfully', 201, $module);
        } catch (Exception $e) {
            // Rollback the transaction in case of error
            DB::rollBack();
    
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }

    public function show($id)
    {
        try {
            $module = Module::with('menuItems.subMenuItems')->findOrFail($id);
            return $this->helper_controller->send_response('success', 'Module retrieved successfully', 200, $module);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'required|string|unique:modules,name,' . $id,
                'priority' => 'nullable|integer|unique:modules,priority,' . $id,
            ]);
    
            // Start a database transaction
            DB::beginTransaction();
    
            // Find the module
            $module = Module::findOrFail($id);
            $oldPriority = $module->priority;
    
            // If priority is set and different, adjust priorities
            if ($request->has('priority') && $request->priority != $oldPriority) {
                $newPriority = $request->priority;
    
                if ($newPriority > $oldPriority) {
                    // Decrement priorities between old and new priority
                    Module::whereBetween('priority', [$oldPriority + 1, $newPriority])->decrement('priority');
                } else {
                    // Increment priorities between new and old priority
                    Module::whereBetween('priority', [$newPriority, $oldPriority - 1])->increment('priority');
                }
            } elseif (!$request->has('priority')) {
                // If priority is not set, keep the same priority
                $request->merge(['priority' => $oldPriority]);
            }
    
            // Update the module
            $module->update($request->all());
    
            // Commit the transaction
            DB::commit();
    
            return $this->helper_controller->send_response('success', 'Module updated successfully', 200, $module);
        } catch (Exception $e) {
            // Rollback the transaction in case of error
            DB::rollBack();
    
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }

    public function destroy($id)
    {
        try {
            $module = Module::findOrFail($id);
            $module->delete();
            return $this->helper_controller->send_response('success', 'Module deleted successfully', 200, $module);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 422);
        }
    }
}
