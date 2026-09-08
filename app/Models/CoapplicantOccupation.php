<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoapplicantOccupation extends Model
{
    use HasFactory;
    protected $table = "coapplicant_occupations";
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
        "resident_type",
        "current_address",
        "years_living",
        "permanent_address",
    ];

    public function loanBasicDetails()
    {
        return $this->belongsTo(LoanBasicDetails::class, 'id', 'borrower_id');
    }
}
