<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnachRequest extends Model
{
    use HasFactory;
    protected $table = "enach_requests";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'borrower_id',
        'transaction_id',
        'phone',
        'email',
        'e_nach_amount',
        'is_enach_activated',
        'status',
        'activation_date',
        'update_date',
        'access_key',
        'mandate_type',
        'account_number',
        'account_holder_name',
        'account_type',
        'ifsc',
        'auth_mode',
        'Authorization',
        'bank_code',
    ];
}
