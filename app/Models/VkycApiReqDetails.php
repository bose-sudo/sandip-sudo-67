<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VkycApiReqDetails extends Model
{
    use HasFactory;
    protected $table = "vkyc_api_req_details";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'user_id',
        'borrower_id',
        'application_number',
        'mobile',
        'request',
        'vkyc_url',
    ];
}
