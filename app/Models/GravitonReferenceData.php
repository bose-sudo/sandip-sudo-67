<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GravitonReferenceData extends Model
{
    protected $table = 'graviton_reference_data';

    protected $fillable = [
        'borrower_id',
        'loan_template_data',
        'family_member_template_data',
        'credit_bureau_template_data',
        'repayment_every',
        'loan_term_frequency',
        'transaction_processing_strategy_id',
        'legal_form_id',
        'salutation_id',
        'document_type_id',
        'sync_status',
        'sync_error',
        'last_synced_at',
    ];

    protected $casts = [
        'loan_template_data'           => 'array',
        'family_member_template_data'  => 'array',
        'credit_bureau_template_data'  => 'array',
        'last_synced_at'               => 'datetime',
    ];

    // Relation
    public function borrower()
    {
        return $this->belongsTo(BorrowerKyc::class, 'borrower_id');
    }
}