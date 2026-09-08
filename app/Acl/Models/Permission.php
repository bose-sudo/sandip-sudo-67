<?php

namespace App\Acl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = [
        'module_id',
        'menu_id',
        'name',
        'slug',
        'route_name',
        'method',
        'type',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_permission',  // pivot table
            'permission_id',    // FK for Permission in pivot
            'role_id',          // FK for Role (user_types) in pivot
            'id',               // local key on permissions
            'id'                // related key on user_types
        )->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            config('acl.user_model', \App\Models\AdminUser::class),
            'user_permissions',
            'permission_id',    // FK for Permission in pivot
            'user_id',          // FK for AdminUser in pivot
            'id',
            'id'
        )->withPivot(['allow', 'reason', 'granted_by'])
         ->withTimestamps();
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    // Fully qualified table name to avoid ambiguous column errors in PostgreSQL
    public function scopeActive($query)
    {
        return $query->where('permissions.is_active', true);
    }

    public function scopeBySlug($query, string $slug)
    {
        return $query->where('permissions.slug', $slug);
    }

    public function scopeByRoute($query, string $routeName)
    {
        return $query->where('permissions.route_name', $routeName);
    }

    public function scopeSidebar($query)
    {
        return $query->where('permissions.type', 'sidebar');
    }

    public function scopeHeader($query)
    {
        return $query->where('permissions.type', 'header');
    }
}