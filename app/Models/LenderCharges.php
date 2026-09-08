<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LenderCharges extends Model
{
    use HasFactory;
    protected $table = "lender_charges";
    protected $primaryKey = "id";

    protected $fillable = [
        'token_id',
        'lender_id',
        'charge_head',
        'charge_type',
        'charge_value',
        'status',
        'ip',
        'user_id',
        'finayo_share',
        'nbfc_share',
    ];

    public function lender()
    {
        return $this->belongsTo(Lenders::class, 'lender_id', 'id');
    }
}
