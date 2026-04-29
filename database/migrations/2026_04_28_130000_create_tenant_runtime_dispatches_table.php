<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_runtime_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('use_case');
            $table->string('trigger_source');
            $table->string('source_channel')->nullable();
            $table->string('source_name')->nullable();
            $table->string('effective_model')->nullable();
            $table->string('request_correlation_key')->unique();
            $table->string('dispatch_status')->default('pending');
            $table->string('workspace_run_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'use_case']);
            $table->index(['tenant_id', 'effective_model']);
            $table->index(['tenant_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_runtime_dispatches');
    }
};
