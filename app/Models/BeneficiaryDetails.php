<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BeneficiaryDetails extends Model
{
    use HasFactory;
    protected $table = "beneficiary_details";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "user_type",
        "user_id",
        "benefeciary_name",
        "account_number",
        "ifsc_code",
        "micr_code",
        "status",
        "active",
        "action_taken_by",
        "ip",
        "remarks",
    ];

    /**
     * Relation with Admin User.
     */
    public function actionTakenBy()
    {
        return $this->belongsTo(AdminUser::class, 'action_taken_by', 'id');
    }
}
