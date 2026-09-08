<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmiTransactions extends Model
{
    use HasFactory;
    protected $table = "emi_transactions";
    protected $primaryKey = "id";
    
    protected $fillable =  [
        'payment_date',
        'borrower_id',
        'transaction_id',
        'payment_status', // [ PENDING, SUCCESS, FAILED]
        'emi_amount',
        'fine_amount',
        'total_payment',
        'payment_initiated_at',
        'payment_updated_at',
        'user_id',
        'ip',
        'bank_ref_num',
        'bankcode',
        'error',
        'error_Message',
        'name_on_card',
        'upi_va',
        'cardnum',
        'issuing_bank',
        'easepayid',
        'card_type',
        'hash',
        'gateway_status',
        'bank_name',
        'auth_code',
        'auth_ref_num',
        'user_type',
        'loan_id',
        'payment_mode',
    ];
}
