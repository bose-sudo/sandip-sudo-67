<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserType extends Model
{
    use HasFactory;
    protected $table = "user_types";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'user_type',
        'role',
        'updated_by',
        'is_active',
        'is_deleted',
        'deleted_at',
    ];

    public function adminUsers()
    {
        return $this->hasMany(AdminUser::class, 'user_type', 'id');
    }

    public function accessRoles()
    {
        return $this->hasMany(AccessRole::class, 'user_type_id', 'id');
    }
}
