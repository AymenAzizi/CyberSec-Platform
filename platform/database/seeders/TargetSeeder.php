<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Target;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds three in-scope targets per project: main domain, www subdomain,
 * and an api subdomain. Each target carries realistic OSINT metadata
 * (registrar, DNS, SSL, tech stack and discovered subdomains) that the
 * platform can render without re-running external collectors.
 *
 * Idempotent: keyed by (project_id, domain_url).
 */
class TargetSeeder extends Seeder
{
    /**
     * Per-project per-target OSINT payloads.
     *
     * Keyed by the project's root domain so the same data set is reused
     * across the three demo engagements without copying literal arrays.
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private const TARGET_MATRIX = [
        'ensi.tn' => [
            [
                'name' => 'ENSI main portal',
                'domain_url' => 'ensi.tn',
                'ip_address' => '193.95.68.21',
                'scope_type' => Target::SCOPE_DOMAIN,
                'tech_stack' => ['Apache 2.4.58', 'PHP 8.2', 'WordPress 6.5', 'MySQL 8.0'],
                'subdomains' => ['www.ensi.tn', 'api.ensi.tn', 'intranet.ensi.tn', 'mail.ensi.tn'],
                'osint_data' => [
                    'whois' => [
                        'registrar' => 'Tunisian Internet Agency (ATI)',
                        'created_at' => '2002-03-14',
                        'expires_at' => '2027-03-14',
                        'registrant_org' => 'Ecole Nationale des Sciences de l\'Informatique',
                    ],
                    'dns' => [
                        'A'     => ['193.95.68.21'],
                        'MX'    => ['10 mail.ensi.tn.'],
                        'NS'    => ['ns1.ati.tn.', 'ns2.ati.tn.'],
                        'TXT'   => ['v=spf1 mx a -all'],
                    ],
                    'ssl' => [
                        'issuer'          => 'Let\'s Encrypt R3',
                        'valid_from'      => '2026-01-12',
                        'valid_to'        => '2026-04-12',
                        'sha256_fingerprint' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90',
                        'san' => ['ensi.tn', 'www.ensi.tn'],
                    ],
                ],
            ],
            [
                'name' => 'ENSI student portal',
                'domain_url' => 'www.ensi.tn',
                'ip_address' => '193.95.68.21',
                'scope_type' => Target::SCOPE_SUBDOMAIN,
                'tech_stack' => ['Nginx 1.25', 'React 18.2', 'Node.js 20 LTS', 'PostgreSQL 16'],
                'subdomains' => [],
                'osint_data' => [
                    'whois' => ['registrar' => 'Tunisian Internet Agency (ATI)'],
                    'dns' => [
                        'A'  => ['193.95.68.21'],
                        'CNAME' => ['ensi.tn.'],
                    ],
                    'ssl' => [
                        'issuer' => 'Let\'s Encrypt R3',
                        'valid_from' => '2026-01-12',
                        'valid_to' => '2026-04-12',
                    ],
                ],
            ],
            [
                'name' => 'ENSI public API',
                'domain_url' => 'api.ensi.tn',
                'ip_address' => '193.95.68.23',
                'scope_type' => Target::SCOPE_SUBDOMAIN,
                'tech_stack' => ['Nginx 1.25', 'Express 4.19', 'OpenAPI 3.1', 'Redis 7'],
                'subdomains' => [],
                'osint_data' => [
                    'whois' => ['registrar' => 'Tunisian Internet Agency (ATI)'],
                    'dns' => ['A' => ['193.95.68.23']],
                    'ssl' => [
                        'issuer' => 'Let\'s Encrypt R3',
                        'valid_from' => '2026-02-01',
                        'valid_to' => '2026-05-02',
                    ],
                ],
            ],
        ],
        'acme-example.com' => [
            [
                'name' => 'ACME marketing site',
                'domain_url' => 'acme-example.com',
                'ip_address' => '203.0.113.45',
                'scope_type' => Target::SCOPE_DOMAIN,
                'tech_stack' => ['Cloudflare', 'Nginx 1.27', 'WordPress 6.4', 'MariaDB 10.11'],
                'subdomains' => ['www.acme-example.com', 'api.acme-example.com', 'shop.acme-example.com', 'blog.acme-example.com'],
                'osint_data' => [
                    'whois' => [
                        'registrar' => 'Cloudflare, Inc.',
                        'created_at' => '2018-07-04',
                        'expires_at' => '2027-07-04',
                        'registrant_org' => 'ACME Corp.',
                    ],
                    'dns' => [
                        'A'   => ['203.0.113.45'],
                        'MX'  => ['10 mail.acme-example.com.'],
                        'NS'  => ['ns1.cloudflare.com.', 'ns2.cloudflare.com.'],
                        'TXT' => ['v=spf1 include:_spf.cloudflare.net ~all'],
                    ],
                    'ssl' => [
                        'issuer' => 'Cloudflare Inc ECC CA-3',
                        'valid_from' => '2026-01-01',
                        'valid_to' => '2026-12-31',
                    ],
                ],
            ],
            [
                'name' => 'ACME www redirect',
                'domain_url' => 'www.acme-example.com',
                'ip_address' => '203.0.113.45',
                'scope_type' => Target::SCOPE_SUBDOMAIN,
                'tech_stack' => ['Cloudflare', 'Nginx 1.27'],
                'subdomains' => [],
                'osint_data' => [
                    'dns' => [
                        'A'     => ['203.0.113.45'],
                        'CNAME' => ['acme-example.com.'],
                    ],
                    'ssl' => ['issuer' => 'Cloudflare Inc ECC CA-3'],
                ],
            ],
            [
                'name' => 'ACME commerce API',
                'domain_url' => 'api.acme-example.com',
                'ip_address' => '203.0.113.46',
                'scope_type' => Target::SCOPE_SUBDOMAIN,
                'tech_stack' => ['Cloudflare', 'Envoy 1.29', 'Go 1.22', 'PostgreSQL 16', 'Kafka 3.7'],
                'subdomains' => [],
                'osint_data' => [
                    'dns' => ['A' => ['203.0.113.46']],
                    'ssl' => [
                        'issuer' => 'Cloudflare Inc ECC CA-3',
                        'valid_from' => '2026-01-01',
                        'valid_to' => '2026-12-31',
                    ],
                ],
            ],
        ],
        'lab.local' => [
            [
                'name' => 'Lab main host',
                'domain_url' => 'lab.local',
                'ip_address' => '10.10.0.10',
                'scope_type' => Target::SCOPE_DOMAIN,
                'tech_stack' => ['Apache 2.4.58', 'PHP 8.2', 'DVWA 2.2', 'MySQL 8.0'],
                'subdomains' => ['www.lab.local', 'api.lab.local'],
                'osint_data' => [
                    'dns' => [
                        'A'  => ['10.10.0.10'],
                        'NS' => ['lab-dns.lab.local.'],
                    ],
                    'ssl' => [
                        'issuer' => 'lab-internal-CA',
                        'valid_from' => '2025-09-01',
                        'valid_to' => '2035-09-01',
                    ],
                ],
            ],
            [
                'name' => 'Lab www',
                'domain_url' => 'www.lab.local',
                'ip_address' => '10.10.0.10',
                'scope_type' => Target::SCOPE_SUBDOMAIN,
                'tech_stack' => ['Nginx 1.25', 'Juice Shop 18.0', 'Node.js 20 LTS'],
                'subdomains' => [],
                'osint_data' => [
                    'dns' => ['A' => ['10.10.0.10']],
                ],
            ],
            [
                'name' => 'Lab API',
                'domain_url' => 'api.lab.local',
                'ip_address' => '10.10.0.12',
                'scope_type' => Target::SCOPE_SUBDOMAIN,
                'tech_stack' => ['Nginx 1.25', 'VAmPI 1.0', 'Python 3.12', 'Flask 3.0'],
                'subdomains' => [],
                'osint_data' => [
                    'dns' => ['A' => ['10.10.0.12']],
                ],
            ],
        ],
    ];

    /**
     * Run the seeder.
     */
    public function run(): void
    {
        foreach (Project::all() as $project) {
            // Identify the project's root domain from its scope_config.
            $rootDomain = $project->scope_config['allowed_domains'][0]
                ?? $this->inferRootDomain($project);

            $matrix = self::TARGET_MATRIX[$rootDomain] ?? null;

            if (! $matrix) {
                $this->command?->warn(
                    "No target matrix for project [{$project->name}] "
                    . "(root domain: {$rootDomain}); skipping.",
                );
                continue;
            }

            // Authorisation is granted for active / completed engagements.
            $isAuthorized = in_array($project->status, [
                Project::STATUS_ACTIVE,
                Project::STATUS_COMPLETED,
            ], true);

            foreach ($matrix as $row) {
                Target::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'domain_url' => $row['domain_url'],
                    ],
                    array_merge($row, [
                        'project_id' => $project->id,
                        'authorization_status' => $isAuthorized
                            ? Target::AUTH_APPROVED
                            : Target::AUTH_PENDING,
                        'authorization_document' => $isAuthorized
                            ? $project->authorization_document
                            : null,
                        'authorized_at' => $isAuthorized
                            ? ($project->authorized_at ?? Carbon::now())
                            : null,
                        'last_seen_at' => Carbon::now()->subHours(random_int(1, 48)),
                        'notes' => 'Auto-seeded target — review scope before scanning.',
                    ]),
                );
            }
        }
    }

    /**
     * Best-effort root-domain inference when scope_config is missing.
     */
    private function inferRootDomain(Project $project): ?string
    {
        // The ClientSeeder-registered projects store the root domain in the
        // client_name-derived placeholder via the worklog spec; we fall back
        // to a deterministic mapping for safety.
        return match ($project->client_name) {
            'ENSI' => 'ensi.tn',
            'ACME' => 'acme-example.com',
            'Internal' => 'lab.local',
            default => null,
        };
    }
}
