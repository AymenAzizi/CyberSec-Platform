<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the four default platform accounts used for demos and defense day.
 *
 * Each account is bound to its corresponding RBAC role produced by
 * {@see RoleSeeder}. Passwords are hashed via Hash::make() (the User model
 * also declares a 'hashed' cast which is a no-op on already-hashed values
 * thanks to Hash::needsRehash()).
 *
 * Idempotent: existing users are updated in place; role assignments are
 * re-synced so the role catalogue can evolve without leaving stale links.
 */
class UserSeeder extends Seeder
{
    /**
     * Default account catalogue.
     *
     * @var array<int,array<string,mixed>>
     */
    private const USERS = [
        [
            'name' => 'Platform Administrator',
            'email' => 'admin@cybersec.local',
            'role' => 'admin',
        ],
        [
            'name' => 'Security Analyst',
            'email' => 'analyst@cybersec.local',
            'role' => 'analyst',
        ],
        [
            'name' => 'Engagement Client',
            'email' => 'client@cybersec.local',
            'role' => 'client',
        ],
        [
            'name' => 'Compliance Auditor',
            'email' => 'auditor@cybersec.local',
            'role' => 'auditor',
        ],
    ];

    /**
     * Run the seeder.
     */
    public function run(): void
    {
        foreach (self::USERS as $entry) {
            $role = $entry['role'];
            unset($entry['role']);

            /** @var User $user */
            $user = User::updateOrCreate(
                ['email' => $entry['email']],
                array_merge($entry, [
                    'password' => Hash::make('password'),
                    'is_active' => true,
                    'last_login_at' => now(),
                    'email_verified_at' => now(),
                    'quota_scans_per_day' => User::DEFAULT_QUOTA_SCANS_PER_DAY,
                ]),
            );

            // syncRoles is idempotent and detaches any role no longer
            // expected for this account.
            $user->syncRoles($role);
        }
    }
}
