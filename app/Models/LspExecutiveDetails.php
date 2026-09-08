<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Twilio\Rest\Accounts\V1\AuthTokenPromotionContext;
use Tymon\JWTAuth\Contracts\JWTSubject;

class LspExecutiveDetails extends Authenticatable implements JWTSubject
{
    use HasFactory;
    protected $table = "lsp_executive_details";
    protected $primaryKey = "id";

    protected $fillable = [
        'executive_id',
        'lsp_id',
        'date_of_joining',
        'city',
        'district',
        'pincode',
        'address',
        'alternative_number',
        'pan',
        'aadhar',
        'user_id',
        'name',
        'mobile',
        'email',
        'password',
        'is_active',
        'otp',
        'branch_id',
        'executive_type',
        'dealer_id',
        'state',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'password' => 'hashed',
    ];

    public function getJWTIdentifier() {
        return $this->getKey();
    }

    public function getJWTCustomClaims() {
        return [];
    }


    public function lsp()
    {
        return $this->belongsTo(LoanServiceProvider::class, 'lsp_id', 'id');
    }
    
    public function dealer()
    {
        return $this->belongsTo(Dealer::class, 'dealer_id', 'id');
    }
    
    // public function user() {
    //     return $this->belongsTo(AdminUser::class, 'executive_id', 'id');
    // }

    public function branchLocation()
    {
        return $this->belongsTo(BranchLocation::class, 'branch_id', 'id');
    }

    public function state()
    {
        return $this->belongsTo(StateMaster::class, 'state', 'id');
    }
}
