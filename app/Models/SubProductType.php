<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubProductType extends Model
{
    use HasFactory;
    protected $table = "sub_product_types";
    protected $primaryKey = "id";

    const STATUS_ACTIVE   = 'A';
    const STATUS_INACTIVE = 'I';
    const STATUS_DELETED  = 'D';

    protected $fillable = [
        'product_type_id',
        'sub_product_type',
        'status',
    ];
}
