<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use App\Acl\Traits\HasPermissions;

class AdminUser extends Authenticatable implements JWTSubject
{
    use HasFactory, HasPermissions;

    protected $table = 'admin_users';
    protected $primaryKey = 'id';

    protected $fillable = [
        'name', 'mobile', 'email', 'password',
        'email_verified_at', 'email_confirmation_code',
        'alternative_mobile_1', 'otp', 'department',
        'user_type', 'is_active', 'is_deleted',
        'is_all_borrower_accessible',
        'updated_by', 'deleted_at', 'auth_token', 'remarks',
    ];

    
    // ── Constants ─────────────────────────────────────────────────────────────

    const RSM_USER = 40;

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at'          => 'datetime',
        'password'                   => 'hashed',
        'is_active'                  => 'boolean',
        'is_deleted'                 => 'boolean',
        'is_all_borrower_accessible' => 'boolean',
    ];

    // ── JWT ──────────────────────────────────────────────────────────────────

    public function getJWTIdentifier(): mixed { return $this->getKey(); }
    public function getJWTCustomClaims(): array { return []; }

    // ── Existing ─────────────────────────────────────────────────────────────

    public function departmentRelation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(UserDepartments::class, 'department', 'id');
    }

    public function userTypeRelation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(UserType::class, 'user_type', 'id');
    }

    // ── Hierarchy ─────────────────────────────────────────────────────────────

    public function parentRelationships(): HasMany
    {
        return $this->hasMany(UserRelationship::class, 'child_user_id', 'id');
    }

    public function childRelationships(): HasMany
    {
        return $this->hasMany(UserRelationship::class, 'parent_user_id', 'id');
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(AdminUser::class, 'user_relationships', 'child_user_id', 'parent_user_id')
                    ->wherePivot('is_active', true);
    }

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(AdminUser::class, 'user_relationships', 'parent_user_id', 'child_user_id')
                    ->wherePivot('is_active', true);
    }

    public function closureAsAncestor(): HasMany
    {
        return $this->hasMany(UserHierarchyClosure::class, 'ancestor_id', 'id');
    }

    public function closureAsDescendant(): HasMany
    {
        return $this->hasMany(UserHierarchyClosure::class, 'descendant_id', 'id');
    }

    public function dealerMappings(): HasMany
    {
        return $this->hasMany(DealerUserMap::class, 'user_id', 'id');
    }

    public function activeDealerMappings(): HasMany
    {
        return $this->hasMany(DealerUserMap::class, 'user_id', 'id')->where('status', 'active');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Whether this user bypasses hierarchy and sees ALL borrowers.
     * This is the single check that should be used everywhere in the app.
     */
    public function hasFullBorrowerAccess(): bool
    {
        return (bool) $this->is_all_borrower_accessible;
    }

    public function getDescendantIds(): \Illuminate\Support\Collection
    {
        return UserHierarchyClosure::where('ancestor_id', $this->id)->pluck('descendant_id');
    }

    public function getAncestorIds(): \Illuminate\Support\Collection
    {
        return UserHierarchyClosure::where('descendant_id', $this->id)->pluck('ancestor_id');
    }
}
