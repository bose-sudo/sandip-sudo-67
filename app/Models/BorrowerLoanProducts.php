<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerLoanProducts extends Model
{
    use HasFactory;
    protected $table = "borrower_loan_products";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "borrower_id",
        "product_id",
        "quantity",
        "amount",
        "user_ip",
        "created_by",
    ];
}
