<?php

namespace App\Acl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function menus(): HasMany
    {
        // Menu model has $table = 'menu_items'
        return $this->hasMany(Menu::class)->orderBy('sort_order');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }

    public function scopeActive($query)
    {
        return $query->where('modules.is_active', true);
    }
}