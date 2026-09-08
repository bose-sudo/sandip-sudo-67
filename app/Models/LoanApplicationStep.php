<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanApplicationStep extends Model
{
    use HasFactory;
    protected $table = 'loan_application_steps';
    protected $primaryKey = 'step_id';

    protected $fillable = [
        'step_flag',
        'step',
        'step_order',
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
}
