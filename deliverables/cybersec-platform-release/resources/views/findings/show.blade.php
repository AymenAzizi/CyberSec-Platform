@extends('layouts.app')

@section('title', 'Finding · ' . $finding->title)

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <a href="{{ route('reports.index') }}" class="hover:text-white">Findings</a>
    <span class="text-gray-600">/</span>
    <span class="text-white truncate max-w-xs">{{ $finding->title }}</span>
@endsection

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="card p-6 border-l-4" style="border-left-color: {{ ['critical'=>'#ef4444','high'=>'#f97316','medium'=>'#f59e0b','low'=>'#06b6d4','info'=>'#6b7280'][$finding->severity] ?? '#6b7280' }}">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <x-severity-badge :severity="$finding->severity" />
                    <span class="badge-neutral">{{ $finding->source_tool }}</span>
                    @if ($finding->cve_id) <a href="https://nvd.nist.gov/vuln/detail/{{ $finding->cve_id }}" target="_blank" class="badge-danger">CVE {{ $finding->cve_id }}</a> @endif
                    @if ($finding->cwe_id) <span class="badge-neutral">CWE {{ $finding->cwe_id }}</span> @endif
                    @if ($finding->cvss_score) <span class="badge-neutral">CVSS {{ number_format($finding->cvss_score, 1) }}</span> @endif
                    @if ($finding->is_false_positive) <span class="badge-medium">False positive</span> @endif
                </div>
                <h1 class="font-display text-2xl font-semibold text-white">{{ $finding->title }}</h1>
                @if ($finding->endpoint) <div class="text-xs font-mono text-gray-400 mt-2 break-all">{{ $finding->endpoint }}</div> @endif
                <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500 mt-2">
                    @if ($finding->project) <span>Project: <a href="{{ route('projects.show', $finding->project) }}" class="text-cyan-400 hover:text-cyan-300">{{ $finding->project->name }}</a></span> @endif
                    @if ($finding->scan) <span>Scan: <a href="{{ route('scans.show', $finding->scan) }}" class="text-cyan-400 hover:text-cyan-300 font-mono">{{ $finding->scan->type }}</a></span> @endif
                    @if ($finding->target) <span>Target: <a href="{{ route('osint.results', $finding->target) }}" class="text-cyan-400 hover:text-cyan-300">{{ $finding->target->name }}</a></span> @endif
                </div>
            </div>
            <div class="flex flex-col gap-2 shrink-0">
                @if (in_array($finding->severity, ['high','critical']) && $finding->remediationScripts->isEmpty())
                    <form method="POST" action="{{ route('remediation.generate', $finding) }}">
                        @csrf
                        <button type="submit" class="btn-secondary w-full">
                            <span class="material-symbols-rounded text-base">auto_fix_high</span> Generate Remediation
                        </button>
                    </form>
                @endif
                @if ($finding->scan && $finding->scan->report)
                    <a href="{{ route('reports.show', $finding->scan->report) }}" class="btn-outline">
                        <span class="material-symbols-rounded text-base">description</span> View Report
                    </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Description + evidence --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="card p-5">
            <h2 class="font-display text-lg text-white mb-3">Description</h2>
            <p class="text-sm text-gray-300 leading-relaxed">{{ $finding->description }}</p>
            @if ($finding->affected_component)
                <div class="mt-3 text-xs">
                    <span class="text-gray-500">Affected component:</span>
                    <code class="text-cyan-300">{{ $finding->affected_component }}</code>
                </div>
            @endif
        </div>

        <div class="card p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-display text-lg text-white">Evidence</h2>
                <button type="button" data-copy-target="finding-evidence" class="btn-ghost !p-1.5 text-xs" title="Copy">
                    <span class="material-symbols-rounded text-[16px]">content_copy</span>
                </button>
            </div>
            <pre id="finding-evidence" class="terminal !rounded-lg max-h-64"><code>{{ $finding->evidence }}</code></pre>
        </div>
    </div>

    {{-- Remediation text --}}
    @if ($finding->remediation)
    <div class="card p-5">
        <h2 class="font-display text-lg text-white mb-3">Remediation</h2>
        <div class="prose prose-invert max-w-none text-sm text-gray-300">{{ Illuminate\Support\Str::markdown($finding->remediation) }}</div>
    </div>
    @endif

    {{-- Remediation scripts --}}
    @if ($finding->remediationScripts->isNotEmpty())
    <div class="card p-5">
        <h2 class="font-display text-lg text-white mb-3">Remediation Scripts ({{ $finding->remediationScripts->count() }})</h2>
        <div class="space-y-3">
            @foreach ($finding->remediationScripts as $script)
                <div class="card !rounded-lg overflow-hidden">
                    <div class="px-4 py-2.5 border-b border-white/5 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="badge-violet uppercase">{{ $script->language }}</span>
                            <span class="text-sm text-white">{{ $script->title }}</span>
                            @if ($script->status === 'verified') <span class="badge-success"><span class="material-symbols-rounded text-[12px]">verified</span> Verified</span> @endif
                            @if ($script->status === 'applied') <span class="badge-success"><span class="material-symbols-rounded text-[12px]">task_alt</span> Applied</span> @endif
                        </div>
                        <div class="flex items-center gap-1">
                            <a href="{{ route('remediation.download', $script) }}" class="btn-ghost !p-1.5 text-xs" title="Download"><span class="material-symbols-rounded text-[16px]">download</span></a>
                            @if ($script->status === 'generated')
                                <form method="POST" action="{{ route('remediation.verify', $script) }}">
                                    @csrf
                                    <button type="submit" class="btn-ghost !p-1.5 text-xs text-cyan-300" title="Verify"><span class="material-symbols-rounded text-[16px]">verified</span></button>
                                </form>
                                <form method="POST" action="{{ route('remediation.apply', $script) }}">
                                    @csrf
                                    <button type="submit" class="btn-ghost !p-1.5 text-xs text-emerald-300" title="Mark as applied" data-confirm="Mark this script as applied?"><span class="material-symbols-rounded text-[16px]">task_alt</span></button>
                                </form>
                            @endif
                        </div>
                    </div>
                    @if ($script->explanation)
                        <p class="px-4 py-2 text-xs text-gray-400 border-b border-white/5">{{ $script->explanation }}</p>
                    @endif
                    <pre class="!bg-black/40 p-4 text-xs overflow-auto max-h-80"><code>{{ $script->code }}</code></pre>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Citations --}}
    @if (!empty($finding->citations))
    <div class="card p-5">
        <h2 class="font-display text-lg text-white mb-3">Citations</h2>
        <div class="space-y-1">
            @foreach ($finding->citations as $cit)
                <a href="{{ route('scans.show', $finding->scan) }}#raw-output"
                   class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-white/5 border border-white/5">
                    <span class="text-sm text-gray-300">📖 {{ $cit['title'] ?? 'Reference' }}</span>
                    @if (!empty($cit['line'])) <span class="badge-cyan font-mono text-[11px]">L{{ $cit['line'] }}</span> @endif
                </a>
            @endforeach
        </div>
    </div>
    @endif
</div>
@endsection
