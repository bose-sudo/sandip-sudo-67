<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleTypes extends Model
{
    use HasFactory;
    protected $table = "vehicle_types";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'vehicle_type',
        'is_active',
        'is_deleted',
        'deleted_at',
    ];
}
