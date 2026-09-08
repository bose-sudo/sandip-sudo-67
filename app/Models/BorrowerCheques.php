<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerCheques extends Model
{
    use HasFactory;
    protected $table = "borrower_cheques";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'borrower_id',
        'file',
        'cheque_no',
        'ifsc',
        'account_number',
        'is_used',
        'used_on',
        'remarks',
        'status',
        'ip',
        'modified_by',
    ];

    public function modifiedBy()
    {
        return $this->belongsTo(AdminUser::class, 'modified_by', 'id');
    }
}
