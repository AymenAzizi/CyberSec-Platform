<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Main seeder orchestrating the platform's full demo dataset.
 *
 * The order below respects foreign-key dependencies (roles → users →
 * projects → targets → scans → findings → assets → alerts → reports →
 * chat sessions). Every constituent seeder is idempotent so the whole
 * chain is safe to re-run with `php artisan db:seed --force`.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeders in dependency order.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ScanProfileSeeder::class,
            UserSeeder::class,
            ProjectSeeder::class,
            TargetSeeder::class,
            ScanSeeder::class,
            FindingSeeder::class,
            AssetSeeder::class,
            AlertSeeder::class,
            ReportSeeder::class,
            ChatSessionSeeder::class,
        ]);
    }
}
