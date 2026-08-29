@extends('layouts.app')

@section('title', 'Scan #' . $scan->id)

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('scans.index') }}" class="hover:text-white">Scans</a>
    <span class="text-gray-600">/</span>
    <span class="text-white">#{{ $scan->id }}</span>
@endsection

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <h1 class="font-display text-2xl font-semibold text-white font-mono">{{ $scan->type }}</h1>
                <x-status-badge :status="$scan->status" />
                <x-profile-badge :profile="$scan->profile" />
            </div>
            <div class="text-sm text-gray-400 font-mono">{{ $scan->target_url }}</div>
            <div class="flex flex-wrap items-center gap-4 text-xs text-gray-500 mt-2">
                @if ($scan->project) <span><a href="{{ route('projects.show', $scan->project) }}" class="hover:text-white">{{ $scan->project->name }}</a></span> @endif
                <span>Started: {{ $scan->started_at?->toDateTimeString() ?? '—' }}</span>
                <span>Duration: {{ $scan->duration ? formatDuration($scan->duration) : '—' }}</span>
                <span>Attempt: {{ $scan->attempt }}/{{ $scan->max_attempts }}</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if (!auth()->user()->isClient() && !auth()->user()->isAuditor())
                @if (in_array($scan->status, ['queued','running','pending']))
                    <form method="POST" action="{{ route('scans.cancel', $scan) }}" data-confirm="Cancel this scan?">
                        @csrf
                        <button type="submit" class="btn-danger"><span class="material-symbols-rounded text-base">cancel</span> Cancel</button>
                    </form>
                @endif
                @if ($scan->status === 'failed' && $scan->canRetry())
                    <form method="POST" action="{{ route('scans.retry', $scan) }}">
                        @csrf
                        <button type="submit" class="btn-accent"><span class="material-symbols-rounded text-base">refresh</span> Retry</button>
                    </form>
                @endif
                @if ($scan->status === 'completed' && !$scan->report)
                    <a href="{{ route('reports.generate', $scan) }}" class="btn-secondary"><span class="material-symbols-rounded text-base">description</span> Generate Report</a>
                @endif
            @endif
            @if ($scan->report)
                <a href="{{ route('reports.show', $scan->report) }}" class="btn-secondary"><span class="material-symbols-rounded text-base">description</span> View Report</a>
                <a href="{{ route('reports.export', [$scan->report, 'pdf']) }}" class="btn-outline"><span class="material-symbols-rounded text-base">picture_as_pdf</span> Export PDF</a>
            @endif
            @if ($scan->status === 'completed')
                <a href="{{ route('scans.export', $scan) }}" class="btn-outline"><span class="material-symbols-rounded text-base">code</span> Export JSON</a>
            @endif
        </div>
    </div>

    {{-- Status timeline --}}
    <div class="card p-5">
        <div class="flex items-center justify-between gap-2">
            @php
                $steps = ['queued','running','completed'];
                $currentIdx = array_search($scan->status, $steps);
                if ($scan->status === 'pending') $currentIdx = -1;
                if ($scan->status === 'failed') $currentIdx = 1; // running step shows red
                if ($scan->status === 'cancelled') $currentIdx = -1;
            @endphp
            @foreach ($steps as $i => $step)
                @php
                    $done = $currentIdx !== false && $i <= $currentIdx && $scan->status !== 'failed';
                    $active = $currentIdx === $i && $scan->status === 'running';
                    $failed = $scan->status === 'failed' && $i === 1;
                @endphp
                <div class="flex-1 flex items-center {{ $i < count($steps) - 1 ? 'flex-1' : '' }}">
                    <div class="flex flex-col items-center gap-1">
                        <div class="h-8 w-8 rounded-full flex items-center justify-center border-2
                                    {{ $failed ? 'bg-danger border-danger text-white' : ($done ? 'bg-success border-success text-white' : ($active ? 'bg-primary border-primary text-white animate-pulse' : 'bg-surface border-white/10 text-gray-500')) }}">
                            @if ($failed)
                                <span class="material-symbols-rounded text-[16px]">error</span>
                            @elseif ($done)
                                <span class="material-symbols-rounded text-[16px]">check</span>
                            @else
                                <span class="material-symbols-rounded text-[16px]">{{ ['hourglass_top','play_circle','check_circle'][$i] }}</span>
                            @endif
                        </div>
                        <span class="text-xs {{ $done || $active || $failed ? 'text-white' : 'text-gray-500' }}">{{ ucfirst($step) }}</span>
                    </div>
                    @if ($i < count($steps) - 1)
                        <div class="flex-1 h-0.5 mx-2 {{ $i < $currentIdx ? 'bg-success' : 'bg-white/10' }}"></div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Findings --}}
    <div class="card p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-display text-lg text-white">Findings ({{ $scan->findings->count() }})</h2>
            <div class="flex flex-wrap gap-1.5" id="severity-chips">
                @foreach (['all','critical','high','medium','low','info'] as $sev)
                    <button data-chip="{{ $sev }}"
                            class="badge {{ $sev === 'all' ? 'badge-violet' : 'badge-' . $sev }} cursor-pointer hover:opacity-80">
                        {{ $sev === 'all' ? 'All' : ucfirst($sev) }}
                        @if ($sev !== 'all') <span class="ml-1 text-[10px] opacity-70">{{ $scan->findings->where('severity',$sev)->count() }}</span> @endif
                    </button>
                @endforeach
            </div>
        </div>

        <div class="space-y-3" id="findings-list">
            @forelse ($scan->findings as $finding)
                <div class="card p-4 border-l-4"
                     data-severity="{{ $finding->severity }}"
                     style="border-left-color: {{ ['critical'=>'#ef4444','high'=>'#f97316','medium'=>'#f59e0b','low'=>'#06b6d4','info'=>'#6b7280'][$finding->severity] ?? '#6b7280' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <x-severity-badge :severity="$finding->severity" />
                                <span class="badge-neutral">{{ $finding->source_tool }}</span>
                                @if ($finding->cve_id) <span class="badge-danger">CVE {{ $finding->cve_id }}</span> @endif
                                @if ($finding->cvss_score) <span class="badge-neutral">CVSS {{ number_format($finding->cvss_score, 1) }}</span> @endif
                            </div>
                            <h3 class="font-medium text-white">{{ $finding->title }}</h3>
                            @if ($finding->endpoint) <div class="text-xs font-mono text-gray-400 mt-1">{{ $finding->endpoint }}</div> @endif
                            <p class="text-sm text-gray-400 mt-1">{{ $finding->description }}</p>
                            @if ($finding->evidence)
                                <details class="mt-2">
                                    <summary class="text-xs text-cyan-400 cursor-pointer hover:text-cyan-300">View evidence</summary>
                                    <pre class="terminal mt-2 text-xs whitespace-pre-wrap"><code>{{ $finding->evidence }}</code></pre>
                                </details>
                            @endif
                        </div>
                        <div class="flex flex-col gap-1.5 shrink-0">
                            <a href="{{ route('remediation.show', $finding) }}" class="btn-ghost !py-1.5 text-xs text-center">View Details</a>
                            @if ($finding->remediationScripts->isEmpty())
                                <form method="POST" action="{{ route('remediation.generate', $finding) }}">
                                    @csrf
                                    <button type="submit" class="btn-secondary !py-1.5 text-xs w-full">
                                        <span class="material-symbols-rounded text-[14px]">auto_fix_high</span> Generate Remediation
                                    </button>
                                </form>
                            @else
                                <a href="{{ route('remediation.show', $finding) }}" class="btn-secondary !py-1.5 text-xs text-center w-full">
                                    <span class="material-symbols-rounded text-[14px]">code</span> Remediation ({{ $finding->remediationScripts->count() }})
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <x-empty-state icon="search_off" title="No findings" message="This scan produced no findings." />
            @endforelse
        </div>
    </div>

    {{-- Raw output + AI analysis --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3 border-b border-white/5">
                <h2 class="font-display text-lg text-white">Raw Output</h2>
                <div class="flex items-center gap-2">
                    <input id="raw-search" type="text" class="input !py-1 !text-xs w-44" placeholder="Filter output…">
                    <button type="button" data-copy-target="raw-output" class="btn-ghost !p-1.5 text-xs" title="Copy">
                        <span class="material-symbols-rounded text-[16px]">content_copy</span>
                    </button>
                </div>
            </div>
            <div id="raw-output" class="terminal !rounded-none h-[400px] overflow-auto">
                @if ($scan->raw_output)
                    @php
                        $lines = explode("\n", $scan->raw_output);
                    @endphp
                    @foreach ($lines as $i => $line)
                        <div class="raw-line hover:bg-white/5"><span class="line-num">{{ $i + 1 }}</span><span class="raw-text">{{ $line }}</span></div>
                    @endforeach
                @else
                    <div class="text-gray-500 p-4">No raw output captured.</div>
                @endif
            </div>
        </div>

        @if ($scan->ai_analysis ?? null)
        <div class="card overflow-hidden">
            <div class="px-5 py-3 border-b border-white/5">
                <h2 class="font-display text-lg text-white">AI Analysis</h2>
            </div>
            <div class="p-5 space-y-4">
                @php $ai = $scan->ai_analysis; @endphp
                @if (!empty($ai['summary']))
                    <div>
                        <h3 class="text-sm font-medium text-gray-300 mb-1">Summary</h3>
                        <p class="text-sm text-gray-400">{{ $ai['summary'] }}</p>
                    </div>
                @endif
                @if (!empty($ai['citations']))
                    <div>
                        <h3 class="text-sm font-medium text-gray-300 mb-1">Citations</h3>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($ai['citations'] as $cit)
                                <a href="#raw-output" data-line="{{ $cit['line'] ?? null }}"
                                   class="badge-cyan cursor-pointer hover:opacity-80"
                                   onclick="document.getElementById('raw-output').scrollTop = (({{ $cit['line'] ?? 0 }} - 1) * 18); return false;">
                                    📖 {{ $cit['title'] ?? 'Citation' }} @if (!empty($cit['line'])) · L{{ $cit['line'] }} @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if (!empty($ai['remediation_scripts']))
                    <div>
                        <h3 class="text-sm font-medium text-gray-300 mb-2">Remediation Scripts</h3>
                        <div class="space-y-3">
                            @foreach ($ai['remediation_scripts'] as $script)
                                <div class="card !rounded-lg overflow-hidden">
                                    <div class="px-3 py-2 border-b border-white/5 flex items-center justify-between">
                                        <span class="badge-violet uppercase">{{ $script['language'] ?? 'bash' }}</span>
                                        <div class="flex gap-1">
                                            <button type="button" data-copy-text="{{ $script['code'] ?? '' }}" class="btn-ghost !p-1 text-xs" title="Copy"><span class="material-symbols-rounded text-[14px]">content_copy</span></button>
                                            <a href="#" class="btn-ghost !p-1 text-xs" title="Download" data-copy-text="{{ $script['code'] ?? '' }}"><span class="material-symbols-rounded text-[14px]">download</span></a>
                                        </div>
                                    </div>
                                    <pre class="!bg-black/40 p-3 text-xs overflow-auto max-h-64"><code>{{ $script['code'] ?? '' }}</code></pre>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
        @endif
    </div>

    {{-- Knowledge graph mini-widget --}}
    @if ($scan->project && $scan->project->assets->isNotEmpty())
    <div class="card p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-display text-lg text-white">Knowledge Graph</h2>
            <a href="{{ route('projects.graph', $scan->project) }}" class="text-xs text-cyan-400 hover:text-cyan-300">View Full Graph</a>
        </div>
        <div id="scan-graph" class="h-[320px]"></div>
    </div>
    @endif
</div>

@push('scripts')
<script>
    // Severity chip filter
    document.querySelectorAll('#severity-chips [data-chip]').forEach((chip) => {
        chip.addEventListener('click', () => {
            const sev = chip.dataset.chip;
            document.querySelectorAll('#findings-list [data-severity]').forEach((el) => {
                el.style.display = (sev === 'all' || el.dataset.severity === sev) ? '' : 'none';
            });
        });
    });

    // Raw output search
    const rawSearch = document.getElementById('raw-search');
    if (rawSearch) {
        rawSearch.addEventListener('input', () => {
            const q = rawSearch.value.toLowerCase();
            document.querySelectorAll('#raw-output .raw-line').forEach((line) => {
                const t = line.querySelector('.raw-text')?.textContent.toLowerCase() || '';
                line.style.display = !q || t.includes(q) ? '' : 'none';
            });
        });
    }

    // Copy arbitrary text buttons
    document.querySelectorAll('[data-copy-text]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            await window.copyToClipboard(btn.dataset.copyText);
            const original = btn.innerHTML;
            btn.innerHTML = '<span class="material-symbols-rounded text-[14px]">check</span>';
            setTimeout(() => { btn.innerHTML = original; }, 1200);
        });
    });
</script>
@endpush

@if ($scan->project && $scan->project->assets->isNotEmpty())
@push('scripts')
<script type="module">
    import '{{ Vite::asset('resources/js/graph.js') }}';
    window.addEventListener('DOMContentLoaded', async () => {
        try {
            const res = await fetch("{{ route('projects.graph.data', $scan->project) }}", { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (window.initKnowledgeGraph) {
                window.initKnowledgeGraph('#scan-graph', data.elements || []);
            }
        } catch (e) { console.warn(e); }
    });
</script>
@endpush
@endif
@endsection
