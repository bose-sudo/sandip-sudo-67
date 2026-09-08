<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserDepartments extends Model
{
    use HasFactory;
    protected $table = "user_departments";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'department',
        'updated_by',
        'is_active',
        'is_deleted',
        'deleted_at',
    ];

    public function adminUsers()
    {
        return $this->hasMany(AdminUser::class, 'department', 'id');
    }
}
