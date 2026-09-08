<?php

namespace App\Acl\Middleware;

use App\Acl\Services\PermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function __construct(protected PermissionResolver $resolver) {}

    /**
     * Usage on routes:
     *   ->middleware('role:3')
     *   ->middleware('role:1,3')   // any of these role IDs
     *
     * Note: uses role IDs, not slugs, since user_types table has no reliable slug.
     */
    public function handle(Request $request, Closure $next, int|string ...$roleIds): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->deny($request, 'Unauthenticated.');
        }

        // Cast all to int for comparison
        $roleIds = array_map('intval', $roleIds);

        if (in_array((int) $user->user_type, $roleIds, true)) {
            return $next($request);
        }

        return $this->deny($request, 'You do not have the required role.');
    }

    protected function deny(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], Response::HTTP_FORBIDDEN);
        }

        abort(403, $message);
    }
}