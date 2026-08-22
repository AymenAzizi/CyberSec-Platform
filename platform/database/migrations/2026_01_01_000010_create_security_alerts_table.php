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
