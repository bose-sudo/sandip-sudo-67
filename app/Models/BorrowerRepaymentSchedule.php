<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerRepaymentSchedule extends Model
{
    use HasFactory;
    protected $table = "borrower_repayment_schedules";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'borrower_id',
        'payment_status',
        'emi_amount',
        'number_of_installment',
        'principal_amount',
        'interest_amount',
        'fine_amount',
        'total_payment',
        'receipt_no',
        'payment_date',
        'due_date',
        'payment_mode',
        'reference',
        'remarks',
        'user_id',
        'ip',
    ];
}
