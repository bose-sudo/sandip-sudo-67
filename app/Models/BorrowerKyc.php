<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerKyc extends Model
{
    use HasFactory;
    protected $table = "borrower_kycs";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "name",
        "mobile",
        "is_verified_mobile",
        "pan",
        "is_verified_pan",
        "pan_message",
        "aadhar",
        "is_verified_aadhar",
        "asset_value",
        "loan_amount",
        "down_payment",
        "dob_date",
        "marital_status",
        "husband_name",
        "father_name",
        "gender",
        "alternate_number1",
        "alternate_number2",
        "email",
        "cibil",
        "postal",
        "state",
        "country",
        "avtar",
        "is_driving_licence_verified",
        "is_electricity_verified",
        "created_by",
        "updated_by",
        "deleted_at",
        "borrower_id",
        "driving_license_no",
        "skip_co_applicant",
        "policy_number",
        "chasis_number",
        "policy_premium",
        "policy_start_date",
        "policy_end_date",
        "udyam_aadhaar_number",
        "vehicle_reg_no",
// New columns added in the migration
        "graviton_loan_id",
        "graviton_client_id",
        "dedupe_match_percentage",
        "dedupe_data",
        "dedupe_checked_at",
        "dedupe_status",
        'graviton_credit_report_resource_id',   // ✅ NEW
        'graviton_credit_report_status',         // ✅ NEW
        'graviton_credit_report_synced_at',      // ✅ NEW
        'graviton_credit_report_error',          // ✅ NEW
    ];

    protected $casts = [
        'dedupe_data'             => 'array',
        'dedupe_match_percentage' => 'decimal:2',
        'dedupe_checked_at'       => 'datetime',
        'is_verified_mobile'      => 'boolean',
        'is_verified_pan'         => 'boolean',
        'is_verified_aadhar'      => 'boolean',
        'graviton_credit_report_synced_at' => 'datetime',   // ✅ NEW
    ];
    public function loanBasicDetails()
    {
        return $this->belongsTo(LoanBasicDetails::class, 'borrower_id', 'id');
    }

    // app/Models/BorrowerKyc.php

    public function gravitonReferenceData()
    {
        return $this->hasOne(GravitonReferenceData::class, 'borrower_id');
    }

    // app/Models/BorrowerKyc.php

    public function loanScheme()
    {
        return $this->hasOne(
            BorrowerLoanScheme::class,
            'borrower_id',    // foreign key in borrower_loan_schemes
            'borrower_id'     // local key in borrower_kycs ✅
        );
    }
}
