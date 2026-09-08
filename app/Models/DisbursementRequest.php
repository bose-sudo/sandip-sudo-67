<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DisbursementRequest extends Model
{
    use HasFactory;
    protected $table = 'disbursement_requests';
    protected $primaryKey = "id";

    protected $fillable = [
        'borrower_id',
        'disbursement_amount',
        'request_date',
        'request_remarks',
        'request_ip',
        'request_by',
        'status',  // [PENDING, HOLD, APPROVED, REJECTED]
        'updated_by',
        'admin_remarks',
        'status_updated_on',
        'admin_ip',
    ];

    /**
     * Relation with Admin User.
     */
    public function requestBy()
    {
        return $this->belongsTo(AdminUser::class, 'request_by', 'id');
    }
    
    public function updatedBy()
    {
        return $this->belongsTo(AdminUser::class, 'updated_by', 'id');
    }
}
