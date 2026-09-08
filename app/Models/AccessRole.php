<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessRole extends Model
{
    use HasFactory;
    protected $table = "access_roles";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'user_type_id',
        'submenu_id',
        'permission',
    ];

    public function userType()
    {
        return $this->belongsTo(UserType::class, 'user_type_id');
    }
    
    public function submenu()
    {
        return $this->belongsTo(SubMenuItem::class, 'submenu_id');
    }
}
