<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_skill_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_catalog_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('skill_key');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('last_apply_status')->nullable();
            $table->text('last_apply_error')->nullable();
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'skill_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_skill_assignments');
    }
};
