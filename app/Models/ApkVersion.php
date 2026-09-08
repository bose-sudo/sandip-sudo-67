<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApkVersion extends Model
{
    use HasFactory;
    protected $table = "apk_versions";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'file_name',
        'file_path',
        'version',
    ];
}
