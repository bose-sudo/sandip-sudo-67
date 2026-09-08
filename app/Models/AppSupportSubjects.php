<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppSupportSubjects extends Model
{
    use HasFactory;
    protected $table = "app_support_subjects";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "subject",
        "status",
        "ip",
        "updated_by",
    ];
}
