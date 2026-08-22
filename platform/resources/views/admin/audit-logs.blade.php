@extends('layouts.app')

@section('title', 'Audit Logs')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <span class="text-white">Audit Logs</span>
@endsection

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="font-display text-2xl font-semibold text-white">Audit Logs</h1>
        <p class="text-sm text-gray-400">Immutable trail of every privileged action.</p>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('admin.audit-logs') }}" class="card p-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <label for="user" class="label">User</label>
                <select id="user" name="user" class="input">
                    <option value="">All users</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}" @selected(request('user') == $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="action" class="label">Action</label>
                <input id="action" type="text" name="action" value="{{ request('action') }}" class="input" placeholder="e.g. project.create">
            </div>
            <div>
                <label for="from" class="label">From</label>
                <input id="from" type="date" name="from" value="{{ request('from') }}" class="input">
            </div>
            <div>
                <label for="to" class="label">To</label>
                <input id="to" type="date" name="to" value="{{ request('to') }}" class="input">
            </div>
        </div>
        <div class="flex items-center justify-end gap-2 mt-3">
            <a href="{{ route('admin.audit-logs') }}" class="btn-ghost text-xs">Reset</a>
            <button type="submit" class="btn-primary text-xs"><span class="material-symbols-rounded text-base">filter_alt</span> Apply</button>
        </div>
    </form>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto" data-searchable>
            <table class="table">
                <thead>
                    <tr><th>Timestamp</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th><th>Details</th></tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr data-search-item="{{ $log->action }} {{ $log->user?->name ?? '' }}">
                            <td class="text-xs">{{ $log->created_at?->toDateTimeString() }}</td>
                            <td class="text-sm text-white">{{ $log->user?->name ?? 'system' }}</td>
                            <td><span class="badge-violet font-mono">{{ $log->action }}</span></td>
                            <td class="text-xs">{{ $log->entity_type ? "{$log->entity_type}#{$log->entity_id}" : '—' }}</td>
                            <td class="text-xs font-mono">{{ $log->ip_address ?? '—' }}</td>
                            <td>
                                @if ($log->details)
                                    <details>
                                        <summary class="text-xs text-cyan-400 cursor-pointer hover:text-cyan-300">View JSON</summary>
                                        <pre class="terminal mt-1 text-[11px]"><code>{{ json_encode($log->details, JSON_PRETTY_PRINT) }}</code></pre>
                                    </details>
                                @else
                                    <span class="text-xs text-gray-500">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-gray-500 py-6">No audit log entries.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-white/5">{{ $logs->withQueryString()->links() }}</div>
    </div>
</div>
@endsection
