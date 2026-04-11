<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->text('litellm_virtual_key')->nullable()->after('runtime_path');
            $table->string('litellm_key_alias')->nullable()->after('litellm_virtual_key');
            $table->string('litellm_plan_name')->nullable()->after('litellm_key_alias');
            $table->decimal('litellm_max_budget', 10, 2)->nullable()->after('litellm_plan_name');
            $table->string('litellm_budget_duration')->nullable()->after('litellm_max_budget');
            $table->timestamp('litellm_last_synced_at')->nullable()->after('litellm_budget_duration');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'litellm_virtual_key',
                'litellm_key_alias',
                'litellm_plan_name',
                'litellm_max_budget',
                'litellm_budget_duration',
                'litellm_last_synced_at',
            ]);
        });
    }
};
