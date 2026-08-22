@extends('layouts.auth')

@section('title', 'Set new password · CyberSec Platform')

@section('content')
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <label for="email" class="label">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email', $request->email ?? '') }}"
                   class="input @error('email') border-danger @enderror"
                   placeholder="you@example.com" required autofocus autocomplete="email">
            @error('email')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="label">New password</label>
            <input id="password" type="password" name="password"
                   class="input @error('password') border-danger @enderror"
                   placeholder="At least 8 characters" required autocomplete="new-password">
            @error('password')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password_confirmation" class="label">Confirm new password</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   class="input @error('password_confirmation') border-danger @enderror"
                   placeholder="Repeat password" required autocomplete="new-password">
            @error('password_confirmation')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>

        <button type="submit" class="btn-primary w-full">
            <span class="material-symbols-rounded text-base">lock_reset</span>
            Reset Password
        </button>

        @if (Route::has('login'))
            <p class="text-center text-sm text-gray-400 pt-2">
                <a href="{{ route('login') }}" class="text-cyan-400 hover:text-cyan-300">Back to sign in</a>
            </p>
        @endif
    </form>
@endsection
