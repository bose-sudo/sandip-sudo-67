<?php

namespace App\Services;

use App\Models\SystemSetting;
use InvalidArgumentException;

class SettingService
{
    public function get(string $key, mixed $default = null): mixed
    {
        $setting = SystemSetting::where('key', $key)->first();

        return $setting?->value ?? $default;
    }

    public function group(string $group): array
    {
        return SystemSetting::where('group', $group)
            ->get()
            ->mapWithKeys(fn($s) => [$s->key => $s->value])
            ->toArray();
    }

    public function set(string $key, mixed $value): SystemSetting
    {
        $setting = SystemSetting::where('key', $key)->firstOrFail();

        if ($setting->type === 'enum') {

            // Convert JSON string options to array
            $allowed = $setting->options;

            if (is_string($allowed)) {
                $allowed = json_decode($allowed, true);
            }

            if (!is_array($allowed)) {
                $allowed = [];
            }

            if (!in_array($value, $allowed, true)) {
                throw new InvalidArgumentException(
                    "Invalid value '{$value}' for setting '{$key}'. Allowed: " . implode(', ', $allowed)
                );
            }
        }

        $setting->update([
            'value' => $value
        ]);

        return $setting->fresh();
    }
}