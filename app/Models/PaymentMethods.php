<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethods extends Model
{
    use HasFactory;
    protected $table = "payment_methods";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "payment_method",
        "status",
        "ip",
        "updated_by",
    ];

    public function updatedBy()
    {
        return $this->belongsTo(AdminUser::class, 'updated_by', 'id');
    }
}
