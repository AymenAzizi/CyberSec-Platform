@extends('layouts.app')

@section('title', 'Security Alerts')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('security.alerts') }}" class="hover:text-white">Security</a>
    <span class="text-gray-600">/</span>
    <span class="text-white">Alerts</span>
@endsection

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="font-display text-2xl font-semibold text-white">Security Alerts</h1>
        <p class="text-sm text-gray-400">{{ $alerts->total() }} alerts · {{ $alerts->where('acknowledged', false)->count() }} unacknowledged</p>
    </div>

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('security.alerts') }}" class="card p-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label for="severity" class="label">Severity</label>
                <select id="severity" name="severity" class="input">
                    <option value="">All</option>
                    @foreach (['critical','high','medium','low','info'] as $s)
                        <option value="{{ $s }}" @selected(request('severity') === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="acknowledged" class="label">Acknowledged</label>
                <select id="acknowledged" name="acknowledged" class="input">
                    <option value="">All</option>
                    <option value="0" @selected(request('acknowledged') === '0')>Unacknowledged</option>
                    <option value="1" @selected(request('acknowledged') === '1')>Acknowledged</option>
                </select>
            </div>
            <div>
                <label for="project" class="label">Project</label>
                <select id="project" name="project" class="input">
                    <option value="">All projects</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}" @selected(request('project') == $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="flex items-center justify-end gap-2 mt-3">
            <a href="{{ route('security.alerts') }}" class="btn-ghost text-xs">Reset</a>
            <button type="submit" class="btn-primary text-xs"><span class="material-symbols-rounded text-base">filter_alt</span> Apply</button>
        </div>
    </form>

    <div class="space-y-3">
        @forelse ($alerts as $alert)
            <div class="card p-4 border-l-4"
                 style="border-left-color: {{ ['critical'=>'#ef4444','high'=>'#f97316','medium'=>'#f59e0b','low'=>'#06b6d4','info'=>'#6b7280'][$alert->severity] ?? '#6b7280' }}"
                 data-search-item="{{ $alert->title }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <x-severity-badge :severity="$alert->severity" />
                            @if ($alert->acknowledged)
                                <span class="badge-success"><span class="material-symbols-rounded text-[12px]">check_circle</span> Acknowledged</span>
                            @else
                                <span class="badge-danger"><span class="material-symbols-rounded text-[12px]">error</span> New</span>
                            @endif
                            <span class="badge-neutral capitalize">{{ str_replace('_',' ', $alert->source) }}</span>
                        </div>
                        <h3 class="font-medium text-white">{{ $alert->title }}</h3>
                        <p class="text-sm text-gray-400 mt-1">{{ $alert->description }}</p>
                        <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500 mt-2">
                            @if ($alert->project) <span><a href="{{ route('projects.show', $alert->project) }}" class="hover:text-white">{{ $alert->project->name }}</a></span> @endif
                            @if ($alert->scan) <span><a href="{{ route('scans.show', $alert->scan) }}" class="hover:text-white font-mono">{{ $alert->scan->type }}</a></span> @endif
                            <span>{{ $alert->created_at?->diffForHumans() }}</span>
                        </div>
                    </div>
                    @if (!$alert->acknowledged)
                        <form method="POST" action="{{ route('security.alerts.acknowledge', $alert) }}">
                            @csrf
                            <button type="submit" class="btn-success !py-1.5 text-xs">
                                <span class="material-symbols-rounded text-[16px]">check</span> Acknowledge
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <x-empty-state icon="notifications_off" title="No alerts match the filters" message="Try clearing filters or check back later." />
        @endforelse
    </div>

    <div>{{ $alerts->withQueryString()->links() }}</div>
</div>
@endsection
