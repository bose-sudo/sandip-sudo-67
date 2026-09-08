<?php

namespace App\Acl\Traits;

use App\Acl\Services\PermissionResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Add this trait to your base Controller.
 *
 * class Controller extends BaseController {
 *     use AuthorizesPermissions;
 * }
 *
 * Then in any controller method:
 *   $this->authorizePermission('employee.delete');
 *   $this->authorizeAnyPermission(['employee.edit', 'employee.delete']);
 */
trait AuthorizesPermissions
{
    /**
     * Abort with 403 if the authenticated user lacks the given permission slug.
     */
    protected function authorizePermission(string $slug, ?string $message = null): void
    {
        $user = request()->user();

        if (! $user || ! app(PermissionResolver::class)->resolve($user, $slug)) {
            throw new AccessDeniedHttpException(
                $message ?? "Permission denied: {$slug}"
            );
        }
    }

    /**
     * Abort with 403 if the user doesn't have ANY of the listed slugs.
     */
    protected function authorizeAnyPermission(array $slugs, ?string $message = null): void
    {
        $user = request()->user();
        $resolver = app(PermissionResolver::class);

        if (! $user) {
            throw new AccessDeniedHttpException('Unauthenticated.');
        }

        foreach ($slugs as $slug) {
            if ($resolver->resolve($user, $slug)) {
                return;
            }
        }

        throw new AccessDeniedHttpException(
            $message ?? 'Permission denied: none of [' . implode(', ', $slugs) . ']'
        );
    }

    /**
     * Abort with 403 if user doesn't have ALL of the listed slugs.
     */
    protected function authorizeAllPermissions(array $slugs, ?string $message = null): void
    {
        $user = request()->user();
        $resolver = app(PermissionResolver::class);

        if (! $user) {
            throw new AccessDeniedHttpException('Unauthenticated.');
        }

        foreach ($slugs as $slug) {
            if (! $resolver->resolve($user, $slug)) {
                throw new AccessDeniedHttpException(
                    $message ?? "Permission denied: {$slug}"
                );
            }
        }
    }

    /**
     * Return boolean without aborting (useful for conditional logic).
     */
    protected function userCan(string $slug): bool
    {
        $user = request()->user();
        return $user && app(PermissionResolver::class)->resolve($user, $slug);
    }
}
