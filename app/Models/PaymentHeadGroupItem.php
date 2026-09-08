<?php

namespace App\Models;

use App\Traits\HasAuditStamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentHeadGroupItem extends Model
{
    use HasAuditStamps;

    protected $fillable = [
        'payment_head_group_id',
        'payment_head_id',
    ];

    // ─── Relationships ────────────────────────────────────────

    public function group(): BelongsTo
    {
        return $this->belongsTo(PaymentHeadGroup::class, 'payment_head_group_id');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(PaymentHead::class, 'payment_head_id');
    }
}