<?php

namespace App\Traits;

use App\Models\Asset;
use App\Models\Finding;
use App\Models\RemediationScript;
use App\Models\Scan;
use App\Models\SecurityAlert;
use App\Services\AuditLogger;
use App\Services\GraphBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared logic that ingests the result payload produced by a microservice
 * after running a scan, and persists it into the relational schema.
 *
 * Both the {@see \App\Jobs\ExecuteScan} job and the
 * {@see \App\Http\Controllers\Api\ScanCallbackController} HTTP callback
 * consume this trait so the platform behaves identically whether scans
 * are completed by a Redis Streams consumer (job path) or by a worker
 * polling the HTTP queue (callback path).
 *
 * The processor is idempotent: re-processing the same scan with the same
 * payload deletes previously-persisted findings before re-inserting, so
 * retries do not produce duplicate rows.
 */
trait ProcessesScanResults
{
    /**
     * Persist the result payload of a scan into findings, alerts,
     * remediation scripts, assets and asset_relations.
     *
     * @param  Scan  $scan  The scan being finalised.
     * @param  array<string,mixed>  $payload  Normalised microservice response.
     * @param  bool  $replaceExisting  When true (default), previously persisted
     *   findings for this scan are deleted first (idempotent reprocessing).
     */
    protected function processScanResults(Scan $scan, array $payload, bool $replaceExisting = true): void
    {
        DB::transaction(function () use ($scan, $payload, $replaceExisting): void {
            // 1. Update scan-level aggregates.
            $scan->fill([
                'status' => $payload['status'] ?? Scan::STATUS_COMPLETED,
                'raw_output' => is_string($payload['raw_output'] ?? null)
                    ? $payload['raw_output']
                    : json_encode($payload['raw_output'] ?? $payload, JSON_PRETTY_PRINT),
                'tools_status' => $payload['tools_status'] ?? $scan->tools_status,
                'severity_counts' => $payload['severity_counts'] ?? $this->computeSeverityCounts($payload['findings'] ?? []),
                'completed_at' => now(),
            ]);
            $scan->save();

            // 2. (Re)place findings.
            if ($replaceExisting) {
                $scan->findings()->delete();
            }

            $findings = $payload['findings'] ?? [];
            $createdFindings = [];
            foreach ($findings as $findingData) {
                $createdFindings[] = $this->persistFinding($scan, $findingData);
            }

            // 3. Raise alerts for high/critical findings.
            foreach ($createdFindings as $finding) {
                if (in_array($finding->severity, [Finding::SEVERITY_HIGH, Finding::SEVERITY_CRITICAL], true)) {
                    $this->raiseAlert($scan, $finding);
                }
            }

            // 4. Persist remediation scripts (if returned by the AI service).
            $scripts = $payload['remediation_scripts'] ?? [];
            foreach ($scripts as $scriptData) {
                $this->persistRemediationScript($scan, $scriptData);
            }

            // 5. Build/update the knowledge graph.
            if (! empty($createdFindings)) {
                try {
                    app(GraphBuilder::class)->createFromFindings($scan);
                } catch (\Throwable $e) {
                    Log::warning('scan.graph_build_failed', [
                        'scan_id' => $scan->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        AuditLogger::system('scan.results_processed', [
            'scan_id' => $scan->id,
            'correlation_id' => $scan->correlation_id,
            'findings_count' => count($payload['findings'] ?? []),
        ]);
    }

    /**
     * Persist a single finding row attached to the scan.
     *
     * @param  array<string,mixed>  $data
     */
    protected function persistFinding(Scan $scan, array $data): Finding
    {
        $severity = (string) ($data['severity'] ?? 'info');
        if (! in_array($severity, [
            Finding::SEVERITY_INFO,
            Finding::SEVERITY_LOW,
            Finding::SEVERITY_MEDIUM,
            Finding::SEVERITY_HIGH,
            Finding::SEVERITY_CRITICAL,
        ], true)) {
            $severity = Finding::SEVERITY_INFO;
        }

        return Finding::create([
            'scan_id' => $scan->id,
            'project_id' => $scan->project_id,
            'target_id' => $scan->target_id,
            'title' => (string) ($data['title'] ?? 'Untitled finding'),
            'description' => (string) ($data['description'] ?? ''),
            'severity' => $severity,
            'cvss_score' => $data['cvss_score'] ?? null,
            'cvss_vector' => $data['cvss_vector'] ?? null,
            'cve_id' => $data['cve_id'] ?? null,
            'cwe_id' => $data['cwe_id'] ?? null,
            'evidence' => (string) ($data['evidence'] ?? ''),
            'endpoint' => $data['endpoint'] ?? null,
            'affected_component' => $data['affected_component'] ?? null,
            'source_tool' => (string) ($data['source_tool'] ?? $scan->type),
            'remediation' => $data['remediation'] ?? null,
            'status' => Finding::STATUS_NEW,
            'is_false_positive' => (bool) ($data['is_false_positive'] ?? false),
            'impact_score' => (float) ($data['impact_score'] ?? 0),
            'citations' => $data['citations'] ?? null,
        ]);
    }

    /**
     * Raise a security alert for a high/critical finding.
     */
    protected function raiseAlert(Scan $scan, Finding $finding): SecurityAlert
    {
        return SecurityAlert::firstOrCreate([
            'project_id' => $scan->project_id,
            'scan_id' => $scan->id,
            'finding_id' => $finding->id,
            'title' => $finding->title,
        ], [
            'type' => 'finding.'.$finding->severity,
            'severity' => $finding->severity,
            'description' => $finding->description,
            'source' => SecurityAlert::SOURCE_SCAN,
            'acknowledged' => false,
        ]);
    }

    /**
     * Persist a remediation script produced by the AI service.
     *
     * @param  array<string,mixed>  $data
     */
    protected function persistRemediationScript(Scan $scan, array $data): ?RemediationScript
    {
        $title = $data['title'] ?? null;
        $code = $data['code'] ?? null;
        $findingId = $data['finding_id'] ?? null;
        if (! $title || ! $code || ! $findingId) {
            return null;
        }

        $finding = Finding::find($findingId);
        if (! $finding || (int) $finding->scan_id !== (int) $scan->id) {
            return null;
        }

        return RemediationScript::create([
            'finding_id' => $finding->id,
            'user_id' => $scan->user_id,
            'title' => (string) $title,
            'language' => (string) ($data['language'] ?? RemediationScript::LANG_BASH),
            'code' => (string) $code,
            'explanation' => $data['explanation'] ?? null,
            'status' => RemediationScript::STATUS_GENERATED,
        ]);
    }

    /**
     * Compute severity counts from a list of finding payloads.
     *
     * @param  array<int,array<string,mixed>>  $findings
     * @return array<string,int>
     */
    protected function computeSeverityCounts(array $findings): array
    {
        $counts = array_fill_keys([
            Finding::SEVERITY_INFO,
            Finding::SEVERITY_LOW,
            Finding::SEVERITY_MEDIUM,
            Finding::SEVERITY_HIGH,
            Finding::SEVERITY_CRITICAL,
        ], 0);

        foreach ($findings as $f) {
            $severity = $f['severity'] ?? Finding::SEVERITY_INFO;
            if (! isset($counts[$severity])) {
                $severity = Finding::SEVERITY_INFO;
            }
            $counts[$severity]++;
        }

        return $counts;
    }
}
