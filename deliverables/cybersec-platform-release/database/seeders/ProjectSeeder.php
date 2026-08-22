<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds three demo engagements owned by the analyst account.
 *
 * Each project carries an authorisation document path placeholder, a
 * branded scope_config listing the allowed in-scope domains, and a
 * realistic lifecycle status. Projects are keyed by name so the seeder
 * is safe to re-run.
 */
class ProjectSeeder extends Seeder
{
    /**
     * Demo engagement catalogue.
     *
     * @var array<int,array<string,mixed>>
     */
    private const PROJECTS = [
        [
            'name' => 'ENSI University Security Audit',
            'client_name' => 'ENSI',
            'target' => 'ensi.tn',
            'description' => 'Annual perimeter security audit of the ENSI '
                . 'University public-facing web estate, covering the main '
                . 'portal, the student portal and the public API endpoint. '
                . 'Scope explicitly excludes internal administrative '
                . 'applications reachable only from the campus VPN.',
            'status' => Project::STATUS_ACTIVE,
            'branding_color' => '#1d4ed8',
            'expires_at' => null,
        ],
        [
            'name' => 'ACME Corp Pentest',
            'client_name' => 'ACME',
            'target' => 'acme-example.com',
            'description' => 'Black-box penetration test of ACME Corp\'s '
                . 'customer-facing marketing site and e-commerce API. '
                . 'Authorised under signed SOW #ACME-2026-Q1-004. No '
                . 'denial-of-service techniques permitted; aggressive '
                . 'profile locked behind admin approval.',
            'status' => Project::STATUS_ACTIVE,
            'branding_color' => '#7c3aed',
            'expires_at' => null,
        ],
        [
            'name' => 'Internal Lab Assessment',
            'client_name' => 'Internal',
            'target' => 'lab.local',
            'description' => 'Closed lab assessment against the internal '
                . 'research network (lab.local). Used to validate the '
                . 'platform\'s full pipeline end-to-end before customer '
                . 'engagements. All targets are owned by the platform team.',
            'status' => Project::STATUS_COMPLETED,
            'branding_color' => '#059669',
            'expires_at' => null,
        ],
    ];

    /**
     * Run the seeder.
     */
    public function run(): void
    {
        $analyst = User::where('email', 'analyst@cybersec.local')->first();

        if (! $analyst) {
            throw new \RuntimeException(
                'Analyst user not found — run UserSeeder before ProjectSeeder.',
            );
        }

        foreach (self::PROJECTS as $entry) {
            $target = $entry['target'];
            $status = $entry['status'];
            unset($entry['target']);

            $authorizedAt = in_array($status, [
                Project::STATUS_ACTIVE,
                Project::STATUS_COMPLETED,
            ], true) ? Carbon::now() : null;

            Project::updateOrCreate(
                [
                    'user_id' => $analyst->id,
                    'name' => $entry['name'],
                ],
                array_merge($entry, [
                    'user_id' => $analyst->id,
                    'scope_config' => [
                        'allowed_domains' => [$target, "www.{$target}", "api.{$target}"],
                        'excluded_paths' => ['/admin', '/logout', '/cart/checkout'],
                        'max_concurrent_scans' => 3,
                        'allowed_profiles' => ['silent', 'balanced', 'aggressive'],
                        'deny_profiles_for_production' => ['aggressive'],
                    ],
                    'authorization_document' => "storage/auth-docs/{$entry['client_name']}-authorization.pdf",
                    'authorized_at' => $authorizedAt,
                ]),
            );
        }
    }
}
