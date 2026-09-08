<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LspDirector extends Model
{
    use HasFactory;
    protected $table = "lsp_directors";
    protected $primaryKey = "id";

    protected $fillable = [
        'lsp_id',
        'name',
        'mobile',
        'alternative_mobile',
        'email',
        'aadhar',
        'pan',
    ];

    public function lsp()
    {
        return $this->belongsTo(LenderServiceProvider::class, 'lsp_id', 'id');
    }
}
