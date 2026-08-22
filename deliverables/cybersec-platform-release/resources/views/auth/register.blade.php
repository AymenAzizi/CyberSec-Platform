@extends('layouts.auth')

@section('title', 'Create account · CyberSec Platform')

@section('content')
    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <div>
            <label for="name" class="label">Full name</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}"
                   class="input @error('name') border-danger @enderror"
                   placeholder="Jane Doe" required autofocus autocomplete="name">
            @error('name')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="email" class="label">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="input @error('email') border-danger @enderror"
                   placeholder="you@example.com" required autocomplete="email">
            @error('email')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="label">Password</label>
            <input id="password" type="password" name="password"
                   class="input @error('password') border-danger @enderror"
                   placeholder="At least 8 characters" required autocomplete="new-password">
            <div class="mt-2 flex items-center gap-2">
                <div class="flex-1 h-1.5 rounded-full bg-white/5 overflow-hidden">
                    <div id="password-strength" class="h-full w-0 transition-all bg-danger"></div>
                </div>
                <span id="password-strength-label" class="text-[11px] text-gray-500 w-16 text-right">Weak</span>
            </div>
            @error('password')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password_confirmation" class="label">Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   class="input @error('password_confirmation') border-danger @enderror"
                   placeholder="Repeat password" required autocomplete="new-password">
            @error('password_confirmation')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>

        <label class="flex items-start gap-2 text-sm text-gray-300 cursor-pointer select-none">
            <input type="checkbox" name="terms" value="1" class="mt-1 rounded border-white/10 bg-background text-primary focus:ring-primary" required>
            <span>I agree to the platform terms of use and accept that all activity is logged for audit purposes.</span>
        </label>

        <button type="submit" class="btn-primary w-full">
            <span class="material-symbols-rounded text-base">person_add</span>
            Create Account
        </button>

        <p class="text-center text-xs text-gray-500 pt-2">
            First account becomes admin
        </p>

        @if (Route::has('login'))
            <p class="text-center text-sm text-gray-400">
                Already registered?
                <a href="{{ route('login') }}" class="text-cyan-400 hover:text-cyan-300">Sign in</a>
            </p>
        @endif
    </form>

    <script>
        const pwd = document.getElementById('password');
        const bar = document.getElementById('password-strength');
        const label = document.getElementById('password-strength-label');
        if (pwd) {
            pwd.addEventListener('input', () => {
                const v = pwd.value;
                let score = 0;
                if (v.length >= 8) score++;
                if (v.length >= 12) score++;
                if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
                if (/\d/.test(v)) score++;
                if (/[^A-Za-z0-9]/.test(v)) score++;
                const pct = Math.min(100, (score / 5) * 100);
                bar.style.width = pct + '%';
                const colors = ['bg-danger', 'bg-high', 'bg-medium', 'bg-low', 'bg-success'];
                const labels = ['Weak', 'Fair', 'Good', 'Strong', 'Excellent'];
                bar.className = 'h-full transition-all ' + (colors[score - 1] || 'bg-danger');
                label.textContent = labels[score - 1] || 'Weak';
            });
        }
    </script>
@endsection
