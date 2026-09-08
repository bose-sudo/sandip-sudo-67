<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubMenuItem extends Model
{
    use HasFactory;
    protected $table = "sub_menu_items";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "name",
        "menu_id",
        "path",
    ];

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class, 'menu_id');
    }
}
