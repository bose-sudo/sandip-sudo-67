<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanApplicationSummary extends Model
{
    use HasFactory;
    protected $table = "loan_application_summaries";
    protected $primaryKey = "id";

    protected $fillable = [
        "borrower_id",
        "product_id",
        "loan_amount",
        "tenure",
        "age",
        "cibil_score",
        "co_applicant_id",
        "guarantor_id",
        "resience_type",
        "residence_stability",
        "field_verification",
        "dpd_30_in_3_month",
        "dpd_90_in_1_year",
        "is_npa",
        "is_willful_defaulter",
        "is_eligible",
        "reason",
        "updated_by",
        "user_id",
        "coappli_cibil_score",
        "coappli_dpd_30",
        "coappli_dpd_90",
        "coappli_is_npa",
        "coappli_defaulter",
        "guarantor_cibil_score",
        "guarantor_dpd_30",
        "guarantor_dpd_90",
        "guarantor_is_npa",
        "guarantor_defaulter",
    ];

    public function loanBasicDetails()
    {
        return $this->belongsTo(LoanBasicDetails::class, 'id', 'borrower_id');
    }
}
