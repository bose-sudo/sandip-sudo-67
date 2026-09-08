<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class WebResponseController extends Controller
{
    // Success URL
    public function enach_successUrl(Request $request)
    {
        // You can log or process mandate reference here if needed

        // Example response or redirect
        return response()->json([
            'status' => 'success',
            'message' => 'Mandate created successfully.'
        ], 200);
    }

    // Failure URL
    public function enach_failureUrl(Request $request)
    {
        $errorMessage = $request->input('error_message', 'Mandate creation failed.');
        
        return response()->json([
            'status' => 'failed',
            'message' => $errorMessage
        ], 400);
    }
}
