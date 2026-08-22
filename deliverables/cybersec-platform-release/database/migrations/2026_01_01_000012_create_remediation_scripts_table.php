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
