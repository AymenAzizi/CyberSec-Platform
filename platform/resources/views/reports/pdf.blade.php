<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $report->title }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css'])
    <style>
        body { background: #ffffff !important; color: #0f172a !important; }
        .pdf-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .pdf-card h2 { color: #0f172a; border-bottom: 2px solid {{ $report->project?->branding_color ?? '#7c3aed' }}; padding-bottom: 8px; margin-bottom: 12px; }
        .pdf-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .pdf-table th { background: {{ $report->project?->branding_color ?? '#7c3aed' }}; color: white; text-align: left; padding: 6px 8px; }
        .pdf-table td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        .brand-bar { background: {{ $report->project?->branding_color ?? '#7c3aed' }}; height: 6px; width: 100%; }
        @page { margin: 24px 18px; }
        .page-footer { position: fixed; bottom: 8px; left: 18px; right: 18px; text-align: center; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 4px; }
    </style>
</head>
<body>
    <div class="brand-bar"></div>

    <header class="px-6 py-5 border-b border-slate-200 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="h-10 w-10 rounded-lg flex items-center justify-center text-white" style="background: {{ $report->project?->branding_color ?? '#7c3aed' }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
            </div>
            <div>
                <div class="font-display font-semibold text-slate-900 text-lg">CyberSec Platform</div>
                <div class="text-xs text-slate-500">Security Assessment Report</div>
            </div>
        </div>
        <div class="text-right text-xs text-slate-500">
            <div>Generated: {{ $report->generated_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i') }}</div>
            @if ($report->is_signed) <div class="text-emerald-600 font-medium mt-1">✓ Signed report</div> @endif
        </div>
    </header>

    <main class="px-6 py-6">
        <h1 class="font-display text-2xl font-semibold text-slate-900 mb-1">{{ $report->title }}</h1>
        <div class="text-sm text-slate-500 mb-6">
            @if ($report->project) Project: {{ $report->project->name }} @endif
            @if ($report->project?->client_name) · Client: {{ $report->project->client_name }} @endif
        </div>

        @if ($report->executive_summary)
        <section class="pdf-card">
            <h2>Executive Summary</h2>
            <div class="text-sm text-slate-700 leading-relaxed">{{ Illuminate\Support\Str::markdown($report->executive_summary) }}</div>
        </section>
        @endif

        @if ($report->scan)
        <section class="pdf-card">
            <h2>Scan Information</h2>
            <table class="pdf-table">
                <tr><th>Type</th><td>{{ $report->scan->type }}</td><th>Target</th><td>{{ $report->scan->target_url }}</td></tr>
                <tr><th>Profile</th><td>{{ ucfirst($report->scan->profile) }}</td><th>Duration</th><td>{{ $report->scan->duration ? formatDuration($report->scan->duration) : '—' }}</td></tr>
                <tr><th>Started</th><td>{{ $report->scan->started_at?->format('Y-m-d H:i') ?? '—' }}</td><th>Completed</th><td>{{ $report->scan->completed_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
            </table>
        </section>
        @endif

        @if ($report->scan && $report->scan->findings->isNotEmpty())
        <section class="pdf-card">
            <h2>Findings ({{ $report->scan->findings->count() }})</h2>
            <table class="pdf-table">
                <thead>
                    <tr><th>Severity</th><th>Title</th><th>Tool</th><th>CVE</th><th>Endpoint</th></tr>
                </thead>
                <tbody>
                    @foreach ($report->scan->findings->sortByDesc(fn($f) => \App\Models\Finding::SEVERITY_RANK[$f->severity] ?? 0) as $f)
                        <tr>
                            <td style="color: {{ ['#ef4444','#f97316','#f59e0b','#06b6d4','#6b7280'][array_search($f->severity,['critical','high','medium','low','info'])] ?: '#6b7280' }}; font-weight:600; text-transform:uppercase; font-size:11px;">{{ $f->severity }}</td>
                            <td>{{ $f->title }}</td>
                            <td>{{ $f->source_tool }}</td>
                            <td>{{ $f->cve_id ?? '—' }}</td>
                            <td>{{ $f->endpoint ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        @endif

        @if (!empty($report->recommendations))
        <section class="pdf-card">
            <h2>Recommendations</h2>
            <ul class="text-sm text-slate-700 list-disc pl-5 space-y-1">
                @foreach (($report->recommendations) as $rec)
                    <li>{{ is_array($rec) ? ($rec['action'] ?? $rec['description'] ?? json_encode($rec)) : $rec }}</li>
                @endforeach
            </ul>
        </section>
        @endif

        @if (!empty($report->sbom))
        <section class="pdf-card">
            <h2>SBOM</h2>
            <table class="pdf-table">
                <thead><tr><th>Component</th><th>Version</th><th>License</th></tr></thead>
                <tbody>
                    @foreach (($report->sbom['components'] ?? $report->sbom) as $comp)
                        <tr>
                            <td>{{ $comp['name'] ?? '—' }}</td>
                            <td>{{ $comp['version'] ?? '—' }}</td>
                            <td>{{ $comp['license'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        @endif
    </main>

    <div class="page-footer">CyberSec Platform · {{ $report->title }} · Page <span class="page-number"></span></div>
    <script>
        // Page numbers (filled by the PDF renderer / browser print dialog)
        document.querySelectorAll('.page-number').forEach((el) => { el.textContent = '1'; });
        window.print();
    </script>
</body>
</html>
