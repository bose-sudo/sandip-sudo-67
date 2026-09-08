<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Languages extends Model
{
    use HasFactory;
    protected $table = "languages";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'language',
        'is_active',
        'is_deleted',
        'deleted_at',
    ];
}
