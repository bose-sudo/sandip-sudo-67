<?php
// app/Acl/Middleware/AutoCheckPermission.php

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

        // No named route → skip (or deny, your choice)
        if (! $routeName) {
            return $next($request);
        }

        // Skip ACL's own routes
        if (str_starts_with($routeName, 'acl.')) {
            return $next($request);
        }

        // If no permission registered for this route → pass through
        // (controlled by ACL_ALLOW_UNREGISTERED in config)
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

        abort(403);
    }
}