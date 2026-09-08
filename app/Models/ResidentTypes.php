<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResidentTypes extends Model
{
    use HasFactory;
    protected $table = "resident_types";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'resident_type',
        'is_active',
        'is_deleted',
        'deleted_at',
    ];
}
