<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BatteryTypes extends Model
{
    use HasFactory;
    protected $table = "battery_types";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'battery_type',
        'is_active',
        'is_deleted',
        'deleted_at',
    ];
}
