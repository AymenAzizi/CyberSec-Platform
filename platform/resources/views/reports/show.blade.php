@extends('layouts.app')

@section('title', $report->title)

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('reports.index') }}" class="hover:text-white">Reports</a>
    <span class="text-gray-600">/</span>
    <span class="text-white truncate max-w-xs">{{ $report->title }}</span>
@endsection

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-semibold text-white">{{ $report->title }}</h1>
            <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500 mt-2">
                <span class="badge-neutral uppercase">{{ $report->format }}</span>
                @if ($report->is_signed)
                    <span class="badge-success"><span class="material-symbols-rounded text-[12px]">verified</span> Signed</span>
                @endif
                @if ($report->project) <span><a href="{{ route('projects.show', $report->project) }}" class="hover:text-white">{{ $report->project->name }}</a></span> @endif
                @if ($report->scan) <span><a href="{{ route('scans.show', $report->scan) }}" class="hover:text-white font-mono">{{ $report->scan->type }}</a></span> @endif
                <span>Generated: {{ $report->generated_at?->toDateTimeString() ?? '—' }}</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('reports.export', [$report, 'pdf']) }}" class="btn-outline"><span class="material-symbols-rounded text-base">picture_as_pdf</span> PDF</a>
            <a href="{{ route('reports.export', [$report, 'html']) }}" class="btn-outline"><span class="material-symbols-rounded text-base">code</span> HTML</a>
            <a href="{{ route('reports.export', [$report, 'json']) }}" class="btn-outline"><span class="material-symbols-rounded text-base">data_object</span> JSON</a>
            @if ($report->file_path)
                <a href="{{ route('reports.download', $report) }}" class="btn-primary"><span class="material-symbols-rounded text-base">download</span> Download file</a>
            @endif
        </div>
    </div>

    {{-- Executive summary --}}
    @if ($report->executive_summary)
    <div class="card p-5">
        <h2 class="font-display text-lg text-white mb-3">Executive Summary</h2>
        <div class="prose prose-invert max-w-none text-sm text-gray-300">{{ Illuminate\Support\Str::markdown($report->executive_summary) }}</div>
    </div>
    @endif

    {{-- Technical details --}}
    <div class="card p-5">
        <h2 class="font-display text-lg text-white mb-3">Technical Details</h2>
        @if ($report->scan)
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
                <div class="card !rounded-lg p-3"><div class="text-[10px] uppercase text-gray-500">Type</div><div class="text-sm text-white font-mono">{{ $report->scan->type }}</div></div>
                <div class="card !rounded-lg p-3"><div class="text-[10px] uppercase text-gray-500">Target</div><div class="text-sm text-white truncate">{{ $report->scan->target_url }}</div></div>
                <div class="card !rounded-lg p-3"><div class="text-[10px] uppercase text-gray-500">Profile</div><div class="text-sm text-white">{{ ucfirst($report->scan->profile) }}</div></div>
                <div class="card !rounded-lg p-3"><div class="text-[10px] uppercase text-gray-500">Duration</div><div class="text-sm text-white">{{ $report->scan->duration ? formatDuration($report->scan->duration) : '—' }}</div></div>
                <div class="card !rounded-lg p-3"><div class="text-[10px] uppercase text-gray-500">Started</div><div class="text-sm text-white">{{ $report->scan->started_at?->format('Y-m-d H:i') ?? '—' }}</div></div>
            </div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <h3 class="text-sm font-medium text-gray-300 mb-2">Findings by Severity</h3>
                <div id="severity-chart" class="h-64"></div>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-300 mb-2">Findings</h3>
                <div class="overflow-x-auto max-h-64">
                    <table class="table">
                        <thead>
                            <tr><th>Severity</th><th>Title</th><th>Tool</th><th>CVE</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($report->scan?->findings ?? collect() as $finding)
                                <tr>
                                    <td><x-severity-badge :severity="$finding->severity" size="xs" /></td>
                                    <td class="text-xs text-white"><a href="{{ route('remediation.show', $finding) }}" class="hover:text-cyan-300">{{ $finding->title }}</a></td>
                                    <td class="text-xs">{{ $finding->source_tool }}</td>
                                    <td class="text-xs font-mono">{{ $finding->cve_id ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-gray-500 py-3">No findings.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Recommendations --}}
    @if (!empty($report->recommendations))
    <div class="card p-5">
        <h2 class="font-display text-lg text-white mb-3">Recommendations</h2>
        @php
            $recs = collect($report->recommendations)->groupBy(function($r) { return $r['priority'] ?? 'medium'; });
            $order = ['critical','high','medium','low'];
        @endphp
        @foreach ($order as $p)
            @if ($recs->has($p))
                <div class="mb-4">
                    <div class="text-xs uppercase tracking-wider text-gray-500 mb-2">{{ ucfirst($p) }} priority</div>
                    <div class="space-y-1.5">
                        @foreach ($recs[$p] as $rec)
                            <label class="flex items-start gap-2 text-sm text-gray-300 cursor-pointer">
                                <input type="checkbox" class="mt-1 rounded border-white/10 bg-background text-primary focus:ring-primary">
                                <span>{{ $rec['action'] ?? $rec['description'] ?? (is_string($rec) ? $rec : json_encode($rec)) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </div>
    @endif

    {{-- AI analysis --}}
    @if (!empty($report->ai_analysis))
    <div class="card p-5">
        <h2 class="font-display text-lg text-white mb-3">AI Analysis</h2>
        @php $ai = $report->ai_analysis; @endphp
        @if (!empty($ai['summary']))
            <p class="text-sm text-gray-400 mb-4">{{ $ai['summary'] }}</p>
        @endif
        @if (!empty($ai['remediation_scripts']))
            <div class="space-y-3">
                @foreach ($ai['remediation_scripts'] as $script)
                    <div class="card !rounded-lg overflow-hidden">
                        <div class="px-3 py-2 border-b border-white/5 flex items-center justify-between">
                            <span class="badge-violet uppercase">{{ $script['language'] ?? 'bash' }}</span>
                            <button type="button" data-copy-text="{{ $script['code'] ?? '' }}" class="btn-ghost !p-1 text-xs" title="Copy"><span class="material-symbols-rounded text-[14px]">content_copy</span></button>
                        </div>
                        <pre class="!bg-black/40 p-3 text-xs overflow-auto max-h-64"><code>{{ $script['code'] ?? '' }}</code></pre>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
    @endif

    {{-- SBOM --}}
    @if (!empty($report->sbom))
    <div class="card p-5">
        <h2 class="font-display text-lg text-white mb-3">Software Bill of Materials (SBOM)</h2>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Component</th><th>Version</th><th>Type</th><th>License</th></tr></thead>
                <tbody>
                    @foreach (($report->sbom['components'] ?? $report->sbom) as $comp)
                        <tr>
                            <td class="text-sm text-white">{{ $comp['name'] ?? '—' }}</td>
                            <td class="text-xs font-mono">{{ $comp['version'] ?? '—' }}</td>
                            <td class="text-xs">{{ $comp['type'] ?? '—' }}</td>
                            <td class="text-xs">{{ $comp['license'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Graph snapshot --}}
    @if (!empty($report->graph_snapshot))
    <div class="card p-5">
        <h2 class="font-display text-lg text-white mb-3">Graph Snapshot</h2>
        <img src="{{ $report->graph_snapshot['image'] ?? '' }}" alt="Graph snapshot" class="max-w-full rounded-lg border border-white/5">
    </div>
    @endif
</div>

@push('scripts')
<script>
    window.addEventListener('DOMContentLoaded', () => {
        @php
            $findings = $report->scan?->findings ?? collect();
            $sevData = collect(['critical','high','medium','low','info'])->map(fn($s) => [
                'name' => ucfirst($s),
                'value' => $findings->where('severity', $s)->count(),
                'itemStyle' => ['color' => ['#ef4444','#f97316','#f59e0b','#06b6d4','#6b7280'][array_search($s, ['critical','high','medium','low','info'])]],
            ])->values()->all();
        @endphp
        const data = @json($sevData);
        if (window.echarts && document.getElementById('severity-chart')) {
            const chart = window.echarts.init(document.getElementById('severity-chart'), window.echartsTheme);
            chart.setOption({
                tooltip: { trigger: 'item' },
                legend: { bottom: 0, textStyle: { color: '#94a3b8' } },
                series: [{
                    type: 'pie', radius: ['45%','70%'],
                    label: { color: '#cbd5e1' },
                    data: data.some(d => d.value > 0) ? data : [{ name: 'No findings', value: 1 }],
                }],
            });
            window.addEventListener('resize', () => chart.resize());
        }

        document.querySelectorAll('[data-copy-text]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                await window.copyToClipboard(btn.dataset.copyText);
                const orig = btn.innerHTML;
                btn.innerHTML = '<span class="material-symbols-rounded text-[14px]">check</span>';
                setTimeout(() => { btn.innerHTML = orig; }, 1200);
            });
        });
    });
</script>
@endpush
@endsection
