<?php

namespace App\Http\Controllers;

use App\Models\SubMenuItem;
use Exception;
use Illuminate\Http\Request;

class SubMenuItemController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function index()
    {
        try {
            $subMenuItems = SubMenuItem::all();
            return $this->helper_controller->send_response('success', 'Sub-menu items retrieved successfully', 200, $subMenuItems);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'menu_id' => 'required|exists:menu_items,id',
                'path' => 'required',
            ]);

            $subMenuExist = SubMenuItem::where('name', $request->name)
                            ->where('menu_id', $request->menu_id)
                            ->first();
            if($subMenuExist) {
                return $this->helper_controller->send_response('success', 'Submenu item already exist in this module', 422);
            }

            $subMenuItem = SubMenuItem::create($request->all());
            return $this->helper_controller->send_response('success', 'Sub-menu item created successfully', 201, $subMenuItem);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $subMenuItem = SubMenuItem::findOrFail($id);
            return $this->helper_controller->send_response('success', 'Sub-menu item retrieved successfully', 200, $subMenuItem);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'menu_id' => 'required|exists:menu_items,id',
                'path' => 'required',
            ]);

            $subMenuExist = SubMenuItem::where('name', $request->name)
                            ->where('menu_id', $request->menu_id)
                            ->where('id', '!=', $id)
                            ->first();
            if($subMenuExist) {
                return $this->helper_controller->send_response('success', 'Submenu item already exist in this module', 422);
            }

            $subMenuItem = SubMenuItem::findOrFail($id);
            $subMenuItem->update($request->all());
            return $this->helper_controller->send_response('success', 'Sub-menu item updated successfully', 200, $subMenuItem);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $subMenuItem = SubMenuItem::findOrFail($id);
            $subMenuItem->delete();
            return $this->helper_controller->send_response('success', 'Sub-menu item deleted successfully', 200, $subMenuItem);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('error', $e->getMessage(), 500);
        }
    }
}
