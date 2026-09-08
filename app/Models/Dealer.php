<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Dealer extends Model
{
    use HasFactory;
    protected $table = "dealers";
    protected $primaryKey = "id";

    protected $fillable = [
        'dealer_unq as dealer_id',
        'name',
        'is_active',
        'is_deleted',
        'delete_date_time',
        'modified_by',
        'dob',
        'mobile',
        'is_mobile_verified',
        'alternative_number',
        'email',
        'is_email_verified',
        'pan',
        'is_pan_verified',
        'aadhar',
        'landmark',
        'city',
        'pincode',
        'address_line_1',
        'address_line_2',
        'dealer_loi',
        'bank_statement',
        'cibil_report',
        'trade_certificate',
        'gst_certificate',
        'security_cheque_1',
        'security_cheque_2',
        'security_cheque_3',
        'security_cheque_4',
        'rent_agreement',
        'dealer_image_1',
        'dealer_image_2',
        'showroom_img_1',
        'showroom_img_2',
        'showroom_img_3',
        'showroom_img_4',
        'itr_file',
        'balance_sheet',
        'dealer_auth_id',
        'dealer_finayo_agreement',
        'dealer_type',
        'onboarding_fees',
        'payment_method',
        'payment_proof_img',
        'longitude',
        'latitude',
        'lat_long_image',
        'state',
        'oem_id',
        'lender_id',
    ];

    public function products()
    {
        return $this->hasMany(ProductModel::class, 'dealer_id', 'id');
    }

    public function designatedAuthorities()
    {
        return $this->hasMany(DealerDesignatedAuthority::class, 'dealer_id', 'id');
    }

    // Define the relationship with company documents
    public function companyDocuments()
    {
        return $this->hasOne(DealerCompanyDocuments::class, 'dealer_id', 'id');
    }

    public function lenderDealerProductCharges()
    {
        return $this->hasMany(LenderDealerProductCharges::class, 'dealer_id', 'id');
    }

    public function beneficiaryDetails()
    {
        return $this->hasOne(BeneficiaryDetails::class, 'user_id', 'id')
                    ->where('user_type', 'DEALER');
    }

    public function state()
    {
        return $this->belongsTo(StateMaster::class, 'state', 'id');
    }

    public function sales_executives()
    {
        return $this->belongsTo(SalesExecutiveDealerGrouping::class, 'id', 'dealer_id')
                    ->where('status', 'active');
    }

    public function oem()
    {
        return $this->belongsTo(Manufacturers::class, 'oem_id', 'id');
    }
    
    public function lender()
    {
        return $this->belongsTo(Lenders::class, 'lender_id', 'id');
    }

    public function accountAggregator()
    {
        return $this->hasOne(AaEntityRequestDetails::class, 'entity_id', 'id')
            ->where('entity_type', 'dealer')
            // ->where('aa_status', 'COMPLETED')
            ->orderByDesc('id'); // or created_at
    }

    public function disbursedLoans()
    {
        return $this->hasMany(LoanBasicDetails::class, 'dealer_id', 'dealer_unq')
                    ->where('status', 'DISBURSED');
    }
}
