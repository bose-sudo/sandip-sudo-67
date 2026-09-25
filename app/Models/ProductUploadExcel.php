<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductUploadExcel extends Model
{
    use HasFactory;
    protected $table = "product_upload_excels";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'file_name',
        'status',
        'reason',
        'uploaded_by',
        'updated_by',
        'errors',
        'file_path',
        'approve_multi',
    ];
}
