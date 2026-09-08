<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileStreamController extends Controller
{
    public function show(Request $request)
    {
        return Storage::disk('public')->response($request->query('path'));
    }
}