<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Password reset controller — validates the reset token and updates
 * the user's password.
 *
 * Tokens are single-use and expire after 60 minutes (per `config/auth.php`).
 * The new password must meet the same complexity requirements as
 * registration (min 12 chars + 4 character classes) to keep the floor
 * uniform across all credential-setting flows.
 *
 * Route: `POST /password/reset` — also exposed as `update()` to match
 * the task spec's naming.
 */
class ResetPasswordController extends Controller
{
    public function showResetForm(Request $request, ?string $token = null): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->input('email'),
            'pageTitle' => 'Set a new password',
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        return $this->update($request);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:180'],
            'password' => [
                'required',
                'string',
                'min:12',
                'max:128',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
            ],
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) use ($request): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));

                AuditLogger::log($user, 'auth.password_reset', $user);
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __($status))
            : back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
    }
}
