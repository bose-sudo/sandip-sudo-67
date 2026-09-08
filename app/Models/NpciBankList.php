<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NpciBankList extends Model
{
    use HasFactory;
    protected $table = "npci_bank_lists";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "bank_id",
        "bank_name",
        "netbank_flag",
        "card_flag",
        "adhar_flag",
    ];
}
