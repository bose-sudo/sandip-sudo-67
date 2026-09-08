<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerReferenceDetails extends Model
{
    use HasFactory;
    protected $table = "borrower_reference_details";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'borrower_id',
        'reference_1',
        'mobile_1',
        'relation_1',
        'address_1',
        'is_verified_mobile_1',
        'reference_2',
        'mobile_2',
        'relation_2',
        'address_2',
        'is_verified_mobile_2',
        'latitude_1',
        'longitude_1',
        'verification_url_1',
        'shortend_url_1',
        'is_verified_1',
        'latitude_2',
        'longitude_2',
        'verification_url_2',
        'shortend_url_2',
        'is_verified_2',
    ];
}
