@extends('layouts.app')

@section('title', 'Impact Analysis · ' . $asset->label)

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('projects.show', $project) }}" class="hover:text-white">{{ $project->name }}</a>
    <span class="text-gray-600">/</span>
    <a href="{{ route('projects.graph', $project) }}" class="hover:text-white">Knowledge Graph</a>
    <span class="text-gray-600">/</span>
    <span class="text-white">Impact</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl font-semibold text-white">Impact Analysis</h1>
            <p class="text-sm text-gray-400">Blast radius from <span class="font-mono text-cyan-300">{{ $asset->label }}</span></p>
        </div>
        <a href="{{ route('projects.graph', $project) }}" class="btn-outline">
            <span class="material-symbols-rounded text-base">arrow_back</span> Back to graph
        </a>
    </div>

    <div class="card p-5">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <span class="badge-violet uppercase">{{ $asset->type }}</span>
                <div class="font-display text-lg text-white mt-2">{{ $asset->label }}</div>
                @if ($asset->value)
                    <div class="text-xs font-mono text-gray-400 mt-1 break-all">{{ $asset->value }}</div>
                @endif
            </div>
            @if ($asset->risk_score > 0)
                <div class="text-right">
                    <div class="text-xs text-gray-500">Risk score</div>
                    <div class="font-display text-2xl text-white">{{ number_format($asset->risk_score, 1) }}<span class="text-sm text-gray-500">/10</span></div>
                </div>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="card lg:col-span-2 p-1 relative" style="height: 500px;">
            <div id="impact-graph" class="w-full h-full"></div>
        </div>

        <div class="card p-5">
            <h2 class="font-display text-lg text-white mb-3">Affected Assets ({{ $affected->count() ?? 0 }})</h2>
            @if (($affected->count() ?? 0) > 0)
                <div class="space-y-2 max-h-[420px] overflow-y-auto">
                    @foreach ($affected as $a)
                        <div class="card !rounded-lg p-3">
                            <div class="flex items-center justify-between">
                                <span class="badge-neutral capitalize text-[10px]">{{ $a->type }}</span>
                                @if ($a->risk_score > 0)
                                    <span class="text-xs font-mono text-amber-300">{{ number_format($a->risk_score, 1) }}</span>
                                @endif
                            </div>
                            <div class="text-sm text-white mt-1">{{ $a->label }}</div>
                            @if ($a->value)
                                <div class="text-xs font-mono text-gray-500 mt-0.5 truncate">{{ $a->value }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500">No reachable assets from this seed.</p>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script type="module">
    import '{{ Vite::asset('resources/js/graph.js') }}';
    window.addEventListener('DOMContentLoaded', () => {
        const elements = @json([
            'nodes' => $affected->map(fn($a) => ['data' => [
                'id' => (string) $a->id, 'label' => $a->label, 'type' => $a->type,
                'value' => $a->value, 'risk_score' => (float) $a->risk_score,
            ]])->merge([['data' => ['id' => (string) $seed->id, 'label' => $seed->label, 'type' => $seed->type, 'risk_score' => (float) $seed->risk_score]]])->all(),
            'edges' => [],
        ]);
        const cy = window.initKnowledgeGraph('#impact-graph', elements.nodes);
        if (cy) {
            const seed = cy.getElementById('{{ $seed->id }}');
            if (seed.length > 0) window.runImpactAnalysis?.(cy, seed);
        }
    });
</script>
@endpush
@endsection
