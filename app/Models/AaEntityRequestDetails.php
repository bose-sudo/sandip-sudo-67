<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AaEntityRequestDetails extends Model
{
    use HasFactory;

    protected $table = 'aa_entity_request_details';
    protected $primaryKey = 'id';

    protected $fillable = [
        'entity_id',
        'entity_type',
        'lender_id',
        'mobile',
        'pan_no',
        'fip_ids',
        'tracking_id',
        'reference_id',
        'aa_status',
        'created_by',
        'created_by_ip',
        'input_data',
    ];

    protected $casts = [
        'input_data' => 'array',
    ];
 
    // ── Relationships ──────────────────────────────────────────────

    /**
     * Polymorphic-style helper: resolve the actual entity model.
     * Usage: $record->entity  (returns Dealer|null for now)
     */
    public function getEntityAttribute(): ?Model
    {
        return match (strtolower($this->entity_type)) {
            'dealer' => Dealer::find($this->entity_id),
            default  => null,
        };
    }

    public function lender()
    {
        return $this->belongsTo(Lenders::class, 'lender_id');
    }

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
