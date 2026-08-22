<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetRelation;
use App\Models\Finding;
use App\Models\Scan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds and queries the project knowledge graph.
 *
 * The graph is a typed, directed multigraph stored in the `assets` and
 * `asset_relations` tables. Nodes are typed (domain, ip, host, port,
 * service, vulnerability, impact) and edges describe structural
 * relationships (has_port, hosts, exposes, has_vulnerability, impacts,
 * connects_to).
 *
 * The builder consumes normalised findings produced by the recon/security
 * microservices and upserts the corresponding graph nodes + edges. Because
 * assets are unique by `(project_id, type, label, value)`, re-running a
 * scan on the same target is idempotent at the graph level: existing
 * nodes are kept and only their `last_seen_at` / `risk_score` are refreshed.
 */
class GraphBuilder
{
    /**
     * Build (or refresh) graph nodes + edges from a scan's findings.
     *
     * Findings without sufficient context (no affected component and no
     * endpoint) are skipped, since they cannot be reliably attached to
     * a concrete asset.
     */
    public function createFromFindings(Scan $scan): void
    {
        $projectId = (int) $scan->project_id;
        $now = now();

        /** @var Collection<int, Finding> $findings */
        $findings = $scan->findings()->get();

        if ($findings->isEmpty()) {
            return;
        }

        // Root asset: the target's host (or domain) — created once per scan.
        $root = $this->upsertAsset(
            projectId: $projectId,
            type: Asset::TYPE_DOMAIN,
            label: $scan->target_url,
            value: $scan->target_url,
            riskScore: 0.0,
            now: $now,
        );

        foreach ($findings as $finding) {
            try {
                $this->attachFinding($projectId, $root, $finding, $now);
            } catch (\Throwable $e) {
                // A single malformed finding must never break the whole graph build.
                Log::warning('graph_builder.finding_skipped', [
                    'finding_id' => $finding->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Attach a single finding to the graph: ensures a vulnerability node
     * exists and is linked to the root asset (or a more specific component
     * when the finding carries an affected_component).
     */
    protected function attachFinding(int $projectId, Asset $root, Finding $finding, $now): void
    {
        $componentLabel = $finding->affected_component ?: $finding->endpoint ?: $root->label;

        // If the finding names a specific component, model it as a host/service
        // node connected to the root.
        $hostAsset = $root;
        if ($componentLabel && $componentLabel !== $root->label) {
            $hostAsset = $this->upsertAsset(
                projectId: $projectId,
                type: Asset::TYPE_HOST,
                label: $componentLabel,
                value: $componentLabel,
                riskScore: 0.0,
                now: $now,
            );
            $this->upsertRelation($hostAsset->id, $root->id, AssetRelation::TYPE_HOSTS);
        }

        // Vulnerability node — risk score derived from CVSS or severity rank.
        $riskScore = $this->findingRiskScore($finding);
        $vulnAsset = $this->upsertAsset(
            projectId: $projectId,
            type: Asset::TYPE_VULNERABILITY,
            label: $finding->title,
            value: $finding->cve_id ?: $finding->cwe_id ?: $finding->title,
            riskScore: $riskScore,
            now: $now,
            metadata: [
                'severity' => $finding->severity,
                'cvss' => $finding->cvss_score,
                'cve' => $finding->cve_id,
                'cwe' => $finding->cwe_id,
            ],
        );

        $this->upsertRelation($hostAsset->id, $vulnAsset->id, AssetRelation::TYPE_HAS_VULNERABILITY);

        // Link the finding row to the vuln asset for traceability.
        if ($finding->asset_id !== $vulnAsset->id) {
            $finding->asset_id = $vulnAsset->id;
            $finding->saveQuietly();
        }
    }

    /**
     * Insert or update an asset node. Matching is done on the
     * `(project_id, type, label, value)` unique key.
     */
    protected function upsertAsset(
        int $projectId,
        string $type,
        string $label,
        ?string $value,
        float $riskScore,
        $now,
        array $metadata = null,
    ): Asset {
        $attributes = [
            'project_id' => $projectId,
            'type' => $type,
            'label' => $label,
            'value' => $value,
        ];

        $asset = Asset::where($attributes)->first();

        if ($asset === null) {
            $asset = new Asset($attributes + [
                'risk_score' => $riskScore,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'metadata' => $metadata,
            ]);
            $asset->save();
        } else {
            // Refresh timestamps + escalate risk_score if higher.
            $asset->last_seen_at = $now;
            if ($riskScore > $asset->risk_score) {
                $asset->risk_score = $riskScore;
            }
            if ($metadata) {
                $asset->metadata = array_merge((array) ($asset->metadata ?? []), $metadata);
            }
            $asset->save();
        }

        return $asset;
    }

    /**
     * Insert a relation edge if it doesn't already exist (no duplicates).
     */
    protected function upsertRelation(int $sourceId, int $targetId, string $type, float $weight = 1.0): AssetRelation
    {
        $relation = AssetRelation::where([
            'source_asset_id' => $sourceId,
            'target_asset_id' => $targetId,
            'type' => $type,
        ])->first();

        if ($relation === null) {
            $relation = new AssetRelation([
                'source_asset_id' => $sourceId,
                'target_asset_id' => $targetId,
                'type' => $type,
                'weight' => $weight,
            ]);
            $relation->save();
        }

        return $relation;
    }

    /**
     * Numeric risk score (0–10) for a finding.
     *
     * Uses CVSS when available, otherwise falls back to a severity-bucket
     * mapping so that findings without a CVSS still contribute to the graph.
     */
    protected function findingRiskScore(Finding $finding): float
    {
        if (is_numeric($finding->cvss_score) && $finding->cvss_score > 0) {
            return (float) min(10.0, $finding->cvss_score);
        }

        return match ($finding->severity) {
            Finding::SEVERITY_CRITICAL => 9.5,
            Finding::SEVERITY_HIGH => 7.5,
            Finding::SEVERITY_MEDIUM => 5.0,
            Finding::SEVERITY_LOW => 2.5,
            default => 1.0,
        };
    }

    /**
     * Compute the blast radius (set of affected assets) for a given asset.
     *
     * Performs an undirected BFS over the graph, following every edge type
     * except `connects_to` (which is informational rather than an impact
     * propagation path). Returns the affected assets ordered by their
     * distance from the seed, with the shortest path for each.
     *
     * @return array{
     *     seed: Asset,
     *     affected: list<array{asset: Asset, distance: int, path: list<int>}>
     * }
     */
    public function impactPropagation(Asset $asset, int $maxDepth = 10): array
    {
        $seed = $asset;
        $visited = [(int) $asset->id => 0];
        $paths = [(int) $asset->id => [(int) $asset->id]];
        $queue = [$asset->id];
        $affected = [];

        // Skip informational edges when propagating impact.
        $skipTypes = [AssetRelation::TYPE_CONNECTS_TO];

        while (! empty($queue) && $maxDepth > 0) {
            $next = [];
            foreach ($queue as $currentId) {
                $currentDepth = $visited[$currentId];

                // Fetch both outgoing and incoming edges (undirected traversal).
                $edges = DB::table('asset_relations')
                    ->where(function ($q) use ($currentId): void {
                        $q->where('source_asset_id', $currentId)
                            ->orWhere('target_asset_id', $currentId);
                    })
                    ->whereNotIn('type', $skipTypes)
                    ->get();

                foreach ($edges as $edge) {
                    $neighborId = (int) ($edge->source_asset_id === $currentId
                        ? $edge->target_asset_id
                        : $edge->source_asset_id);

                    if (isset($visited[$neighborId])) {
                        continue;
                    }

                    $visited[$neighborId] = $currentDepth + 1;
                    $paths[$neighborId] = array_merge($paths[$currentId], [$neighborId]);
                    $next[] = $neighborId;

                    $neighborAsset = Asset::find($neighborId);
                    if ($neighborAsset) {
                        $affected[] = [
                            'asset' => $neighborAsset,
                            'distance' => $currentDepth + 1,
                            'path' => $paths[$neighborId],
                        ];
                    }
                }
            }

            $queue = array_values(array_unique($next));
            $maxDepth--;
        }

        return [
            'seed' => $seed,
            'affected' => $affected,
        ];
    }

    /**
     * Serialise the project graph as a Cytoscape.js-compatible payload.
     *
     * @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>}
     */
    public function toCytoscape(int $projectId): array
    {
        $assets = Asset::where('project_id', $projectId)->get();
        $assetIds = $assets->pluck('id')->all();

        $relations = empty($assetIds)
            ? collect()
            : AssetRelation::whereIn('source_asset_id', $assetIds)
                ->orWhereIn('target_asset_id', $assetIds)
                ->get();

        $nodes = $assets->map(fn (Asset $a) => [
            'data' => [
                'id' => 'n-'.$a->id,
                'label' => $a->display_label,
                'type' => $a->type,
                'value' => $a->value,
                'risk_score' => $a->risk_score,
            ],
        ])->all();

        $edges = $relations->map(fn (AssetRelation $r) => [
            'data' => [
                'id' => 'e-'.$r->id,
                'source' => 'n-'.$r->source_asset_id,
                'target' => 'n-'.$r->target_asset_id,
                'type' => $r->type,
                'weight' => $r->weight,
            ],
        ])->all();

        return [
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }
}
