<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LenderServiceProvider extends Model
{
    use HasFactory;
    protected $table = "lender_service_providers";
    protected $primaryKey = "id";

    protected $fillable = [
        'lsp_unq',
        'name',
        'mobile',
        'is_mobile_verified',
        'alternative_number',
        'email',
        'is_email_verified',
        'pan',
        'is_pan_verified',
        'aadhar',
        'landmark',
        'city',
        'pincode',
        'address_line_1',
        'address_line_2',
        'company_type',
        'gst',
        'gst_certificate',
        'lender_lsp_agreement',
        'finayo_lsp_agreement',
        'moa_and_aoa',
        'certificate_of_incorporation',
        'address_proof_doc',
        'is_deleted',
        'user_id',
        'lsp_auth_id',
        'is_active',
        'aoa',
        'moa',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
