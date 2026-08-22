<?php

namespace Database\Seeders;

use App\Models\ScanProfile;
use Illuminate\Database\Seeder;

/**
 * Seeds the three execution profiles defined by the Final CDC.
 *
 * Profiles are referenced by name from scans and by the worker service,
 * so the canonical names (silent / balanced / aggressive) MUST remain
 * stable across environments. The numeric parameters and per-tool flag
 * arrays mirror what the Python microservices apply at execution time.
 *
 * Idempotent: existing rows are updated to match the spec.
 */
class ScanProfileSeeder extends Seeder
{
    /**
     * Canonical profile definitions.
     *
     * Each entry maps to a row in `scan_profiles` and is keyed by the
     * canonical profile name referenced by {@see \App\Models\ScanProfile::NAMES}.
     *
     * @var array<string,array<string,mixed>>
     */
    private const PROFILES = [
        [
            'name' => ScanProfile::NAME_SILENT,
            'display_name' => 'Silent (IDS-evasion)',
            'description' => 'Low-and-slow profile designed to slip past IDS/IPS '
                . 'rate-based detectors. Use on production targets where stealth '
                . 'matters more than wall-clock time. Aggressive scanning from '
                . 'this profile is disabled.',
            'rate_limit_qps' => 2,
            'jitter_min_ms' => 500,
            'jitter_max_ms' => 2000,
            'timeout_seconds' => 1200,
            'max_retries' => 3,
            'requires_admin_approval' => false,
            'tool_flags' => [
                'nmap'     => ['-T2', '--scan-delay 1s', '--max-rate 10'],
                'nuclei'   => ['-rate-limit 2', '-bulk-size 1'],
                'gobuster' => ['--delay 500ms'],
            ],
            'is_active' => true,
        ],
        [
            'name' => ScanProfile::NAME_BALANCED,
            'display_name' => 'Balanced (default)',
            'description' => 'Default profile for authorised engagements. '
                . 'Provides a reasonable trade-off between detection risk '
                . 'and scan duration. Suitable for the majority of scoped '
                . 'reconnaissance and security testing work.',
            'rate_limit_qps' => 8,
            'jitter_min_ms' => 100,
            'jitter_max_ms' => 500,
            'timeout_seconds' => 600,
            'max_retries' => 3,
            'requires_admin_approval' => false,
            'tool_flags' => [
                'nmap'     => ['-T3', '--max-rate 50'],
                'nuclei'   => ['-rate-limit 10'],
                'gobuster' => [],
            ],
            'is_active' => true,
        ],
        [
            'name' => ScanProfile::NAME_AGGRESSIVE,
            'display_name' => 'Aggressive (requires approval)',
            'description' => 'High-throughput profile intended only for '
                . 'time-boxed engagements against hardened or isolated '
                . 'targets. Locked behind admin approval to prevent '
                . 'accidental noisy scans against production assets.',
            'rate_limit_qps' => 25,
            'jitter_min_ms' => 0,
            'jitter_max_ms' => 100,
            'timeout_seconds' => 300,
            'max_retries' => 2,
            'requires_admin_approval' => true,
            'tool_flags' => [
                'nmap'     => ['-T4', '--max-rate 200'],
                'nuclei'   => ['-rate-limit 30'],
                'gobuster' => ['-t 50'],
            ],
            'is_active' => true,
        ],
    ];

    /**
     * Run the seeder.
     */
    public function run(): void
    {
        foreach (self::PROFILES as $profile) {
            ScanProfile::updateOrCreate(
                ['name' => $profile['name']],
                $profile,
            );
        }
    }
}
