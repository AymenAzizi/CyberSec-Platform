<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        // The api.php routes already declare the 'api' prefix explicitly so
        // the framework's automatic prefix is disabled to avoid double-prefixing.
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Spatie permission middleware aliases — referenced by routes via
        // `->middleware('role:admin')`, `->middleware('permission:...')`,
        // and `->middleware('role_or_permission:...')`.
        //
        // A separate `admin` alias is registered for the platform's
        // simpler EnsureUserIsAdmin middleware (used as a shortcut for
        // admin-only endpoints like user management + system health).
        $middleware->alias([
            'role'                => RoleMiddleware::class,
            'permission'          => PermissionMiddleware::class,
            'role_or_permission'  => RoleOrPermissionMiddleware::class,
            'admin'               => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);

        // Append the sidebar-counter sharing middleware to the web group
        // so every authenticated view receives the alert badge counts.
        $middleware->appendToGroup('web', \App\Http\Middleware\ShareSidebarCounters::class);

        // Trust the Next.js reverse proxy so Laravel uses the correct
        // host/scheme from X-Forwarded-* headers (preview deployment).
        $middleware->trustProxies(at: '*');

        // Redirect unauthenticated browser sessions to the login page.
        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
