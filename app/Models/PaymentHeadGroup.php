<?php

namespace App\Models;

use App\Traits\HasAuditStamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentHeadGroup extends Model
{
    use HasFactory, HasAuditStamps;

    const STATUS_ACTIVE   = 'A';
    const STATUS_INACTIVE = 'I';
    const STATUS_DELETED  = 'D';

    protected $fillable = [
        'name',
        'code',
        'description',
        'row_status',
    ];

    // ─── Global Scope ─────────────────────────────────────────

    protected static function booted(): void
    {
        static::addGlobalScope('not_deleted', function (Builder $builder) {
            $builder->where('row_status', '!=', self::STATUS_DELETED);
        });
    }

    // ─── Relationships ────────────────────────────────────────

    public function items(): HasMany
    {
        return $this->hasMany(PaymentHeadGroupItem::class, 'payment_head_group_id');
    }

    public function heads(): BelongsToMany
    {
        return $this->belongsToMany(
            PaymentHead::class,
            'payment_head_group_items',
            'payment_head_group_id',
            'payment_head_id'
        )->withTimestamps();
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('row_status', self::STATUS_ACTIVE);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('row_status', self::STATUS_INACTIVE);
    }

    // ─── Helpers ──────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->row_status === self::STATUS_ACTIVE;
    }
}