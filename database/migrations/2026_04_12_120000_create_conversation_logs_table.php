<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 50);
            $table->string('external_message_id', 255)->nullable();
            $table->string('from_identifier', 255);
            $table->text('message_in');
            $table->text('message_out')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'channel', 'external_message_id'], 'conversation_logs_tenant_channel_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_logs');
    }
};
