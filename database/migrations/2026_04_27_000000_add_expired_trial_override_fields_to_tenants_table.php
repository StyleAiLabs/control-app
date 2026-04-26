<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('allow_polling_when_trial_expired')->default(false)->after('trial_expired_notified_at');
            $table->boolean('allow_runtime_replies_when_trial_expired')->default(false)->after('allow_polling_when_trial_expired');
            $table->boolean('allow_litellm_when_trial_expired')->default(false)->after('allow_runtime_replies_when_trial_expired');
            $table->decimal('litellm_default_max_budget', 10, 2)->nullable()->after('litellm_max_budget');
            $table->string('litellm_default_budget_duration')->nullable()->after('litellm_budget_duration');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'allow_polling_when_trial_expired',
                'allow_runtime_replies_when_trial_expired',
                'allow_litellm_when_trial_expired',
                'litellm_default_max_budget',
                'litellm_default_budget_duration',
            ]);
        });
    }
};
