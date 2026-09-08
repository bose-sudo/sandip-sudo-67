<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmiDebitConsent extends Model
{
    use HasFactory;
    protected $table = "emi_debit_consents";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "content",
        "is_active",
        "is_deleted",
        "deleted_at",
    ];
}
