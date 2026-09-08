<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppSupport extends Model
{
    use HasFactory;
    protected $table = "app_supports";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "app_type",
        "borrower_id",
        "name",
        "contact_number",
        "subject",
        "description",
        "status",
        "remarks",
        "ip",
        "created_by",
        "updated_by",
    ];

    // Separate relationships for B2C and non-B2C
    public function userCreatedBy()
    {
        return $this->belongsTo(User::class, 'created_by', 'id')->select(['id', 'name', 'email']);
    }

    public function executiveCreatedBy()
    {
        return $this->belongsTo(LspExecutiveDetails::class, 'created_by', 'id')->select(['id', 'name', 'email']);
    }

    
    public function updatedBy()
    {
        return $this->belongsTo(AdminUser::class, 'updated_by', 'id')->select(['id', 'name', 'email']);
    }
}
