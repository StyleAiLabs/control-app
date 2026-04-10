<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->string('slug')->unique();
            $table->string('business_name');
            $table->string('industry');
            $table->string('skill_pack');
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('trial_status');
            $table->string('provisioning_status');
            $table->unsignedInteger('assigned_port')->nullable()->unique();
            $table->string('workspace_url')->nullable();
            $table->string('runtime_path')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
