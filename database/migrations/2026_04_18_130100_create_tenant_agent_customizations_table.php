<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_agent_customizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('prompt_overrides_json')->nullable();
            $table->json('assigned_skill_pack_ids')->nullable();
            $table->json('agent_defaults_json')->nullable();
            $table->unsignedInteger('draft_version')->default(0);
            $table->foreignId('draft_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('draft_updated_at')->nullable();
            $table->json('last_applied_input_snapshot_json')->nullable();
            $table->string('applied_snapshot_hash', 64)->nullable();
            $table->timestamp('last_applied_at')->nullable();
            $table->string('last_apply_status')->nullable();
            $table->text('last_apply_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_agent_customizations');
    }
};
