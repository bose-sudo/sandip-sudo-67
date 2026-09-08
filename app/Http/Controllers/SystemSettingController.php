<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SystemSetting;
use App\Services\SettingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class SystemSettingController extends Controller
{
    use ApiResponse;
    public function __construct(protected SettingService $settings) {}

    // ─── GET /admin/settings/enach ────────────────────────────────────────────────
    //
    // Returns all settings in the 'enach' group.
    // Admin UI uses this to render the current state of the toggle.

    public function enach(): JsonResponse
    {
        $setting = SystemSetting::where('key', 'enach.auth_mode')
            ->first();

        if (!$setting) {
            return $this->send_response('error', "System setting not found.", 404);
        }

        $response = [
            'key'         => $setting->key,
            'label'       => $setting->label,
            // 'description' => $setting->description,
            // 'type'        => $setting->type,
            'value'       => $setting->value,
            // 'options'     => $setting->options,
        ];

        return $this->send_response(
            'success',
            "System setting fetched successfully.",
            200,
            $response
        );
    }

    // ─── PATCH /admin/settings/enach/auth-mode ────────────────────────────────────
    //
    // Switches eNACH auth mode between 'debit_card' and 'aadhaar'.
    // Admin sends: { "value": "aadhaar" } or { "value": "debit_card" }

    public function updateEnachAuthMode(): JsonResponse
    {
        $setting = SystemSetting::where('key', 'enach.auth_mode')->firstOrFail();

        // Convert options to array
        $options = $setting->options;

        if (is_string($options)) {
            $options = json_decode($options, true);
        }

        if (!is_array($options)) {
            $options = [];
        }

        // Toggle value
        $newValue = $setting->value === 'debit_card'
            ? 'aadhaar'
            : 'debit_card';

        // Validate toggled value exists in options
        if (!in_array($newValue, $options, true)) {
            return $this->send_response(
                'failed',
                "Invalid auth mode configuration.",
                422
            );
        }

        $updated = $this->settings->set('enach.auth_mode', $newValue);

        return $this->send_response(
            'success',
            "eNACH auth mode updated successfully.",
            200,
            [
                'key'   => $updated->key,
                'label' => $updated->label,
                'value' => $updated->value,
            ]
        );
    }

    // ─── Generic: GET /admin/settings ─────────────────────────────────────────────
    //
    // Returns all settings (paginated). Useful for a full settings admin panel later.

    public function index(): JsonResponse
    {
        $settings = SystemSetting::orderBy('group')
            ->orderBy('key')
            ->get()
            ->map(fn($s) => [
                'key'         => $s->key,
                'group'       => $s->group,
                'label'       => $s->label,
                'description' => $s->description,
                'type'        => $s->type,
                'value'       => $s->value,
                'options'     => $s->options,
                'is_public'   => $s->is_public,
            ]);

        return $this->send_response('success', "System settings fetched successfully.", 200, $settings);
    }

    // ─── Generic: PATCH /admin/settings/{key} ─────────────────────────────────────
    //
    // Updates any setting by key. Useful as a catch-all for future settings.
    // Key uses dot notation in URL as: enach.auth_mode → enach.auth_mode

    public function update(Request $request, string $key): JsonResponse
    {
        $setting = SystemSetting::where('key', $key)->firstOrFail();

        // Build validation rules dynamically based on setting type
        $valueRules = ['required'];

        $valueRules[] = match ($setting->type) {
            'integer' => 'integer',
            'float'   => 'numeric',
            'boolean' => 'boolean',
            'enum'    => Rule::in($setting->options ?? []),
            'json'    => 'array',
            default   => 'string',
        };

        $request->validate(['value' => $valueRules]);

        $updated = $this->settings->set($key, $request->value);

        return $this->send_response('success', "Setting '{$key}' updated successfully.", 200, [
            'key'   => $updated->key,
            'label' => $updated->label,
            'value' => $updated->value,
        ]);
    }
}
