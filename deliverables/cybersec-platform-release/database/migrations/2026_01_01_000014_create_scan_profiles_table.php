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
