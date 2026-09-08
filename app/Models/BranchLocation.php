<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BranchLocation extends Model
{
    use HasFactory;
    protected $table = "branch_locations";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "location_name",
        "updated_by",
        "is_active",
        "is_deleted",
        "deleted_at",
    ];
}
