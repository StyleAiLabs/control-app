<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique('tenants_assigned_port_unique');
            $table->unique(['server_id', 'assigned_port'], 'tenants_server_id_assigned_port_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique('tenants_server_id_assigned_port_unique');
            $table->unique('assigned_port', 'tenants_assigned_port_unique');
        });
    }
};
