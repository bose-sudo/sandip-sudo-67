<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LenderDealerProductCharges extends Model
{
    use HasFactory;
    protected $table = "lender_dealer_product_charges";
    protected $primaryKey = "id";

    protected $fillable = [
        'token_id',
        'lender_id',
        'dealer_id',
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

    public function dealer()
    {
        return $this->belongsTo(Dealer::class, 'dealer_id', 'id');
    }
}
