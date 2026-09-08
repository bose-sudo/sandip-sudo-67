<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppActivityLog extends Model
{
    use HasFactory;
    protected $table = "app_activity_logs";
    protected $primaryKey = "id";
    
    protected $fillable = 
    [
        "user_id",
        "user_type",
        "borrower_id",
        "action",
        "details",
        "reference",
        "ip_address",
    ];

    /**
     * Define a relationship with the User model if user_type is BORROWER.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Define a relationship with the LspExecutiveDetails model if user_type is not BORROWER.
     */
    public function lspExecutiveDetails()
    {
        return $this->belongsTo(LspExecutiveDetails::class, 'user_id');
    }

    /**
     * Accessor for user_name.
     * Dynamically retrieves the correct user's name.
     */
    public function getUserNameAttribute()
    {
        if ($this->user_type === 'BORROWER') {
            // If user_type is BORROWER, get the name from the User model.
            return $this->user ? $this->user->name : null;
        }

        // If user_type is not BORROWER, get the name from the LspExecutiveDetails model.
        return $this->lspExecutiveDetails ? $this->lspExecutiveDetails->name : null;
    }
}
