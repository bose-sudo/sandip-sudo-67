<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Relations extends Model
{
    use HasFactory;
    protected $table = "relations";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'relation',
        'is_active',
        'is_deleted',
        'deleted_at',
    ];
}
