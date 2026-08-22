<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Scan;
use App\Models\ScanProfile;
use App\Models\Target;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds completed reconnaissance scans (nmap, nuclei, osint) against
 * every target produced by {@see TargetSeeder}.
 *
 * Each scan carries:
 *   - realistic wall-clock duration (60–300 s)
 *   - severity_counts seeded from the templates used by FindingSeeder
 *   - raw_output containing a real-shaped JSON sample for the tool
 *
 * The FindingSeeder re-computes severity_counts from the rows it
 * actually inserts, so the numbers stay accurate even if a template
 * changes. Idempotent: keyed on (target_id, type, profile).
 */
class ScanSeeder extends Seeder
{
    /**
     * Per-target scan template. Two reconnaissance scans (nmap + nuclei)
     * are created for every target; an additional osint scan is created
     * for the root domain of each project.
     *
     * The severity_counts here mirror the FindingSeeder catalogue so the
     * dashboard renders consistent values before FindingSeeder runs.
     *
     * @var array<string,array<string,mixed>>
     */
    private const NMAP_TEMPLATE = [
        'severity_counts' => ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 2, 'info' => 4],
    ];

    private const NUCLEI_TEMPLATE = [
        'severity_counts' => ['critical' => 2, 'high' => 3, 'medium' => 3, 'low' => 2, 'info' => 1],
    ];

    private const OSINT_TEMPLATE = [
        'severity_counts' => ['critical' => 0, 'high' => 0, 'medium' => 1, 'low' => 2, 'info' => 5],
    ];

    /**
     * Run the seeder.
     */
    public function run(): void
    {
        $analyst = User::where('email', 'analyst@cybersec.local')->firstOrFail();
        $balanced = ScanProfile::byName(ScanProfile::NAME_BALANCED);

        foreach (Project::all() as $project) {
            foreach ($project->targets as $target) {
                $this->createNmapScan($project, $target, $analyst, $balanced);
                $this->createNucleiScan($project, $target, $analyst, $balanced);

                // OSINT scans only run at the root-domain level.
                if ($target->scope_type === Target::SCOPE_DOMAIN) {
                    $this->createOsintScan($project, $target, $analyst, $balanced);
                }
            }
        }
    }

    /**
     * Create (or update) an nmap reconnaissance scan for a target.
     */
    private function createNmapScan(Project $project, Target $target, User $analyst, ?ScanProfile $profile): Scan
    {
        $startedAt = Carbon::now()->subMinutes(random_int(120, 1440));
        $duration = random_int(60, 180);
        $completedAt = (clone $startedAt)->addSeconds($duration);

        $scan = Scan::updateOrCreate(
            [
                'target_id' => $target->id,
                'type' => 'nmap',
                'profile' => ScanProfile::NAME_BALANCED,
            ],
            [
                'project_id'      => $project->id,
                'user_id'         => $analyst->id,
                'target_url'      => $target->domain_url,
                'jitter_ms'       => $profile?->sampleJitterMs() ?? 250,
                'rate_limit_qps'  => $profile?->rate_limit_qps ?? 8,
                'status'          => Scan::STATUS_COMPLETED,
                'tools_status'    => ['nmap' => 'completed'],
                'severity_counts' => self::NMAP_TEMPLATE['severity_counts'],
                'config' => [
                    'ports' => '22,80,443,8080',
                    'timing_template' => 'T3',
                    'max_rate' => 50,
                    'scripts' => ['ssl-cert', 'http-title', 'http-server-header'],
                ],
                'raw_output' => $this->nmapJsonOutput($target),
                'worker_id'      => 'worker-1',
                'attempt'        => 1,
                'max_attempts'   => $profile?->max_retries ?? 3,
                'correlation_id' => Str::uuid()->toString(),
                'queued_at'      => (clone $startedAt)->subSeconds(5),
                'started_at'     => $startedAt,
                'completed_at'   => $completedAt,
            ],
        );

        return $scan;
    }

    /**
     * Create (or update) a nuclei vulnerability scan for a target.
     */
    private function createNucleiScan(Project $project, Target $target, User $analyst, ?ScanProfile $profile): Scan
    {
        $startedAt = Carbon::now()->subMinutes(random_int(60, 720));
        $duration = random_int(90, 300);
        $completedAt = (clone $startedAt)->addSeconds($duration);

        return Scan::updateOrCreate(
            [
                'target_id' => $target->id,
                'type' => 'nuclei',
                'profile' => ScanProfile::NAME_BALANCED,
            ],
            [
                'project_id'      => $project->id,
                'user_id'         => $analyst->id,
                'target_url'      => $target->domain_url,
                'jitter_ms'       => $profile?->sampleJitterMs() ?? 250,
                'rate_limit_qps'  => $profile?->rate_limit_qps ?? 8,
                'status'          => Scan::STATUS_COMPLETED,
                'tools_status'    => ['nuclei' => 'completed'],
                'severity_counts' => self::NUCLEI_TEMPLATE['severity_counts'],
                'config' => [
                    'templates' => ['cves', 'exposures', 'misconfiguration', 'default-logins'],
                    'rate_limit' => 10,
                    'concurrency' => 25,
                    'severity' => ['critical', 'high', 'medium', 'low', 'info'],
                ],
                'raw_output' => $this->nucleiJsonOutput($target),
                'worker_id'      => 'worker-2',
                'attempt'        => 1,
                'max_attempts'   => $profile?->max_retries ?? 3,
                'correlation_id' => Str::uuid()->toString(),
                'queued_at'      => (clone $startedAt)->subSeconds(5),
                'started_at'     => $startedAt,
                'completed_at'   => $completedAt,
            ],
        );
    }

    /**
     * Create (or update) an OSINT scan for a target's root domain.
     */
    private function createOsintScan(Project $project, Target $target, User $analyst, ?ScanProfile $profile): Scan
    {
        $startedAt = Carbon::now()->subMinutes(random_int(180, 2880));
        $duration = random_int(60, 120);
        $completedAt = (clone $startedAt)->addSeconds($duration);

        return Scan::updateOrCreate(
            [
                'target_id' => $target->id,
                'type' => 'osint',
                'profile' => ScanProfile::NAME_BALANCED,
            ],
            [
                'project_id'      => $project->id,
                'user_id'         => $analyst->id,
                'target_url'      => $target->domain_url,
                'jitter_ms'       => 0,
                'rate_limit_qps'  => $profile?->rate_limit_qps ?? 8,
                'status'          => Scan::STATUS_COMPLETED,
                'tools_status'    => ['whois' => 'completed', 'dns' => 'completed', 'ssl' => 'completed', 'crtsh' => 'completed', 'tech_detector' => 'completed'],
                'severity_counts' => self::OSINT_TEMPLATE['severity_counts'],
                'config' => [
                    'modules' => ['whois', 'dns', 'ssl', 'crtsh', 'tech_detector'],
                    'passive_only' => true,
                ],
                'raw_output' => $this->osintJsonOutput($target),
                'worker_id'      => 'worker-3',
                'attempt'        => 1,
                'max_attempts'   => $profile?->max_retries ?? 3,
                'correlation_id' => Str::uuid()->toString(),
                'queued_at'      => (clone $startedAt)->subSeconds(5),
                'started_at'     => $startedAt,
                'completed_at'   => $completedAt,
            ],
        );
    }

    /**
     * Render a realistic nmap XML→JSON payload for a target.
     */
    private function nmapJsonOutput(Target $target): string
    {
        $ip = $target->ip_address ?? '203.0.113.45';
        $host = $target->domain_url;

        $payload = [
            'nmaprun' => [
                'scanner' => 'nmap',
                'args' => "nmap -sS -sV -T3 --max-rate 50 -p 22,80,443,8080 -oX - {$host}",
                'start' => now()->timestamp,
                'startstr' => now()->toIso8601String(),
                'version' => '7.94',
                'xmloutputversion' => '1.05',
                'host' => [[
                    'starttime' => now()->timestamp,
                    'endtime' => now()->addSeconds(120)->timestamp,
                    'status' => ['state' => 'up', 'reason' => 'echo-reply', 'reason_ttl' => '64'],
                    'address' => [
                        ['addr' => $ip, 'addrtype' => 'ipv4'],
                        ['addr' => '02:42:ac:11:00:0a', 'addrtype' => 'mac', 'vendor' => 'Unknown'],
                    ],
                    'hostnames' => [['hostname' => ['name' => $host, 'type' => 'user']]],
                    'ports' => ['port' => [
                        ['portid' => '22', 'protocol' => 'tcp',
                         'state' => ['state' => 'open', 'reason' => 'syn-ack', 'reason_ttl' => '64'],
                         'service' => ['name' => 'ssh', 'product' => 'OpenSSH', 'version' => '8.9p1 Ubuntu 3ubuntu0.6', 'extrainfo' => 'Ubuntu 4ubuntu0.5; protocol 2.0', 'ostype' => 'Linux', 'method' => 'probed', 'conf' => '10']],
                        ['portid' => '80', 'protocol' => 'tcp',
                         'state' => ['state' => 'open', 'reason' => 'syn-ack', 'reason_ttl' => '64'],
                         'service' => ['name' => 'http', 'product' => 'Apache httpd', 'version' => '2.4.58', 'extrainfo' => '(Ubuntu)', 'method' => 'probed', 'conf' => '10']],
                        ['portid' => '443', 'protocol' => 'tcp',
                         'state' => ['state' => 'open', 'reason' => 'syn-ack', 'reason_ttl' => '64'],
                         'service' => ['name' => 'https', 'product' => 'Apache httpd', 'version' => '2.4.58', 'extrainfo' => '(Ubuntu)', 'tunnel' => 'ssl', 'method' => 'probed', 'conf' => '10']],
                        ['portid' => '8080', 'protocol' => 'tcp',
                         'state' => ['state' => 'open', 'reason' => 'syn-ack', 'reason_ttl' => '64'],
                         'service' => ['name' => 'http-proxy', 'product' => 'Jetty', 'version' => '9.4.51.v20230217', 'method' => 'probed', 'conf' => '10']],
                    ]],
                ]],
                'runstats' => [
                    'finished' => ['time' => now()->addSeconds(120)->timestamp, 'timestr' => now()->addSeconds(120)->toIso8601String(), 'elapsed' => '120.45', 'summary' => 'Nmap done; 1 IP address (1 host up) scanned in 120.45 seconds', 'exit' => 'success'],
                    'hosts' => ['up' => '1', 'down' => '0', 'total' => '1'],
                ],
            ],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Render a realistic nuclei JSONL payload for a target.
     */
    private function nucleiJsonOutput(Target $target): string
    {
        $host = $target->domain_url;
        $ip = $target->ip_address ?? '203.0.113.45';

        $findings = [
            [
                'template-id' => 'CVE-2024-1234',
                'info' => ['name' => 'Apache OFBiz Path Traversal Remote Code Execution', 'author' => ['pdteam'], 'tags' => ['cve', 'cve2024', 'apache', 'ofbiz', 'traversal', 'rce'], 'severity' => 'critical', 'reference' => ['https://nvd.nist.gov/vuln/detail/CVE-2024-1234']],
                'type' => 'http',
                'host' => "https://{$host}",
                'matched-at' => "https://{$host}/webtools/control/ProgramExport;/~Example",
                'ip' => $ip,
                'timestamp' => now()->toIso8601String(),
                'curl-command' => "curl -X 'GET' -d 'exampleParam=~/Example' 'https://{$host}/webtools/control/ProgramExport'",
            ],
            [
                'template-id' => 'CVE-2023-5678',
                'info' => ['name' => 'WordPress Plugin SQL Injection', 'author' => ['pdteam'], 'tags' => ['cve', 'cve2023', 'wordpress', 'sqli'], 'severity' => 'critical', 'reference' => ['https://nvd.nist.gov/vuln/detail/CVE-2023-5678']],
                'type' => 'http',
                'host' => "https://{$host}",
                'matched-at' => "https://{$host}/wp-admin/admin-ajax.php?action=example&id=1'",
                'ip' => $ip,
                'timestamp' => now()->toIso8601String(),
            ],
            [
                'template-id' => 'git-config',
                'info' => ['name' => 'Git Config Exposure', 'author' => ['pdteam'], 'tags' => ['exposure', 'config', 'git'], 'severity' => 'high'],
                'type' => 'http',
                'host' => "https://{$host}",
                'matched-at' => "https://{$host}/.git/config",
                'extracted-results' => ['[core]', 'repositoryformatversion = 0'],
                'ip' => $ip,
                'timestamp' => now()->toIso8601String(),
            ],
            [
                'template-id' => 'missing-security-headers',
                'info' => ['name' => 'Missing Security Headers', 'author' => ['pdteam'], 'tags' => ['misconfiguration', 'headers'], 'severity' => 'medium'],
                'type' => 'http',
                'host' => "https://{$host}",
                'matched-at' => "https://{$host}/",
                'ip' => $ip,
                'timestamp' => now()->toIso8601String(),
            ],
            [
                'template-id' => 'tls-version-1-0',
                'info' => ['name' => 'TLS Version 1.0 Protocol Deprecated', 'author' => ['pdteam'], 'tags' => ['tls', 'ssl'], 'severity' => 'low'],
                'type' => 'http',
                'host' => "https://{$host}",
                'matched-at' => "https://{$host}:443",
                'ip' => $ip,
                'timestamp' => now()->toIso8601String(),
            ],
        ];

        // nuclei emits JSON lines, one finding per line.
        return implode("\n", array_map(
            static fn (array $f): string => json_encode($f, JSON_UNESCAPED_SLASHES),
            $findings,
        ));
    }

    /**
     * Render a realistic OSINT aggregate JSON payload for a target.
     */
    private function osintJsonOutput(Target $target): string
    {
        $host = $target->domain_url;

        return json_encode([
            'target' => $host,
            'collected_at' => now()->toIso8601String(),
            'modules' => [
                'whois' => $target->osint_data['whois'] ?? [],
                'dns' => $target->osint_data['dns'] ?? [],
                'ssl' => $target->osint_data['ssl'] ?? [],
                'crtsh' => [
                    'subdomains_from_cert_transparency' => $target->subdomains ?? [],
                    'cert_count' => count($target->subdomains ?? []) + 1,
                ],
                'tech_detector' => [
                    'tech_stack' => $target->tech_stack ?? [],
                    'headers' => [
                        'Server' => 'Apache/2.4.58 (Ubuntu)',
                        'X-Powered-By' => 'PHP/8.2.15',
                        'Set-Cookie' => 'PHPSESSID=...; HttpOnly',
                    ],
                ],
            ],
            'summary' => [
                'subdomain_count' => count($target->subdomains ?? []),
                'tech_count' => count($target->tech_stack ?? []),
                'ssl_days_remaining' => random_int(45, 90),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
