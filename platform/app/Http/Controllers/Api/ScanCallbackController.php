<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetRelation;
use App\Models\Scan;
use App\Services\AuditLogger;
use App\Services\GraphBuilder;
use App\Traits\ProcessesScanResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * HTTP callback endpoint invoked by the platform's worker (HTTP polling
 * fallback when Redis Streams are unavailable) and by CI/CD integrations
 * that need to push findings or graph updates directly.
 *
 * Four endpoints are exposed:
 *   - `GET  /api/queue/next`               — fetch the next queued scan
 *   - `POST /api/scans/{scan}/callback`    — post back final scan status
 *   - `POST /api/scans/{scan}/findings`    — append findings to a running scan
 *   - `POST /api/scans/{scan}/graph`       — upsert graph nodes + edges
 *
 * All routes are protected by `auth:sanctum` (configured in routes/api.php).
 * When called by the worker (which authenticates with a bearer token rather
 * than a Sanctum session), the controller also honours the
 * `WORKER_CALLBACK_TOKEN` env var as a fallback authenticator.
 *
 * The `update()` method accepts a normalised payload describing the scan's
 * outcome (status, raw_output, findings, tools_status, severity_counts,
 * ai_analysis, remediation_scripts, graph_data) and persists it using the
 * shared {@see ProcessesScanResults} trait, so the behaviour is identical
 * whether the scan was completed via the job path or via the HTTP callback.
 */
class ScanCallbackController extends Controller
{
    use ProcessesScanResults;

    /** Name of the env var holding the worker callback token. */
    public const TOKEN_ENV = 'WORKER_CALLBACK_TOKEN';

    /**
     * Atomically claim the next queued scan for execution.
     *
     * Returns 204 when no scans are queued.
     */
    public function next(Request $request): JsonResponse
    {
        if (! $this->authenticate($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $scan = Scan::where('status', Scan::STATUS_QUEUED)
            ->orderBy('queued_at')
            ->first();

        if (! $scan) {
            return response()->json(null, 204);
        }

        // Atomic claim so multiple workers don't double-pick.
        $claimed = Scan::where('id', $scan->id)
            ->where('status', Scan::STATUS_QUEUED)
            ->update([
                'status' => Scan::STATUS_RUNNING,
                'started_at' => now(),
                'worker_id' => $request->ip().'#'.$request->header('X-Worker-Id', 'unknown'),
                'attempt' => $scan->attempt + 1,
            ]);

        if (! $claimed) {
            return $this->next($request);
        }

        $scan->refresh();

        return response()->json([
            'scan' => $scan->only([
                'id', 'project_id', 'target_id', 'user_id', 'type',
                'target_url', 'profile', 'config', 'correlation_id',
                'attempt', 'max_attempts',
            ]),
            'target' => $scan->target?->only(['id', 'name', 'domain_url', 'ip_address']),
        ]);
    }

    /**
     * Receive the final result of a scan from the worker.
     */
    public function update(Request $request, Scan $scan): JsonResponse
    {
        if (! $this->authenticate($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $this->validateUpdatePayload($request);

        $correlationId = $request->input('correlation_id');
        if ($correlationId && $scan->correlation_id && $correlationId !== $scan->correlation_id) {
            Log::warning('scan_callback.correlation_mismatch', [
                'scan_id' => $scan->id,
                'expected' => $scan->correlation_id,
                'received' => $correlationId,
            ]);

            return response()->json(['error' => 'Correlation id mismatch'], 422);
        }

        $this->processScanResults($scan, $payload);

        AuditLogger::system('scan.callback.processed', [
            'scan_id' => $scan->id,
            'correlation_id' => $scan->correlation_id,
            'status' => $payload['status'] ?? 'completed',
            'findings' => count($payload['findings'] ?? []),
        ]);

        $scan->refresh();

        return response()->json([
            'ok' => true,
            'scan_id' => $scan->id,
            'status' => $scan->status,
            'findings_count' => $scan->findings()->count(),
        ]);
    }

    /**
     * Append a batch of findings to a (possibly still-running) scan.
     *
     * Unlike {@see update()}, this endpoint does NOT transition the scan
     * status — it only inserts new findings and re-computes severity counts.
     */
    public function addFindings(Request $request, Scan $scan): JsonResponse
    {
        if (! $this->authenticate($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'findings' => ['required', 'array', 'min:1'],
            'findings.*.title' => ['required_with:findings', 'string'],
            'findings.*.severity' => ['nullable', 'string'],
            'findings.*.description' => ['nullable', 'string'],
            'findings.*.evidence' => ['nullable', 'string'],
            'findings.*.endpoint' => ['nullable', 'string'],
            'findings.*.affected_component' => ['nullable', 'string'],
            'findings.*.source_tool' => ['nullable', 'string'],
            'findings.*.cvss_score' => ['nullable', 'numeric'],
            'findings.*.cve_id' => ['nullable', 'string'],
            'findings.*.cwe_id' => ['nullable', 'string'],
            'findings.*.remediation' => ['nullable', 'string'],
        ]);

        $created = 0;
        foreach ($validated['findings'] as $findingData) {
            $this->persistFinding($scan, $findingData);
            $created++;
        }

        // Refresh severity counts on the scan row.
        $scan->severity_counts = $this->computeSeverityCounts($validated['findings']);
        $scan->save();

        AuditLogger::system('scan.callback.findings_added', [
            'scan_id' => $scan->id,
            'added' => $created,
        ]);

        return response()->json([
            'ok' => true,
            'scan_id' => $scan->id,
            'findings_added' => $created,
        ]);
    }

    /**
     * Upsert graph nodes + edges from a payload sent by the worker or
     * the recon microservice's graph builder.
     *
     * Expected payload shape:
     *   {
     *     "nodes": [{ "type": "...", "label": "...", "value": "...", ... }],
     *     "edges": [{ "source": "...", "target": "...", "type": "...", ... }]
     *   }
     */
    public function updateGraph(Request $request, Scan $scan): JsonResponse
    {
        if (! $this->authenticate($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'nodes' => ['nullable', 'array'],
            'nodes.*.type' => ['required_with:nodes', 'string'],
            'nodes.*.label' => ['required_with:nodes', 'string'],
            'nodes.*.value' => ['nullable', 'string'],
            'nodes.*.metadata' => ['nullable', 'array'],
            'nodes.*.risk_score' => ['nullable', 'numeric'],
            'edges' => ['nullable', 'array'],
            'edges.*.source_asset_id' => ['nullable', 'integer'],
            'edges.*.target_asset_id' => ['nullable', 'integer'],
            'edges.*.source_label' => ['nullable', 'string'],
            'edges.*.target_label' => ['nullable', 'string'],
            'edges.*.type' => ['required_with:edges', 'string'],
            'edges.*.weight' => ['nullable', 'numeric'],
        ]);

        $nodes = $validated['nodes'] ?? [];
        $edges = $validated['edges'] ?? [];
        $projectId = (int) $scan->project_id;

        $labelToId = [];
        foreach ($nodes as $nodeData) {
            $asset = Asset::firstOrCreate(
                [
                    'project_id' => $projectId,
                    'type' => $nodeData['type'],
                    'label' => $nodeData['label'],
                    'value' => $nodeData['value'] ?? null,
                ],
                [
                    'risk_score' => (float) ($nodeData['risk_score'] ?? 0),
                    'metadata' => $nodeData['metadata'] ?? null,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]
            );
            $labelToId[$nodeData['label']] = $asset->id;
        }

        $createdEdges = 0;
        foreach ($edges as $edgeData) {
            $sourceId = $edgeData['source_asset_id']
                ?? ($labelToId[$edgeData['source_label'] ?? ''] ?? null);
            $targetId = $edgeData['target_asset_id']
                ?? ($labelToId[$edgeData['target_label'] ?? ''] ?? null);
            if (! $sourceId || ! $targetId) {
                continue;
            }

            AssetRelation::firstOrCreate(
                [
                    'source_asset_id' => $sourceId,
                    'target_asset_id' => $targetId,
                    'type' => $edgeData['type'],
                ],
                [
                    'weight' => (float) ($edgeData['weight'] ?? 1.0),
                ]
            );
            $createdEdges++;
        }

        AuditLogger::system('scan.callback.graph_updated', [
            'scan_id' => $scan->id,
            'nodes' => count($nodes),
            'edges' => $createdEdges,
        ]);

        return response()->json([
            'ok' => true,
            'scan_id' => $scan->id,
            'nodes_upserted' => count($nodes),
            'edges_upserted' => $createdEdges,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Authenticate the inbound callback.
     *
     * Falls back to the WORKER_CALLBACK_TOKEN env var when Sanctum is
     * unavailable (worker-side bearer token). Both paths must be present
     * because the worker does not have a Sanctum session.
     */
    protected function authenticate(Request $request): bool
    {
        // 1. Sanctum-driven authentication (CI/CD clients).
        if ($request->user()) {
            return true;
        }

        // 2. Worker bearer-token fallback.
        $expected = env(self::TOKEN_ENV);
        if (! $expected) {
            Log::error('scan_callback.no_token_configured');

            return false;
        }

        $provided = $request->bearerToken();

        return is_string($provided) && hash_equals($expected, $provided);
    }

    /**
     * Validate the inbound update() payload.
     *
     * @return array<string,mixed>
     */
    protected function validateUpdatePayload(Request $request): array
    {
        $validated = $request->validate([
            'correlation_id' => ['nullable', 'string', 'uuid'],
            'status' => ['nullable', 'string', Rule::in(['completed', 'failed', 'running'])],
            'raw_output' => ['nullable', 'string'],
            'findings' => ['nullable', 'array'],
            'findings.*.title' => ['required_with:findings', 'string'],
            'findings.*.severity' => ['nullable', 'string'],
            'findings.*.description' => ['nullable', 'string'],
            'findings.*.evidence' => ['nullable', 'string'],
            'findings.*.endpoint' => ['nullable', 'string'],
            'findings.*.affected_component' => ['nullable', 'string'],
            'findings.*.source_tool' => ['nullable', 'string'],
            'findings.*.cvss_score' => ['nullable', 'numeric'],
            'findings.*.cve_id' => ['nullable', 'string'],
            'findings.*.cwe_id' => ['nullable', 'string'],
            'findings.*.remediation' => ['nullable', 'string'],
            'tools_status' => ['nullable', 'array'],
            'severity_counts' => ['nullable', 'array'],
            'ai_analysis' => ['nullable', 'array'],
            'remediation_scripts' => ['nullable', 'array'],
            'remediation_scripts.*.finding_id' => ['nullable', 'integer'],
            'remediation_scripts.*.title' => ['nullable', 'string'],
            'remediation_scripts.*.language' => ['nullable', 'string'],
            'remediation_scripts.*.code' => ['nullable', 'string'],
            'remediation_scripts.*.explanation' => ['nullable', 'string'],
            'graph_data' => ['nullable', 'array'],
        ]);

        return array_merge([
            'status' => 'completed',
            'raw_output' => null,
            'findings' => [],
            'tools_status' => null,
            'severity_counts' => null,
            'remediation_scripts' => [],
        ], $validated);
    }
}
