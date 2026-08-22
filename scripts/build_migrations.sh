#!/bin/bash
# Generate all migration files for CyberSec Platform
set -e
cd /home/z/my-project/platform

# 1. Users
cat > database/migrations/2026_01_01_000001_create_users_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('avatar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('two_factor_enabled')->default(false);
            $table->string('two_factor_secret')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();
            $table->integer('quota_scans_per_day')->default(20);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
PHPEOF

# 2. Cache + Jobs
cat > database/migrations/2026_01_01_000002_create_cache_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
PHPEOF

# 3. Permission tables (Spatie)
cat > database/migrations/2026_01_01_000003_create_permission_tables.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $teams = config('permission.teams');
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');

        Schema::create($tableNames['permissions'], function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['roles'], function (Blueprint $table) use ($teams, $columnNames) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            if ($teams || config('permission.testing')) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
                $table->index($columnNames['team_foreign_key']);
            }
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['model_has_permissions'], function (Blueprint $table) use ($tableNames, $columnNames) {
            $permissionForeignKey = $tableNames['permissions'] . '_id';
            $table->unsignedBigInteger($permissionForeignKey);
            $table->string('model_type');
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type']);
            $table->foreign($permissionForeignKey)->references('id')->on($tableNames['permissions'])->onDelete('cascade');
            if ($teams || config('permission.testing')) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
                $table->index($columnNames['team_foreign_key']);
            }
            $table->primary([$permissionForeignKey, $columnNames['model_morph_key'], 'model_type'],
                'model_has_permissions_permission_model_type_primary');
        });

        Schema::create($tableNames['model_has_roles'], function (Blueprint $table) use ($tableNames, $columnNames) {
            $roleForeignKey = $tableNames['roles'] . '_id';
            $table->unsignedBigInteger($roleForeignKey);
            $table->string('model_type');
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type']);
            $table->foreign($roleForeignKey)->references('id')->on($tableNames['roles'])->onDelete('cascade');
            if ($teams || config('permission.testing')) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
                $table->index($columnNames['team_foreign_key']);
            }
            $table->primary([$roleForeignKey, $columnNames['model_morph_key'], 'model_type'],
                'model_has_roles_role_model_type_primary');
        });

        Schema::create($tableNames['role_has_permissions'], function (Blueprint $table) use ($tableNames) {
            $permissionForeignKey = $tableNames['permissions'] . '_id';
            $roleForeignKey = $tableNames['roles'] . '_id';
            $table->unsignedBigInteger($permissionForeignKey);
            $table->unsignedBigInteger($roleForeignKey);
            $table->foreign($permissionForeignKey)->references('id')->on($tableNames['permissions'])->onDelete('cascade');
            $table->foreign($roleForeignKey)->references('id')->on($tableNames['roles'])->onDelete('cascade');
            $table->primary([$permissionForeignKey, $roleForeignKey], 'role_has_permissions_permission_id_role_id_primary');
        });
    }
    public function down(): void
    {
        $tableNames = config('permission.table_names');
        Schema::dropIfExists($tableNames['role_has_permissions']);
        Schema::dropIfExists($tableNames['model_has_roles']);
        Schema::dropIfExists($tableNames['model_has_permissions']);
        Schema::dropIfExists($tableNames['roles']);
        Schema::dropIfExists($tableNames['permissions']);
    }
};
PHPEOF

# 4. Projects
cat > database/migrations/2026_01_01_000004_create_projects_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->jsonb('scope_config')->nullable();
            $table->string('authorization_document')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_logo')->nullable();
            $table->string('branding_color')->default('#7c3aed');
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('projects'); }
};
PHPEOF

# 5. Targets
cat > database/migrations/2026_01_01_000005_create_targets_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('domain_url');
            $table->string('ip_address')->nullable();
            $table->string('scope_type')->default('domain');
            $table->string('authorization_status')->default('pending');
            $table->string('authorization_document')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('osint_data')->nullable();
            $table->jsonb('tech_stack')->nullable();
            $table->jsonb('subdomains')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'authorization_status']);
        });
    }
    public function down(): void { Schema::dropIfExists('targets'); }
};
PHPEOF

# 6. Scans
cat > database/migrations/2026_01_01_000006_create_scans_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('target_url');
            $table->string('profile')->default('balanced');
            $table->integer('jitter_ms')->default(0);
            $table->integer('rate_limit_qps')->default(50);
            $table->string('status')->default('pending');
            $table->jsonb('tools_status')->nullable();
            $table->jsonb('severity_counts')->nullable();
            $table->jsonb('config')->nullable();
            $table->longText('raw_output')->nullable();
            $table->string('worker_id')->nullable();
            $table->integer('attempt')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->string('correlation_id')->nullable()->index();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('scans'); }
};
PHPEOF

# 7. Findings
cat > database/migrations/2026_01_01_000007_create_findings_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('severity')->default('info');
            $table->float('cvss_score')->nullable();
            $table->string('cvss_vector')->nullable();
            $table->string('cve_id')->nullable()->index();
            $table->string('cwe_id')->nullable();
            $table->text('evidence');
            $table->string('endpoint')->nullable();
            $table->string('affected_component')->nullable();
            $table->string('source_tool');
            $table->text('remediation')->nullable();
            $table->string('status')->default('new');
            $table->boolean('is_false_positive')->default(false);
            $table->float('impact_score')->default(0);
            $table->jsonb('citations')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by')->nullable();
            $table->timestamps();
            $table->index(['scan_id', 'severity']);
            $table->index(['project_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('findings'); }
};
PHPEOF

# 8. Assets (knowledge graph nodes)
cat > database/migrations/2026_01_01_000008_create_assets_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('label');
            $table->string('value')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->jsonb('properties')->nullable();
            $table->float('risk_score')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'type']);
            $table->unique(['project_id', 'type', 'label', 'value']);
        });

        Schema::create('asset_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('target_asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('type');
            $table->jsonb('properties')->nullable();
            $table->float('weight')->default(1);
            $table->timestamps();
            $table->index(['source_asset_id', 'target_asset_id']);
            $table->index('type');
        });
    }
    public function down(): void {
        Schema::dropIfExists('asset_relations');
        Schema::dropIfExists('assets');
    }
};
PHPEOF

# 9. Reports
cat > database/migrations/2026_01_01_000009_create_reports_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->longText('executive_summary')->nullable();
            $table->jsonb('technical_details')->nullable();
            $table->jsonb('recommendations')->nullable();
            $table->jsonb('ai_analysis')->nullable();
            $table->jsonb('remediation_scripts')->nullable();
            $table->jsonb('sbom')->nullable();
            $table->jsonb('graph_snapshot')->nullable();
            $table->string('format')->default('pdf');
            $table->string('file_path')->nullable();
            $table->string('signature')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'generated_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('reports'); }
};
PHPEOF

# 10. Security alerts
cat > database/migrations/2026_01_01_000010_create_security_alerts_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('security_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finding_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('severity');
            $table->string('title');
            $table->text('description');
            $table->string('source')->default('system');
            $table->boolean('acknowledged')->default(false);
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'severity', 'acknowledged']);
        });
    }
    public function down(): void { Schema::dropIfExists('security_alerts'); }
};
PHPEOF

# 11. Audit logs
cat > database/migrations/2026_01_01_000011_create_audit_logs_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->jsonb('details')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'action']);
            $table->index(['entity_type', 'entity_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('audit_logs'); }
};
PHPEOF

# 12. Remediation scripts
cat > database/migrations/2026_01_01_000012_create_remediation_scripts_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('remediation_scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('language');
            $table->longText('code');
            $table->text('explanation')->nullable();
            $table->string('status')->default('generated');
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_log')->nullable();
            $table->string('signature')->nullable();
            $table->timestamps();
            $table->index(['finding_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('remediation_scripts'); }
};
PHPEOF

# 13. Chat sessions
cat > database/migrations/2026_01_01_000013_create_chat_sessions_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_session_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->longText('content');
            $table->jsonb('citations')->nullable();
            $table->timestamps();
            $table->index('chat_session_id');
        });
    }
    public function down(): void {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_sessions');
    }
};
PHPEOF

# 14. Scan profiles
cat > database/migrations/2026_01_01_000014_create_scan_profiles_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scan_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('display_name');
            $table->text('description');
            $table->integer('rate_limit_qps')->default(50);
            $table->integer('jitter_min_ms')->default(0);
            $table->integer('jitter_max_ms')->default(500);
            $table->integer('timeout_seconds')->default(600);
            $table->integer('max_retries')->default(3);
            $table->boolean('requires_admin_approval')->default(false);
            $table->jsonb('tool_flags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('scan_profiles'); }
};
PHPEOF

echo "All 14 migration files created successfully"
ls database/migrations/ | wc -l
