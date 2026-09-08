<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerIdentifier extends Model
{
    use HasFactory;
    protected $table = "borrower_identifiers";
    protected $primaryKey = "id";

    protected $fillable = [
        'identifier_name',
        'users'
    ];

    protected $casts = [
        'users' => 'array'
    ];

    public function mappings()
    {
        return $this->hasMany(BorrowerIdentifierMapping::class,'identifier_id');
    }
}
