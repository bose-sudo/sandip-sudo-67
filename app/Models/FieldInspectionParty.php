<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldInspectionParty extends Model
{
    use HasFactory;

    public const TYPE_BORROWER     = 'borrower';
    public const TYPE_CO_APPLICANT = 'co_applicant';
    public const TYPE_GUARANTOR    = 'guarantor';

    public const TYPES = [self::TYPE_BORROWER, self::TYPE_CO_APPLICANT, self::TYPE_GUARANTOR];

    protected $table = 'field_inspection_parties';

    protected $fillable = [
        'field_inspection_detail_id', 'borrower_id', 'party_type', 'party_uid',
        'name', 'mobile', 'otp', 'otp_expires_at', 'access_token', 'token_expires_at',
        'meter_image', 'meter_verification_status', 'meter_remarks', 'meter_uploaded_at',
        'meter_verified_by', 'meter_verified_at', 'fi_image', 'latitude', 'longitude',
        'distance', 'verification_time', 'is_verified',
    ];

    protected $casts = [
        'otp_expires_at'    => 'datetime',
        'token_expires_at'  => 'datetime',
        'meter_uploaded_at' => 'datetime',
        'meter_verified_at' => 'datetime',
        'verification_time' => 'datetime',
        'is_verified'       => 'boolean',
        'latitude'          => 'float',
        'longitude'         => 'float',
        'distance'          => 'float',
    ];

    protected $hidden = ['otp', 'access_token'];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(FieldInspectionDetails::class, 'field_inspection_detail_id');
    }

    public function label(): string
    {
        return match ($this->party_type) {
            self::TYPE_CO_APPLICANT => 'Co-applicant',
            self::TYPE_GUARANTOR    => 'Guarantor',
            default                 => 'Borrower',
        };
    }
}
