@extends('layouts.app')

@section('title', 'Sandbox')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('security.alerts') }}" class="hover:text-white">Security</a>
    <span class="text-gray-600">/</span>
    <span class="text-white">Sandbox</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl font-semibold text-white">Sandbox</h1>
            <p class="text-sm text-gray-400">Isolated testing environment for safe exploit validation.</p>
        </div>
        <a href="{{ route('security.sandbox.launch') }}" class="btn-primary">
            <span class="material-symbols-rounded text-base">rocket_launch</span> Launch Sandbox
        </a>
    </div>

    {{-- Available vulnerable apps --}}
    <div>
        <h2 class="font-display text-lg text-white mb-3">Available Vulnerable Applications</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            @php
                $apps = [
                    'DVWA' => ['image' => 'vulnerables/web-dvwa',       'desc' => 'Damn Vulnerable Web Application — PHP/MySQL app covering common vulnerabilities.'],
                    'SQLi-Labs' => ['image' => 'audi/sqlilabs',         'desc' => 'Series of SQL injection scenarios with progressive difficulty.'],
                    'WebGoat' => ['image' => 'webgoat/goatandwolf',     'desc' => 'OWASP WebGoat — intentionally vulnerable Java web application.'],
                    'bWAPP' => ['image' => 'hackersploit/bwapp',        'desc' => 'Buggy Web Application — over 100 vulnerability scenarios.'],
                ];
            @endphp
            @foreach ($apps as $name => $info)
                <div class="card-hover p-5">
                    <div class="flex items-start justify-between mb-3">
                        <h3 class="font-display text-lg text-white">{{ $name }}</h3>
                        <span class="badge-danger"><span class="material-symbols-rounded text-[12px]">warning</span> Vulnerable</span>
                    </div>
                    <p class="text-sm text-gray-400 mb-4">{{ $info['desc'] }}</p>
                    <div class="text-xs text-gray-500 mb-3 font-mono">{{ $info['image'] }}</div>
                    <form method="POST" action="{{ route('security.sandbox.launch') }}">
                        @csrf
                        <input type="hidden" name="app" value="{{ $name }}">
                        <input type="hidden" name="image" value="{{ $info['image'] }}">
                        <button type="submit" class="btn-primary w-full text-xs">
                            <span class="material-symbols-rounded text-base">play_arrow</span> Launch
                        </button>
                    </form>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Running containers --}}
    <div>
        <h2 class="font-display text-lg text-white mb-3">Running Containers ({{ $containers->count() }})</h2>
        @if ($containers->isEmpty())
            <x-empty-state icon="inbox" title="No containers running" message="Launch a vulnerable app above to start a sandboxed instance." />
        @else
            <div class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr><th>Name</th><th>Image</th><th>Status</th><th>Ports</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($containers as $c)
                                <tr data-search-item="{{ $c['name'] ?? '' }} {{ $c['image'] ?? '' }}">
                                    <td class="text-sm text-white font-mono">{{ $c['name'] ?? '—' }}</td>
                                    <td class="text-xs font-mono text-gray-400">{{ $c['image'] ?? '—' }}</td>
                                    <td>
                                        @if (($c['status'] ?? '') === 'running')
                                            <span class="badge-success"><span class="material-symbols-rounded text-[12px]">check_circle</span> Running</span>
                                        @else
                                            <span class="badge-neutral">{{ $c['status'] ?? '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="text-xs font-mono">{{ is_array($c['ports'] ?? null) ? implode(', ', $c['ports']) : ($c['ports'] ?? '—') }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('security.sandbox.stop', $c['id'] ?? '') }}" data-confirm="Stop this container?">
                                            @csrf
                                            <button type="submit" class="btn-ghost !p-1.5 text-red-300 hover:text-red-200" title="Stop">
                                                <span class="material-symbols-rounded text-[18px]">stop_circle</span>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
