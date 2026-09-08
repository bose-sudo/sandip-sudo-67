<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealerUserMap extends Model
{
    use HasFactory;

    protected $table = 'dealer_user_map';
    protected $primaryKey = 'id';

    protected $fillable = [
        'dealer_unq',
        'user_id',
        'status',
        'visibility_mode',
        'created_by',
        'updated_by',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function dealer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Dealer::class, 'dealer_unq', 'dealer_unq');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'user_id', 'id');
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by', 'id');
    }

    public function updatedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by', 'id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'inactive');
    }

    public function scopeInherit($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('visibility_mode', 'inherit');
    }

    public function scopeStrict($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('visibility_mode', 'strict');
    }
}
