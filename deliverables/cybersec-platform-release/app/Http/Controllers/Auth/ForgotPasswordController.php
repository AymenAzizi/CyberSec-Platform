<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * "Forgot password" flow — sends a signed reset link to the user's email.
 *
 * The broker is configured in `config/auth.php` and stores tokens in the
 * `password_reset_tokens` table (created by the users migration). The
 * controller is intentionally lightweight: the heavy lifting is delegated
 * to Laravel's Password broker, which handles token generation, throttling
 * (60s between sends) and the actual notification email.
 */
class ForgotPasswordController extends Controller
{
    public function showLinkRequestForm(): View
    {
        return view('auth.forgot-password', ['pageTitle' => 'Reset your password']);
    }

    public function sendResetLinkEmail(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:180'],
        ]);

        $status = Password::broker()->sendResetLink($validated);

        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', __($status))
            : back()->withInput($validated)->withErrors(['email' => __($status)]);
    }
}
