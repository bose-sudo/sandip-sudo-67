<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaritalStatus extends Model
{
    use HasFactory;
    protected $table = "marital_statuses";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'status',
        'is_active',
        'is_deleted',
        'deleted_at',
    ];
}
