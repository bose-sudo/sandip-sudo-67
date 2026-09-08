<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LoanApplicationStepLog extends Model
{
    use HasFactory;
    protected $table = 'loan_application_step_logs';

    protected $fillable = [
        'borrower_id',
        'step_number',
        'step_flag',
        'attempt_number',
        'status',
        'platform',
        'done_by',
        'description',
        'done_at',
    ];

    protected $casts = [
        'done_at'        => 'datetime',
        'step_number'    => 'integer',
        'attempt_number' => 'integer',
    ];

    // ── Relationships ──────────────────────────────────────────────

    public function doneBy()
    {
        return $this->belongsTo(AdminUser::class, 'done_by', 'id');
    }

    public function borrower()
    {
        return $this->belongsTo(LoanBasicDetails::class, 'borrower_id', 'id');
    }

    // LoanBasicDetails model
    public function kyc()
    {
        return $this->hasOne(BorrowerKyc::class, 'borrower_id', 'borrower_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeExcludeAuditMarkers(Builder $query): Builder
    {
        return $query->where('step_flag', '!=', 'step_reverted');
    }

    /**
     * Latest attempt per borrower+step_flag — use for all stats/reporting.
     * Filters to the MAX(id) per group which naturally represents
     * the latest attempt since IDs are sequential.
     */
    public function scopeLatestAttempts(Builder $query): Builder
    {
        return $query->whereIn('id', function ($sub) {
            $sub->selectRaw('MAX(id)')
                ->from('loan_application_step_logs')
                ->where('status', 'completed')
                ->where('step_flag', '!=', 'step_reverted')
                ->groupBy('borrower_id', 'step_flag');
        });
    }
}
