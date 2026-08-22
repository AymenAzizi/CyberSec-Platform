<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session-based authentication for the platform's web UI.
 *
 * The login flow enforces a 5-attempts-per-IP rate limit (matched by
 * email + IP) to slow down credential-stuffing attacks, and records
 * the user's `last_login_at` / `last_login_ip` plus an audit-log entry
 * on every successful authentication. Inactive accounts are rejected
 * even when credentials match, so disabled users immediately lose access.
 */
class LoginController extends Controller
{
    /** Maximum failed login attempts per IP before throttling kicks in. */
    public const MAX_ATTEMPTS = 5;

    /** Throttle decay window in minutes. */
    public const DECAY_MINUTES = 1;

    /**
     * Show the login form.
     */
    public function showLoginForm(): View
    {
        return view('auth.login', ['pageTitle' => 'Sign in']);
    }

    /**
     * Authenticate an incoming login request.
     *
     * Route: `POST /login` — aliased as `authenticate()` for backwards
     * compatibility with the platform's earlier controller naming.
     *
     * @throws ValidationException
     */
    public function login(Request $request): RedirectResponse
    {
        return $this->authenticate($request);
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $this->ensureNotRateLimited($request);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! $user->is_active) {
            RateLimiter::hit($this->throttleKey($request), 60 * self::DECAY_MINUTES);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        if (! Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']], $request->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey($request), 60 * self::DECAY_MINUTES);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));
        $request->session()->regenerate();

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        AuditLogger::log($user, 'auth.login', $user, [
            'remember' => $request->boolean('remember'),
        ]);

        $request->session()->flash('status', __('Welcome back, :name.', ['name' => $user->name]));

        return $user->isAdmin()
            ? redirect()->intended(route('admin.system-health'))
            : redirect()->intended(route('dashboard'));
    }

    /**
     * Log the user out and invalidate the session.
     */
    public function logout(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user) {
            AuditLogger::log($user, 'auth.logout', $user);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Reject the request when the IP has exceeded the failed-attempt threshold.
     *
     * @throws ValidationException
     */
    protected function ensureNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => __('Too many login attempts. Please try again in :seconds seconds.', [
                'seconds' => $seconds,
            ]),
        ])->status(429);
    }

    /**
     * Build the throttle key: combines the email (lowercased) and IP so
     * that an attacker rotating emails from one IP still gets throttled,
     * but a legitimate user behind a shared NAT does not lock out others
     * with different emails.
     */
    protected function throttleKey(Request $request): string
    {
        return mb_strtolower((string) $request->input('email')).'|'.$request->ip();
    }
}
