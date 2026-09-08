<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DisbursementDetails extends Model
{
    use HasFactory;
    protected $table = 'disbursement_details';
    protected $primaryKey = "id";

    protected $fillable = [
        'utr_number',
        'disbursement_amount',
        'disbursement_date',
        'borrower_id',
        'loan_id',
        'lender_id',
        'modified_by',
        'status',
        'ip',
        'transaction_id',
    ];

    // Relationships
    public function borrower()
    {
        return $this->belongsTo(LoanBasicDetails::class, 'borrower_id');
    }

    public function adminUser()
    {
        return $this->belongsTo(AdminUser::class, 'modified_by');
    }
}
