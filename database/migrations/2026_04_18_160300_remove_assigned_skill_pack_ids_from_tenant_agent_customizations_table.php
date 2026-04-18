<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_agent_customizations', function (Blueprint $table): void {
            if (Schema::hasColumn('tenant_agent_customizations', 'assigned_skill_pack_ids')) {
                $table->dropColumn('assigned_skill_pack_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_agent_customizations', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_agent_customizations', 'assigned_skill_pack_ids')) {
                $table->json('assigned_skill_pack_ids')->nullable();
            }
        });
    }
};
