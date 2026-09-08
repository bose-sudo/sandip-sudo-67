<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'group',
        'value',
        'type',
        'options',
        'label',
        'description',
        'is_public',
    ];
 
    protected $casts = [
        'options'   => 'array',
        'is_public' => 'boolean',
    ];
 
    // ─── Auto-cast value to the correct PHP type on read ─────────────────────────
 
    public function getValueAttribute(mixed $raw): mixed
    {
        return match ($this->type) {
            'integer' => (int) $raw,
            'float'   => (float) $raw,
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode($raw, true),
            default   => $raw, // string, enum → return as-is
        };
    }
 
    // ─── Normalize value to string for storage ────────────────────────────────────
 
    public function setValueAttribute(mixed $value): void
    {
        $this->attributes['value'] = is_array($value)
            ? json_encode($value)
            : (string) $value;
    }
 
    // ─── Scopes ───────────────────────────────────────────────────────────────────
 
    public function scopeGroup(Builder $query, string $group)
    {
        return $query->where('group', $group);
    }
 
    public function scopePublic(Builder $query)
    {
        return $query->where('is_public', true);
    }
}
