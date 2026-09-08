<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LspExecutiveDealerGrouping extends Model
{
    use HasFactory;
    protected $table = "lsp_executive_dealer_groupings";
    protected $primaryKey = "id";

    protected $fillable = [
        'executive_id',
        'dealer_id',
        'user_id',
    ];

    public function dealer()
    {
        return $this->belongsTo(Dealer::class, 'dealer_id', 'id');
    }
}
