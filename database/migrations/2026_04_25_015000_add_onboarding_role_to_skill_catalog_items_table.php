<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_catalog_items', function (Blueprint $table): void {
            $table->string('onboarding_role', 20)->default('hidden')->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('skill_catalog_items', function (Blueprint $table): void {
            $table->dropColumn('onboarding_role');
        });
    }
};
