<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AaWebhookResponse extends Model
{
    use HasFactory;
    protected $table = "aa_webhook_responses";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'notification_type',
        'status',
        'tracking_id',
        'reference_id',
        'response_data',
        'ip_address',
    ];
}
