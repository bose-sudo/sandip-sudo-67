<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerLoanScheme extends Model
{
    use HasFactory;
    protected $table = "borrower_loan_schemes";
    protected $primaryKey = "id";

    protected $fillable = [
        'borrower_id',
        'product_id',
        'ex_showroom_price',
        'loan_to_value_ratio',
        'min_loan_amount',
        'max_loan_amount',
        'min_loan_tenure',
        'max_loan_tenure',
        'interest',
        'processing_fee',
        'subvention_amount',
        'fldg',
        'iot',
        'insurance_1st_year',
        'insurance_2nd_year',
        'down_payment_amount',
        'loan_amount',
        'total_interest',
        'loan_emi',
        'interest_type',
        'pricing_source',
        'version',
        'is_current',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('current', fn($q) => $q->where('is_current', true));
    }
}
