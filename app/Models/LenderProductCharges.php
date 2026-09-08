<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LenderProductCharges extends Model
{
    use HasFactory;
    protected $table = "lender_product_charges";
    protected $primaryKey = "id";

    protected $fillable = [
        'token_id',
        'lender_id',
        'product_id',
        'charge_head',
        'charge_type',
        'charge_value',
        'status',
        'ip',
        'user_id',
    ];

    public function lender()
    {
        return $this->belongsTo(Lenders::class, 'lender_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(ProductModel::class, 'product_id', 'id');
    }
}
