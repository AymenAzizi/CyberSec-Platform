<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Self-service registration controller.
 *
 * The platform seeds its first administrator automatically: when zero
 * users exist, the next registration grants the `admin` role; every
 * subsequent registration gets the platform's default role (controlled
 * by `RBAC_DEFAULT_ROLE`, defaults to `analyst`).
 *
 * Passwords must be at least 12 characters long and contain at least
 * one character from each of the four character classes (uppercase,
 * lowercase, digit, symbol) — a baseline compliant with NIST 800-63B
 * guidance for memorised secrets.
 */
class RegisterController extends Controller
{
    public function showRegistrationForm(): View
    {
        return view('auth.register', ['pageTitle' => 'Create an account']);
    }

    /**
     * Persist a new user account. Route: `POST /register`. Also exposed
     * as `store()` to match the task spec's naming.
     */
    public function register(Request $request): RedirectResponse
    {
        return $this->store($request);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:180'],
            'email' => ['required', 'email', 'max:180', 'unique:users,email'],
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

        $user = DB::transaction(function () use ($validated) {
            $isAdminBootstrap = User::count() === 0;
            $roleName = $isAdminBootstrap
                ? 'admin'
                : (string) (env('RBAC_DEFAULT_ROLE') ?? 'analyst');

            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'is_active' => true,
                'quota_scans_per_day' => User::DEFAULT_QUOTA_SCANS_PER_DAY,
            ]);

            $user->assignRole($role);

            return $user;
        });

        AuditLogger::log($user, 'auth.register', $user, [
            'first_user' => User::count() === 1,
        ]);

        auth()->login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')
            ->with('status', __('Account created. Welcome to the platform.'));
    }
}
