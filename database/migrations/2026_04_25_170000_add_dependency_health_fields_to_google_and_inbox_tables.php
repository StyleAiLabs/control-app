<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_google_credentials', function (Blueprint $table): void {
            $table->string('health_status', 50)->nullable()->after('runtime_sync_status');
            $table->timestamp('health_checked_at')->nullable()->after('last_synced_at');
            $table->timestamp('last_verified_at')->nullable()->after('health_checked_at');
            $table->timestamp('predicted_testing_expiry_at')->nullable()->after('last_verified_at');
            $table->timestamp('expiry_warning_3day_sent_at')->nullable()->after('predicted_testing_expiry_at');
            $table->timestamp('expiry_warning_1day_sent_at')->nullable()->after('expiry_warning_3day_sent_at');
            $table->timestamp('incident_alert_sent_at')->nullable()->after('expiry_warning_1day_sent_at');
            $table->string('incident_alert_reason', 120)->nullable()->after('incident_alert_sent_at');
        });

        Schema::table('tenant_inbox_monitor_states', function (Blueprint $table): void {
            $table->string('health_status', 50)->nullable()->after('status');
            $table->timestamp('health_checked_at')->nullable()->after('last_checked_at');
            $table->timestamp('incident_alert_sent_at')->nullable()->after('health_checked_at');
            $table->string('incident_alert_reason', 120)->nullable()->after('incident_alert_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inbox_monitor_states', function (Blueprint $table): void {
            $table->dropColumn([
                'health_status',
                'health_checked_at',
                'incident_alert_sent_at',
                'incident_alert_reason',
            ]);
        });

        Schema::table('tenant_google_credentials', function (Blueprint $table): void {
            $table->dropColumn([
                'health_status',
                'health_checked_at',
                'last_verified_at',
                'predicted_testing_expiry_at',
                'expiry_warning_3day_sent_at',
                'expiry_warning_1day_sent_at',
                'incident_alert_sent_at',
                'incident_alert_reason',
            ]);
        });
    }
};
