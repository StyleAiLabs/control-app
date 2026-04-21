<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_skill_analytics_sync_states', function (Blueprint $table): void {
            $table->timestamp('last_failed_at')->nullable()->after('last_synced_at');
            $table->text('last_error_message')->nullable()->after('last_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_skill_analytics_sync_states', function (Blueprint $table): void {
            $table->dropColumn(['last_failed_at', 'last_error_message']);
        });
    }
};
