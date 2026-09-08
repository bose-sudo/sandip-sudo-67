<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use HasFactory;
    protected $table = "menu_items";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "name",
        "module_id",
    ];

    public function module()
    {
        return $this->belongsTo(Module::class, 'module_id');
    }

    public function subMenuItems()
    {
        return $this->hasMany(SubMenuItem::class, 'menu_id');
    }
}
