<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class ProductRule extends Model
{
    use HasFactory;
    
    protected $table = "product_rules";
    protected $primaryKey = "id";

    protected $fillable = ['rule_set_id', 'rule_type', 'priority', 'output', 'status'];
    protected $casts = ['output' => 'array', 'priority' => 'integer'];

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(ProductRuleSet::class, 'rule_set_id');
    }
    public function conditions(): HasMany
    {
        return $this->hasMany(ProductRuleCondition::class, 'rule_id');
    }
}
