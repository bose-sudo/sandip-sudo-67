<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldInspectionDetails extends Model
{
    use HasFactory;
    protected $table = "field_inspection_details";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'borrower_id',
        'name',
        'mobile',
        'is_inspection_verified',
        'verification_time',
        'longitude',
        'latitude',
        'verification_url',
        'otp',
        'access_token',
        'fi_image',
        'distance',
        'address',
        'dealer_shop_id',
        'meter_image',
        'meter_verification_status',
        'meter_remarks',
        'meter_uploaded_at',
        'meter_verified_by',
        'meter_verified_at',
        'borrower_required',
        'co_applicant_required',
        'guarantor_required',
        'completed_at',
    ];

    protected $casts = [
        'meter_uploaded_at' => 'datetime',
        'meter_verified_at' => 'datetime',
        'completed_at'           => 'datetime',
        'is_inspection_verified' => 'boolean',
        'borrower_required'      => 'boolean',
        'co_applicant_required'  => 'boolean',
        'guarantor_required'     => 'boolean',
    ];

    public function parties(): HasMany
    {
        return $this->hasMany(FieldInspectionParty::class, 'field_inspection_detail_id');
    }

    /** @return string[] */
    public function requiredPartyTypes(): array
    {
        return array_values(array_filter([
            $this->borrower_required     ? FieldInspectionParty::TYPE_BORROWER     : null,
            $this->co_applicant_required ? FieldInspectionParty::TYPE_CO_APPLICANT : null,
            $this->guarantor_required    ? FieldInspectionParty::TYPE_GUARANTOR    : null,
        ]));
    }
}
