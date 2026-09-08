<?php

namespace App\Http\Controllers;

use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Http\Request;

class IndexController extends Controller
{
    public function __construct() {
        $this->middleware('auth:api');
    }

    public function index(){
        return 'Ook';
    }
}
