<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanApplicationQuote extends Model
{
    use HasFactory;

    protected $table = "loan_application_quotes";
    protected $primaryKey = "id";

    protected $fillable = [
        'application_id',
        'product_id',
        'default_set_id',
        'dealer_set_id',
        'stage',
        'eligible',
        'facts',
        'resolved',
        'matched_rule_ids',
        'reasons',
    ];
    protected $casts = [
        'eligible' => 'boolean',
        'facts' => 'array',
        'resolved' => 'array',
        'matched_rule_ids' => 'array',
        'reasons' => 'array',
    ];
}
