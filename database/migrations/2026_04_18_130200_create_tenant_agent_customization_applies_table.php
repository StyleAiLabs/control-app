<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_agent_customization_applies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_agent_customization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->unsignedInteger('draft_version_applied')->default(0);
            $table->json('input_snapshot_json')->nullable();
            $table->string('before_output_hash', 64)->nullable();
            $table->string('after_output_hash', 64)->nullable();
            $table->string('status');
            $table->text('error')->nullable();
            $table->json('composed_output_json')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_agent_customization_applies');
    }
};
