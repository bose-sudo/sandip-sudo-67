<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModuleFunctionality extends Model
{
    use HasFactory;
    protected $table = 'module_functionalities';
    protected $primaryKey = 'mfunc_id';

    protected $fillable = [
        'name',
        'route_used',
        'description',
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

    public function makerCheckerAuthorizations()
    {
        return $this->hasMany(MakerCheckerAuthorization::class, 'mfunc_id', 'mfunc_id');
    }
}
