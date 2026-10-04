<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LoanBasicDetails extends Model
{
    use HasFactory;
    protected $table = "loan_basic_details";
    protected $primaryKey = "id";
    
    protected $fillable = [
        'user_id',
        'borrower_id',
        'loan_application_steps',
        'preferred_language',
        'dealer_id',
        'model',
        'loan_amount',
        'loan_term',
        'status', // ['NEW', 'APPROVED', 'REJECTED', 'HOLD', 'AWAITED', 'INPROCESS', 'DISBURSED', 'CLOSED', 'INTRANSIT', 'SOFT_APPROVED', 'CREDITCHECK', 'FI', 'CAM_REPORT']
        'is_active',
        'loan_details_id',
        'borrower_details_id',
        'co_applicant_id',
        'guarantor_id',
        'deleted_at',
        'product_id',
        'emi_debit_consent',
        'lender_consent',
        'executive_id',
        'model_id',
        'admin_user_id',
        'down_payment',
        'lender_id',
        'skip_coapplicant_guarantor',
        'admin_remarks',
        'remarks_added_by',
        'skip_enach_step',
        'enach_step_skip_reason',
        'is_aa_skippable',
        'aa_skip_enabled_by',
        'aa_skip_enabled_at',
        'aa_skip_reason',
        'is_agreement_reviewed',
        'agreement_preview_remarks',

        'manual_override',
        'manual_override_at',
        'manual_override_by',
        'manual_override_reason',
    ];

    public function dealer()
    {
        return $this->hasOne(Dealer::class, 'dealer_unq', 'dealer_id')->latest();
    }

    public function aaSkipEnabledBy()
    {
        return $this->belongsTo(AdminUser::class, 'aa_skip_enabled_by', 'id');
    }
    
    // public function user()
    // {
    //     return $this->belongsTo(User::class, 'user_id', 'id');
    // }

    public function latestBorrowerKyc()
    {
        return $this->hasOne(BorrowerKyc::class, 'borrower_id', 'id')->latest();
    }

    public function latestBorrowerAddress()
    {
        return $this->hasOne(BorrowerAddress::class, 'borrower_id', 'id')->latest();
    }

    public function latestBorrowerOccupation()
    {
        return $this->hasOne(BorrowerOccupation::class, 'borrower_id', 'id')->latest();
    }

    public function latestCoapplicantKyc()
    {
        return $this->hasOne(CoapplicantKyc::class, 'borrower_id', 'id')->latest();
    }

    public function latestCoapplicantOccupation()
    {
        return $this->hasOne(CoapplicantOccupation::class, 'borrower_id', 'id')->latest();
    }

    public function latestGuarantor()
    {
        return $this->hasOne(Guarantor::class, 'borrower_id', 'id')->latest();
    }

    public function latestGuarantorOccupation()
    {
        return $this->hasOne(GuarantorOccupation::class, 'borrower_id', 'id')->latest();
    }
    
    public function latestCreditSummary()
    {
        return $this->hasOne(LoanApplicationSummary::class, 'borrower_id', 'id')
            ->whereRaw('loan_application_summaries.borrower_id = CAST(loan_basic_details.id AS TEXT)')
            ->orderByDesc('created_at');
    }

    public function latestCreditSummary2()
    {
        return $this->hasOne(
            LoanApplicationSummary::class,
            'borrower_id',
            'id'
        )->latest();
    }

    public function latestDocuments() {
        return $this->hasOne(Documents::class, 'borrower_id', 'id')->latest();
    }

    public function latestBankDetails() {
        return $this->hasOne(BorrowerAccountDetails::class, 'borrower_id', 'id')->latest();
    }

    
    public function borrowerAa()
    {
        return $this->hasOne(AaRequestDetails::class, 'borrower_id', 'id')
            ->where('applicant_type', 'BORROWER')
            ->latest('id')               // pick the most‑recent try
            ->withDefault(['aa_status' => 'PENDING']);
    }

    public function coapplicantAa()
    {
        return $this->hasOne(AaRequestDetails::class, 'borrower_id', 'id')
            ->where('applicant_type', 'COAPPLICANT')
            ->latest('id')
            ->withDefault(['aa_status' => 'PENDING']);
    }

    public function guarantorAa()
    {
        return $this->hasOne(AaRequestDetails::class, 'borrower_id', 'id')
            ->where('applicant_type', 'GUARANTOR')
            ->latest('id')
            ->withDefault(['aa_status' => 'PENDING']);
    }

    public function user_detail() {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function dealer_detail() {
        return $this->belongsTo(Dealer::class, 'dealer_id', 'dealer_unq');
    }

    // public function sales_executive()
    // {
    //     return $this->belongsTo(SalesExecutiveDealerGrouping::class, 'dealer_id', 'dealer_id')
    //                 ->where('status', 'active');
    // }
    

    // In LoanBasicDetails model
    public function lenderGroup(){
        return $this->hasOne(LenderBorrowerGroup::class, 'borrower_id', 'id');
    }

    // LenderBorrowerGroup model needs:
    public function lender(){
        return $this->belongsTo(Lenders::class, 'lender_id', 'id');
    }

    // latestLoanScheme model needs:
    public function latestLoanScheme(){
        return $this->hasOne(BorrowerLoanScheme::class, 'borrower_id', 'id')
                ->orderByDesc('id');
    }

    public function sanctionMail()
    {
        return $this->hasOne(TeleduceMailLog::class, 'reference', 'borrower_id')
                    ->from(DB::raw(
                        "(SELECT DISTINCT ON (REPLACE(reference, 'sanction-borrower-', '')) 
                                 id, 
                                 REPLACE(reference, 'sanction-borrower-', '') AS reference,
                                 created_at 
                          FROM teleduce_mail_logs 
                          WHERE reference LIKE 'sanction-borrower-%'
                          ORDER BY REPLACE(reference, 'sanction-borrower-', ''), created_at DESC
                         ) as teleduce_mail_logs"
                    ));
    }

    public function externalIdentifier() {
        return $this->hasOne(DealerUserMap::class, 'dealer_unq', 'dealer_id')->where('status', 'active')->orderByDesc('id');
    }

    public function firstRepaymentSchedule()
    {
        return $this->hasOne(BorrowerRepaymentSchedule::class, 'borrower_id', 'id')
                    ->oldestOfMany('id');
    }

    public function repaymentSchedules()
    {
        return $this->hasMany(BorrowerRepaymentSchedule::class, 'borrower_id', 'id')
                    ->orderBy('id', 'asc');
    }
    
    public function latestBorrowerReferenceDetails()
    {
        return $this->hasOne(BorrowerReferenceDetails::class, 'borrower_id', 'id')->latest();
    }

    public function latestReference(){
        return $this->hasOne(BorrowerReferenceDetails::class, 'borrower_id', 'id')
                ->orderByDesc('id');
    }

    public function latestDisbursement(){
        return $this->hasOne(DisbursementDetails::class, 'borrower_id', 'id')
                ->latest();
    }

    public function firstRepaymentSchedule2()
    {
        return $this->hasOne(BorrowerRepaymentSchedule::class, 'borrower_id', 'id')
                    ->orderBy('due_date', 'asc')
                    ->limit(1);
    }

    public function latestRepaymentSchedule()
    {
        return $this->hasOne(BorrowerRepaymentSchedule::class, 'borrower_id', 'id')
                    ->orderBy('due_date', 'desc')
                    ->limit(1);
    }

     public function currentLoanScheme(){
        return $this->hasOne(BorrowerLoanScheme::class, 'borrower_id', 'id')
                ->where('is_current', true)
                ->orderByDesc('id');
    }
}
