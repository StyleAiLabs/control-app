<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_runtime_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_runtime_dispatch_id')->nullable()->constrained('tenant_runtime_dispatches')->nullOnDelete();
            $table->string('use_case');
            $table->string('trigger_source');
            $table->string('effective_model')->nullable();
            $table->unsignedInteger('request_count')->default(1);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost_amount', 12, 6)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->timestamp('occurred_at')->nullable();
            $table->string('litellm_call_id')->nullable()->unique();
            $table->string('litellm_spend_log_id')->nullable()->unique();
            $table->string('litellm_key_alias')->nullable();
            $table->json('raw_payload_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'use_case']);
            $table->index(['tenant_id', 'effective_model']);
            $table->index(['tenant_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_runtime_usage_events');
    }
};
