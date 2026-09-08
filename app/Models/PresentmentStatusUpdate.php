<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PresentmentStatusUpdate extends Model
{
    use HasFactory;
    protected $table = "presentment_status_updates";
    protected $primaryKey = "id";

    protected $fillable = [
        "event",
        "raw_data",
        "status",
        "merchant_request_number",
        "transaction_reference_number",
        "pg_transaction_id",
        "status_at_bank",
        "created_at_event",
        "presentment_date",
        "amount",
        "split_payments",
        "bank_reference_number",
        "net_amount",
        "response_code",
        "response_description",
        "external_id",
    ];
}
