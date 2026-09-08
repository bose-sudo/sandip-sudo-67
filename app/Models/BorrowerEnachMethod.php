<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BorrowerEnachMethod extends Model
{
    use HasFactory;

    protected $table = 'borrower_enach_methods';

    protected $fillable = [
        'borrower_id',
        'enach_method',
        'remarks',
        'is_active',
        'created_by',
        'created_ip',
        'updated_by',
        'updated_ip',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'borrower_id' => 'integer',
    ];

    public function loanDetails(): BelongsTo
    {
        return $this->belongsTo(LoanBasicDetails::class, 'borrower_id', 'id');
    }

    public function borrowerKyc(): BelongsTo
    {
        return $this->belongsTo(BorrowerKyc::class, 'borrower_id', 'borrower_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by', 'id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by', 'id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}