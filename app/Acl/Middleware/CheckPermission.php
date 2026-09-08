<?php

namespace App\Acl\Middleware;

use App\Acl\Services\PermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function __construct(protected PermissionResolver $resolver) {}

    /**
     * Handle an incoming request.
     *
     * Usage on routes:
     *   ->middleware('permission:employee.edit')
     *   ->middleware('permission:employee.edit,employee.create')  // any of these
     */
    public function handle(Request $request, Closure $next, string ...$slugs): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->deny($request, 'Unauthenticated.');
        }

        // Multiple slugs = require ANY one of them
        foreach ($slugs as $slug) {
            if ($this->resolver->resolve($user, $slug)) {
                return $next($request);
            }
        }

        return $this->deny($request, 'You do not have permission to perform this action.');
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
