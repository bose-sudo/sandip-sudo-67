<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnachDebitResponse extends Model
{
    use HasFactory;
    protected $table = "enach_debit_responses";
    protected $primaryKey = "id";
    
    protected $fillable =  [
        'response_id',
        'pg_transaction_id',
        'amount',
        'presentment_date',
        'status',
        'net_amount',
        'transaction_reference_number',
        'bank_reference_number',
        'status_at_bank',
        'split_payments',
        'response_meta',
        'merchant_request_number',
        'response_created_at',
    ];
}
