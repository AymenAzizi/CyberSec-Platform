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
