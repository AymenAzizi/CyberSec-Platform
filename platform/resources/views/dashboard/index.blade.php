@extends('layouts.app')

@section('title', 'Dashboard')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <span class="text-white">Dashboard</span>
@endsection

@section('content')
<div class="space-y-6">
    {{-- Page header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl font-semibold text-white">Dashboard</h1>
            <p class="text-sm text-gray-400">Welcome back, {{ auth()->user()->name }}.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('projects.create') }}" class="btn-primary">
                <span class="material-symbols-rounded text-base">add</span> New Project
            </a>
            <a href="{{ route('scans.create') }}" class="btn-secondary">
                <span class="material-symbols-rounded text-base">radar</span> New Scan
            </a>
            <a href="{{ route('reports.index') }}" class="btn-outline">
                <span class="material-symbols-rounded text-base">description</span> Reports
            </a>
        </div>
    </div>

    {{-- KPI cards (real counts) --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
        <x-kpi-card icon="folder"            label="Total Projects"           :value="$kpis['projects']"          color="violet" href="{{ route('projects.index') }}" />
        <x-kpi-card icon="radar"             label="Active Scans"             :value="$kpis['active_scans']"      color="cyan"   :pulse="true" href="{{ route('scans.index', ['status' => 'running']) }}" />
        <x-kpi-card icon="check_circle"      label="Completed Scans (today)"  :value="$kpis['completed_today']"   color="emerald" href="{{ route('scans.index') }}" />
        <x-kpi-card icon="priority_high"     label="Critical Findings"        :value="$kpis['critical']"          color="red"    href="{{ route('findings.index', ['severity' => 'critical']) }}" />
        <x-kpi-card icon="warning"           label="High Findings"            :value="$kpis['high']"              color="orange" href="{{ route('findings.index', ['severity' => 'high']) }}" />
        <x-kpi-card icon="notifications_active" label="Unacknowledged Alerts" :value="$kpis['unack_alerts']"      color="red"    href="{{ route('security.alerts') }}" />
        <x-kpi-card icon="summarize"         label="Total Findings"           :value="$kpis['total_findings']"    color="violet" href="{{ route('findings.index') }}" />
    </div>

    {{-- Charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="card p-5">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h2 class="font-display text-lg text-white">Findings by Severity</h2>
                    <p class="text-xs text-gray-500">Distribution across all projects</p>
                </div>
                <span class="material-symbols-rounded text-gray-600">donut_large</span>
            </div>
            <div id="severity-chart" class="h-72"></div>
        </div>

        <div class="card p-5">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h2 class="font-display text-lg text-white">Scans by Type</h2>
                    <p class="text-xs text-gray-500">Count of scans grouped by tool</p>
                </div>
                <span class="material-symbols-rounded text-gray-600">bar_chart</span>
            </div>
            <div id="scans-type-chart" class="h-72"></div>
        </div>
    </div>

    {{-- Recent scans + alerts --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="card xl:col-span-2 overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3 border-b border-white/5">
                <h2 class="font-display text-lg text-white">Recent Scans</h2>
                <a href="{{ route('scans.index') }}" class="text-xs text-cyan-400 hover:text-cyan-300">View all</a>
            </div>
            <div class="overflow-x-auto" data-searchable>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Target</th>
                            <th>Status</th>
                            <th>Profile</th>
                            <th>Started</th>
                            <th>Duration</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentScans as $scan)
                            <tr data-search-item="{{ $scan->type }} {{ $scan->target_url }}">
                                <td class="font-mono text-xs">{{ $scan->type }}</td>
                                <td class="text-sm text-white truncate max-w-[180px]">{{ $scan->target_url }}</td>
                                <td><x-status-badge :status="$scan->status" /></td>
                                <td><x-profile-badge :profile="$scan->profile" /></td>
                                <td class="text-xs">{{ $scan->started_at ? $scan->started_at->diffForHumans() : '—' }}</td>
                                <td class="text-xs">{{ $scan->duration ? formatDuration($scan->duration) : '—' }}</td>
                                <td>
                                    <a href="{{ route('scans.show', $scan) }}" class="btn-ghost !p-1.5" title="View">
                                        <span class="material-symbols-rounded text-[18px]">visibility</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-gray-500 py-6">No scans yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3 border-b border-white/5">
                <h2 class="font-display text-lg text-white">Recent Alerts</h2>
                <a href="{{ route('security.alerts') }}" class="text-xs text-cyan-400 hover:text-cyan-300">View all</a>
            </div>
            <div class="divide-y divide-white/5">
                @forelse ($recentAlerts as $alert)
                    <div class="px-5 py-3">
                        <div class="flex items-start gap-2">
                            <x-severity-badge :severity="$alert->severity" />
                            <div class="flex-1 min-w-0">
                                <div class="text-sm text-white truncate">{{ $alert->title }}</div>
                                <div class="text-[11px] text-gray-500 mt-0.5">
                                    @if ($alert->project) <a href="{{ route('projects.show', $alert->project) }}" class="hover:text-white">{{ $alert->project->name }}</a> · @endif
                                    {{ $alert->created_at->diffForHumans() }}
                                </div>
                            </div>
                            <form method="POST" action="{{ route('security.alerts.acknowledge', $alert) }}">
                                @csrf
                                <button type="submit" class="btn-ghost !p-1.5" title="Acknowledge">
                                    <span class="material-symbols-rounded text-[18px]">check</span>
                                </button>
                            </form>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-10 text-center text-sm text-gray-500">
                        <span class="material-symbols-rounded text-[28px] block mb-2 text-emerald-400">check_circle</span>
                        No unacknowledged alerts.
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Top vulnerable assets --}}
    <div class="card overflow-hidden">
        <div class="flex items-center justify-between px-5 py-3 border-b border-white/5">
            <h2 class="font-display text-lg text-white">Top Vulnerable Assets</h2>
            <a href="{{ route('projects.graph', $topAssets->first()?->project_id ?? 1) }}" class="text-xs text-cyan-400 hover:text-cyan-300">Open graph</a>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Asset</th>
                        <th>Type</th>
                        <th>Project</th>
                        <th>Risk score</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($topAssets as $asset)
                        @php $pct = min(100, ($asset->risk_score / 10) * 100); @endphp
                        <tr>
                            <td class="text-sm text-white">{{ $asset->label }}</td>
                            <td><span class="badge-neutral capitalize">{{ $asset->type }}</span></td>
                            <td class="text-xs">
                                @if ($asset->project) <a href="{{ route('projects.show', $asset->project) }}" class="hover:text-white">{{ $asset->project->name }}</a> @else — @endif
                            </td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="flex-1 max-w-[200px] h-1.5 rounded-full bg-white/5 overflow-hidden">
                                        <div class="h-full bg-gradient-to-r from-secondary to-danger" style="width: {{ number_format($pct, 1) }}%"></div>
                                    </div>
                                    <span class="text-xs font-mono text-white">{{ number_format($asset->risk_score, 1) }}/10</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-gray-500 py-6">No assets ranked yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@push('scripts')
<script>
    window.addEventListener('DOMContentLoaded', () => {
        const sevData = @json($severityChart);
        const typeData = @json($typeChart);

        if (window.echarts && document.getElementById('severity-chart')) {
            const sevTotal = sevData.reduce((s, d) => s + d.value, 0);
            const chart = window.echarts.init(document.getElementById('severity-chart'), window.echartsTheme);
            chart.setOption({
                tooltip: { trigger: 'item', formatter: (p) => `${p.name}: ${p.value} (${sevTotal ? (p.value / sevTotal * 100).toFixed(1) : 0}%)` },
                legend: { bottom: 0, textStyle: { color: '#94a3b8' } },
                series: [{
                    type: 'pie',
                    radius: ['45%', '70%'],
                    avoidLabelOverlap: true,
                    label: { color: '#cbd5e1' },
                    data: sevData.length ? sevData : [{ name: 'No findings', value: 1 }],
                }],
            });
            window.addEventListener('resize', () => chart.resize());
        }

        if (window.echarts && document.getElementById('scans-type-chart')) {
            const chart = window.echarts.init(document.getElementById('scans-type-chart'), window.echartsTheme);
            chart.setOption({
                tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                grid: { left: 40, right: 16, top: 16, bottom: 24 },
                xAxis: { type: 'category', data: typeData.map(d => d.name) },
                yAxis: { type: 'value', minInterval: 1 },
                series: [{
                    type: 'bar',
                    barWidth: '60%',
                    itemStyle: { color: '#7c3aed', borderRadius: [4, 4, 0, 0] },
                    data: typeData.map(d => d.value),
                }],
            });
            window.addEventListener('resize', () => chart.resize());
        }
    });
</script>
@endpush
@endsection
