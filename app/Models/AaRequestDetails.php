<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AaRequestDetails extends Model
{
    use HasFactory;
    protected $table = "aa_request_details";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'borrower_id',
        'mobile',
        'pan_no',
        'fip_ids',
        'tracking_id',
        'reference_id',
        'aa_status',
        'applicant_type',
        'action_performed_by',
        'action_user_type',
        'ip_address',
        'input_data',
    ];

    public function getAaStatusAttribute($value)
    {
        return $value === null || $value === '' ? 'PENDING' : $value;
    }

}
