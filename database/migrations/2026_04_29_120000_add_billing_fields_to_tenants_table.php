<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('billing_status')->default('trialing')->after('allow_litellm_when_trial_expired');
            $table->string('billing_plan')->nullable()->after('billing_status');
            $table->timestamp('billing_started_at')->nullable()->after('billing_plan');
            $table->timestamp('billing_grace_ends_at')->nullable()->after('billing_started_at');
            $table->timestamp('billing_cycle_anchor_at')->nullable()->after('billing_grace_ends_at');
            $table->timestamp('billing_cycle_ends_at')->nullable()->after('billing_cycle_anchor_at');
            $table->timestamp('billing_first_paid_at')->nullable()->after('billing_cycle_ends_at');

            $table->index(['billing_status', 'billing_plan']);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex(['billing_status', 'billing_plan']);
            $table->dropColumn([
                'billing_status',
                'billing_plan',
                'billing_started_at',
                'billing_grace_ends_at',
                'billing_cycle_anchor_at',
                'billing_cycle_ends_at',
                'billing_first_paid_at',
            ]);
        });
    }
};
