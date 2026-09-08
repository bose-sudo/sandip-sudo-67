<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlockedContactExcel extends Model
{
    use HasFactory;
    protected $table = 'blocked_contact_excels';
    protected $primaryKey = "id";

    protected $fillable = [
        "file_name",
        "status",
        "reason",
        "uploaded_by",
        "updated_by",
        "errors",
        "file_path",
    ];

    
    /**
     * Relation with Admin User.
     */
    public function modifiedBy()
    {
        return $this->belongsTo(AdminUser::class, 'updated_by', 'id');
    }
    
    public function uploadedBy()
    {
        return $this->belongsTo(AdminUser::class, 'uploaded_by', 'id');
    }
}
