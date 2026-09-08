<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EligibilityCheck extends Model
{
    use HasFactory;
    protected $table = "eligibility_checks";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'user_id',
        'language',
        'partner_code',
        'service',
        'sub_service',
        'dob',
        'monthly_income',
        'occupation',
        'loan_amount',
        'loan_term',
        'loan_purpose',
        'is_eligible',
        'is_deleted',
    ];
}
