<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->string('skill_key')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->boolean('is_assignable')->default(true);
            $table->boolean('is_orphaned')->default(false);
            $table->text('orphaned_warning')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_catalog_items');
    }
};
