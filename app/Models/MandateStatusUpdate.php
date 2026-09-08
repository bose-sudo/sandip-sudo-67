<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MandateStatusUpdate extends Model
{
    use HasFactory;
    protected $table = "mandate_status_updates";
    protected $primaryKey = "id";

    protected $fillable = [
        "event",
        "status",
        "umrn",
        "customer_name",
        "customer_account_number",
        "customer_ifsc",
        "auth_mode",
        "frequency",
        "mandate_type",
        "start_date",
        "end_date",
        "amount",
        "transaction_id",
        "bank_reference_number",
        "bank_code",
        "customer_email",
        "customer_phone",
        "amount_rule",
        "customer_account_type",
        "is_revokable",
        "response_code",
        "response_description",
    ];
}
