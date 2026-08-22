<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'CyberSec Platform'))</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('scripts')
</head>
<body class="min-h-screen">
    <header class="border-b border-white/5 bg-surface/60 backdrop-blur sticky top-0 z-10">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between">
            <a href="/" class="flex items-center gap-2.5">
                <span class="h-8 w-8 rounded-lg bg-gradient-to-br from-primary to-secondary flex items-center justify-center">
                    <span class="material-symbols-rounded text-white text-[20px]">shield</span>
                </span>
                <span class="font-display font-semibold text-white text-sm">CyberSec Platform</span>
            </a>
            <nav class="flex items-center gap-2">
                @if (Route::has('login'))
                    <a href="{{ route('login') }}" class="btn-ghost text-sm">Sign in</a>
                @endif
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="btn-primary text-sm">Get started</a>
                @endif
            </nav>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 sm:px-6 py-10">
        @yield('content')
    </main>

    <footer class="border-t border-white/5 py-6 text-center text-xs text-gray-500">
        © 2026 CyberSec Platform — Final Year Project — TEK-UP
    </footer>
</body>
</html>
