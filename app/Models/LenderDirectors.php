<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LenderDirectors extends Model
{
    use HasFactory;
    protected $table = "lender_directors";
    protected $primaryKey = "id";

    protected $fillable = [
        'lender_id',
        'name',
        'email',
        'mobile',
        'user_id',
        'status', // active, inactive, deleted
        'auth_id', // admin users id
    ];

    public function lender()
    {
        return $this->belongsTo(Lenders::class, 'lender_id', 'id');
    }
}
