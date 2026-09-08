<?php

namespace App\Http\Controllers;

use App\Models\LspDirector;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LspDirectorsController extends Controller
{
    public function __construct(HelperController $helperController)
    {
        $this->helper_controller = $helperController;
    }

    // Create LspDirector
    public function store(Request $request)
    {
        // Validation rules
        $inputs = $request->all();
        $inputs['alternative_mobile'] = $inputs['alternative_number'] ?? null; // Map alternative_number to alternative_mobile

        $validator = Validator::make($inputs, [
            'lsp_id' => 'required|integer',
            'name' => 'required|string|max:255|regex:/^[\p{L}\p{M} \-\'.]+$/u',
            'mobile' => 'required|digits:10|unique:lsp_directors',
            'alternative_mobile' => 'nullable|digits:10|different:mobile',
            'email' => 'required|email|max:255|unique:lsp_directors',
            'aadhar' => 'nullable|digits:12|unique:lsp_directors',
            'pan' => 'nullable|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|unique:lsp_directors',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $lspDirector = LspDirector::create($inputs);

            return $this->helper_controller->send_response('success', 'LSP director created successfully', 201, $lspDirector);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // Read LspDirector
    public function show($id)
    {
        try {
            $lspDirector = LspDirector::find($id);
            if(!$lspDirector || $lspDirector->count() < 1) {

                return $this->helper_controller->send_response('success', 'LSP director not found', 200);
            }
            return $this->helper_controller->send_response('success', 'LSP director fetched successfully', 200, $lspDirector);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // Update LspDirector
    public function update(Request $request, $id)
    {
        try {
            $inputs = $request->all();
            $inputs['alternative_mobile'] = $inputs['alternative_number'] ?? null;

            $validator = Validator::make($inputs, [
                'lsp_id' => 'required|integer',
                'name' => 'required|string|max:255|regex:/^[\p{L}\p{M} \-\'.]+$/u',
                'mobile' => 'required|digits:10|unique:lsp_directors,mobile,'.$id,
                'alternative_mobile' => 'nullable|digits:10|different:mobile',
                'email' => 'required|email|max:255|unique:lsp_directors,email,'.$id,
                'aadhar' => 'nullable|digits:12|unique:lsp_directors,email,'.$id,
                'pan' => 'nullable|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|unique:lsp_directors,email,'.$id,
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $lspDirector = LspDirector::find($id);
            if (!$lspDirector) {
                return $this->helper_controller->send_response('success', 'No directors found', 200);
            }

            $lspDirector->update($inputs);
            return $this->helper_controller->send_response('success', 'LSP director updated successfully', 200, $lspDirector);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function director_list_by_lsp_id($lsp_id)
    {
        try {
            // Fetch the directors filtered by lsp_id
            $directors = LspDirector::where('lsp_id', $lsp_id)->get();

            if (!$directors || $directors->count() < 1) {
                return $this->helper_controller->send_response('success', 'No directors found', 200);
            }

            return $this->helper_controller->send_response('success', 'Directors fetched successfully', 200, $directors);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }


    // Delete LspDirector
    public function destroy($id)
    {
        try {
            $lspDirector = LspDirector::find($id);
            if (!$lspDirector || $lspDirector->count() < 1) {
                return $this->helper_controller->send_response('success', 'No directors found', 200);
            }
            $lspDirector->delete();
            return $this->helper_controller->send_response('success', 'LSP director deleted successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }
}
