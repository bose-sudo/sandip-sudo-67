<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealerDesignatedAuthority extends Model
{
    use HasFactory;
    protected $table = "dealer_designated_authorities";
    protected $primaryKey = "id";

    protected $fillable = [
        "dealer_id",
        "name",
        "mobile",
        "alternative_mobile",
        "aadhar",
        "pan",
        "bank_statement",
        "itr_file",
        "photo",
    ];

    public function dealer()
    {
        return $this->belongsTo(Dealer::class, 'dealer_id', 'id');
    }
}
