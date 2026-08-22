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
