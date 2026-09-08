<?php
namespace App\Traits;

trait ApiResponse
{
    protected function send_response($status='success', $message = null, $code = 200, $data = null)
    {
        return response()->json([
            'status' => $status,
            'message' => $message,
            'data' => $data
        ], $code);
    }
}
