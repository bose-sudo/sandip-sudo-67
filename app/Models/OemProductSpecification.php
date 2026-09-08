<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OemProductSpecification extends Model
{
    use HasFactory;
    protected $table = "oem_product_specifications";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "oem_id",
        "motor",
        "battery",
        "controller",
        "tyre",
        "performance",
        "brochure",
        "created_by",
        "ip",
    ];

    public function oem()
    {
        return $this->belongsTo(Manufacturers::class, 'oem_id', 'id');
    }
}
