<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorise access based on the user's Spatie role(s).
 *
 * Usage in routes:
 *
 *   Route::middleware('role:admin')->group(...);
 *   Route::middleware('role:admin|auditor')->group(...);
 *
 * Multiple roles are accepted via the pipe `|` separator; the user must
 * hold **any** of the listed roles. The middleware is fail-closed:
 * unauthenticated requests are redirected to login, and authenticated
 * users lacking the role receive a 403 (so the platform never silently
 * leaks privileged content).
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        // Allow a single argument with pipe separators: role:admin|auditor.
        $allowed = collect($roles)
            ->flatMap(static fn (string $r): array => explode('|', $r))
            ->map(static fn (string $r): string => trim($r))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($allowed)) {
            // No role specified — nothing to enforce, treat as pass-through.
            return $next($request);
        }

        foreach ($allowed as $role) {
            if ($user->hasRole($role)) {
                return $next($request);
            }
        }

        abort(403, 'This action requires one of the following roles: '.implode(', ', $allowed));
    }
}
