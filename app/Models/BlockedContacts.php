<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlockedContacts extends Model
{
    use HasFactory;
    protected $table = "blocked_contacts";
    protected $primaryKey = "id";
    
    protected $fillable = 
    [
        "mobile",
        "status",
        "ip",
        "updated_by",
    ];

    /**
     * Relation with Admin User.
     */
    public function modifiedBy()
    {
        return $this->belongsTo(AdminUser::class, 'updated_by', 'id');
    }
}
