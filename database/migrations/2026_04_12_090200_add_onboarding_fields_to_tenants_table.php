<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('onboarding_status')->default('pending')->after('provisioning_status');
            $table->unsignedTinyInteger('onboarding_step')->default(0)->after('onboarding_status');
            $table->string('tone', 50)->nullable()->after('skill_pack');
            $table->json('capabilities')->nullable()->after('tone');
            $table->string('channel', 50)->nullable()->after('capabilities');
            $table->text('channel_config')->nullable()->after('channel');
            $table->string('agent_status')->default('offline')->after('channel_config');
            $table->timestamp('agent_last_synced_at')->nullable()->after('agent_status');
            $table->string('webhook_secret', 100)->nullable()->after('agent_last_synced_at');
            $table->timestamp('last_health_check_at')->nullable()->after('webhook_secret');
            $table->string('last_health_check_status', 50)->nullable()->after('last_health_check_at');
            $table->text('health_check_message')->nullable()->after('last_health_check_status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'onboarding_status',
                'onboarding_step',
                'tone',
                'capabilities',
                'channel',
                'channel_config',
                'agent_status',
                'agent_last_synced_at',
                'webhook_secret',
                'last_health_check_at',
                'last_health_check_status',
                'health_check_message',
            ]);
        });
    }
};
