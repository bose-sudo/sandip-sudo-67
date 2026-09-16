<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GravitonReferenceData extends Model
{
    protected $table = 'graviton_reference_data';

    protected $fillable = [
        'type', 'graviton_id', 'code', 'label', 'position',
        'is_active', 'is_mandatory', 'parent_graviton_id',
        'raw_response', 'synced_at',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'is_mandatory' => 'boolean',
        'raw_response' => 'array',
        'synced_at'    => 'datetime',
    ];

    /**
     * Lookup graviton_id by type + label/code
     */
    public static function lookup(string $type, ?string $key): ?int
    {
        if (empty($key)) return null;

        return static::query()
            ->where('type', $type)
            ->where(function ($q) use ($key) {
                $q->where('label', $key)->orWhere('code', $key);
            })
            ->where('is_active', true)
            ->value('graviton_id');
    }
}