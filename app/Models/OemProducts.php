<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OemProducts extends Model
{
    use HasFactory;
    protected $table = "oem_products";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "oem_id",
        "oem_product_unq",
        "product_name",
        "battery_type",
        "ex_showroom_price",
        "loan_to_value_ratio",
        "min_loan_amount",
        "max_loan_amount",
        "product_type",
        "interest_rate",
        "processing_fee",
        "subvention_amount",
        "fldg_amount",
        "iot_amount",
        "insurance_1st_year",
        "insurance_2nd_year",
        "status",
        "admin_remarks",
        "modified_by",
        "ip",
        "min_loan_tenure",
        "max_loan_tenure",
    ];

    /**
     * Relation with AdminUser (modified_by).
     * Returns only id, name, and email.
     */
    public function modifiedBy()
    {
        return $this->belongsTo(AdminUser::class, 'modified_by', 'id')
            ->select(['id', 'name', 'email']);
    }

    /**
     * Relation with Manufacturers (oem_id).
     * Returns all fields for the manufacturer.
     */
    public function manufacturer()
    {
        return $this->belongsTo(Manufacturers::class, 'oem_id', 'id')
                        ->select(['id', 'manufacturer']);
    }
}
