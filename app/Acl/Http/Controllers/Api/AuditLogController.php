<?php

namespace App\Acl\Http\Controllers\Api;

use App\Acl\Models\PermissionAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AuditLogController extends Controller
{
    /**
     * GET /api/acl/admin/audit-logs
     *
     * Query params:
     *   ?subject_type=user&subject_id=5
     *   ?subject_type=role&subject_id=2
     *   ?performed_by=1
     *   ?from=2024-01-01&to=2024-12-31
     *   ?per_page=25
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'subject_type' => ['nullable', 'in:user,role'],
            'subject_id'   => ['nullable', 'integer'],
            'performed_by' => ['nullable', 'integer'],
            'from'         => ['nullable', 'date'],
            'to'           => ['nullable', 'date'],
            'per_page'     => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $logs = PermissionAuditLog::with('performer:id,name,email')
            ->when($request->subject_type, fn ($q) => $q->where('subject_type', $request->subject_type))
            ->when($request->subject_id,   fn ($q) => $q->where('subject_id',   $request->subject_id))
            ->when($request->performed_by, fn ($q) => $q->where('performed_by', $request->performed_by))
            ->when($request->from,         fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to,           fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'data'    => $logs,
        ]);
    }
}
