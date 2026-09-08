<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LendersDataExportLimit extends Model
{
    use HasFactory;

    protected $table = "lenders_data_export_limits";
    protected $primaryKey = "id";

    protected $fillable = [
        'lender_id',
        'borrower_id',
        'file_type',
        'export_limit',
        'exports_used',
        'export_by',
        'exported_at',
        'exporter_ip',
        'limit_increased_by',
        'limit_increased_at',
        'increaser_ip',
    ];
}
