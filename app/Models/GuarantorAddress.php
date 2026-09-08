<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GuarantorAddress extends Model
{
    use HasFactory;
    protected $table = "guarantor_addresses";
    protected $primaryKey = "id";

    protected $fillable = [
        "resident_type",
        "current_address",
        "years_living",
        "permanent_address",
        "family_members",
        "earning_members",
        "family_income",
        "created_by",
        "updated_by",
        "deleted_at",
        "relation",
    ];
}
