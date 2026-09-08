<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanStepModificationLog extends Model
{
    use HasFactory;

    protected $table = 'loan_step_modification_logs';
    protected $primaryKey = 'lsml_id';

    public $timestamps = false;

    protected $fillable = [
        'borrower_id',
        'table_name',
        'data',
        'data_status',
        'created_by',
        'created_at',
        'modified_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'modified_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by', 'id');
    }
}
