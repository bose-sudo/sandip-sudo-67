<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnachMandateFormData extends Model
{
    use HasFactory;
    protected $table = "enach_mandate_form_data";
    protected $primaryKey = "id";
    
    protected $fillable =  [
        'transaction_id',
        'payload',
        'status',
    ];
}
