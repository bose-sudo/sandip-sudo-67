<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerOccupation extends Model
{
    use HasFactory;
    protected $table = "borrower_occupations";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "occupation_type",
        "current_occupation",
        "driving_experience",
        "vehicle_type",
        "monthly_earning",
        "expected_monthly_earning",
        "extra_source_income",
        "source_income_area",
        "total_income",
        "current_city",
        "created_by",
        "updated_by",
        "deleted_at",
    ];

    public function loanBasicDetails()
    {
        return $this->belongsTo(LoanBasicDetails::class, 'id', 'borrower_id');
    }
}
