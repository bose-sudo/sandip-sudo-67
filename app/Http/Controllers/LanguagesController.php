<?php

namespace App\Http\Controllers;

use App\Models\Languages;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LanguagesController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        // $this->middleware('auth:api');
        $this->helper_controller = $helper_controller;
    }

    public function create(Request $request)
    {
        try {
            $request->validate([
                'language' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            ]);

            // Convert the language to lowercase for case-insensitive comparison
            $languageInput = strtolower($request->language);

            // Check if the language already exists (case-insensitive)
            $existingLanguage = Languages::whereRaw('LOWER(language) = ?', [$languageInput])->first();

            if ($existingLanguage) {
                return $this->helper_controller->send_response('failed', 'Language already exists', 400);
            }

            // Create a new language record
            $language = new Languages();
            $language->language = $request->language;
            $language->save();

            return $this->helper_controller->send_response('success', 'Language created successfully', 200, $language);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }


    public function read($id)
    {
        try {
            $language = Languages::find($id);
            if(!$language) {
                return $this->helper_controller->send_response('failed', 'Language not found', 400);
            }
            return $this->helper_controller->send_response('success', 'Language details fetched', 200, $language);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'language' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
            ]);

            // Convert the language to lowercase for case-insensitive comparison
            $languageInput = strtolower($request->language);

            // Check if the language already exists (case-insensitive) except for the current record
            $existingLanguage = Languages::whereRaw('LOWER(language) = ?', [$languageInput])
                                        ->where('id', '!=', $id)
                                        ->first();

            if ($existingLanguage) {
                return $this->helper_controller->send_response('failed', 'Language already exists', 400);
            }

            // Find the language record and update it
            $language = Languages::find($id);
            if(!$language) {
                return $this->helper_controller->send_response('failed', 'Language not found', 400);
            }
            $language->language = $request->language;
            $language->save();

            return $this->helper_controller->send_response('success', 'Language updated successfully', 200, $language);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }


    public function delete($id)
    {
        try {
            $language = Languages::find($id);
            if(!$language) {
                return $this->helper_controller->send_response('failed', 'Language not found', 400);
            }
            $language->is_active  = false;
            $language->is_deleted = true;
            $language->deleted_at = Carbon::now()->format('Y-m-d H:i:s');
            $language->save();

            return $this->helper_controller->send_response('success', 'Language deleted successfully', 200, $language);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
}
