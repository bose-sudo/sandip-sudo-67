<?php

namespace App\Support;

use Carbon\Carbon;

trait NormalizesEditValues
{
    /** DB value → comparable/storable string. */
    protected function stringifyValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    /** Stored string → typed value for the column. */
    protected function castValue(?string $value, string $cast)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($cast) {
            BorrowerEditFieldRegistry::CAST_INT     => (int) $value,
            BorrowerEditFieldRegistry::CAST_DECIMAL => (float) $value,
            BorrowerEditFieldRegistry::CAST_DATE    => Carbon::parse($value)->format('Y-m-d'),
            default                                 => $value,
        };
    }

    /** Normalise both sides before comparing, so 1000 vs 1000.00 isn't a false diff. */
    protected function isSameValue(?string $old, ?string $new, string $cast): bool
    {
        if ($cast === BorrowerEditFieldRegistry::CAST_DECIMAL
            || $cast === BorrowerEditFieldRegistry::CAST_INT) {
            return (float) $old === (float) $new;
        }
        if ($cast === BorrowerEditFieldRegistry::CAST_DATE) {
            return $this->castValue($old, $cast) === $this->castValue($new, $cast);
        }

        return trim((string) $old) === trim((string) $new);
    }
}