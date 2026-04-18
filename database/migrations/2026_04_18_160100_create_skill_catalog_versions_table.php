<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_catalog_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('skill_catalog_item_id')->constrained()->cascadeOnDelete();
            $table->string('skill_key');
            $table->string('version');
            $table->json('manifest_json');
            $table->boolean('is_active_published')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_available')->default(true);
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();

            $table->unique(['skill_catalog_item_id', 'version']);
            $table->index(['skill_key', 'is_active_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_catalog_versions');
    }
};
