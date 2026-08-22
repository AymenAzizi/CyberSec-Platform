@extends('layouts.auth')

@section('title', 'Sign in · CyberSec Platform')

@section('content')
    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="label">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="input @error('email') border-danger @enderror"
                   placeholder="you@example.com" required autofocus autocomplete="email">
            @error('email')
                <p class="text-xs text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <div class="flex items-center justify-between mb-1.5">
                <label for="password" class="label !mb-0">Password</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs text-cyan-400 hover:text-cyan-300">Forgot password?</a>
                @endif
            </div>
            <input id="password" type="password" name="password"
                   class="input @error('password') border-danger @enderror"
                   placeholder="••••••••" required autocomplete="current-password">
            @error('password')
                <p class="text-xs text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer select-none">
            <input type="checkbox" name="remember" class="rounded border-white/10 bg-background text-primary focus:ring-primary">
            Remember me on this device
        </label>

        <button type="submit" class="btn-primary w-full">
            <span class="material-symbols-rounded text-base">login</span>
            Sign In
        </button>

        @if (Route::has('register'))
            <p class="text-center text-sm text-gray-400 pt-2">
                No account yet?
                <a href="{{ route('register') }}" class="text-cyan-400 hover:text-cyan-300">Create one</a>
            </p>
        @endif
    </form>
@endsection
