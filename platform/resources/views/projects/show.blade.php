@extends('layouts.app')

@section('title', $project->name)

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('projects.index') }}" class="hover:text-white">Projects</a>
    <span class="text-gray-600">/</span>
    <span class="text-white">{{ $project->name }}</span>
@endsection

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="card p-6 relative overflow-hidden">
        <div class="absolute top-0 left-0 right-0 h-1" style="background: {{ $project->branding_color }}"></div>
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-2">
                    <h1 class="font-display text-2xl font-semibold text-white">{{ $project->name }}</h1>
                    <x-status-badge :status="$project->status" />
                </div>
                @if ($project->client_name)
                    <div class="text-sm text-gray-400 flex items-center gap-2 mb-2">
                        <span class="material-symbols-rounded text-[16px]">business</span>
                        {{ $project->client_name }}
                    </div>
                @endif
                <div class="flex flex-wrap items-center gap-4 text-xs text-gray-500">
                    <span class="flex items-center gap-1"><span class="material-symbols-rounded text-[14px]">calendar_month</span> Created {{ $project->created_at?->format('Y-m-d') }}</span>
                    @if ($project->expires_at)
                        <span class="flex items-center gap-1"><span class="material-symbols-rounded text-[14px]">event</span> Expires {{ $project->expires_at?->format('Y-m-d') }}</span>
                    @endif
                    @if ($project->is_authorized)
                        <span class="badge-success"><span class="material-symbols-rounded text-[12px]">verified</span> Authorized</span>
                    @else
                        <span class="badge-medium"><span class="material-symbols-rounded text-[12px]">gpp_bad</span> Not authorized</span>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if (!auth()->user()->isClient() && !auth()->user()->isAuditor())
                    <a href="{{ route('projects.edit', $project) }}" class="btn-outline">
                        <span class="material-symbols-rounded text-base">edit</span> Edit
                    </a>
                    <a href="{{ route('scans.create', ['project' => $project->id]) }}" class="btn-primary">
                        <span class="material-symbols-rounded text-base">radar</span> New Scan
                    </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="border-b border-white/5" data-tab-group="project-tabs">
        <nav class="flex gap-1 overflow-x-auto tab-scroll">
            <button data-tab="overview"  class="nav-link !rounded-none border-b-2 border-transparent tab-active">Overview</button>
            <button data-tab="targets"   class="nav-link !rounded-none border-b-2 border-transparent">Targets</button>
            @if (!auth()->user()->isClient())
                <button data-tab="scans"     class="nav-link !rounded-none border-b-2 border-transparent">Scans</button>
                <button data-tab="findings"  class="nav-link !rounded-none border-b-2 border-transparent">Findings</button>
            @endif
            @if (!auth()->user()->isClient() && !auth()->user()->isAuditor())
                <button data-tab="graph"     class="nav-link !rounded-none border-b-2 border-transparent">Graph</button>
            @endif
            <button data-tab="reports"   class="nav-link !rounded-none border-b-2 border-transparent">Reports</button>
        </nav>
    </div>

    {{-- Overview --}}
    <div data-tab-panel="overview" data-tab-group="project-tabs">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="card p-5 lg:col-span-2 space-y-4">
                <h2 class="font-display text-lg text-white">Description</h2>
                @if ($project->description)
                    <p class="text-sm text-gray-300 leading-relaxed whitespace-pre-line">{{ $project->description }}</p>
                @else
                    <p class="text-sm text-gray-500">No description provided.</p>
                @endif

                <div>
                    <h3 class="text-sm font-medium text-gray-300 mb-2">Scope</h3>
                    @php $scope = $project->scope_config ?? []; @endphp
                    <div class="space-y-2 text-xs">
                        @if (!empty($scope['allowed_domains']))
                            <div>
                                <span class="text-gray-500">Allowed domains:</span>
                                @foreach ($scope['allowed_domains'] as $d)
                                    @if ($d) <span class="badge-cyan mr-1 mb-1">{{ $d }}</span> @endif
                                @endforeach
                            </div>
                        @endif
                        @if (!empty($scope['allowed_ips']))
                            <div>
                                <span class="text-gray-500">Allowed IPs/CIDRs:</span>
                                @foreach ($scope['allowed_ips'] as $ip)
                                    @if ($ip) <span class="badge-violet mr-1 mb-1">{{ $ip }}</span> @endif
                                @endforeach
                            </div>
                        @endif
                        @if (!empty($scope['excluded_paths']))
                            <div>
                                <span class="text-gray-500">Excluded paths:</span>
                                @foreach ((array) $scope['excluded_paths'] as $p)
                                    @if ($p) <code class="text-gray-300 mr-2">{{ $p }}</code> @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                @if ($project->authorization_document)
                    <div>
                        <h3 class="text-sm font-medium text-gray-300 mb-1">Authorization document</h3>
                        <a href="{{ Storage::url($project->authorization_document) }}" target="_blank" class="text-cyan-400 hover:text-cyan-300 text-sm inline-flex items-center gap-1">
                            <span class="material-symbols-rounded text-[16px]">attachment</span>
                            {{ basename($project->authorization_document) }}
                        </a>
                    </div>
                @endif
            </div>

            <div class="card p-5 space-y-3">
                <h2 class="font-display text-lg text-white">Stats</h2>
                <div class="grid grid-cols-2 gap-2">
                    <div class="card !rounded-lg p-3">
                        <div class="text-2xl font-display text-white">{{ $project->scans->count() }}</div>
                        <div class="text-xs text-gray-500">Scans</div>
                    </div>
                    <div class="card !rounded-lg p-3">
                        <div class="text-2xl font-display text-critical">{{ $project->findings->where('severity','critical')->count() }}</div>
                        <div class="text-xs text-gray-500">Critical</div>
                    </div>
                    <div class="card !rounded-lg p-3">
                        <div class="text-2xl font-display text-high">{{ $project->findings->where('severity','high')->count() }}</div>
                        <div class="text-xs text-gray-500">High</div>
                    </div>
                    <div class="card !rounded-lg p-3">
                        <div class="text-2xl font-display text-medium">{{ $project->findings->where('severity','medium')->count() }}</div>
                        <div class="text-xs text-gray-500">Medium</div>
                    </div>
                    <div class="card !rounded-lg p-3">
                        <div class="text-2xl font-display text-low">{{ $project->findings->where('severity','low')->count() }}</div>
                        <div class="text-xs text-gray-500">Low</div>
                    </div>
                    <div class="card !rounded-lg p-3">
                        <div class="text-2xl font-display text-info">{{ $project->findings->where('severity','info')->count() }}</div>
                        <div class="text-xs text-gray-500">Info</div>
                    </div>
                </div>
                <div class="card !rounded-lg p-3">
                    <div class="text-2xl font-display text-danger">{{ $project->alerts->where('acknowledged', false)->count() }}</div>
                    <div class="text-xs text-gray-500">Unacknowledged alerts</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Targets --}}
    <div data-tab-panel="targets" data-tab-group="project-tabs" class="hidden">
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Value</th>
                            <th>Authorization</th>
                            <th>OSINT</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($project->targets as $target)
                            <tr>
                                <td class="text-sm text-white">{{ $target->name }}</td>
                                <td><span class="badge-neutral capitalize">{{ $target->scope_type }}</span></td>
                                <td class="font-mono text-xs">{{ $target->domain_url ?: $target->ip_address ?: '—' }}</td>
                                <td><x-status-badge :status="$target->authorization_status" /></td>
                                <td>
                                    @if ($target->osint_data) <span class="badge-success">Available</span> @else <span class="badge-neutral">None</span> @endif
                                </td>
                                <td>
                                    @if (!auth()->user()->isClient() && !auth()->user()->isAuditor())
                                        <div class="flex items-center gap-1">
                                            <form method="POST" action="{{ route('osint.run', $target) }}">
                                                @csrf
                                                <button type="submit" class="btn-ghost !p-1.5 text-xs" title="Run passive OSINT">
                                                    <span class="material-symbols-rounded text-[18px]">travel_explore</span>
                                                </button>
                                            </form>
                                            @if ($target->osint_data)
                                                <a href="{{ route('osint.results', $target) }}" class="btn-ghost !p-1.5 text-xs" title="View OSINT">
                                                    <span class="material-symbols-rounded text-[18px]">visibility</span>
                                                </a>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-xs text-gray-500">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-gray-500 py-6">No targets declared.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if (!auth()->user()->isClient())
    {{-- Scans --}}
    <div data-tab-panel="scans" data-tab-group="project-tabs" class="hidden">
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Type</th><th>Target</th><th>Status</th><th>Profile</th><th>Started</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($project->scans as $scan)
                            <tr>
                                <td class="font-mono text-xs">{{ $scan->type }}</td>
                                <td class="text-sm text-white truncate max-w-[200px]">{{ $scan->target_url }}</td>
                                <td><x-status-badge :status="$scan->status" /></td>
                                <td><x-profile-badge :profile="$scan->profile" /></td>
                                <td class="text-xs">{{ $scan->started_at?->diffForHumans() ?? '—' }}</td>
                                <td><a href="{{ route('scans.show', $scan) }}" class="btn-ghost !p-1.5"><span class="material-symbols-rounded text-[18px]">visibility</span></a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-gray-500 py-6">No scans run for this project.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Findings --}}
    <div data-tab-panel="findings" data-tab-group="project-tabs" class="hidden">
        <div class="card p-4 mb-4">
            <div class="flex flex-wrap items-center gap-3">
                <select id="finding-severity-filter" class="input !w-auto">
                    <option value="">All severities</option>
                    @foreach (['critical','high','medium','low','info'] as $s)
                        <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
                <select id="finding-status-filter" class="input !w-auto">
                    <option value="">All statuses</option>
                    @foreach (['new','triaged','remediating','resolved','accepted_risk','false_positive'] as $s)
                        <option value="{{ $s }}">{{ ucfirst(str_replace('_',' ',$s)) }}</option>
                    @endforeach
                </select>
                <select id="finding-tool-filter" class="input !w-auto">
                    <option value="">All tools</option>
                    @foreach ($project->findings->pluck('source_tool')->unique() as $t)
                        <option value="{{ $t }}">{{ $t }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="space-y-3" id="findings-list">
            @forelse ($project->findings as $finding)
                <div class="card p-4 border-l-4"
                     data-severity="{{ $finding->severity }}"
                     data-status="{{ $finding->status }}"
                     data-tool="{{ $finding->source_tool }}"
                     style="border-left-color: {{ ['critical'=>'#ef4444','high'=>'#f97316','medium'=>'#f59e0b','low'=>'#06b6d4','info'=>'#6b7280'][$finding->severity] ?? '#6b7280' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <x-severity-badge :severity="$finding->severity" />
                                <span class="badge-neutral">{{ $finding->source_tool }}</span>
                                @if ($finding->cve_id) <span class="badge-danger">CVE {{ $finding->cve_id }}</span> @endif
                                @if ($finding->cvss_score) <span class="badge-neutral">CVSS {{ number_format($finding->cvss_score, 1) }}</span> @endif
                            </div>
                            <h3 class="font-medium text-white">{{ $finding->title }}</h3>
                            @if ($finding->endpoint) <div class="text-xs font-mono text-gray-400 mt-1">{{ $finding->endpoint }}</div> @endif
                            <p class="text-sm text-gray-400 mt-1 line-clamp-2">{{ $finding->description }}</p>
                        </div>
                        @if (!auth()->user()->isAuditor())
                            <a href="{{ route('remediation.show', $finding) }}" class="btn-ghost !py-1.5 text-xs">
                                View Remediation <span class="material-symbols-rounded text-[16px]">arrow_forward</span>
                            </a>
                        @else
                            <a href="{{ route('findings.show', $finding) }}" class="btn-ghost !py-1.5 text-xs">
                                Inspect Finding <span class="material-symbols-rounded text-[16px]">arrow_forward</span>
                            </a>
                        @endif
                    </div>
                </div>
            @empty
                <x-empty-state icon="search_off" title="No findings" message="Findings will appear here once scans complete." />
            @endforelse
        </div>
    </div>
    @endif

    @if (!auth()->user()->isClient() && !auth()->user()->isAuditor())
    {{-- Graph --}}
    <div data-tab-panel="graph" data-tab-group="project-tabs" class="hidden">
        <div class="card p-4">
            <div id="project-graph" class="h-[480px]"></div>
        </div>
    </div>
    @endif

    {{-- Reports --}}
    <div data-tab-panel="reports" data-tab-group="project-tabs" class="hidden">
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Title</th><th>Format</th><th>Generated</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($project->reports as $report)
                            <tr>
                                <td class="text-sm text-white">{{ $report->title }}</td>
                                <td><span class="badge-neutral uppercase">{{ $report->format }}</span></td>
                                <td class="text-xs">{{ $report->generated_at?->diffForHumans() ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('reports.show', $report) }}" class="btn-ghost !p-1.5"><span class="material-symbols-rounded text-[18px]">visibility</span></a>
                                    @if ($report->file_path)
                                        <a href="{{ route('reports.download', $report) }}" class="btn-ghost !p-1.5"><span class="material-symbols-rounded text-[18px]">download</span></a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-gray-500 py-6">No reports generated yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Finding filters
    (function () {
        const sev = document.getElementById('finding-severity-filter');
        const stat = document.getElementById('finding-status-filter');
        const tool = document.getElementById('finding-tool-filter');
        const items = document.querySelectorAll('#findings-list [data-severity]');
        function apply() {
            items.forEach((el) => {
                const ok = (!sev.value || el.dataset.severity === sev.value)
                    && (!stat.value || el.dataset.status === stat.value)
                    && (!tool.value || el.dataset.tool === tool.value);
                el.style.display = ok ? '' : 'none';
            });
        }
        [sev, stat, tool].forEach((s) => s && s.addEventListener('change', apply));
    })();
</script>
@endpush

@if ($project->assets->isNotEmpty())
@push('scripts')
<script type="module">
    import '{{ Vite::asset("resources/js/graph.js") }}';
    window.addEventListener('DOMContentLoaded', async () => {
        try {
            const res = await fetch("{{ route('projects.graph.data', $project) }}", { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (window.initKnowledgeGraph) {
                window.initKnowledgeGraph('#project-graph', data.elements || []);
            }
        } catch (e) {
            console.warn('graph load failed', e);
        }
    });
</script>
@endpush
@endif
@endsection
