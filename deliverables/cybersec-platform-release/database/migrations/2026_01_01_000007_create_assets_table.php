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
