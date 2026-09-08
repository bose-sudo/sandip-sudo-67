<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AaEntityApiLog extends Model
{
    use HasFactory;
    protected $table = 'aa_entity_api_logs';
    protected $primaryKey = 'id';

    protected $fillable = [
        'entity_id',
        'entity_type',
        'lender_id',
        'api_flag',
        'request',
        'response',
        'status',
        'created_by',
        'created_by_ip',
        'date_time',
    ];

    protected $casts = [
        'request' => 'array',
    ];

    public function lender()
    {
        return $this->belongsTo(Lenders::class, 'lender_id');
    }

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
