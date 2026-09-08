<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanStepBackRequest extends Model
{
    use HasFactory;
    protected $table = 'loan_step_back_requests';
    protected $primaryKey = 'lsbr_id';

    protected $fillable = [
        'borrower_id',
        'step_id',
        'step_flag',
        'maker_id',
        'reason',
        'requested_at',
        'r1_approval_status',
        'r1_id',
        'r1_approved_at',
        'r1_reason',
        'r2_approval_status',
        'r2_id',
        'r2_approved_at',
        'r2_reason',
        'row_status',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'r1_approved_at' => 'datetime',
        'r2_approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('row_status', 'A');
    }

    public function step()
    {
        return $this->belongsTo(LoanApplicationStep::class, 'step_id', 'step_id');
    }

    public function maker()
    {
        return $this->belongsTo(AdminUser::class, 'maker_id', 'id');
    }

    public function reviewer1()
    {
        return $this->belongsTo(AdminUser::class, 'r1_id', 'id');
    }

    public function reviewer2()
    {
        return $this->belongsTo(AdminUser::class, 'r2_id', 'id');
    }

    public function borrower()
    {
        return $this->belongsTo(LoanBasicDetails::class, 'borrower_id', 'id');
    }

    public function latestBorrowerKyc()
    {
        return $this->hasOne(BorrowerKyc::class, 'borrower_id', 'borrower_id')
            ->latestOfMany('id')
            ->select([
                // 'borrower_kycs.id',
                'borrower_kycs.borrower_id',
                'borrower_kycs.name',
            ]);
    }
}
