<?php

namespace App\Acl\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the incoming bulk user-permission override payload.
 *
 * Expected request body (JSON):
 * {
 *   "permission_ids": [1, 3, 7, 12],
 *   "action": "grant",           // "grant" | "deny" | "revoke"
 *   "reason": "Q4 audit access"  // optional, stored in audit log
 * }
 *
 * "grant"  → set allow = true  (override: user CAN, even if role says no)
 * "deny"   → set allow = false (override: user CANNOT, even if role says yes)
 * "revoke" → remove override entirely (falls back to role resolution)
 */
class BulkUserPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ensure the authenticated admin has permission to manage users
        return true; //$this->user()?->hasPermission('acl.user-permissions.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'permission_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'permission_ids.*' => ['required', 'integer', 'exists:permissions,id'],
            // 'action'           => ['required', Rule::in(['grant', 'deny', 'revoke'])],
            'reason'           => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'permission_ids.required'   => 'At least one permission must be selected.',
            'permission_ids.max'        => 'Cannot modify more than 500 permissions at once.',
            'permission_ids.*.exists'   => 'One or more selected permissions do not exist.',
            // 'action.in'                 => 'Action must be one of: grant, deny, revoke.',
        ];
    }
}
