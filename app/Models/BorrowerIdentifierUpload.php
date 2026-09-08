<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerIdentifierUpload extends Model
{
    use HasFactory;
    protected $table = "borrower_identifier_uploads";
    protected $primaryKey = "id";

    protected $fillable = [
        'file_path',
        'uploaded_by',
        'approved_rejected_by',
        'upload_remark',
        'approval_remark',
        'failed_reason',
        'status',
        'row_status',
        'row_status_remark',
        'uploaded_at',
        'approved_rejected_at'
    ];

    protected $casts = [
        'failed_reason' => 'array'
    ];

    public function uploader()
    {
        return $this->belongsTo(AdminUser::class,'uploaded_by');
    }

    public function approver()
    {
        return $this->belongsTo(AdminUser::class,'approved_rejected_by');
    }
}
