<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lenders extends Model
{
    use HasFactory;
    protected $table = "lenders";
    protected $primaryKey = "id";

    protected $fillable = [
        'lender_unq',
        'name',
        'is_active',
        'is_deleted',
        'delete_date_time',
        'modified_by',
        'agreement_doc',
        'company_type',
        'coi_doc',
        'nbfc_certificate',
        'complience_officer_name',
        'complience_officer_mobile',
        'complience_officer_email',
        'processing_fee_sharing_status',
        'finayo_share',
        'lender_share',
        'processing_fee_amount',
        'lending_roi',
        'user_id',
        'processing_fee_type',
        'seed_rate',
        'lender_auth_id',
        'corporate_address_1',
        'corporate_address_2',
        'corporate_address_city',
        'corporate_address_state',
        'corporate_address_pin',
        'corporate_address_landmark',
        'registered_office_address_1',
        'registered_office_address_2',
        'registered_office_city',
        'registered_office_state',
        'registered_office_pin',
        'registered_office_landmark',
    ];

    public function directors()
    {
        return $this->hasMany(LenderDirectors::class, 'lender_id', 'id');
    }

    
    public function cp_state()
    {
        return $this->belongsTo(StateMaster::class, 'corporate_address_state', 'id');
    }

    public function rg_state()
    {
        return $this->belongsTo(StateMaster::class, 'registered_office_state', 'id');
    }

    public function lenderManufacturerProductCharges()
    {
        return $this->hasMany(LenderManufacturerProductCharges::class, 'lender_id', 'id');
    }

    public function charges()
    {
        return $this->hasMany(LenderCharges::class, 'lender_id');
    }
}
