<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class ProductRuleCondition extends Model
{
    use HasFactory;
    
    protected $table = "product_rule_conditions";
    protected $primaryKey = "id";

    protected $fillable = ['rule_id', 'attribute', 'operator', 'value'];
    protected $casts = ['value' => 'array'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ProductRule::class, 'rule_id');
    }
}
