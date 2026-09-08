<?php

namespace App\Acl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $table      = 'user_types';
    protected $primaryKey = 'id';

    protected $fillable = [
        'user_type',
        'role',
        'updated_by',
        'is_active',
        'is_deleted',
        'deleted_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_permission',
            'role_id',
            'permission_id',
            'id',
            'id'
        )->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            config('acl.user_model', \App\Models\AdminUser::class),
            'user_roles',
            'role_id',
            'user_id',
            'id',
            'id'
        )->withTimestamps();
    }

    /**
     * Get IDs of all admin_users belonging to this role.
     * Uses direct DB query — no BelongsToMany needed since
     * admin_users.user_type is the FK (not a pivot table).
     */
    public function getUserIds(): array
    {
        return DB::table('admin_users')
            ->where('user_type', $this->id)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function syncPermissionsByIds(array $permissionIds): void
    {
        $this->permissions()->sync($permissionIds);
    }

    // Use fully-qualified table name to avoid ambiguous column in PostgreSQL
    public function scopeActive($query)
    {
        return $query->where('user_types.is_active', true);
    }
}