<?php
declare(strict_types=1);

namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class DeliveryOrder extends Model
{
    use HasFactory;
    use HasUuids;
    protected $table = 'delivery_orders';
    protected $primaryKey = 'id';
    protected $fillable = [
        'uuid',
        'lender_id',
        'borrower_id',
        'application_no',
        'lender_name',
        'dealer_id',
        'do_number',
        'do_date',
        'ex_showroom_cost',
        'loan_amount',
        'down_payment',
        'roi',
        'roi_type',
        'tenure',
        'emi',
        'file_charge',
        'disbursement_amount',
        'borrower_name',
        'borrower_email',
        'dealer_name',
        'dealer_email',
        'file_path',
        'status',
        'sent_at',
        'mail_response',
        'payload',
        'generated_by',
        'ip',
        'access_token',
    ];
    protected $casts = [
        'do_date'             => 'date',
        'ex_showroom_cost'    => 'decimal:2',
        'loan_amount'         => 'decimal:2',
        'down_payment'        => 'decimal:2',
        'emi'                 => 'decimal:2',
        'file_charge'         => 'decimal:2',
        'disbursement_amount' => 'decimal:2',
        'payload'             => 'array',
        'mail_response'       => 'array',
        'sent_at'             => 'datetime',
    ];
    /**
     * Tell HasUuids to fill the `uuid` column, not the `id` PK.
     * `id` stays a normal auto-increment integer.
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }
    /** Route-model binding on preview uses the uuid, never the numeric id. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}