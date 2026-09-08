<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerIotDetails extends Model
{
    use HasFactory;
    protected $table = "borrower_iot_details";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'user_id',
        'borrower_id',
        'vehicle_id',
        'vehicle_uuid',
        'status',
        'api_response_id',
    ];
}
