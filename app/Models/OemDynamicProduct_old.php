<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class OemDynamicProduct extends Model
{
    protected $table = 'oem_dynamic_products';
    protected $primaryKey = "id";

    const FLAT     = 'flat';
    const REDUCING = 'reducing';

    protected $fillable = [
        'oem_product_unq',
        'oem_id',
        'product_name',
        'product_type',
        'product_sub_type',
        'product_sub_type_id',
        'battery_type',
        'ex_showroom_price',
        'loan_to_value_ratio',
        'max_dealer_borrower_km',
        'min_loan_amount',
        'max_loan_amount',
        'min_loan_tenure',
        'max_loan_tenure',
        'interest_type',
        'interest',
        'processing_fee',
        'subvention_amount',
        'fldg',
        'iot',
        'insurance_1st_year',
        'insurance_2nd_year',
        'pricing_source',
        'status',
        'modified_by',
        'ip',
    ];

    protected $casts = [
        'ex_showroom_price'      => 'decimal:2',
        'loan_to_value_ratio'    => 'decimal:2',
        'max_dealer_borrower_km' => 'decimal:2',
        'min_loan_amount'        => 'decimal:2',
        'max_loan_amount'        => 'decimal:2',
        'interest'               => 'decimal:2',
        'processing_fee'         => 'decimal:2',
        'subvention_amount'      => 'decimal:2',
        'fldg'                   => 'decimal:2',
        'iot'                    => 'decimal:2',
        'insurance_1st_year'     => 'decimal:2',
        'insurance_2nd_year'     => 'decimal:2',
    ];

    public function oem(): BelongsTo
    {
        return $this->belongsTo(Manufacturers::class, 'oem_id')->select(['id', 'manufacturer', 'oem_unq']);
    }
    public function ruleSets(): HasMany
    {
        return $this->hasMany(ProductRuleSet::class, 'product_id');
    }
    public function defaultRuleSet()
    {
        return $this->ruleSets()
            ->where('scope', 'product_default')->where('status', 'active')->first();
    }
    public function dealerRuleSet(int $dealerId)
    {
        return $this->ruleSets()
            ->where('scope', 'dealer_override')->where('dealer_id', $dealerId)
            ->where('status', 'active')->first();
    }

    public function dealerLinks(): HasMany
    {
        return $this->hasMany(DealerProduct::class, 'product_id');
    }
}
