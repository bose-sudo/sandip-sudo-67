<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanDetails extends Model
{
    use HasFactory;
    protected $table = "loan_details";
    protected $primaryKey = "id";

    protected $fillable = [
        "borrower_kyc_id",
        "borrower_address_id",
        "borrower_occupation_id",
        "borrower_document_id",
        "coapplicant_kyc_id",
        "coapplicant_address_id",
        "coapplicant_occupation_id",
        "coapplicant_document_id",
        "courier_id",
        "asset_id",
        "lender_id",
        "user_id",
        "status",
        "updated_at",
        "updated_by",
        "reference_id",
        "guarantor_id",
        "guarantor_address_id",
        "guarantor_occupation_id",
        "uuid",
        "fi_executive_id",
    ];
}
