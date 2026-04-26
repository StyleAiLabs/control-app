<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_agent_customizations', function (Blueprint $table): void {
            $table->text('runtime_api_key_override')->nullable()->after('agent_defaults_json');
            $table->text('last_applied_runtime_api_key_override')->nullable()->after('last_applied_input_snapshot_json');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_agent_customizations', function (Blueprint $table): void {
            $table->dropColumn([
                'runtime_api_key_override',
                'last_applied_runtime_api_key_override',
            ]);
        });
    }
};
