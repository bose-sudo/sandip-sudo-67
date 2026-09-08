<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AaLenderCode extends Model
{
    use HasFactory;
    protected $table = "aa_lender_codes";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'lender_id',
        'key',
        'env',
        'url',
    ];
}
