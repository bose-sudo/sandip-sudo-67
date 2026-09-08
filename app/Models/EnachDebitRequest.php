<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnachDebitRequest extends Model
{
    use HasFactory;
    protected $table = "enach_debit_requests";
    protected $primaryKey = "id";
    
    protected $fillable =  [
        'borrower_id',
        'transaction_id',
        'amount',
        'presentment_date',
        'merchant_request_number',
        'authorization',
        'modified_by',
        'ip',
    ];
}
