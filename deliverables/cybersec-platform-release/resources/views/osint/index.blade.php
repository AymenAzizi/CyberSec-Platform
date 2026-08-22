@extends('layouts.app')

@section('title', 'OSINT')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <span class="text-white">OSINT</span>
@endsection

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="font-display text-2xl font-semibold text-white">OSINT</h1>
        <p class="text-sm text-gray-400">Passive intelligence gathered on scoped targets.</p>
    </div>

    @if ($targets->isEmpty())
        <x-empty-state icon="travel_explore" title="No targets yet"
            message="Add targets to a project to start collecting OSINT data." />
    @else
        <div class="card overflow-hidden">
            <div class="overflow-x-auto" data-searchable>
                <table class="table">
                    <thead>
                        <tr><th>Target</th><th>Project</th><th>Type</th><th>OSINT</th><th>Last seen</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($targets as $target)
                            <tr data-search-item="{{ $target->name }} {{ $target->domain_url }} {{ $target->project?->name }}">
                                <td class="text-sm text-white">{{ $target->name }}</td>
                                <td class="text-xs">
                                    @if ($target->project) <a href="{{ route('projects.show', $target->project) }}" class="hover:text-white">{{ $target->project->name }}</a> @endif
                                </td>
                                <td><span class="badge-neutral capitalize">{{ $target->scope_type }}</span></td>
                                <td>
                                    @if ($target->osint_data)
                                        <span class="badge-success"><span class="material-symbols-rounded text-[12px]">check_circle</span> Available</span>
                                    @else
                                        <span class="badge-neutral">None</span>
                                    @endif
                                </td>
                                <td class="text-xs">{{ $target->last_seen_at?->diffForHumans() ?? '—' }}</td>
                                <td>
                                    <div class="flex items-center gap-1">
                                        <form method="POST" action="{{ route('osint.run', $target) }}">
                                            @csrf
                                            <button type="submit" class="btn-ghost !p-1.5 text-xs" title="Run passive OSINT">
                                                <span class="material-symbols-rounded text-[18px]">play_arrow</span>
                                            </button>
                                        </form>
                                        @if ($target->osint_data)
                                            <a href="{{ route('osint.results', $target) }}" class="btn-ghost !p-1.5 text-xs" title="View results">
                                                <span class="material-symbols-rounded text-[18px]">visibility</span>
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-gray-500 py-6">No targets declared.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
