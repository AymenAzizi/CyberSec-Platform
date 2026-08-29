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
    @stack('styles')
</head>
<body class="min-h-screen">
<div class="flex min-h-screen">

    {{-- Mobile sidebar overlay --}}
    <div id="sidebar-overlay" class="hidden fixed inset-0 bg-black/60 z-30 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside id="sidebar"
           class="sidebar-scroll fixed lg:sticky top-0 z-40 h-screen w-64 shrink-0 bg-surface border-r border-white/5 flex flex-col -translate-x-full lg:translate-x-0 transition-transform">

        {{-- Brand --}}
        <div class="px-4 py-4 border-b border-white/5 flex items-center justify-between">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
                <span class="h-9 w-9 rounded-lg bg-gradient-to-br from-primary to-secondary flex items-center justify-center shadow-glow">
                    <span class="material-symbols-rounded text-white">shield</span>
                </span>
                <div class="leading-tight">
                    <div class="font-display font-semibold text-white text-sm">CyberSec</div>
                    <div class="text-[10px] uppercase tracking-wider text-gray-500">Platform</div>
                </div>
            </a>
            <button id="sidebar-close" class="lg:hidden text-gray-400 hover:text-white" aria-label="Close sidebar">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
            @php
                $user = auth()->user();
                $isClient = $user?->isClient();
                $isAuditor = $user?->isAuditor();

                $navItems = [
                    ['route' => 'dashboard',          'label' => 'Dashboard',        'icon' => 'grid_view'],
                    ['route' => 'projects.index',     'label' => 'Projects',         'icon' => 'folder'],
                ];

                if (!$isClient) {
                    $navItems[] = ['route' => 'scans.index',    'label' => 'Scans',    'icon' => 'radar'];
                    $navItems[] = ['route' => 'findings.index', 'label' => 'Findings', 'icon' => 'bug_report'];
                }

                $navItems[] = ['route' => 'reports.index',   'label' => 'Reports',         'icon' => 'description'];
                $navItems[] = ['route' => 'security.alerts', 'label' => 'Security Alerts', 'icon' => 'notifications_active', 'badge' => $unacknowledgedAlerts ?? 0];

                if (!$isClient && !$isAuditor) {
                    $navItems[] = ['route' => 'security.monitoring','label' => 'Monitoring',      'icon' => 'monitoring'];
                    $navItems[] = ['route' => 'security.sandbox',   'label' => 'Sandbox',         'icon' => 'science'];
                    $navItems[] = ['route' => 'projects.graph',     'label' => 'Knowledge Graph', 'icon' => 'hub', 'params' => (!empty($defaultProject) ? [$defaultProject] : null), 'fallback' => 'projects.create'];
                    $navItems[] = ['route' => 'osint.index',        'label' => 'OSINT',           'icon' => 'travel_explore'];
                }

                $navItems[] = ['route' => 'chat.index', 'label' => 'AI Chatbot', 'icon' => 'smart_toy'];
            @endphp

            @foreach ($navItems as $item)
                @php
                    $active = request()->routeIs($item['route']);
                    $routeParams = $item['params'] ?? [];
                    // Knowledge Graph requires a project; fall back to
                    // the create-project page when the user has none.
                    if ($item['route'] === 'projects.graph' && empty($routeParams)) {
                        $href = route($item['fallback'] ?? 'projects.index');
                    } else {
                        $href = route($item['route'], $routeParams);
                    }
                @endphp
                <a href="{{ $href }}" class="nav-link {{ $active ? 'nav-link-active' : '' }}">
                    <span class="material-symbols-rounded text-[20px]">{{ $item['icon'] }}</span>
                    <span class="flex-1">{{ $item['label'] }}</span>
                    @if (!empty($item['badge']) && $item['badge'] > 0)
                        <span class="badge-danger text-[10px] px-1.5">{{ $item['badge'] }}</span>
                    @endif
                </a>
            @endforeach

            @if (auth()->check() && (auth()->user()->isAdmin() || auth()->user()->isAuditor()))
                <div class="pt-4 pb-1 px-3 text-[10px] uppercase tracking-wider text-gray-500 font-semibold">
                    {{ auth()->user()->isAdmin() ? 'Administration' : 'Audit & Compliance' }}
                </div>
                @php
                    $adminItems = [];
                    if (auth()->user()->isAdmin()) {
                        $adminItems[] = ['route' => 'admin.users.index', 'label' => 'Users', 'icon' => 'group'];
                    }
                    $adminItems[] = ['route' => 'admin.audit-logs', 'label' => 'Audit Logs', 'icon' => 'receipt_long'];
                    $adminItems[] = ['route' => 'admin.system-health', 'label' => 'System Health', 'icon' => 'health_and_safety'];
                @endphp
                @foreach ($adminItems as $item)
                    <a href="{{ route($item['route']) }}"
                       class="nav-link {{ request()->routeIs($item['route']) ? 'nav-link-active' : '' }}">
                        <span class="material-symbols-rounded text-[20px]">{{ $item['icon'] }}</span>
                        <span class="flex-1">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            @endif
        </nav>

        {{-- User info --}}
        <div class="px-3 py-3 border-t border-white/5">
            <div class="flex items-center gap-3 px-2 py-2 rounded-lg">
                <div class="h-9 w-9 rounded-full bg-gradient-to-br from-primary to-secondary flex items-center justify-center text-white text-sm font-semibold">
                    {{ strtoupper(substr(auth()->user()?->name ?? '?', 0, 1)) }}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-white truncate">{{ auth()->user()?->name }}</div>
                    <div class="text-[11px] text-gray-500 truncate">{{ auth()->user()?->email }}</div>
                </div>
            </div>
            <div class="mt-2 flex items-center gap-2">
                @php
                    $role = collect(auth()->user()?->roles ?? [])->first()?->name ?? 'user';
                @endphp
                <span class="badge-violet flex-1 justify-center">{{ ucfirst($role) }}</span>
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="btn-ghost !p-2" title="Sign out" aria-label="Sign out">
                        <span class="material-symbols-rounded text-[18px]">logout</span>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- Main column --}}
    <div class="flex-1 flex flex-col min-w-0">

        {{-- Top bar --}}
        <header class="sticky top-0 z-20 bg-background/80 backdrop-blur border-b border-white/5">
            <div class="flex items-center gap-3 px-4 lg:px-6 h-14">
                <button id="sidebar-toggle" class="lg:hidden text-gray-400 hover:text-white" aria-label="Open sidebar">
                    <span class="material-symbols-rounded">menu</span>
                </button>

                {{-- Breadcrumb --}}
                <nav class="hidden md:flex items-center gap-2 text-sm text-gray-400 min-w-0">
                    <a href="{{ route('dashboard') }}" class="hover:text-white">Home</a>
                    @yield('breadcrumb')
                </nav>

                <div class="flex-1"></div>

                {{-- Search --}}
                <div class="relative hidden sm:block">
                    <span class="material-symbols-rounded absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-500 text-[18px]">search</span>
                    <input id="global-search" type="text"
                           class="input !pl-9 !py-1.5 w-56 lg:w-72 text-sm"
                           placeholder="Search projects, scans…">
                </div>

                {{-- Notifications --}}
                @php
                    $recentAlerts = isset($recentAlerts) ? $recentAlerts : collect();
                @endphp
                <div class="relative">
                    <button id="notifications-bell" class="relative btn-ghost !p-2" aria-label="Notifications">
                        <span class="material-symbols-rounded text-[20px]">notifications</span>
                        @if ($unacknowledgedAlerts ?? 0 > 0)
                            <span class="absolute -top-0.5 -right-0.5 h-4 min-w-4 px-1 rounded-full bg-danger text-white text-[10px] flex items-center justify-center">
                                {{ min(99, $unacknowledgedAlerts ?? 0) }}
                            </span>
                        @endif
                    </button>
                    <div id="notifications-dropdown"
                         class="hidden absolute right-0 mt-2 w-80 max-h-96 overflow-y-auto card shadow-xl p-2 z-40">
                        <div class="flex items-center justify-between px-2 py-1.5 mb-1 border-b border-white/5">
                            <span class="text-xs uppercase tracking-wider text-gray-500">Recent alerts</span>
                            <a href="{{ route('security.alerts') }}" class="text-xs text-cyan-400 hover:text-cyan-300">View all</a>
                        </div>
                        @forelse ($recentAlerts as $alert)
                            <a href="{{ route('security.alerts', ['alert' => $alert->id]) }}"
                               class="block px-2 py-2 rounded-lg hover:bg-white/5">
                                <div class="flex items-center gap-2">
                                    <x-severity-badge :severity="$alert->severity" size="xs" />
                                    <span class="text-sm text-white truncate flex-1">{{ $alert->title }}</span>
                                </div>
                                <div class="text-[11px] text-gray-500 mt-0.5">{{ \Illuminate\Support\Carbon::parse($alert->created_at)->diffForHumans() }}</div>
                            </a>
                        @empty
                            <div class="px-2 py-6 text-center text-sm text-gray-500">
                                <span class="material-symbols-rounded text-[24px] block mb-1">check_circle</span>
                                No new alerts.
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- Theme toggle --}}
                <button id="theme-toggle" class="btn-ghost !p-2" aria-label="Toggle theme" title="Toggle theme">
                    <span class="material-symbols-rounded text-[20px]">dark_mode</span>
                </button>
            </div>
        </header>

        {{-- Flash messages --}}
        @if (session('success'))
            <div data-flash class="mx-4 lg:mx-6 mt-4 card border-success/30 bg-success/10 px-4 py-3 flex items-center gap-2 text-sm text-emerald-200">
                <span class="material-symbols-rounded text-success">check_circle</span>
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div data-flash class="mx-4 lg:mx-6 mt-4 card border-danger/30 bg-danger/10 px-4 py-3 flex items-center gap-2 text-sm text-red-200">
                <span class="material-symbols-rounded text-danger">error</span>
                {{ session('error') }}
            </div>
        @endif
        @if (isset($errors) && $errors->any())
            <div data-flash class="mx-4 lg:mx-6 mt-4 card border-danger/30 bg-danger/10 px-4 py-3 flex items-start gap-2 text-sm text-red-200">
                <span class="material-symbols-rounded text-danger">error</span>
                <div>
                    <div class="font-medium">Please fix the following errors:</div>
                    <ul class="list-disc list-inside mt-1 text-xs">
                        @foreach ($errors->all() as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        {{-- Main content --}}
        <main class="flex-1 p-4 lg:p-6">
            @yield('content')
        </main>

        {{-- Footer --}}
        <footer class="px-4 lg:px-6 py-4 border-t border-white/5 text-xs text-gray-500 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <span>© 2026 CyberSec Platform — Final Year Project — TEK-UP</span>
            <span class="font-mono">{{ config('app.name') }} v1.0</span>
        </footer>
    </div>
</div>

{{-- Floating chatbot --}}
@if (auth()->check())
<button id="chatbot-fab"
        class="fixed bottom-6 right-6 z-40 h-12 w-12 rounded-full bg-primary hover:bg-violet-700 text-white shadow-glow flex items-center justify-center"
        aria-label="Open chatbot">
    <span class="material-symbols-rounded">smart_toy</span>
</button>

<div id="chatbot-panel" class="hidden fixed bottom-24 right-6 z-40 w-[360px] max-w-[calc(100vw-3rem)] h-[480px] card flex flex-col overflow-hidden">
    <div class="flex items-center justify-between px-4 py-3 border-b border-white/5 bg-primary/10">
        <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-violet-300">smart_toy</span>
            <div>
                <div class="text-sm font-medium text-white">CyberSec Assistant</div>
                <div class="text-[10px] text-gray-400">Ask about your projects and findings</div>
            </div>
        </div>
        <button id="chatbot-close" class="text-gray-400 hover:text-white" aria-label="Close chat">
            <span class="material-symbols-rounded">close</span>
        </button>
    </div>
    <div id="chatbot-messages" class="flex-1 overflow-y-auto p-3 space-y-3" data-endpoint="{{ route('chat.store') }}"></div>
    <form id="chatbot-form" class="p-2 border-t border-white/5 flex items-end gap-2">
        @csrf
        <input type="hidden" name="floating" value="1">
        <textarea id="chatbot-input" name="content" rows="1"
                  class="input !py-1.5 flex-1 resize-none max-h-32"
                  placeholder="Ask anything…"></textarea>
        <button type="submit" class="btn-primary !p-2" aria-label="Send">
            <span class="material-symbols-rounded">send</span>
        </button>
    </form>
</div>

@stack('chat-scripts')
@endif
@stack('scripts')
</body>
</html>
