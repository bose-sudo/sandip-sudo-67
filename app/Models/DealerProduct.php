<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerProduct extends Model
{
    use HasFactory;
    protected $table = 'dealer_products';
    protected $primaryKey = "id";
    protected $fillable = ['dealer_id', 'product_id', 'status', 'assigned_by'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(OemDynamicProduct::class, 'product_id');
    }
    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class, 'dealer_id');
    }
}
