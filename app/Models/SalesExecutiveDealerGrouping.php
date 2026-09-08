<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesExecutiveDealerGrouping extends Model
{
    use HasFactory;
    protected $table = "sales_executive_dealer_groupings";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'executive_id', 
        'dealer_id', 
        'status', 
        'modified_by', 
        'ip'
    ];

    public function executive()
    {
        return $this->belongsTo(AdminUser::class, 'executive_id');
    }
    
    public function dealer()
    {
        return $this->belongsTo(Dealer::class, 'dealer_id');
    }
    
    public function modified_by_user()
    {
        return $this->belongsTo(AdminUser::class, 'modified_by');
    }
}
