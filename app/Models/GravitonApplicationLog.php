<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GravitonApplicationLog extends Model
{
    protected $table = 'graviton_application_logs';

    protected $fillable = [
        'borrower_id',
        'endpoint',
        'request_payload',
        'response_payload',
        'http_status',
        'graviton_client_id',
        'graviton_loan_id',
        'resource_id',
        'office_id',
        'status',
        'error_message',
        'validation_errors',
        'requested_at',
        'completed_at',
    ];

    protected $casts = [
        'request_payload'    => 'array',
        'response_payload'   => 'array',
        'validation_errors'  => 'array',
        'requested_at'       => 'datetime',
        'completed_at'       => 'datetime',
    ];

    // Relations
    public function borrower()
    {
        return $this->belongsTo(BorrowerKyc::class, 'borrower_id');
    }
}