<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnachScheduleLogs extends Model
{
    use HasFactory;
    protected $table = "enach_schedule_logs";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "borrower_id",
        "merchant_request_number",
        "request_payload",
        "response_payload",
        "schedule_id",
        "scheduler_id",
        "status",
        "scheduled_created_at",
    ];
}
