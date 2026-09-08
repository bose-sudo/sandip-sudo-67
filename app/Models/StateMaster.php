<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StateMaster extends Model
{
    use HasFactory;
    protected $table = "state_masters";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'state',
        'status',
        'user_id',
        'ip',
    ];

    /**
     * Relation with AdminUser (modified_by).
     * Returns only id, name, and email.
     */
    public function modifiedBy()
    {
        return $this->belongsTo(AdminUser::class, 'user_id', 'id')
            ->select(['id', 'name', 'email']);
    }
}
