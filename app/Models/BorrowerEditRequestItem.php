<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerEditRequestItem extends Model
{
    use HasFactory;

    protected $table = 'borrower_edit_request_items';
    protected $primaryKey = 'beri_id';

    protected $fillable = [
        'ber_id', 'borrower_id', 'section', 'field_key', 'table_name', 'record_id',
        'column_name', 'old_value', 'new_value', 'applied_old_value',
        'apply_status', 'applied_at', 'applied_by',
    ];

    protected $casts = ['applied_at' => 'datetime'];

    public function request()
    {
        return $this->belongsTo(BorrowerEditRequest::class, 'ber_id', 'ber_id');
    }

    public function appliedBy()
    {
        return $this->belongsTo(AdminUser::class, 'applied_by', 'id');
    }
}