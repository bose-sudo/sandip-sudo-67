<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class ProductRuleSet extends Model
{
    use HasFactory;
    
    protected $table = "product_rule_sets";
    protected $primaryKey = "id";

    protected $fillable = [
        'product_id',
        'dealer_id',
        'scope',
        'version',
        'status',
        'effective_from',
        'effective_to',
    ];
    protected $casts = ['effective_from' => 'datetime', 'effective_to' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(OemDynamicProduct::class, 'product_id');
    }
    public function rules(): HasMany
    {
        return $this->hasMany(ProductRule::class, 'rule_set_id');
    }
}
