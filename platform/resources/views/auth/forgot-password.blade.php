@extends('layouts.auth')

@section('title', 'Reset password · CyberSec Platform')

@section('content')
    <p class="text-sm text-gray-400 mb-5">
        Enter the email address associated with your account and we will send you a password reset link.
    </p>

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="label">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="input @error('email') border-danger @enderror"
                   placeholder="you@example.com" required autofocus autocomplete="email">
            @error('email')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>

        @if (session('status'))
            <div class="card border-success/30 bg-success/10 px-4 py-3 text-sm text-emerald-200 flex items-center gap-2">
                <span class="material-symbols-rounded text-success">check_circle</span>
                {{ session('status') }}
            </div>
        @endif

        <button type="submit" class="btn-primary w-full">
            <span class="material-symbols-rounded text-base">mail</span>
            Send Reset Link
        </button>

        @if (Route::has('login'))
            <p class="text-center text-sm text-gray-400 pt-2">
                <a href="{{ route('login') }}" class="text-cyan-400 hover:text-cyan-300">Back to sign in</a>
            </p>
        @endif
    </form>
@endsection
