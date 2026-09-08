<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductModel extends Model
{
    use HasFactory;

    const FLAT   = 'flat';
    const REDUCING = 'reducing';
    
    protected $table = "product_models";
    protected $primaryKey = "id";

    protected $fillable = [
        'dealer_id',
        'model_unq',
        'name',
        'battery_type',
        'ex_showroom_price',
        'loan_to_value_ratio',
        'manufacture_name',
        'min_loan_amount',
        'max_loan_amount',
        'min_loan_tenure',
        'max_loan_tenure',
        'product_type',
        'interest',
        'processing_fee',
        'subvention_amount',
        'fldg',
        'iot',
        'insurance_1st_year',
        'insurance_2nd_year',
        'status',
        'modified_by',
        'ip',
        'sub_product_type',
        'sub_product_type_id',
        'interest_type'
    ];

    public function dealer()
    {
        return $this->belongsTo(Dealer::class, 'dealer_id', 'id');
    }

    public function lenderProductCharges()
    {
        return $this->hasMany(LenderProductCharges::class, 'product_id', 'id');
    }
}
