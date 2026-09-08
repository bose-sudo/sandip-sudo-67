<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VkycWebResponse extends Model
{
    use HasFactory;
    protected $table = "vkyc_web_responses";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'transaction_id',
        'state',
        'status',
        'unique_id',
        'scheduled_slot_time',
        'borrower_id',
    ];
}
