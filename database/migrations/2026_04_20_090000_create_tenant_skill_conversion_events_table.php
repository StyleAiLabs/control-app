<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_skill_conversion_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('event_id');
            $table->string('skill_key');
            $table->string('skill_version');
            $table->string('event_type');
            $table->string('conversion_type');
            $table->string('conversion_id');
            $table->timestamp('occurred_at');
            $table->string('session_id')->nullable();
            $table->string('customer_label');
            $table->string('contact_masked')->nullable();
            $table->decimal('estimated_value_amount', 12, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->unsignedInteger('human_effort_minutes')->nullable();
            $table->unsignedInteger('agent_effort_minutes')->nullable();
            $table->unsignedInteger('net_minutes_saved')->nullable();
            $table->unsignedInteger('productivity_score')->default(1);
            $table->string('effort_source')->nullable();
            $table->json('effort_override_json')->nullable();
            $table->json('outcome_json');
            $table->timestamps();

            $table->unique(['tenant_id', 'event_id']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['skill_key', 'skill_version']);
            $table->index(['tenant_id', 'skill_key', 'occurred_at'], 'tenant_skill_conversion_events_tenant_skill_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_skill_conversion_events');
    }
};
