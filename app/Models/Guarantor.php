<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Guarantor extends Model
{
    use HasFactory;
    protected $table = "guarantors";
    protected $primaryKey = "id";

    protected $fillable = [
        "name",
        "mobile",
        "is_verified_mobile",
        "pan",
        "is_verified_pan",
        "pan_message",
        "aadhar",
        "is_verified_aadhar",
        "asset_value",
        "loan_amount",
        "down_payment",
        "dob_date",
        "fs_name",
        "gender",
        "email",
        "cibil",
        "postal",
        "state",
        "country",
        "avtar",
        "created_by",
        "updated_by",
        "deleted_at",
        "borrower_id",
    ];

    public function loanBasicDetails()
    {
        return $this->belongsTo(LoanBasicDetails::class, 'id', 'borrower_id');
    }
}
