<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OccupationTypes extends Model
{
    use HasFactory;
    protected $table = "occupation_types";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'occupation_type',
        'is_active',
        'is_deleted',
        'deleted_at',
    ];
}
