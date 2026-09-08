<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Manufacturers extends Model
{
    use HasFactory;
    protected $table = "manufacturers";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'manufacturer',
        'is_active',
        'is_deleted',
        'deleted_at',
        'oem_unq',
        'oem_auth_id',
        'company_type',
        'mobile_number',
        'alternate_mobile_number',
        'email',
        'password',
        'pan_number',
        'address_line_1',
        'address_line_2',
        'city',
        'pin',
        'landmark',
        'pan_doc',
        'oem_finayo_agreement',
        'bank_statement',
        'cheque_1',
        'cheque_2',
        'cheque_3',
        'cheque_4',
        'gst_certificate',
        'rent_agreement',
        'office_image',
        'itr_file',
        'utility_bill',
        'created_by',
        'ip',
        'date_of_incorporation',
        'partnership_deed',
        'balance_sheet',
        'certificate_of_incorporation',
        'aoa',
        'moa',
        'icat_certificate',
        'state',
    ];

    public function lenderManufacturerProductCharges()
    {
        return $this->hasMany(LenderManufacturerProductCharges::class, 'manufacturer_id', 'id');
    }

    public function productSpecification()
    {
        return $this->hasOne(OemProductSpecification::class, 'oem_id', 'id');
    }
    
    public function beneficiaryDetails()
    {
        return $this->hasOne(BeneficiaryDetails::class, 'user_id', 'id')
                    ->where('user_type', 'OEM');
    }

    public function state()
    {
        return $this->belongsTo(StateMaster::class, 'state', 'id');
    }
}
