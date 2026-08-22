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
