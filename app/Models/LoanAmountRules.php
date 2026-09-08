<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanAmountRules extends Model
{
    use HasFactory;
    protected $table = "loan_amount_rules";
    protected $primaryKey = "id";

    protected $fillable = [
        'product_type_id',
        'max_loan_amount',
        'min_loan_amount',
        'min_loan_tenure',
        'max_loan_tenure',
        'modified_by',
        'ip',
    ];

    /**
     * Relation with Admin User.
     */
    public function modifiedBy()
    {
        return $this->belongsTo(AdminUser::class, 'modified_by', 'id');
    }

    /**
     * Relation with Product type.
     */
    public function productType()
    {
        return $this->belongsTo(ProductTypes::class, 'product_type_id', 'id');
    }
}
