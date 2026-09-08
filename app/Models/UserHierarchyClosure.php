<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserHierarchyClosure extends Model
{
    protected $table = 'user_hierarchy_closure';

    // Composite PK — no auto-increment
    public $incrementing = false;
    public $timestamps   = false;

    protected $fillable = [
        'ancestor_id',
        'descendant_id',
        'depth',
    ];

    protected $casts = [
        'depth' => 'integer',
    ];

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * All descendants of a given user (including self at depth 0).
     */
    public function scopeDescendantsOf($query, int $userId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('ancestor_id', $userId);
    }

    /**
     * All ancestors of a given user (including self at depth 0).
     */
    public function scopeAncestorsOf($query, int $userId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('descendant_id', $userId);
    }

    /**
     * Only direct parent→child edges (depth = 1).
     */
    public function scopeDirect($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('depth', 1);
    }
}
