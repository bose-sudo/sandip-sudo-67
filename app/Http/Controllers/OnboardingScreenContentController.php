<?php

namespace App\Http\Controllers;

use App\Models\OnboardingScreenContent;
use Exception;
use Illuminate\Http\Request;

class OnboardingScreenContentController extends Controller
{
    private $helper_controller;
    public function __construct(HelperController $helper_controller) {
        $this->helper_controller = $helper_controller;
    }

    public function index()
    {
        try {
            $data = OnboardingScreenContent::where('is_deleted', 0)->orderBy('created_at', 'asc')->get();
            return $this->helper_controller->send_response('success', 'Data retrieved successfully', 200, $data);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Error occurred', 422);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'title' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
                'content' => 'required|regex:/^[a-zA-Z0-9\s,\.]+$/|max:255',
            ]);
            
            $data = OnboardingScreenContent::create($request->all());
            return $this->helper_controller->send_response('success', 'Data created successfully', 201, $data);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Error occurred', 422);
        }
    }

    public function show($id)
    {
        try {
            $data = OnboardingScreenContent::where('id', $id)->where('is_deleted', 0)->first();
            if(!$data) {
                return $this->helper_controller->send_response('failed', 'Not found', 400);
            }
            return $this->helper_controller->send_response('success', 'Data retrieved successfully', 200, $data);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Error occurred', 422);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'title' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
                'content' => 'required|regex:/^[a-zA-Z0-9\s,\.]+$/|max:255',
            ]);

            $data = OnboardingScreenContent::find($id);
            if(!$data) {
                return $this->helper_controller->send_response('failed', 'Not found', 400);
            }
            $data->update($request->all());
            return $this->helper_controller->send_response('success', 'Data updated successfully', 200, $data);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Error occurred', 422);
        }
    }

    public function destroy($id)
    {
        try {
            $data = OnboardingScreenContent::find($id);
            if(!$data) {
                return $this->helper_controller->send_response('failed', 'Not found', 400);
            }
            $data->update(['is_deleted' => 1, 'deleted_at' => now()]);
            return $this->helper_controller->send_response('success', 'Data deleted successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Error occurred', 422);
        }
    }
}
