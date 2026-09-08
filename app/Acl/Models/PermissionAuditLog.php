<?php

namespace App\Acl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PermissionAuditLog extends Model
{
    protected $fillable = [
        'subject_type',
        'subject_id',
        'action',
        'permission_slugs',
        'allow',
        'performed_by',
        'reason',
        'previous_state',
        'new_state',
    ];

    protected $casts = [
        'permission_slugs' => 'array',
        'previous_state'   => 'array',
        'new_state'        => 'array',
        'allow'            => 'boolean',
    ];

    public function performer(): BelongsTo
    {
        return $this->belongsTo(
            config('acl.user_model', \App\Models\User::class),
            'performed_by'
        );
    }
}
