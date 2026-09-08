<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LenderManufacturerProductCharges extends Model
{
    use HasFactory;
    protected $table = "lender_manufacturer_product_charges";
    protected $primaryKey = "id";

    protected $fillable = [
        'token_id',
        'lender_id',
        'manufacturer_id',
        'charge_head',
        'charge_type',
        'charge_value',
        'status',
        'ip',
        'user_id',
    ];

    public function manufacturer()
    {
        return $this->belongsTo(Manufacturers::class, 'manufacturer_id', 'id');
    }

    public function lender()
    {
        return $this->belongsTo(Lenders::class, 'lender_id', 'id');
    }
}
