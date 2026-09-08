<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Occupation extends Model
{
    use HasFactory;
    protected $table = "occupations";
    protected $primaryKey = "id";

    protected $fillable = [
        'occupation',
        'is_active',
        'is_deleted',
        'deleted_at',
    ];
}
