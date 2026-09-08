<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DisbursementDetailsExcel extends Model
{
    use HasFactory;
    protected $table = 'disbursement_details_excels';
    protected $primaryKey = "id";

    protected $fillable = [   
        "file_name",
        "status",
        "reason",
        "uploaded_by",
        "updated_by",
        "errors",
        "file_path",
    ];
}
