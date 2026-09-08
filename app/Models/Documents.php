<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Documents extends Model
{
    use HasFactory;
    protected $table = "documents";
    protected $primaryKey = "id";

    protected $fillable = [
        "borrower_id",
        "aadhar_front",
        "aahar_back",
        "pan_doc",
        "driving_license",
        "per_address_proof",
        "res_address_proof",
        "vehicle_quotation",
        "coAadhar_front",
        "coAahar_back",
        "coPan_doc",
        "coDriving_license",
        "coPerAddress_proof",
        "coResAddress_proof",
        "additional_doc",
        "invoice",
        "performa",
        "down_payment_receipt",
        "insurance",
        "rto_tax_receipt",
        "dl_receipt",
        "documents_form",
        "created_by",
        "updated_by",
        "deleted_at",
        "guarantor_aadhar_front",
        "guarantor_aadhar_back",
        "guarantor_driving_license",
        "guarantor_pan_doc",
        "guarantor_peraddress_proof",
        "guarantor_coresaddress_proof",
        "borrower_avtar",
        "coapplicant_avtar",
        "guarantor_avtar",
        "loan_file",
        "battery_guaranty",
        "warrant_document",
        "rto_document",
        "mmr",
        "vehicle_image",
        "urc_certificate",
        "borrower_signed_cheque",
        "vehicle_signed_cheque",
        "doh",
        "borrower_other_docs",
        "vehicle_other_docs",
        "borrower_rent_doc",
        "coapplicant_rent_doc",
        "guarantor_rent_doc",
        "borrower_name_declaration",
        "coapplicant_name_declaration",
        "guarantor_name_declaration",
        "chasis_number",
        "motor_controller",
        "battery_image",
        "registration_certificate",
        "bank_statement",
        "battery_signed_cheque",
        "borrower_income_proof_doc",
        "coapplicant_income_proof_doc",
        "guarantor_income_proof_doc",
        "borrower_dob_declaration",
        "coapplicant_dob_declaration",
        "guarantor_dob_declaration",
        "noc_document",
        "borrower_cibil_report",
        "coapplicant_cibil_report",
        "guarantor_cibil_report",
        "loi_permit",
        "vehicle_key_img",
    ];

    public function loanBasicDetails()
    {
        return $this->belongsTo(LoanBasicDetails::class, 'id', 'borrower_id');
    }
}
