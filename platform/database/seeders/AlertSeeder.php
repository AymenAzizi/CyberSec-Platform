<?php

namespace Database\Seeders;

use App\Models\Finding;
use App\Models\Project;
use App\Models\Scan;
use App\Models\SecurityAlert;
use Illuminate\Database\Seeder;

/**
 * Seeds unacknowledged security alerts for each project that has at least
 * one critical or high finding. Two alert templates are produced per
 * critical finding (one for the CVE, one for the business impact), capped
 * at 3 alerts per project to avoid noise.
 *
 * Idempotent: alerts are keyed on (project_id, finding_id, type) so
 * re-running the seeder does not create duplicates.
 */
class AlertSeeder extends Seeder
{
    /**
     * Run the seeder.
     */
    public function run(): void
    {
        foreach (Project::all() as $project) {
            // Wipe existing alerts for this project so the catalogue stays
            // the source of truth and the seeder remains idempotent even
            // when FindingSeeder regenerates finding IDs.
            SecurityAlert::where('project_id', $project->id)->delete();

            $criticalFindings = Finding::where('project_id', $project->id)
                ->whereIn('severity', [Finding::SEVERITY_CRITICAL, Finding::SEVERITY_HIGH])
                ->orderByDesc('cvss_score')
                ->limit(3)
                ->get();

            if ($criticalFindings->isEmpty()) {
                continue;
            }

            foreach ($criticalFindings as $finding) {
                // CVE-driven alert
                if ($finding->cve_id) {
                    $this->upsertAlert($project, $finding, 'cve_detected', $finding->severity, [
                        'title' => "[{$finding->cve_id}] Critical vulnerability detected on {$finding->affected_component}",
                        'description' => "Nuclei matched {$finding->cve_id} on the target "
                            . "{$finding->endpoint}. CVSS {$finding->cvss_score} ({$finding->cvss_vector}). "
                            . "Exploitation has not yet been observed in access logs but the asset is "
                            . "internet-exposed and the exploit is publicly available.",
                    ]);
                }

                // Business-impact alert (always emitted for critical findings)
                $this->upsertAlert($project, $finding, 'business_impact', $finding->severity, [
                    'title' => "Potential {$this->impactLabel($finding)} from {$finding->title}",
                    'description' => "Finding \"{$finding->title}\" was detected at "
                        . "{$finding->endpoint}. Based on the CVSS score and asset context, this "
                        . "exposure could lead to {$this->impactLabel($finding)}. Immediate triage "
                        . "is recommended; the alert will auto-escalate if not acknowledged within 24h.",
                ]);
            }
        }
    }

    /**
     * Insert (or update) an alert keyed on (project_id, finding_id, type).
     *
     * @param  array<string,mixed>  $payload
     */
    private function upsertAlert(Project $project, Finding $finding, string $type, string $severity, array $payload): SecurityAlert
    {
        return SecurityAlert::updateOrCreate(
            [
                'project_id' => $project->id,
                'finding_id' => $finding->id,
                'type' => $type,
            ],
            [
                'project_id'  => $project->id,
                'scan_id'     => $finding->scan_id,
                'finding_id'  => $finding->id,
                'type'        => $type,
                'severity'    => $severity,
                'title'       => $payload['title'],
                'description' => $payload['description'],
                'source'      => SecurityAlert::SOURCE_SCAN,
                'acknowledged' => false,
                'acknowledged_by' => null,
                'acknowledged_at' => null,
            ],
        );
    }

    /**
     * Pick a readable impact label for the alert title.
     */
    private function impactLabel(Finding $finding): string
    {
        $title = strtolower($finding->title);

        return match (true) {
            str_contains($title, 'rce') || str_contains($title, 'remote code') => 'remote code execution',
            str_contains($title, 'sqli') || str_contains($title, 'sql injection') => 'data exfiltration',
            str_contains($title, 'xss') || str_contains($title, 'cross-site') => 'session theft',
            str_contains($title, 'git') || str_contains($title, 'disclosure') => 'source code disclosure',
            default => 'unauthorised access',
        };
    }
}
