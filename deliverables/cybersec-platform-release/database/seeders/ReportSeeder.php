<?php

namespace Database\Seeders;

use App\Models\Finding;
use App\Models\Project;
use App\Models\Report;
use App\Models\Scan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds one signed PDF report per completed scan that produced findings.
 *
 * The report payload includes:
 *   - executive_summary — real prose summarising the scan outcome
 *   - technical_details — findings grouped by severity, with CVSS + endpoint
 *   - recommendations   — prioritised remediation items
 *   - ai_analysis       — structured JSON the AI co-pilot would have produced
 *     (summary, citations referencing findings, remediation_scripts with
 *      language-tagged code blocks)
 *
 * Idempotent: keyed on (project_id, scan_id).
 */
class ReportSeeder extends Seeder
{
    /**
     * Run the seeder.
     */
    public function run(): void
    {
        $scans = Scan::where('status', Scan::STATUS_COMPLETED)
            ->whereHas('findings')
            ->get();

        foreach ($scans as $scan) {
            $project = $scan->project;
            if (! $project) {
                continue;
            }

            $findings = $scan->findings()
                ->orderByDesc('cvss_score')
                ->orderBy('severity')
                ->get();

            if ($findings->isEmpty()) {
                continue;
            }

            $this->upsertReport($project, $scan, $findings);
        }
    }

    /**
     * Build (or update) the report for a single scan.
     *
     * @param  \Illuminate\Support\Collection<int,Finding>  $findings
     */
    private function upsertReport(Project $project, Scan $scan, $findings): Report
    {
        $counts = $this->severityCounts($findings);
        $criticalFindings = $findings->where('severity', Finding::SEVERITY_CRITICAL);
        $highFindings = $findings->where('severity', Finding::SEVERITY_HIGH);

        $title = sprintf(
            '%s — %s Scan Report (%s)',
            $project->name,
            ucfirst($scan->type),
            $scan->target_url,
        );

        $executiveSummary = $this->buildExecutiveSummary($project, $scan, $counts, $criticalFindings, $highFindings);
        $technicalDetails = $this->buildTechnicalDetails($scan, $findings);
        $recommendations = $this->buildRecommendations($findings);
        $aiAnalysis = $this->buildAiAnalysis($scan, $findings);
        $remediationScripts = $this->extractRemediationScripts($findings);
        $sbom = $this->buildSbom($scan);

        return Report::updateOrCreate(
            [
                'project_id' => $project->id,
                'scan_id' => $scan->id,
            ],
            [
                'project_id' => $project->id,
                'scan_id' => $scan->id,
                'title' => $title,
                'executive_summary' => $executiveSummary,
                'technical_details' => $technicalDetails,
                'recommendations' => $recommendations,
                'ai_analysis' => $aiAnalysis,
                'remediation_scripts' => $remediationScripts,
                'sbom' => $sbom,
                'graph_snapshot' => null,
                'format' => Report::FORMAT_PDF,
                'file_path' => 'storage/reports/' . Str::slug($project->name) . '-' . $scan->type . '-' . $scan->id . '.pdf',
                'signature' => hash('sha256', $title . '|' . $scan->id . '|' . now()->timestamp),
                'generated_at' => Carbon::now(),
            ],
        );
    }

    /**
     * Build the executive summary prose from the finding set.
     *
     * @param  \Illuminate\Support\Collection<int,Finding>  $criticalFindings
     * @param  \Illuminate\Support\Collection<int,Finding>  $highFindings
     * @param  array<string,int>  $counts
     */
    private function buildExecutiveSummary(Project $project, Scan $scan, array $counts, $criticalFindings, $highFindings): string
    {
        $total = array_sum($counts);
        $criticalCount = $counts[Finding::SEVERITY_CRITICAL] ?? 0;
        $highCount = $counts[Finding::SEVERITY_HIGH] ?? 0;
        $mediumCount = $counts[Finding::SEVERITY_MEDIUM] ?? 0;
        $lowCount = $counts[Finding::SEVERITY_LOW] ?? 0;
        $infoCount = $counts[Finding::SEVERITY_INFO] ?? 0;

        $duration = $scan->completed_at && $scan->started_at
            ? $scan->completed_at->diffInSeconds($scan->started_at)
            : 0;

        $lines = [];

        $lines[] = "Executive Summary";
        $lines[] = "=================";
        $lines[] = "";
        $lines[] = "This report documents the results of an authorised {$scan->type} "
            . "scan performed against {$scan->target_url} as part of the engagement "
            . "''{$project->name}''. The scan executed in {$duration} seconds using "
            . "the ''{$scan->profile}'' execution profile and completed successfully "
            . "on {$scan->completed_at?->toDateTimeString()}.";
        $lines[] = "";
        $lines[] = "A total of {$total} findings were identified, broken down as follows:";
        $lines[] = "  - Critical: {$criticalCount}";
        $lines[] = "  - High:     {$highCount}";
        $lines[] = "  - Medium:   {$mediumCount}";
        $lines[] = "  - Low:      {$lowCount}";
        $lines[] = "  - Info:     {$infoCount}";
        $lines[] = "";

        if ($criticalCount > 0) {
            $lines[] = "Critical findings require immediate remediation. The following "
                . "issues represent exploitable vulnerabilities that could lead to "
                . "unauthorised access, data exfiltration, or remote code execution:";
            foreach ($criticalFindings as $f) {
                $lines[] = "  - " . ($f->cve_id ? "[{$f->cve_id}] " : '') . $f->title
                    . " (CVSS {$f->cvss_score}) at {$f->endpoint}";
            }
            $lines[] = "";
        }

        if ($highCount > 0) {
            $lines[] = "High-severity findings should be remediated within 7 days. "
                . "These represent exposures that, while not immediately critical, "
                . "materially weaken the security posture of the target:";
            foreach ($highFindings->take(5) as $f) {
                $lines[] = "  - " . ($f->cve_id ? "[{$f->cve_id}] " : '') . $f->title
                    . " at {$f->endpoint}";
            }
            $lines[] = "";
        }

        $lines[] = "Recommendations are prioritised in the Technical Details section "
            . "and accompanied by ready-to-apply remediation scripts reviewed by "
            . "the AI co-pilot. All findings should be validated in a staging "
            . "environment before being marked as resolved.";

        return implode("\n", $lines);
    }

    /**
     * Build the technical_details JSON payload (findings grouped by severity).
     *
     * @param  \Illuminate\Support\Collection<int,Finding>  $findings
     * @return array<string,mixed>
     */
    private function buildTechnicalDetails(Scan $scan, $findings): array
    {
        $grouped = [];

        foreach ([
            Finding::SEVERITY_CRITICAL,
            Finding::SEVERITY_HIGH,
            Finding::SEVERITY_MEDIUM,
            Finding::SEVERITY_LOW,
            Finding::SEVERITY_INFO,
        ] as $severity) {
            $rows = $findings->where('severity', $severity)->values();

            if ($rows->isEmpty()) {
                continue;
            }

            $grouped[] = [
                'severity' => $severity,
                'count' => $rows->count(),
                'items' => $rows->map(fn (Finding $f) => [
                    'id'                 => $f->id,
                    'title'              => $f->title,
                    'cve_id'             => $f->cve_id,
                    'cvss_score'         => $f->cvss_score,
                    'cvss_vector'        => $f->cvss_vector,
                    'cwe_id'             => $f->cwe_id,
                    'endpoint'           => $f->endpoint,
                    'affected_component' => $f->affected_component,
                    'evidence_excerpt'   => mb_substr($f->evidence, 0, 280),
                    'remediation_excerpt' => mb_substr($f->remediation ?? '', 0, 280),
                ])->toArray(),
            ];
        }

        return [
            'scan' => [
                'id'            => $scan->id,
                'type'          => $scan->type,
                'profile'       => $scan->profile,
                'target_url'    => $scan->target_url,
                'started_at'    => $scan->started_at?->toIso8601String(),
                'completed_at'  => $scan->completed_at?->toIso8601String(),
                'duration_sec'  => $scan->duration(),
                'raw_output_len' => strlen((string) $scan->raw_output),
            ],
            'findings_by_severity' => $grouped,
        ];
    }

    /**
     * Build the recommendations array (priority + effort + text per finding).
     *
     * @param  \Illuminate\Support\Collection<int,Finding>  $findings
     * @return list<array<string,mixed>>
     */
    private function buildRecommendations($findings): array
    {
        $recommendations = [];

        foreach ($findings as $f) {
            $recommendations[] = [
                'finding_id'    => $f->id,
                'title'         => $f->title,
                'severity'      => $f->severity,
                'priority'      => $this->priorityFor($f),
                'effort'        => $this->effortFor($f),
                'recommendation' => $f->remediation ?? 'No remediation guidance recorded.',
                'owner'         => null,
                'due_date'      => $this->dueDateFor($f),
            ];
        }

        return $recommendations;
    }

    /**
     * Build the AI analysis JSON payload (summary, citations, remediation scripts).
     *
     * @param  \Illuminate\Support\Collection<int,Finding>  $findings
     * @return array<string,mixed>
     */
    private function buildAiAnalysis(Scan $scan, $findings): array
    {
        $critical = $findings->where('severity', Finding::SEVERITY_CRITICAL)->values();
        $high = $findings->where('severity', Finding::SEVERITY_HIGH)->values();

        $summary = sprintf(
            "AI analysis of %s scan against %s identified %d critical and %d high-severity "
            . "issues out of %d total findings. The most pressing risk is %s. Immediate "
            . "remediation is required to prevent exploitation.",
            $scan->type,
            $scan->target_url,
            $critical->count(),
            $high->count(),
            $findings->count(),
            $critical->first()?->title ?? $high->first()?->title ?? 'no critical issues',
        );

        $citations = $findings->take(10)->map(fn (Finding $f) => [
            'finding_id' => $f->id,
            'title'      => $f->title,
            'cve_id'     => $f->cve_id,
            'endpoint'   => $f->endpoint,
            'lines'      => $f->citations ?? [],
        ])->toArray();

        $remediationScripts = $findings
            ->filter(fn (Finding $f) => filled($f->remediation))
            ->take(5)
            ->map(fn (Finding $f) => [
                'finding_id' => $f->id,
                'title'      => $f->title,
                'language'   => 'bash',
                'code'       => $this->extractScriptBlock($f->remediation),
                'explanation' => 'Auto-generated remediation snippet derived from finding evidence and CVE references.',
            ])
            ->values()
            ->toArray();

        return [
            'model' => 'qwen2.5-coder:7b',
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => $summary,
            'confidence' => 0.92,
            'citations' => $citations,
            'remediation_scripts' => $remediationScripts,
            'next_actions' => [
                'Validate findings in staging environment',
                'Apply remediation scripts in order of priority',
                'Re-scan to verify remediation effectiveness',
                'Update the asset inventory and risk register',
            ],
        ];
    }

    /**
     * Extract ready-to-run remediation scripts from each finding.
     *
     * @param  \Illuminate\Support\Collection<int,Finding>  $findings
     * @return list<array<string,mixed>>
     */
    private function extractRemediationScripts($findings): array
    {
        return $findings
            ->filter(fn (Finding $f) => filled($f->remediation))
            ->take(8)
            ->map(fn (Finding $f) => [
                'finding_id' => $f->id,
                'title'      => $f->title,
                'language'   => 'bash',
                'code'       => $this->extractScriptBlock($f->remediation),
                'explanation' => 'Script extracted from the finding remediation guidance.',
            ])
            ->values()
            ->toArray();
    }

    /**
     * Build a software bill of materials snapshot from the scan's target tech stack.
     *
     * @return array<string,mixed>
     */
    private function buildSbom(Scan $scan): array
    {
        $target = $scan->target;
        $techStack = $target?->tech_stack ?? [];

        $components = array_map(static function (string $tech): array {
            [$name, $version] = array_pad(explode(' ', $tech, 2), 2, null);

            return [
                'name'     => $name,
                'version'  => $version,
                'supplier' => null,
                'purl'     => null,
            ];
        }, $techStack);

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'target' => $scan->target_url,
            'components' => $components,
        ];
    }

    /**
     * Map a finding's severity to a remediation priority label.
     */
    private function priorityFor(Finding $f): string
    {
        return match ($f->severity) {
            Finding::SEVERITY_CRITICAL => 'P0 — immediate',
            Finding::SEVERITY_HIGH     => 'P1 — within 7 days',
            Finding::SEVERITY_MEDIUM   => 'P2 — within 30 days',
            Finding::SEVERITY_LOW      => 'P3 — within 90 days',
            default                    => 'P4 — informational',
        };
    }

    /**
     * Estimate the engineering effort to remediate a finding.
     */
    private function effortFor(Finding $f): string
    {
        $title = strtolower($f->title);

        return match (true) {
            str_contains($title, 'header') => 'low — config change',
            str_contains($title, 'version') => 'medium — package upgrade',
            str_contains($title, 'rce') || str_contains($title, 'remote code') => 'high — code change + upgrade',
            str_contains($title, 'sqli') || str_contains($title, 'sql injection') => 'medium — refactor data layer',
            str_contains($title, 'xss') || str_contains($title, 'cross-site') => 'medium — templating fix',
            default => 'medium — review required',
        };
    }

    /**
     * Compute a due-date string based on severity.
     */
    private function dueDateFor(Finding $f): string
    {
        $days = match ($f->severity) {
            Finding::SEVERITY_CRITICAL => 1,
            Finding::SEVERITY_HIGH     => 7,
            Finding::SEVERITY_MEDIUM   => 30,
            Finding::SEVERITY_LOW      => 90,
            default                    => 180,
        };

        return Carbon::now()->addDays($days)->toDateString();
    }

    /**
     * Extract the code-like block from a remediation text.
     *
     * Falls back to the full remediation text when no fenced block is found.
     */
    private function extractScriptBlock(?string $remediation): string
    {
        if (! $remediation) {
            return '';
        }

        // Heuristic: take everything that looks like a config / shell line.
        $lines = explode("\n", $remediation);
        $code = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || preg_match('/^\d+\.\s/', $trimmed)) {
                continue;
            }
            $code[] = $line;
        }

        return implode("\n", $code);
    }

    /**
     * Count findings per severity bucket.
     *
     * @param  \Illuminate\Support\Collection<int,Finding>  $findings
     * @return array<string,int>
     */
    private function severityCounts($findings): array
    {
        return [
            Finding::SEVERITY_CRITICAL => $findings->where('severity', Finding::SEVERITY_CRITICAL)->count(),
            Finding::SEVERITY_HIGH     => $findings->where('severity', Finding::SEVERITY_HIGH)->count(),
            Finding::SEVERITY_MEDIUM   => $findings->where('severity', Finding::SEVERITY_MEDIUM)->count(),
            Finding::SEVERITY_LOW      => $findings->where('severity', Finding::SEVERITY_LOW)->count(),
            Finding::SEVERITY_INFO     => $findings->where('severity', Finding::SEVERITY_INFO)->count(),
        ];
    }
}
