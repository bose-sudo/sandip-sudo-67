<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserRelationship extends Model
{
    use HasFactory;

    protected $table = 'user_relationships';
    protected $primaryKey = 'id';

    protected $fillable = [
        'parent_user_id',
        'child_user_id',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'parent_user_id', 'id');
    }

    public function child(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'child_user_id', 'id');
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by', 'id');
    }
}
