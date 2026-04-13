<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_logs', function (Blueprint $table): void {
            // OpenClaw session UUID — groups messages that belong to the same conversation thread.
            $table->string('session_id', 100)->nullable()->after('external_message_id');
            $table->index('session_id', 'conversation_logs_session_id_index');

            // LLM-generated 1–2 sentence summary of the full session conversation.
            $table->text('ai_summary')->nullable()->after('meta_json');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_logs', function (Blueprint $table): void {
            $table->dropIndex('conversation_logs_session_id_index');
            $table->dropColumn(['session_id', 'ai_summary']);
        });
    }
};
