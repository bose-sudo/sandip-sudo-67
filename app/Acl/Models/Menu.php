<?php

namespace App\Acl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    protected $table      = 'menu_items';
    protected $primaryKey = 'id';

    protected $fillable = [
        'module_id',
        'parent_id',
        'name',
        'slug',
        'icon',
        'type',
        'route_name',
        'url',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Menu::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * Only sidebar-type permissions — used by MenuBuilderService.
     * Filters at relationship level so eager loads are automatically scoped.
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'menu_id')
                    ->where('permissions.type', 'sidebar');
    }

    /**
     * All permissions regardless of type.
     * Use in admin permission editor / PermissionListController.
     */
    public function allPermissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'menu_id');
    }

    public function isDropdown(): bool
    {
        return is_null($this->route_name);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('menu_items.is_active', true);
    }

    public function scopeSidebar($query)
    {
        return $query->where('menu_items.type', 'sidebar');
    }

    public function scopeHeader($query)
    {
        return $query->where('menu_items.type', 'header');
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('menu_items.parent_id');
    }
}