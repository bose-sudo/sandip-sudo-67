<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentGatewayResponse extends Model
{
    use HasFactory;
    protected $table = "payment_gateway_responses";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'user_id',
        'ip',
        'date_time',
        'status',
        'api_flag',
        'borrower_id',
        'user_type',
        'admin_user_id',
        'executive_id',
        'request',
        'response',
    ];
}
