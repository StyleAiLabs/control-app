<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_inbox_monitor_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('status')->default('idle');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('backoff_until')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->unique('tenant_id');
            $table->index(['enabled', 'status']);
            $table->index('backoff_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inbox_monitor_states');
    }
};
