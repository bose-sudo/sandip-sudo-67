<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppPermission extends Model
{
    use HasFactory;
    protected $table = "app_permissions";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'device_id',
        'user_personal_information',
        'phone',
        'location',
        'camera',
        'storage',
        'third_party_accounts',
        'privacy_policy',
    ];
}
