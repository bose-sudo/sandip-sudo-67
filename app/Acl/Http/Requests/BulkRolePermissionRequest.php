<?php

namespace App\Acl\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the incoming bulk role-permission sync payload.
 *
 * Supports two modes, sent as separate endpoints:
 *
 * MODE A — sync (replace all):
 * POST /api/acl/admin/roles/{role}/permissions/sync
 * {
 *   "permission_ids": [1, 2, 3, 7]   // becomes the role's complete permission set
 * }
 *
 * MODE B — attach/detach specific:
 * POST /api/acl/admin/roles/{role}/permissions/attach
 * POST /api/acl/admin/roles/{role}/permissions/detach
 * {
 *   "permission_ids": [7, 12],
 *   "reason": "Adding payroll permissions for HR role"
 * }
 */
class BulkRolePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; //$this->user()?->hasPermission('acl.role-permissions.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'permission_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'permission_ids.*' => ['required', 'integer', 'exists:permissions,id'],
            'reason'           => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'permission_ids.required' => 'At least one permission must be selected.',
            'permission_ids.max'      => 'Cannot modify more than 500 permissions at once.',
            'permission_ids.*.exists' => 'One or more selected permissions do not exist.',
        ];
    }
}
