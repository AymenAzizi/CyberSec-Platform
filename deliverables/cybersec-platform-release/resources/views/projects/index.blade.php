@extends('layouts.app')

@section('title', 'Projects')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <span class="text-white">Projects</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl font-semibold text-white">Projects</h1>
            <p class="text-sm text-gray-400">{{ $projects->total() }} engagement{{ $projects->total() === 1 ? '' : 's' }} · {{ $projects->pluck('targets')->flatten()->count() }} targets</p>
        </div>
        <a href="{{ route('projects.create') }}" class="btn-primary">
            <span class="material-symbols-rounded text-base">add</span> New Project
        </a>
    </div>

    @if ($projects->isEmpty())
        <x-empty-state icon="folder_off" title="No projects yet"
            message="Create your first engagement to declare scope, attach authorization and queue scans."
            action-label="New Project" action-href="{{ route('projects.create') }}" />
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach ($projects as $project)
                <div class="card-hover p-5 relative overflow-hidden" data-search-item="{{ $project->name }} {{ $project->client_name }}">
                    <div class="absolute top-0 left-0 right-0 h-1" style="background: {{ $project->branding_color }}"></div>
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div class="flex-1 min-w-0">
                            <a href="{{ route('projects.show', $project) }}" class="block">
                                <h3 class="font-display text-lg text-white truncate hover:text-cyan-300">{{ $project->name }}</h3>
                            </a>
                            @if ($project->client_name)
                                <div class="text-xs text-gray-400 mt-0.5 truncate">{{ $project->client_name }}</div>
                            @endif
                        </div>
                        <x-status-badge :status="$project->status" />
                    </div>

                    @if ($project->description)
                        <p class="text-sm text-gray-400 line-clamp-2 mb-4">{{ $project->description }}</p>
                    @endif

                    <div class="grid grid-cols-3 gap-2 text-center mb-4">
                        <div class="card !rounded-lg py-2">
                            <div class="text-base font-display text-white">{{ $project->targets_count ?? $project->targets->count() }}</div>
                            <div class="text-[10px] uppercase text-gray-500">Targets</div>
                        </div>
                        <div class="card !rounded-lg py-2">
                            <div class="text-base font-display text-white">{{ $project->scans_count ?? $project->scans->count() }}</div>
                            <div class="text-[10px] uppercase text-gray-500">Scans</div>
                        </div>
                        <div class="card !rounded-lg py-2">
                            <div class="text-base font-display text-white">{{ $project->findings_count ?? $project->findings->count() }}</div>
                            <div class="text-[10px] uppercase text-gray-500">Findings</div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span>Last scan: {{ $project->scans->last()?->created_at?->diffForHumans() ?? 'never' }}</span>
                    </div>

                    <div class="flex items-center gap-1 mt-3 pt-3 border-t border-white/5">
                        <a href="{{ route('projects.show', $project) }}" class="btn-ghost !py-1.5 text-xs flex-1 justify-center">
                            <span class="material-symbols-rounded text-[16px]">visibility</span> View
                        </a>
                        <a href="{{ route('projects.edit', $project) }}" class="btn-ghost !py-1.5 text-xs">
                            <span class="material-symbols-rounded text-[16px]">edit</span>
                        </a>
                        <form method="POST" action="{{ route('projects.destroy', $project) }}"
                              data-confirm="Delete project {{ $project->name }}? This cannot be undone.">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn-ghost !py-1.5 text-xs text-red-300 hover:text-red-200" title="Delete">
                                <span class="material-symbols-rounded text-[16px]">delete</span>
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>

        <div>{{ $projects->withQueryString()->links() }}</div>
    @endif
</div>
@endsection
