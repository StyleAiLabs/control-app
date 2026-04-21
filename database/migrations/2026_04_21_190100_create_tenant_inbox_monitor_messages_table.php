<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_inbox_monitor_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('gmail_message_id');
            $table->string('gmail_thread_id')->nullable();
            $table->string('sender_domain')->nullable();
            $table->string('subject_preview', 160)->nullable();
            $table->string('subject_hash', 64)->nullable();
            $table->string('status')->default('detected');
            $table->string('skip_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('delivered_to_agent_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'gmail_message_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'gmail_thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inbox_monitor_messages');
    }
};
