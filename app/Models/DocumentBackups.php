<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentBackups extends Model
{
    use HasFactory;
    protected $table = "document_backups";
    protected $primaryKey = "id";

    protected $fillable = [
        "borrower_id",
        "flag_type",
        "doc_type",
        "previous_doc_url",
        "new_doc_url",
        "backup_file_url",
        "user_id",
        "admin_user_id",
        "executive_id",
        "status",
        "remark",
    ];
}
