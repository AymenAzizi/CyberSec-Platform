<?php

namespace App\Jobs;

use App\Models\Report;
use App\Models\Scan;
use App\Services\AuditLogger;
use App\Services\MicroserviceClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Asynchronously generate a structured security assessment report from
 * a completed scan.
 *
 * The job consolidates the scan's findings into the four sections required
 * by the Final CDC: executive summary (AI-assisted), technical details
 * grouped by source tool, recommendations ordered by priority, and AI
 * analysis (which includes remediation scripts). The resulting report
 * row is then persisted with a deterministic PDF/HTML/JSON signature
 * hash so the file path can be served via the ReportController::export
 * method.
 */
class GenerateReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public array $backoff = [15, 30];

    public int $timeout = 300;

    public function __construct(
        public Scan $scan,
        public string $format = Report::FORMAT_PDF,
    ) {
        $this->onQueue('reports');
    }

    public function uniqueId(): string
    {
        return 'report:'.$this->scan->id.':'.$this->format;
    }

    public function handle(MicroserviceClient $client): void
    {
        $this->scan->refresh();

        // Findings + project + target eager-loaded for serialisation.
        $scan = Scan::with(['findings', 'project', 'target', 'user'])
            ->find($this->scan->id);
        if (! $scan) {
            return;
        }

        $findings = $scan->findings()->orderByDesc('severity_rank')->get();

        $executiveSummary = $this->buildExecutiveSummary($scan, $findings, $client);
        $technicalDetails = $this->buildTechnicalDetails($findings);
        $recommendations = $this->buildRecommendations($findings, $client);
        $aiAnalysis = $this->aiAnalysis($scan, $client);
        $remediationScripts = $this->collectRemediationScripts($findings);

        $report = Report::updateOrCreate(
            [
                'scan_id' => $scan->id,
                'format' => $this->format,
            ],
            [
                'project_id' => $scan->project_id,
                'scan_id' => $scan->id,
                'title' => $this->reportTitle($scan),
                'executive_summary' => $executiveSummary,
                'technical_details' => $technicalDetails,
                'recommendations' => $recommendations,
                'ai_analysis' => $aiAnalysis,
                'remediation_scripts' => $remediationScripts,
                'sbom' => null,
                'graph_snapshot' => $this->snapshotGraph($scan),
                'format' => $this->format,
                'generated_at' => now(),
            ],
        );

        $signature = hash('sha256', implode('|', [
            $report->id,
            $report->scan_id,
            $report->generated_at?->toIso8601String(),
            $report->format,
            config('app.key'),
        ]));

        $relativePath = 'reports/report-'.$report->id.'-'.$report->format.'-'
            .$report->generated_at->timestamp;
        $absolutePath = Storage::disk('local')->path($relativePath);
        @mkdir(dirname($absolutePath), 0775, true);

        // Store a canonical export file (PDF/HTML/JSON) so the
        // ReportController::download endpoint can stream it later.
        $contents = $this->serialiseExport($report, $executiveSummary);
        Storage::disk('local')->put($relativePath, $contents);

        $report->file_path = $relativePath;
        $report->signature = $signature;
        $report->save();

        AuditLogger::system('report.generated', [
            'report_id' => $report->id,
            'scan_id' => $scan->id,
            'format' => $this->format,
        ]);
    }

    /**
     * Build the executive summary, optionally augmented by the AI service.
     *
     * @param  \Illuminate\Support\Collection  $findings
     */
    protected function buildExecutiveSummary(Scan $scan, $findings, MicroserviceClient $client): string
    {
        $counts = $this->severityCounts($findings);
        $total = $findings->count();

        $summary = sprintf(
            "This report covers the %s scan executed against %s on %s.\n".
            "A total of %d findings were identified, distributed as follows:\n".
            "Critical: %d, High: %d, Medium: %d, Low: %d, Info: %d.\n".
            "The overall risk posture is %s.",
            $scan->type,
            $scan->target_url,
            $scan->completed_at?->toDateTimeString() ?? now()->toDateTimeString(),
            $total,
            $counts['critical'],
            $counts['high'],
            $counts['medium'],
            $counts['low'],
            $counts['info'],
            $this->overallRiskLevel($counts),
        );

        // Ask the AI service for a one-paragraph executive summary.
        if ($client->isConfigured('ai') && $total > 0) {
            try {
                $ai = $client->call('ai', '/summary', [
                    'scan_id' => $scan->id,
                    'scan_type' => $scan->type,
                    'target' => $scan->target_url,
                    'severity_counts' => $counts,
                    'top_findings' => $findings->take(10)->map(fn ($f) => [
                        'title' => $f->title,
                        'severity' => $f->severity,
                        'cvss' => $f->cvss_score,
                    ])->all(),
                ], timeout: 90, retries: 0);

                $aiSummary = is_array($ai) ? ($ai['summary'] ?? null) : null;
                if (is_string($aiSummary) && filled($aiSummary)) {
                    $summary .= "\n\nAI Executive Summary:\n".$aiSummary;
                }
            } catch (Throwable $e) {
                Log::warning('report.ai_summary_failed', [
                    'scan_id' => $scan->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * Group findings by their source tool to populate the technical details.
     *
     * @param  \Illuminate\Support\Collection  $findings
     * @return array<string,mixed>
     */
    protected function buildTechnicalDetails($findings): array
    {
        $grouped = [];
        foreach ($findings as $finding) {
            $tool = $finding->source_tool ?: 'unknown';
            $grouped[$tool][] = [
                'id' => $finding->id,
                'title' => $finding->title,
                'severity' => $finding->severity,
                'cvss' => $finding->cvss_score,
                'cve' => $finding->cve_id,
                'cwe' => $finding->cwe_id,
                'endpoint' => $finding->endpoint,
                'affected_component' => $finding->affected_component,
                'evidence' => mb_substr($finding->evidence, 0, 500),
            ];
        }

        return [
            'tools' => array_keys($grouped),
            'by_tool' => $grouped,
            'total_findings' => $findings->count(),
        ];
    }

    /**
     * Build an ordered list of recommendations from findings + AI suggestions.
     *
     * @param  \Illuminate\Support\Collection  $findings
     * @return list<array<string,mixed>>
     */
    protected function buildRecommendations($findings, MicroserviceClient $client): array
    {
        $priorityRank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];

        $recommendations = $findings
            ->filter(fn ($f) => filled($f->remediation))
            ->map(fn ($f) => [
                'finding_id' => $f->id,
                'title' => $f->title,
                'severity' => $f->severity,
                'priority' => $priorityRank[$f->severity] ?? 99,
                'remediation' => $f->remediation,
            ])
            ->sortBy('priority')
            ->values()
            ->all();

        return $recommendations;
    }

    /**
     * Fetch AI remediation suggestions for high/critical findings.
     *
     * @return array<string,mixed>
     */
    protected function aiAnalysis(Scan $scan, MicroserviceClient $client): array
    {
        if (! $client->isConfigured('ai')) {
            return [];
        }

        $highCritical = $scan->findings()
            ->whereIn('severity', ['high', 'critical'])
            ->limit(10)
            ->get();

        if ($highCritical->isEmpty()) {
            return [];
        }

        $analyses = [];
        foreach ($highCritical as $finding) {
            try {
                $result = $client->call('ai', '/remediation', [
                    'finding_id' => $finding->id,
                    'title' => $finding->title,
                    'severity' => $finding->severity,
                    'cve' => $finding->cve_id,
                    'cwe' => $finding->cwe_id,
                    'affected_component' => $finding->affected_component,
                    'evidence' => mb_substr($finding->evidence, 0, 1000),
                ], timeout: 120, retries: 0);

                if (! empty($result)) {
                    $analyses[(string) $finding->id] = $result;
                }
            } catch (Throwable $e) {
                Log::warning('report.ai_remediation_failed', [
                    'finding_id' => $finding->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $analyses;
    }

    /**
     * Collect any pre-existing remediation scripts attached to findings.
     *
     * @param  \Illuminate\Support\Collection  $findings
     * @return list<array<string,mixed>>
     */
    protected function collectRemediationScripts($findings): array
    {
        $scripts = [];
        foreach ($findings as $finding) {
            foreach ($finding->remediationScripts as $script) {
                $scripts[] = [
                    'finding_id' => $finding->id,
                    'script_id' => $script->id,
                    'title' => $script->title,
                    'language' => $script->language,
                    'code' => $script->code,
                    'explanation' => $script->explanation,
                ];
            }
        }

        return $scripts;
    }

    /**
     * Snapshot the project graph as a Cytoscape.js-compatible payload.
     *
     * @return array<string,mixed>|null
     */
    protected function snapshotGraph(Scan $scan): ?array
    {
        try {
            return app(\App\Services\GraphBuilder::class)
                ->toCytoscape((int) $scan->project_id);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Serialise the report into its canonical export format.
     */
    protected function serialiseExport(Report $report, string $summary): string
    {
        return match ($this->format) {
            Report::FORMAT_JSON => json_encode([
                'id' => $report->id,
                'title' => $report->title,
                'executive_summary' => $summary,
                'technical_details' => $report->technical_details,
                'recommendations' => $report->recommendations,
                'ai_analysis' => $report->ai_analysis,
                'remediation_scripts' => $report->remediation_scripts,
                'graph_snapshot' => $report->graph_snapshot,
                'signature' => $report->signature,
                'generated_at' => $report->generated_at?->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            Report::FORMAT_HTML, Report::FORMAT_PDF => $this->renderHtmlExport($report, $summary),
            default => $summary,
        };
    }

    /**
     * Render a self-contained HTML representation of the report.
     *
     * The ReportController::export endpoint will convert this to PDF via
     * DomPDF when the package is available; otherwise it is streamed as
     * HTML for download.
     */
    protected function renderHtmlExport(Report $report, string $summary): string
    {
        $findings = $report->technical_details['by_tool'] ?? [];
        $recommendations = $report->recommendations ?? [];
        $ai = $report->ai_analysis ?? [];

        $html = '<!doctype html><html><head><meta charset="utf-8">';
        $html .= '<title>'.e($report->title).'</title>';
        $html .= '<style>';
        $html .= 'body{font-family:Helvetica,Arial,sans-serif;color:#1f2937;padding:2em;line-height:1.5}';
        $html .= 'h1{color:#7c3aed;border-bottom:2px solid #7c3aed;padding-bottom:.3em}';
        $html .= 'h2{color:#4b5563;margin-top:1.5em}';
        $html .= 'pre{background:#f3f4f6;padding:1em;overflow:auto;border-radius:4px}';
        $html .= '.severity-critical{color:#b91c1c;font-weight:bold}';
        $html .= '.severity-high{color:#c2410c;font-weight:bold}';
        $html .= '.severity-medium{color:#a16207}';
        $html .= '.signature{margin-top:3em;border-top:1px solid #d1d5db;padding-top:1em;font-size:.85em;color:#6b7280}';
        $html .= '</style></head><body>';
        $html .= '<h1>'.e($report->title).'</h1>';
        $html .= '<p><em>Generated: '.e($report->generated_at?->toDateTimeString()).'</em></p>';

        $html .= '<h2>Executive Summary</h2>';
        $html .= '<p>'.nl2br(e($summary)).'</p>';

        if (! empty($findings)) {
            $html .= '<h2>Technical Details</h2>';
            foreach ($findings as $tool => $items) {
                $html .= '<h3>'.e($tool).'</h3><ul>';
                foreach ($items as $f) {
                    $html .= '<li class="severity-'.e($f['severity']).'">'
                        .e($f['title']).' ['.e($f['severity']).'] '
                        .($f['endpoint'] ? '— <code>'.e($f['endpoint']).'</code>' : '')
                        .'</li>';
                }
                $html .= '</ul>';
            }
        }

        if (! empty($recommendations)) {
            $html .= '<h2>Recommendations</h2><ol>';
            foreach ($recommendations as $r) {
                $html .= '<li><strong>'.e($r['title']).'</strong> ['.e($r['severity']).']<br>'
                    .nl2br(e($r['remediation'])).'</li>';
            }
            $html .= '</ol>';
        }

        if (! empty($ai)) {
            $html .= '<h2>AI Analysis &amp; Remediation-as-Code</h2>';
            foreach ($ai as $findingId => $analysis) {
                $html .= '<h3>'.e('Finding #'.$findingId).'</h3>';
                $html .= '<pre>'.e(json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>';
            }
        }

        $html .= '<div class="signature">';
        $html .= 'Report signature: <code>'.e($report->signature ?? '(unsigned)').'</code><br>';
        $html .= 'CyberSec Platform — generated '.e(now()->toDateTimeString());
        $html .= '</div>';

        $html .= '</body></html>';

        return $html;
    }

    /**
     * @param  \Illuminate\Support\Collection  $findings
     * @return array<string,int>
     */
    protected function severityCounts($findings): array
    {
        $counts = array_fill_keys(['critical', 'high', 'medium', 'low', 'info'], 0);
        foreach ($findings as $f) {
            $severity = $f->severity;
            if (isset($counts[$severity])) {
                $counts[$severity]++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string,int>  $counts
     */
    protected function overallRiskLevel(array $counts): string
    {
        if ($counts['critical'] > 0) {
            return 'CRITICAL — immediate remediation required';
        }
        if ($counts['high'] > 0) {
            return 'HIGH — prioritised remediation required';
        }
        if ($counts['medium'] > 0) {
            return 'MODERATE — schedule remediation';
        }

        return 'LOW — maintain monitoring';
    }

    protected function reportTitle(Scan $scan): string
    {
        return sprintf(
            'Security Assessment — %s — %s — %s',
            $scan->target_url,
            $scan->type,
            $scan->completed_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
        );
    }
}
