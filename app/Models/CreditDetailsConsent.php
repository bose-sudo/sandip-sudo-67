<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditDetailsConsent extends Model
{
    use HasFactory;
    protected $table = "credit_details_consents";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "content",
        "is_active",
        "is_deleted",
        "deleted_at",
    ];
}
