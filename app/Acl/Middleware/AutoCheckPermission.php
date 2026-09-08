<?php

namespace App\Acl\Middleware;

use App\Acl\Services\PermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AutoCheckPermission
{
    public function __construct(protected PermissionResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No user → let auth middleware handle it
        if (! $user) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        // No named route → skip
        if (! $routeName) {
            return $next($request);
        }

        // Skip ACL's own routes
        if (str_starts_with($routeName, 'acl.')) {
            return $next($request);
        }

        // resolveByRoute handles everything:
        // 1. Looks up permission slug by route_name from permissions table
        // 2. Checks user's resolved permissions
        // 3. Falls back to ACL_ALLOW_UNREGISTERED config if no permission found
        if (! $this->resolver->resolveByRoute($user, $routeName)) {
            return $this->deny($request);
        }

        return $next($request);
    }

    protected function deny(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        abort(403, 'You do not have permission to perform this action.');
    }
}