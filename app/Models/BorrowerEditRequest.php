<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerEditRequest extends Model
{
    use HasFactory;

    protected $table = 'borrower_edit_requests';
    protected $primaryKey = 'ber_id';

    protected $fillable = [
        'borrower_id', 'maker_id', 'reason', 'requested_at',
        'r1_id', 'r1_approval_status', 'r1_reason', 'r1_approved_at', 'r1_ip',
        'r2_id', 'r2_approval_status', 'r2_reason', 'r2_approved_at', 'r2_ip',
        'completion_status', 'completed_at', 'apply_error',
        'maker_ip', 'maker_user_agent',
        'row_status', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'requested_at'   => 'datetime',
        'r1_approved_at' => 'datetime',
        'r2_approved_at' => 'datetime',
        'completed_at'   => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(BorrowerEditRequestItem::class, 'ber_id', 'ber_id');
    }

    public function borrower()
    {
        return $this->belongsTo(LoanBasicDetails::class, 'borrower_id', 'id');
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
}