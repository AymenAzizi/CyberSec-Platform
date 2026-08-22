@extends('layouts.app')

@section('title', 'Findings')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <span class="text-white">Findings</span>
@endsection

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl font-semibold text-white">Security Findings</h1>
            <p class="text-sm text-gray-400">All discovered vulnerabilities and issues across your projects.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('scans.create') }}" class="btn-primary">
                <span class="material-symbols-rounded text-base">radar</span> New Scan
            </a>
            <a href="{{ route('reports.index') }}" class="btn-outline">
                <span class="material-symbols-rounded text-base">description</span> Reports
            </a>
        </div>
    </div>

    {{-- Severity Filter Tabs --}}
    @php
        $activeSev = request('severity', 'all');
    @endphp
    <div class="flex flex-wrap items-center gap-2 border-b border-white/5 pb-3">
        <a href="{{ route('findings.index', array_merge(request()->except('page'), ['severity' => 'all'])) }}"
           class="px-3 py-1.5 rounded-lg text-xs font-medium transition {{ $activeSev === 'all' ? 'bg-primary text-white shadow-glow' : 'bg-white/5 text-gray-400 hover:bg-white/10 hover:text-white' }}">
            All ({{ $counts['all'] ?? 0 }})
        </a>
        <a href="{{ route('findings.index', array_merge(request()->except('page'), ['severity' => 'critical'])) }}"
           class="px-3 py-1.5 rounded-lg text-xs font-medium transition {{ $activeSev === 'critical' ? 'bg-red-500/20 text-red-300 border border-red-500/40 shadow-glow' : 'bg-white/5 text-red-400 hover:bg-red-500/10' }}">
            Critical ({{ $counts['critical'] ?? 0 }})
        </a>
        <a href="{{ route('findings.index', array_merge(request()->except('page'), ['severity' => 'high'])) }}"
           class="px-3 py-1.5 rounded-lg text-xs font-medium transition {{ $activeSev === 'high' ? 'bg-orange-500/20 text-orange-300 border border-orange-500/40 shadow-glow' : 'bg-white/5 text-orange-400 hover:bg-orange-500/10' }}">
            High ({{ $counts['high'] ?? 0 }})
        </a>
        <a href="{{ route('findings.index', array_merge(request()->except('page'), ['severity' => 'medium'])) }}"
           class="px-3 py-1.5 rounded-lg text-xs font-medium transition {{ $activeSev === 'medium' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40 shadow-glow' : 'bg-white/5 text-amber-400 hover:bg-amber-500/10' }}">
            Medium ({{ $counts['medium'] ?? 0 }})
        </a>
        <a href="{{ route('findings.index', array_merge(request()->except('page'), ['severity' => 'low'])) }}"
           class="px-3 py-1.5 rounded-lg text-xs font-medium transition {{ $activeSev === 'low' ? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/40 shadow-glow' : 'bg-white/5 text-cyan-400 hover:bg-cyan-500/10' }}">
            Low ({{ $counts['low'] ?? 0 }})
        </a>
        <a href="{{ route('findings.index', array_merge(request()->except('page'), ['severity' => 'info'])) }}"
           class="px-3 py-1.5 rounded-lg text-xs font-medium transition {{ $activeSev === 'info' ? 'bg-blue-500/20 text-blue-300 border border-blue-500/40 shadow-glow' : 'bg-white/5 text-blue-400 hover:bg-blue-500/10' }}">
            Info ({{ $counts['info'] ?? 0 }})
        </a>
    </div>

    {{-- Search Form --}}
    <form method="GET" action="{{ route('findings.index') }}" class="card p-3 flex items-center gap-2">
        <input type="hidden" name="severity" value="{{ $activeSev }}">
        <span class="material-symbols-rounded text-gray-500">search</span>
        <input type="text" name="search" value="{{ request('search') }}"
               class="input !bg-transparent !border-0 flex-1 focus:ring-0"
               placeholder="Search by title, target, endpoint, tool, CVE...">
        @if(request('search'))
            <a href="{{ route('findings.index', ['severity' => $activeSev]) }}" class="btn-ghost !p-1.5 text-xs text-gray-400 hover:text-white">Clear</a>
        @endif
        <button type="submit" class="btn-primary !py-1.5 !px-3 text-xs">Search</button>
    </form>

    {{-- Findings List --}}
    <div class="space-y-3">
        @forelse ($findings as $finding)
            <div class="card p-4 hover:border-white/20 transition flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="space-y-1.5 flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-severity-badge :severity="$finding->severity" />
                        <span class="font-mono text-xs text-gray-400 uppercase bg-white/5 px-2 py-0.5 rounded border border-white/5">{{ $finding->source_tool }}</span>
                        @if ($finding->cve_id)
                            <span class="font-mono text-xs text-red-300 bg-red-500/10 px-2 py-0.5 rounded border border-red-500/20">{{ $finding->cve_id }}</span>
                        @endif
                        @if ($finding->cvss_score)
                            <span class="font-mono text-xs text-amber-300 bg-amber-500/10 px-2 py-0.5 rounded border border-amber-500/20">CVSS {{ $finding->cvss_score }}</span>
                        @endif
                    </div>
                    <div class="font-medium text-white text-base">
                        <a href="{{ route('remediation.show', $finding) }}" class="hover:text-primary transition">
                            {{ $finding->title }}
                        </a>
                    </div>
                    @if ($finding->endpoint)
                        <div class="font-mono text-xs text-cyan-400 truncate max-w-xl">
                            {{ $finding->endpoint }}
                        </div>
                    @endif
                    <div class="text-xs text-gray-400 line-clamp-2">
                        {{ $finding->description ?: 'No additional description provided.' }}
                    </div>
                    <div class="text-[11px] text-gray-500 flex items-center gap-3 pt-1">
                        @if ($finding->project)
                            <span>Project: <a href="{{ route('projects.show', $finding->project) }}" class="text-gray-300 hover:text-white underline">{{ $finding->project->name }}</a></span>
                        @endif
                        @if ($finding->scan)
                            <span>Scan: <a href="{{ route('scans.show', $finding->scan) }}" class="text-gray-300 hover:text-white underline">#{{ $finding->scan->id }} ({{ $finding->scan->type }})</a></span>
                        @endif
                        <span>Discovered: {{ $finding->created_at ? $finding->created_at->diffForHumans() : '—' }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-2 self-end md:self-center shrink-0">
                    <a href="{{ route('remediation.show', $finding) }}" class="btn-primary !py-1.5 !px-3 text-xs flex items-center gap-1.5">
                        <span class="material-symbols-rounded text-sm">smart_toy</span> AI Remediation
                    </a>
                </div>
            </div>
        @empty
            <x-empty-state icon="search_off" title="No findings found" message="No security findings match your current criteria." />
        @endforelse
    </div>

    {{-- Pagination --}}
    @if ($findings->hasPages())
        <div class="pt-4">
            {{ $findings->links() }}
        </div>
    @endif
</div>
@endsection
