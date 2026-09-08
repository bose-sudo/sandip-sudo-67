<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MakerCheckerAuthorization extends Model
{
    use HasFactory;
    protected $table = 'maker_checker_authorizations';
    protected $primaryKey = 'mca_id';

    protected $fillable = [
        'mfunc_id',
        'maker_id',
        'r1_id',
        'r2_id',
        'created_by',
        'updated_by',
        'row_status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('row_status', 'A');
    }

    public function moduleFunctionality()
    {
        return $this->belongsTo(ModuleFunctionality::class, 'mfunc_id', 'mfunc_id');
    }

    public function maker()
    {
        return $this->belongsTo(AdminUser::class, 'maker_id', 'id');
    }

    public function reviewer1()
    {
        return $this->belongsTo(AdminUser::class, 'r1_id', 'id');
    }

    public function reviewer2()
    {
        return $this->belongsTo(AdminUser::class, 'r2_id', 'id');
    }
}
