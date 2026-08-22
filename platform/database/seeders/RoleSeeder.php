<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the platform's RBAC catalogue: 4 roles + 10 permissions.
 *
 * The role/permission matrix mirrors the Final CDC:
 *
 *   admin    → manage-users, view-all-projects, manage-system, view-audit-logs
 *   analyst  → create-projects, create-scans, view-reports, manage-alerts,
 *              use-chatbot, view-osint
 *   client   → view-reports
 *   auditor  → view-audit-logs, view-reports
 *
 * Idempotent: re-running the seeder is safe and preserves existing
 * role→permission assignments. The Spatie permission cache is flushed
 * at the end so the new matrix is immediately effective.
 */
class RoleSeeder extends Seeder
{
    /**
     * Catalogue of permissions keyed by their canonical name with a
     * human-readable description used purely for documentation.
     *
     * @var array<string,string>
     */
    private const PERMISSIONS = [
        'manage-users'      => 'Create, disable and re-role platform users',
        'view-all-projects' => 'Read every project regardless of ownership',
        'manage-system'     => 'Configure platform-wide settings and feature flags',
        'view-audit-logs'   => 'Read the immutable audit trail',
        'create-projects'   => 'Create new engagement projects',
        'create-scans'      => 'Queue and execute reconnaissance / security scans',
        'view-reports'      => 'View generated assessment reports',
        'manage-alerts'     => 'Acknowledge, escalate and triage security alerts',
        'use-chatbot'       => 'Interact with the AI security co-pilot',
        'view-osint'        => 'Read OSINT data collected on scoped targets',
    ];

    /**
     * Role → permission matrix.
     *
     * @var array<string,list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'admin' => [
            'manage-users',
            'view-all-projects',
            'manage-system',
            'view-audit-logs',
        ],
        'analyst' => [
            'create-projects',
            'create-scans',
            'view-reports',
            'manage-alerts',
            'use-chatbot',
            'view-osint',
        ],
        'client' => [
            'view-reports',
        ],
        'auditor' => [
            'view-audit-logs',
            'view-reports',
        ],
    ];

    /**
     * Run the seeder.
     */
    public function run(): void
    {
        // Start from a clean cache so stale entries never leak in.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1) Permissions ----------------------------------------------------
        foreach (self::PERMISSIONS as $name => $description) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                [],
            );
        }

        // 2) Roles + role→permission assignments ---------------------------
        foreach (self::ROLE_PERMISSIONS as $roleName => $permissionNames) {
            /** @var Role $role */
            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                [],
            );

            // syncPermissions detaches permissions no longer in the matrix,
            // keeping the seeder idempotent and aligned with the spec.
            $role->syncPermissions($permissionNames);
        }

        // 3) Flush cache so the new matrix is observed immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
