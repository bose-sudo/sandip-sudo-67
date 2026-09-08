<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiDemoResponses extends Model
{
    use HasFactory;
    protected $table = "api_demo_responses";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "doc_id",
        "api_flag",
        "response",
    ];
}
