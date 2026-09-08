<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanServiceProvider extends Model
{
    use HasFactory;
    protected $table = "loan_service_providers";
    protected $primaryKey = "id";

    protected $fillable = [
        "lsp_unq",
        "name",
        "date_of_incorporation",
        "mobile",
        "is_mobile_verified",
        "alternative_number",
        "email",
        "is_email_verified",
        "pan",
        "is_pan_verified",
        "aadhar",
        "landmark",
        "city",
        "pincode",
        "address_line_1",
        "address_line_2",
        "bank_statement",
        "cibil_report",
        "trade_certificate",
        "gst_certificate",
        "security_cheque_1",
        "security_cheque_2",
        "security_cheque_3",
        "security_cheque_4",
        "rent_agreement",
        "lsp_finayo_agreement",
        "office_premise_img",
        "is_active",
        "is_deleted",
        "delete_date_time",
        "modified_by",
        "lsp_auth_id",
        "state",
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function lsp_directors()
    {
        return $this->hasMany(LspDirector::class, 'lsp_id', 'id');
    }

    // Define the relationship with company documents
    public function company_documents()
    {
        return $this->hasOne(LspCompanyDocuments::class, 'lsp_id', 'id');
    }

    public function state()
    {
        return $this->belongsTo(StateMaster::class, 'state', 'id');
    }
}
