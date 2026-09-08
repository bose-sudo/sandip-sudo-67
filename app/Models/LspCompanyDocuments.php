<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LspCompanyDocuments extends Model
{
    use HasFactory;
    protected $table = "lsp_company_documents";
    protected $primaryKey = "id";

    protected $fillable = [
        "lsp_id",
        "company_type",
        "pan",
        "aadhar",
        "itr_file",
        "bank_statement",
        "rent_agreement",
        "utility_bill",
        "partnership_deed",
        "balance_sheet",
        "certificate_of_incorporation",
        "aoa",
        "moa",
    ];

    public function loanServiceProvider()
    {
        return $this->belongsTo(LoanServiceProvider::class, 'lsp_id', 'id');
    }
}
