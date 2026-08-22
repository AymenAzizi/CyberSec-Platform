<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign in · CyberSec Platform')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex items-center justify-center px-4 py-10">
    <div class="absolute inset-0 -z-10 bg-gradient-to-br from-violet-900/30 via-background to-cyan-900/20"></div>

    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center h-14 w-14 rounded-2xl bg-gradient-to-br from-primary to-secondary shadow-glow mb-4">
                <span class="material-symbols-rounded text-white text-3xl">shield</span>
            </div>
            <h1 class="font-display text-2xl font-semibold text-white">CyberSec Platform</h1>
            <p class="text-sm text-gray-400 mt-1">Authorized Access Only</p>
        </div>

        <div class="card p-6 sm:p-8">
            @yield('content')
        </div>

        <p class="text-center text-xs text-gray-500 mt-6">
            All activities are monitored and logged
        </p>
    </div>
</body>
</html>
