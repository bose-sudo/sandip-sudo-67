<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerAddress extends Model
{
    use HasFactory;
    protected $table = "borrower_addresses";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "resident_type",
        "current_address",
        "years_living",
        "permanent_address",
        "family_members",
        "earning_members",
        "family_income",
        "reference_one",
        "reference_two",
        "created_by",
        "updated_by",
        "deleted_at",
    ];

    public function loanBasicDetails()
    {
        return $this->belongsTo(LoanBasicDetails::class, 'id', 'borrower_id');
    }
}
