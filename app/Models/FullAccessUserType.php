<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FullAccessUserType extends Model
{
    use HasFactory;

    protected $table = 'full_access_user_types';

    protected $fillable = [
        'user_type_id',
        'granted_by',
    ];

    public function userType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(UserType::class, 'user_type_id', 'id');
    }

    public function grantedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'granted_by', 'id');
    }
}
