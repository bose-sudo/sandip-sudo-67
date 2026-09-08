<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerAccountDetails extends Model
{
    use HasFactory;
    protected $table = "borrower_account_details";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "borrower_id",
        "emi_debit_consent",
        "account_holder_name",
        "account_number",
        "ifsc",
        "bank_name",
        "branch_code",
        "is_enach_enabled",
        "enach_enabled_on",
        "enach_disabled_on",
        "enach_start_date",
        "enach_end_date",
        "account_type",
        "bank_code",
        "enach_status",
        "is_enach_step_skippable",
        "skip_permission_by",
        "skip_permission_granted_at",
        "skip_permission_remarks",
        "enach_method",
    ];

    public function enachPermissionUpdatedBy()
    {
        return $this->belongsTo(AdminUser::class, 'skip_permission_by', 'id');
    }
    
    public function enachSchedule()
    {
        return $this->belongsTo(EnachScheduleLogs::class, 'borrower_id', 'borrower_id')->where('status', 'success');
    }

    public function enachMethods()
    {
        return $this->hasMany(BorrowerEnachMethod::class, 'borrower_id', 'borrower_id');
    }

    public function activeEnachMethod()
    {
        return $this->hasOne(BorrowerEnachMethod::class, 'borrower_id', 'borrower_id')
            ->where('is_active', true)
            ->latestOfMany();
    }
}
