<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealerShops extends Model
{
    use HasFactory;
    protected $table = "dealer_shops";
    protected $primaryKey = "id";

    protected $fillable = 
    [
        "dealer_id",
        "shop_name",
        "longitude",
        "latitude",
        "lat_long_image",
        "status",  // [active, inactive, deleted]
        "modified_by",
        "ip",
        "address",
    ];

    /**
     * Relation with Admin User.
     */
    public function modifiedBy()
    {
        return $this->belongsTo(AdminUser::class, 'modified_by', 'id');
    }
}
