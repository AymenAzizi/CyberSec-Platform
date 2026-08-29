@extends('layouts.app')

@section('title', 'Scans')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <span class="text-white">Scans</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl font-semibold text-white">Scans</h1>
            <p class="text-sm text-gray-4600">{{ $scans->total() }} scan{{ $scans->total() === 1 ? '' : 's' }} · {{ $scans->where('status','running')->count() + $scans->where('status','queued')->count() }} in flight</p>
        </div>
        @if(!auth()->user()->isClient() && !auth()->user()->isAuditor())
        <a href="{{ route('scans.create') }}" class="btn-primary">
            <span class="material-symbols-rounded text-base">add</span> New Scan
        </a>
        @endif
    </div>

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('scans.index') }}" class="card p-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <label for="project" class="label">Project</label>
                <select id="project" name="project" class="input">
                    <option value="">All projects</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}" @selected(request('project') == $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="status" class="label">Status</label>
                <select id="status" name="status" class="input">
                    <option value="">All</option>
                    @foreach (\App\Models\Scan::STATUSES as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="type" class="label">Type</label>
                <select id="type" name="type" class="input">
                    <option value="">All</option>
                    @foreach (\App\Models\Scan::ALL_TYPES as $t)
                        <option value="{{ $t }}" @selected(request('type') === $t)>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="profile" class="label">Profile</label>
                <select id="profile" name="profile" class="input">
                    <option value="">All</option>
                    @foreach (array_keys(\App\Models\Scan::PROFILES) as $pr)
                        <option value="{{ $pr }}" @selected(request('profile') === $pr)>{{ ucfirst($pr) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="flex items-center justify-end gap-2 mt-3">
            <a href="{{ route('scans.index') }}" class="btn-ghost text-xs">Reset</a>
            <button type="submit" class="btn-primary text-xs">
                <span class="material-symbols-rounded text-base">filter_alt</span> Apply filters
            </button>
        </div>
    </form>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto" data-searchable>
            <table class="table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Target</th>
                        <th>Project</th>
                        <th>Profile</th>
                        <th>Status</th>
                        <th>Severity</th>
                        <th>Started</th>
                        <th>Duration</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($scans as $scan)
                        <tr data-search-item="{{ $scan->type }} {{ $scan->target_url }} {{ $scan->project?->name }}">
                            <td class="font-mono text-xs">{{ $scan->type }}</td>
                            <td class="text-sm text-white truncate max-w-[180px]">{{ $scan->target_url }}</td>
                            <td class="text-xs">
                                @if ($scan->project) <a href="{{ route('projects.show', $scan->project) }}" class="hover:text-white">{{ $scan->project->name }}</a> @else — @endif
                            </td>
                            <td><x-profile-badge :profile="$scan->profile" /></td>
                            <td><x-status-badge :status="$scan->status" /></td>
                            <td>
                                @php $sc = $scan->severity_counts ?? []; @endphp
                                <div class="flex items-center gap-1">
                                    @foreach (['critical','high','medium','low','info'] as $sev)
                                        @if (($sc[$sev] ?? 0) > 0)
                                            <span class="badge-{{ $sev }} text-[10px]">{{ $sc[$sev] }}</span>
                                        @endif
                                    @endforeach
                                    @if (empty($sc) || array_sum($sc) === 0)
                                        <span class="text-xs text-gray-500">—</span>
                                    @endif
                                </div>
                            </td>
                            <td class="text-xs">{{ $scan->started_at?->diffForHumans() ?? '—' }}</td>
                            <td class="text-xs">{{ $scan->duration ? formatDuration($scan->duration) : '—' }}</td>
                            <td>
                                <div class="flex items-center gap-1">
                                    <a href="{{ route('scans.show', $scan) }}" class="btn-ghost !p-1.5" title="View"><span class="material-symbols-rounded text-[18px]">visibility</span></a>
                                    @if (in_array($scan->status, ['queued','running','pending']))
                                <form method="POST" action="{{ route('scans.cancel', $scan) }}" data-confirm="Cancel this scan?">
                                    @csrf
                                    <button type="submit" class="btn-ghost !p-1.5 text-red-300" title="Cancel"><span class="material-symbols-rounded text-[18px]">cancel</span></button>
                                </form>
                                    @endif
                                    @if ($scan->status === 'failed' && $scan->canRetry())
                                        <form method="POST" action="{{ route('scans.retry', $scan) }}">
                                            @csrf
                                            <button type="submit" class="btn-ghost !p-1.5 text-amber-300" title="Retry"><span class="material-symbols-rounded text-[18px]">refresh</span></button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-gray-500 py-6">No scans match the current filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-white/5">
            {{ $scans->withQueryString()->links() }}
        </div>
    </div>
</div>
@endsection
