<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LenderDealerGroup extends Model
{
    use HasFactory;
    protected $table = "lender_dealer_groups";
    protected $primaryKey = "id";

    protected $fillable = [
        "lender_id",
        "dealer_id",
        "status",
        "remarks",
        "modified_by",
        "ip",
    ];

    /**
     * Relation with Admin User.
     */
    public function modifiedBy()
    {
        return $this->belongsTo(AdminUser::class, 'modified_by', 'id');
    }

    /**
     * Relation with Lenders.
     */
    public function lender()
    {
        return $this->belongsTo(Lenders::class, 'lender_id', 'id')
                    ->select(['id', 'lender_unq', 'name']);
    }

    /**
     * Relation with Dealers.
     */
    public function dealer()
    {
        return $this->belongsTo(Dealer::class, 'dealer_id', 'id')
                    ->select(['id', 'dealer_unq', 'name']);
    }
}
