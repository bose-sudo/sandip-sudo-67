<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UatApiTest extends Model
{
    use HasFactory;
    protected $table = "uat_api_tests";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'api_flag',
        'ip',
        'date',
        'status',
        'user_id',
        'first_name',
        'middle_name',
        'last_name',
        'pan',
        'aadhar',
        'dob',
        'address',
        'postal',
        'city',
        'status_code',
        'request',
        'response',
    ];
}
