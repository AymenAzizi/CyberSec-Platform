@extends('layouts.app')

@section('title', 'Knowledge Graph · ' . $project->name)

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('projects.index') }}" class="hover:text-white">Projects</a>
    <span class="text-gray-600">/</span>
    <a href="{{ route('projects.show', $project) }}" class="hover:text-white">{{ $project->name }}</a>
    <span class="text-gray-600">/</span>
    <span class="text-white">Knowledge Graph</span>
@endsection

@section('content')
<div class="space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl font-semibold text-white">Knowledge Graph</h1>
            <p class="text-sm text-gray-400">{{ $project->name }} — {{ $assets->count() ?? 0 }} assets discovered</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('projects.show', $project) }}" class="btn-outline">
                <span class="material-symbols-rounded text-base">arrow_back</span> Back to project
            </a>
        </div>
    </div>

    {{-- Controls --}}
    <div class="card p-3 flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-2">
            <label class="text-xs text-gray-500">Layout</label>
            <select id="graph-layout" class="input !w-auto !py-1.5 text-xs">
                @foreach (['cose','breadthfirst','circle','concentric','grid'] as $l)
                    <option value="{{ $l }}">{{ ucfirst($l) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2">
            <label class="text-xs text-gray-500">Type</label>
            <select id="graph-type-filter" class="input !w-auto !py-1.5 text-xs">
                <option value="all">All</option>
                @foreach (['domain','ip','host','port','service','vulnerability','impact'] as $t)
                    <option value="{{ $t }}">{{ ucfirst($t) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2 flex-1 max-w-sm">
            <span class="material-symbols-rounded text-gray-500 text-[18px]">search</span>
            <input id="graph-search" type="text" class="input !py-1.5 text-xs" placeholder="Search assets by label…">
        </div>
        <div class="ml-auto text-xs text-gray-500" id="graph-stats"></div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
        {{-- Graph canvas --}}
        <div class="card lg:col-span-3 p-1 relative" style="height: 600px;">
            <div id="graph-canvas" class="w-full h-full"></div>
        </div>

        {{-- Side panel --}}
        <div id="graph-asset-details" class="hidden card p-4 self-start">
            <p class="text-xs text-gray-500">Click a node to view its details here.</p>
        </div>
    </div>

    @if (($assets->count() ?? 0) === 0)
        <x-empty-state icon="hub" title="No assets yet"
            message="Run a reconnaissance scan to populate the knowledge graph."
            action-label="Run a scan" action-href="{{ route('scans.create', ['project' => $project->id]) }}" />
    @endif

    @if (($topRisky->count() ?? 0) > 0)
    <div class="card overflow-hidden">
        <div class="px-5 py-3 border-b border-white/5">
            <h2 class="font-display text-lg text-white">Top Risky Assets</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th>Asset</th><th>Type</th><th>Risk score</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($topRisky as $asset)
                        @php $pct = min(100, ($asset->risk_score / 10) * 100); @endphp
                        <tr>
                            <td class="text-sm text-white">{{ $asset->label }}</td>
                            <td><span class="badge-neutral capitalize">{{ $asset->type }}</span></td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="flex-1 max-w-[200px] h-1.5 rounded-full bg-white/5 overflow-hidden">
                                        <div class="h-full bg-gradient-to-r from-secondary to-danger" style="width: {{ number_format($pct, 1) }}%"></div>
                                    </div>
                                    <span class="text-xs font-mono text-white">{{ number_format($asset->risk_score, 1) }}/10</span>
                                </div>
                            </td>
                            <td>
                                <a href="{{ route('assets.impact', $asset) }}" class="btn-ghost !py-1.5 text-xs">
                                    <span class="material-symbols-rounded text-[16px]">bolt</span> Impact
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>

@push('scripts')
<script type="module">
    import '{{ Vite::asset('resources/js/graph.js') }}';

    window.addEventListener('DOMContentLoaded', async () => {
        const canvas = document.getElementById('graph-canvas');
        if (!canvas) return;

        let elements = { nodes: [], edges: [] };
        try {
            const res = await fetch('{{ $graphDataUrl ?? route('projects.graph.data', $project) }}', { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            elements = data.elements || { nodes: [], edges: [] };
        } catch (e) {
            console.error('Failed to load graph data', e);
        }

        document.getElementById('graph-stats').textContent =
            `${elements.nodes.length} nodes · ${elements.edges.length} edges`;

        const cy = window.initKnowledgeGraph('#graph-canvas', [
            ...elements.nodes,
            ...elements.edges,
        ]);
        window.__cyInstance = cy;

        document.getElementById('graph-layout')?.addEventListener('change', (e) => {
            window.applyGraphLayout(cy, e.target.value);
        });
        document.getElementById('graph-type-filter')?.addEventListener('change', (e) => {
            window.filterGraphByType(cy, e.target.value);
        });
        document.getElementById('graph-search')?.addEventListener('input', (e) => {
            window.searchGraph(cy, e.target.value);
        });
    });
</script>
@endpush
@endsection
