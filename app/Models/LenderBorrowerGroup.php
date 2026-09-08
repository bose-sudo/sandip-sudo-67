<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LenderBorrowerGroup extends Model
{
    use HasFactory;
    protected $table = "lender_borrower_groups";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "borrower_id",
        "lender_id",
        "status",
        "download_left",
        "remarks",
        "modified_by",
        "ip",
        "is_deleted",
        "deleted_at",
        "borrower_doc_download_left",
        "agreement_download_left",
        "cam_sheet_download_left",
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
}
