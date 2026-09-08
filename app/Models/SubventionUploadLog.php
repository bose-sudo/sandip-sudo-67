<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubventionUploadLog extends Model
{
    use HasFactory;
    protected $table = 'subvention_upload_logs';

    protected $fillable = [
        'file_path',
        'original_name',
        'uploaded_by',
        'uploaded_remarks',
        'ip',
        'status',
        'actioned_by',
        'action_remarks',
        'actioned_at',
        'action_ip',
        'total_rows',
        'success_rows',
        'failed_rows',
        'error_summary',
    ];

    protected $casts = [
        'actioned_at' => 'datetime',
    ];

    /**
     * Relation with Admin User (uploader).
     */
    public function uploadedBy()
    {
        return $this->belongsTo(AdminUser::class, 'uploaded_by', 'id');
    }

    /**
     * Relation with Admin User (approver / rejector).
     */
    public function actionedBy()
    {
        return $this->belongsTo(AdminUser::class, 'actioned_by', 'id');
    }
}
