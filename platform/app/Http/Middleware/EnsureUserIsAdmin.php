<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Simpler alternative to {@see RoleMiddleware} for admin-only routes.
 *
 * Usage: `Route::middleware('admin')`.
 *
 * Prefer `role:admin` for new routes since it is parameterised and
 * uniform; this middleware exists as an explicit, named shortcut for
 * admin-only endpoints (audit logs, user management, system health).
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        if (! $user->isAdmin()) {
            abort(403, 'Administrator access required.');
        }

        return $next($request);
    }
}
