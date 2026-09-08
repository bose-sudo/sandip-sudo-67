<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LenderLoanSetting extends Model
{
    use HasFactory;
    protected $table = "lender_loan_settings";
    protected $primaryKey = "id";

    protected $fillable = [
        "lender_id",
        "co_applicant_step",
        "guarantor_step",
        "modified_by",
        "ip",
    ];

    /**
     * Relation with lender.
     */
    public function lender()
    {
        return $this->belongsTo(Lenders::class, 'lender_id', 'id');
    }

    /**
     * Relation with Admin User.
     */
    public function modifiedBy()
    {
        return $this->belongsTo(AdminUser::class, 'modified_by', 'id');
    }
}
