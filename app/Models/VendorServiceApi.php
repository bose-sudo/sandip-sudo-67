<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorServiceApi extends Model
{
    use HasFactory;
    protected $table = "vendor_service_apis";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'user_id',
        'ip_address',
        'date_time',
        'status',
        'api_flag',
        'request',
        'response',
        'borrower_id',
        'doc_id',
        'applicant_type',
    ];

    /**
     * Indexes:
     * Single column index: user_id, ip_address, date_time, borrower_id, api_flag, admin_user_id
     * Composit index: [doc_id date_time]
     * 
     */
    
}
