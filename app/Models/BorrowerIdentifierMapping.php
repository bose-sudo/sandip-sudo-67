<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerIdentifierMapping extends Model
{
    use HasFactory;
    protected $table = "borrower_identifier_mappings";
    protected $primaryKey = "id";

    protected $fillable = [
        'upload_id',
        'borrower_id',
        'identifier_id',
        'row_status'
    ];

    public function identifier()
    {
        return $this->belongsTo(BorrowerIdentifier::class,'identifier_id');
    }

    public function upload()
    {
        return $this->belongsTo(BorrowerIdentifierUpload::class,'upload_id');
    }
}
