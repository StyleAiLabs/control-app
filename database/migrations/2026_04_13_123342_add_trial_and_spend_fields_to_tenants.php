<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Trial period end — created_at + 14 days, set once at signup
            $table->timestamp('trial_ends_at')->nullable()->after('trial_status');

            // LiteLLM spend cache — refreshed every 30 min by sync360:check-trial-expiry
            $table->decimal('litellm_spend', 10, 6)->nullable()->after('litellm_last_synced_at');
            $table->timestamp('litellm_spend_cached_at')->nullable()->after('litellm_spend');

            // Notification idempotency guards
            $table->timestamp('trial_80pct_notified_at')->nullable()->after('litellm_spend_cached_at');
            $table->timestamp('trial_3day_notified_at')->nullable()->after('trial_80pct_notified_at');
            $table->timestamp('trial_expired_notified_at')->nullable()->after('trial_3day_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'trial_ends_at',
                'litellm_spend',
                'litellm_spend_cached_at',
                'trial_80pct_notified_at',
                'trial_3day_notified_at',
                'trial_expired_notified_at',
            ]);
        });
    }
};
