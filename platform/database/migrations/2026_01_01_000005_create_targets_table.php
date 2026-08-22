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
