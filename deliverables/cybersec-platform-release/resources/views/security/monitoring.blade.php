@extends('layouts.app')

@section('title', 'Monitoring')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('security.alerts') }}" class="hover:text-white">Security</a>
    <span class="text-gray-600">/</span>
    <span class="text-white">Monitoring</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl font-semibold text-white">Monitoring</h1>
            <p class="text-sm text-gray-400">Real-time security event stream from the security microservice.</p>
        </div>
        <div class="flex items-center gap-2 text-xs">
            @if ($recentActivity)
                <span class="pulse-dot bg-emerald-400 mr-1"></span>
                <span class="text-emerald-300">Live · activity in last 5 min</span>
            @else
                <span class="h-2 w-2 rounded-full bg-gray-500 mr-1"></span>
                <span class="text-gray-500">Idle · no recent activity</span>
            @endif
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <x-kpi-card icon="bolt"            label="Total events"     :value="$stats['total'] ?? 0"      color="violet" />
        <x-kpi-card icon="warning"         label="Alerts (24h)"     :value="$stats['alerts_24h'] ?? 0" color="amber" />
        <x-kpi-card icon="notifications_active" label="Unack alerts" :value="$stats['unack'] ?? 0"     color="red" />
        <x-kpi-card icon="speed"           label="Events / min"     :value="$stats['per_minute'] ?? 0" color="cyan" :pulse="($stats['per_minute'] ?? 0) > 0" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="card p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-display text-lg text-white">Events by Type</h2>
                <span class="material-symbols-rounded text-gray-600">donut_large</span>
            </div>
            <div id="events-type-chart" class="h-72"></div>
        </div>

        <div class="card p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-display text-lg text-white">Events by Severity</h2>
                <span class="material-symbols-rounded text-gray-600">bar_chart</span>
            </div>
            <div id="events-severity-chart" class="h-72"></div>
        </div>
    </div>

    {{-- Events table --}}
    <div class="card overflow-hidden">
        <div class="flex items-center justify-between px-5 py-3 border-b border-white/5">
            <h2 class="font-display text-lg text-white">Recent Events ({{ $events->count() }})</h2>
            <button id="refresh-events" class="btn-ghost text-xs"><span class="material-symbols-rounded text-[16px]">refresh</span> Refresh</button>
        </div>
        <div class="overflow-x-auto" data-searchable>
            <table class="table">
                <thead>
                    <tr><th>Timestamp</th><th>Type</th><th>Severity</th><th>Data</th></tr>
                </thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr data-search-item="{{ $event['type'] ?? '' }}">
                            <td class="text-xs">{{ isset($event['timestamp']) ? formatDate($event['timestamp']) : '—' }}</td>
                            <td class="text-sm text-white font-mono">{{ $event['type'] ?? '—' }}</td>
                            <td><x-severity-badge :severity="($event['severity'] ?? 'info')" size="xs" /></td>
                            <td class="text-xs font-mono truncate max-w-[320px]">{{ json_encode($event['data'] ?? $event) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-gray-500 py-6">No events recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@push('scripts')
<script>
    window.addEventListener('DOMContentLoaded', () => {
        const byType = @json($stats['by_type'] ?? []);
        const bySev = @json($stats['by_severity'] ?? []);

        if (window.echarts && document.getElementById('events-type-chart')) {
            const data = Object.entries(byType).map(([name, value]) => ({ name, value }));
            const chart = window.echarts.init(document.getElementById('events-type-chart'), window.echartsTheme);
            chart.setOption({
                tooltip: { trigger: 'item' },
                legend: { bottom: 0, type: 'scroll', textStyle: { color: '#94a3b8' } },
                series: [{
                    type: 'pie', radius: ['40%','70%'],
                    label: { color: '#cbd5e1' },
                    data: data.length ? data : [{ name: 'No events', value: 1 }],
                }],
            });
            window.addEventListener('resize', () => chart.resize());
        }
        if (window.echarts && document.getElementById('events-severity-chart')) {
            const keys = ['critical','high','medium','low','info'];
            const chart = window.echarts.init(document.getElementById('events-severity-chart'), window.echartsTheme);
            chart.setOption({
                tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                grid: { left: 40, right: 16, top: 16, bottom: 24 },
                xAxis: { type: 'category', data: keys.map(ucfirst) },
                yAxis: { type: 'value', minInterval: 1 },
                series: [{
                    type: 'bar', barWidth: '60%',
                    itemStyle: { color: (p) => ['#ef4444','#f97316','#f59e0b','#06b6d4','#6b7280'][p.dataIndex] },
                    data: keys.map(k => bySev[k] ?? 0),
                }],
            });
            window.addEventListener('resize', () => chart.resize());
        }
        function ucfirst(s){ return s.charAt(0).toUpperCase()+s.slice(1); }

        document.getElementById('refresh-events')?.addEventListener('click', () => window.location.reload());
    });
</script>
@endpush
@endsection
