<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\AssetRelation;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Target;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the knowledge graph for every project.
 *
 * For each project the seeder materialises:
 *   - 1 domain asset (root domain)
 *   - 1–2 IP assets (per unique target IP)
 *   - 4 port assets (22, 80, 443, 8080)
 *   - 4 service assets (ssh, http, https, http-proxy)
 *   - 5–8 vulnerability assets (deduplicated by CVE id / title from findings)
 *   - 5 impact assets (Data Exfiltration, Remote Code Execution, ...)
 *
 * And connects them with typed edges:
 *   domain --hosts--> ip --has_port--> port --exposes--> service
 *                                     |
 *                                     +-- has_vulnerability --> vulnerability --impacts--> impact
 *
 * Idempotent: assets are keyed by (project_id, type, label, value) thanks to
 * the unique index from the migration. Relations are wiped per-project and
 * re-inserted so the edge catalogue can evolve.
 */
class AssetSeeder extends Seeder
{
    /**
     * Static catalogue of impact assets shared across projects.
     *
     * @var array<int,array<string,mixed>>
     */
    private const IMPACTS = [
        ['label' => 'Data Exfiltration',         'value' => 'data_exfiltration', 'risk' => 9.5],
        ['label' => 'Remote Code Execution',     'value' => 'rce',               'risk' => 9.8],
        ['label' => 'Account Takeover',          'value' => 'account_takeover',  'risk' => 8.5],
        ['label' => 'Privilege Escalation',      'value' => 'privilege_escalation', 'risk' => 8.0],
        ['label' => 'Service Disruption',        'value' => 'service_disruption', 'risk' => 6.5],
    ];

    /**
     * Static catalogue of ports + services discovered per project.
     *
     * @var array<int,array<string,string|int>>
     */
    private const PORT_SERVICE_MATRIX = [
        ['port' => 22,   'protocol' => 'tcp', 'service' => 'ssh',         'product' => 'OpenSSH 8.9p1'],
        ['port' => 80,   'protocol' => 'tcp', 'service' => 'http',        'product' => 'Apache httpd 2.4.58'],
        ['port' => 443,  'protocol' => 'tcp', 'service' => 'https',       'product' => 'Apache httpd 2.4.58 (TLS)'],
        ['port' => 8080, 'protocol' => 'tcp', 'service' => 'http-proxy',  'product' => 'Jetty 9.4.51.v20230217'],
    ];

    /**
     * Run the seeder.
     */
    public function run(): void
    {
        foreach (Project::all() as $project) {
            $this->seedProjectGraph($project);
        }
    }

    /**
     * Build the full knowledge graph for a single project.
     */
    private function seedProjectGraph(Project $project): void
    {
        $targets = $project->targets;
        if ($targets->isEmpty()) {
            return;
        }

        // Wipe ALL existing assets for this project. The migration sets
        // ON DELETE CASCADE on asset_relations, so the edges are removed
        // automatically. This keeps the seeder fully idempotent — the
        // graph converges to the same node/edge set on every run, even
        // when the underlying target/finding catalogue changes.
        Asset::where('project_id', $project->id)->delete();

        // 1) Domain asset (use the project's root domain).
        $rootTarget = $targets->firstWhere('scope_type', Target::SCOPE_DOMAIN) ?? $targets->first();
        $domain = $this->upsertAsset($project, Asset::TYPE_DOMAIN, $rootTarget->domain_url, $rootTarget->domain_url, [
            'registrar' => $rootTarget->osint_data['whois']['registrar'] ?? null,
            'tech_stack' => $rootTarget->tech_stack ?? [],
        ], 5.0);

        // 2) IP assets (deduplicated by value).
        $ips = [];
        foreach ($targets->pluck('ip_address')->filter() as $ip) {
            if (isset($ips[$ip])) {
                continue;
            }
            $ips[$ip] = $this->upsertAsset($project, Asset::TYPE_IP, $ip, $ip, [
                'reverse_dns' => $rootTarget->domain_url,
            ], 6.0);
        }

        // 3) Port + service assets.
        $ports = [];
        $services = [];
        foreach (self::PORT_SERVICE_MATRIX as $row) {
            $portNumber = (int) $row['port'];
            $protocol = (string) $row['protocol'];
            $portLabel = "Port {$portNumber}/{$protocol}";
            $ports[$portNumber] = $this->upsertAsset($project, Asset::TYPE_PORT, $portLabel, (string) $portNumber, [
                'protocol' => $protocol,
                'state' => 'open',
                'reason' => 'syn-ack',
            ], 7.0);

            $serviceName = (string) $row['service'];
            $serviceLabel = ucfirst($serviceName) . ' Service';
            $services[$serviceName] = $this->upsertAsset($project, Asset::TYPE_SERVICE, $serviceLabel, $serviceName, [
                'product' => $row['product'],
                'transport' => 'tcp',
            ], 7.5);
        }

        // 4) Vulnerability assets — deduplicated by CVE id / title, capped
        //    at 8 per project to keep the knowledge graph readable. We pick
        //    the highest-CVSS findings first so the graph always reflects
        //    the most pressing risks.
        $vulns = [];
        $findings = Finding::where('project_id', $project->id)
            ->orderByDesc('cvss_score')
            ->orderBy('severity')
            ->get();

        foreach ($findings as $finding) {
            if (count($vulns) >= 8) {
                break;
            }
            $key = $finding->cve_id ?: $finding->title;
            if (isset($vulns[$key])) {
                continue;
            }
            $risk = $this->cvssToRiskScore($finding->cvss_score);
            $vulns[$key] = $this->upsertAsset($project, Asset::TYPE_VULNERABILITY, $key, $finding->title, [
                'severity'    => $finding->severity,
                'cvss_score'  => $finding->cvss_score,
                'cvss_vector' => $finding->cvss_vector,
                'cwe_id'      => $finding->cwe_id,
                'finding_id'  => $finding->id,
            ], $risk);
        }

        // 5) Impact assets (shared catalogue).
        $impacts = [];
        foreach (self::IMPACTS as $row) {
            $impacts[$row['value']] = $this->upsertAsset(
                $project,
                Asset::TYPE_IMPACT,
                $row['label'],
                $row['value'],
                ['business_impact' => $row['label']],
                $row['risk'],
            );
        }

        // 6) Wipe existing relations for this project and re-insert.
        AssetRelation::whereIn('source_asset_id', $project->assets()->pluck('id'))
            ->orWhereIn('target_asset_id', $project->assets()->pluck('id'))
            ->delete();

        // domain --hosts--> ip (for each IP)
        foreach ($ips as $ipAsset) {
            $this->link($domain, $ipAsset, AssetRelation::TYPE_HOSTS, ['discovered_via' => 'dns']);
        }

        // For each IP, link to ports, services, vulnerabilities.
        foreach ($ips as $ipAsset) {
            foreach ($ports as $portAsset) {
                $this->link($ipAsset, $portAsset, AssetRelation::TYPE_HAS_PORT, ['state' => 'open']);
            }
            foreach ($ports as $portNumber => $portAsset) {
                $serviceKey = $this->portToService($portNumber);
                if ($serviceKey && isset($services[$serviceKey])) {
                    $this->link($portAsset, $services[$serviceKey], AssetRelation::TYPE_EXPOSES, [
                        'transport' => 'tcp',
                    ]);
                }
            }
        }

        // service --has_vulnerability--> vulnerability (link CVEs to relevant services).
        foreach ($vulns as $vulnAsset) {
            $serviceKey = $this->vulnToService($vulnAsset->label);
            if ($serviceKey && isset($services[$serviceKey])) {
                $this->link($services[$serviceKey], $vulnAsset, AssetRelation::TYPE_HAS_VULNERABILITY, [
                    'severity' => $vulnAsset->metadata['severity'] ?? 'medium',
                ]);
            }
        }

        // vulnerability --impacts--> impact (map each vuln to its likely impacts).
        foreach ($vulns as $vulnAsset) {
            $impactKeys = $this->vulnToImpacts($vulnAsset->label);
            foreach ($impactKeys as $impactKey) {
                if (isset($impacts[$impactKey])) {
                    $this->link($vulnAsset, $impacts[$impactKey], AssetRelation::TYPE_IMPACTS, [
                        'confidence' => 'high',
                        'weight' => 0.9,
                    ]);
                }
            }
        }
    }

    /**
     * Insert (or update) an asset node keyed by the unique composite.
     */
    private function upsertAsset(Project $project, string $type, string $label, ?string $value, array $metadata, float $riskScore): Asset
    {
        return Asset::updateOrCreate(
            [
                'project_id' => $project->id,
                'type' => $type,
                'label' => $label,
                'value' => $value,
            ],
            [
                'project_id'   => $project->id,
                'type'         => $type,
                'label'        => $label,
                'value'        => $value,
                'metadata'     => $metadata,
                'properties'   => ['discovered_via' => 'seeder', 'verified' => false],
                'risk_score'   => $riskScore,
                'first_seen_at' => Carbon::now()->subDays(random_int(1, 14)),
                'last_seen_at'  => Carbon::now()->subHours(random_int(1, 48)),
            ],
        );
    }

    /**
     * Create an edge between two assets (idempotent on (source, target, type)).
     */
    private function link(Asset $source, Asset $target, string $type, array $properties = []): AssetRelation
    {
        return AssetRelation::firstOrCreate(
            [
                'source_asset_id' => $source->id,
                'target_asset_id' => $target->id,
                'type' => $type,
            ],
            [
                'properties' => $properties,
                'weight' => $properties['weight'] ?? 1.0,
            ],
        );
    }

    /**
     * Map a port number to its canonical service name.
     */
    private function portToService(int $port): ?string
    {
        return match ($port) {
            22   => 'ssh',
            80   => 'http',
            443  => 'https',
            8080 => 'http-proxy',
            default => null,
        };
    }

    /**
     * Heuristic: link a vulnerability identifier to the affected service.
     */
    private function vulnToService(string $vulnKey): ?string
    {
        $lower = strtolower($vulnKey);
        return match (true) {
            str_contains($lower, 'ssh')     => 'ssh',
            str_contains($lower, 'hsts')
                || str_contains($lower, 'tls')
                || str_contains($lower, 'csp')
                || str_contains($lower, 'jquery')
                || str_contains($lower, 'xss')
                || str_contains($lower, 'sqli')
                || str_contains($lower, 'sql injection')
                || str_contains($lower, 'cve-2024-1234')
                || str_contains($lower, 'cve-2023-5678')
                || str_contains($lower, 'git')
                || str_contains($lower, 'cookie') => 'https',
            default => null,
        };
    }

    /**
     * Map a vulnerability to the impact assets it is likely to enable.
     *
     * @return list<string>
     */
    private function vulnToImpacts(string $vulnKey): array
    {
        $lower = strtolower($vulnKey);
        $impacts = [];

        if (str_contains($lower, 'rce') || str_contains($lower, 'remote code')
            || str_contains($lower, 'cve-2024-1234')) {
            $impacts[] = 'rce';
            $impacts[] = 'data_exfiltration';
            $impacts[] = 'privilege_escalation';
        } elseif (str_contains($lower, 'sqli') || str_contains($lower, 'sql injection')
            || str_contains($lower, 'cve-2023-5678')) {
            $impacts[] = 'data_exfiltration';
            $impacts[] = 'account_takeover';
        } elseif (str_contains($lower, 'xss') || str_contains($lower, 'cross-site')) {
            $impacts[] = 'account_takeover';
        } elseif (str_contains($lower, 'git') || str_contains($lower, 'disclosure')) {
            $impacts[] = 'data_exfiltration';
            $impacts[] = 'privilege_escalation';
        } elseif (str_contains($lower, 'tls') || str_contains($lower, 'hsts')
            || str_contains($lower, 'ssh') || str_contains($lower, 'cipher')) {
            $impacts[] = 'account_takeover';
            $impacts[] = 'data_exfiltration';
        } elseif (str_contains($lower, 'denial') || str_contains($lower, 'disruption')) {
            $impacts[] = 'service_disruption';
        } else {
            $impacts[] = 'data_exfiltration';
        }

        return array_values(array_unique($impacts));
    }

    /**
     * Map a CVSS score to a 0–100 platform risk score.
     */
    private function cvssToRiskScore(?float $cvss): float
    {
        if ($cvss === null) {
            return 30.0;
        }

        // CVSS 0–10 → platform 0–100.
        return round($cvss * 10.0, 1);
    }
}
