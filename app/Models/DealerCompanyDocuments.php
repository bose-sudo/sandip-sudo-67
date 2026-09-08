<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealerCompanyDocuments extends Model
{
    use HasFactory;
    protected $table = "dealer_company_documents";
    protected $primaryKey = "id";

    protected $fillable = [
        "dealer_id",
        "company_type",
        "pan",
        "aadhar",
        "itr_file",
        "bank_statement",
        "icat_certificate",
        "rent_or_lease_agreement",
        "utility_bill",
        "manufacturing_license",
        "partnership_deed",
        "balance_sheet",
        "moa_and_aoa",
        "certificate_of_incorporation",
        "factory_address_proof",
        "aoa",
        "moa",
    ];

    public function dealer()
    {
        return $this->belongsTo(Dealer::class, 'dealer_id', 'id');
    }
}
