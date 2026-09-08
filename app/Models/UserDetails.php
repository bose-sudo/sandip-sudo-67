<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserDetails extends Model
{
    use HasFactory;
    protected $table = "user_details";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'user_id',
        'address',
        'pincode',
        'city',
        'state',
        'dob',
        'gender',
        'father_name',
        'no_of_dependents',
        'marital_status',
        'residence_status',
        'education',
        'occupation',
        'agree_for_bureau_check',
        'reference_name',
        'reference_number',
        'reference_relation',
    ];
}
