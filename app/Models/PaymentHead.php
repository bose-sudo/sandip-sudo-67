<?php

namespace App\Models;

use App\Traits\HasAuditStamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentHead extends Model
{
    use HasFactory, HasAuditStamps;

    const STATUS_ACTIVE   = 'A';
    const STATUS_INACTIVE = 'I';
    const STATUS_DELETED  = 'D';

    protected $fillable = [
        'name',
        'code',
        'description',
        'calculation_type',
        'default_value',
        'percentage_basis',
        'is_per_annum',
        'is_gst_applicable',
        'gst_rate',
        'is_system',
        'row_status',
    ];

    protected $casts = [
        'default_value'     => 'decimal:4',
        'gst_rate'          => 'decimal:2',
        'is_per_annum'      => 'boolean',
        'is_gst_applicable' => 'boolean',
        'is_system'         => 'boolean',
    ];

    // ─── Global Scope ─────────────────────────────────────────

    protected static function booted(): void
    {
        static::addGlobalScope('not_deleted', function (Builder $builder) {
            $builder->where('row_status', '!=', self::STATUS_DELETED);
        });
    }

    // ─── Relationships ────────────────────────────────────────

    /**
     * Groups this head belongs to.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            PaymentHeadGroup::class,
            'payment_head_group_items',
            'payment_head_id',
            'payment_head_group_id'
        )->withTimestamps();
    }

    /**
     * All charges raised under this head.
     */
    public function charges(): HasMany
    {
        return $this->hasMany(LoanPaymentCharge::class, 'payment_head_id');
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

    public function scopeFixed(Builder $query): Builder
    {
        return $query->where('calculation_type', 'fixed');
    }

    public function scopePercentage(Builder $query): Builder
    {
        return $query->where('calculation_type', 'percentage');
    }

    // ─── Helpers ──────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->row_status === self::STATUS_ACTIVE;
    }

    /**
     * Calculate the gross charge (before GST) for this head.
     *
     * @param  float    $baseAmount  Outstanding principal / overdue EMI amount etc.
     * @param  int|null $delayDays   Required for per-annum percentage heads.
     */
    public function calculateCharge(float $baseAmount, ?int $delayDays = null): float
    {
        if ($this->calculation_type === 'fixed') {
            return (float) $this->default_value;
        }

        $rate = (float) $this->default_value / 100;

        if ($this->is_per_annum) {
            if ($delayDays === null || $delayDays <= 0) {
                return 0.0;
            }
            // Pro-rata daily: (amount × rate × days) / 365
            return round(($baseAmount * $rate * $delayDays) / 365, 2);
        }

        return round($baseAmount * $rate, 2);
    }

    /**
     * Calculate GST on top of the charge amount.
     */
    public function calculateGst(float $chargeAmount): float
    {
        if (! $this->is_gst_applicable || (float) $this->gst_rate <= 0) {
            return 0.0;
        }

        return round($chargeAmount * ((float) $this->gst_rate / 100), 2);
    }

    /**
     * Returns full breakdown: charge + gst + total.
     */
    public function calculateTotal(float $baseAmount, ?int $delayDays = null): array
    {
        $charge = $this->calculateCharge($baseAmount, $delayDays);
        $gst    = $this->calculateGst($charge);

        return [
            'charge_amount' => $charge,
            'gst_amount'    => $gst,
            'total_amount'  => round($charge + $gst, 2),
        ];
    }
}