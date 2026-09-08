<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    use HasFactory;
    protected $table = "modules";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "name",
        "priority",
    ];

    public function menuItems()
    {
        return $this->hasMany(MenuItem::class, 'module_id');
    }
}
