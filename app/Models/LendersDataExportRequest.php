<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LendersDataExportRequest extends Model
{
    use HasFactory;
    protected $table = "lenders_data_export_requests";
    protected $primaryKey = "id";

    protected $fillable =  [
        "lender_id",
        "borrower_id",
        "lenders_message",
        "status",
        "request_user_ip",
        "additional_downloads",
        "modified_by",
        "admin_remarks",
        "response_user_ip",
        "borrower_doc_download",
        "agreement_download",
        "cam_sheet_download",
        "requested_by",
    ];

    /**
     * Relation with lender
     */
    public function lender()
    {
        return $this->belongsTo(Lenders::class, 'lender_id', 'id');
    }

    /**
     * Relation with Admin User.
     */
    public function modifiedBy()
    {
        return $this->belongsTo(AdminUser::class, 'modified_by', 'id');
    }

    /**
     * Relation with Admin User.
     */
    public function requestedBy()
    {
        return $this->belongsTo(AdminUser::class, 'requested_by', 'id');
    }
}
