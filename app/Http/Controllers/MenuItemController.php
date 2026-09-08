<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Exception;
use Illuminate\Http\Request;

class MenuItemController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function index()
    {
        try {
            $menuItems = MenuItem::with('subMenuItems')->get();
            return $this->helper_controller->send_response('success', 'Menu items retrieved successfully', 200, $menuItems);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'module_id' => 'required|exists:modules,id',
            ]);

            $menuExist = MenuItem::where('name', $request->name)
                            ->where('module_id', $request->module_id)
                            ->first();
            if($menuExist) {
                return $this->helper_controller->send_response('success', 'Menu item already exist in this module', 422);
            }

            $menuItem = MenuItem::create($request->all());
            return $this->helper_controller->send_response('success', 'Menu item created successfully', 201, $menuItem);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $menuItem = MenuItem::with('subMenuItems')->findOrFail($id);
            return $this->helper_controller->send_response('success', 'Menu item retrieved successfully', 200, $menuItem);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'module_id' => 'required|exists:modules,id',
            ]);

            // Check if a menu item with the same name and module_id exists, excluding the current item
            $menuExist = MenuItem::where('name', $request->name)
                            ->where('module_id', $request->module_id)
                            ->where('id', '!=', $id)
                            ->first();

            if ($menuExist) {
                return $this->helper_controller->send_response('success', 'Menu item already exists in this module', 422);
            }

            $menuItem = MenuItem::findOrFail($id);
            $menuItem->update($request->all());
            return $this->helper_controller->send_response('success', 'Menu item updated successfully', 200, $menuItem);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $menuItem = MenuItem::findOrFail($id);
            $menuItem->delete();
            return $this->helper_controller->send_response('success', 'Menu item deleted successfully', 200, $menuItem);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 500);
        }
    }

}
