<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubventionDetails extends Model
{
    use HasFactory;
    protected $table = "subvention_details";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "borrower_id",
        "subvention_amount",
        "payment_date",
        "utr_number",
        "payment_receipt_img",
        "transaction_id",
        "bank_name",
        "modified_by",
        "status",
        "ip",
        "rc_amount",
        "rc_utr_number",
        "rc_payment_date",
        "rc_bank_name",
        "rc_payment_receipt_img",
        "cheque_amount",
        "cheque_utr_number",
        "cheque_bank_name",
        "cheque_payment_date",
        "cheque_payment_receipt_img",
    ];

    public function modifiedBy()
    {
        return $this->belongsTo(AdminUser::class, 'modified_by', 'id');
    }
}
