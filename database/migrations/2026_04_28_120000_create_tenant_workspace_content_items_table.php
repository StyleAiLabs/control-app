<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_workspace_content_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 50);
            $table->string('slug', 160);
            $table->string('title');
            $table->string('status', 50)->default('active');
            $table->string('source_url', 500)->nullable();
            $table->string('source_storage_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('summary')->nullable();
            $table->longText('content_markdown')->nullable();
            $table->json('content_json')->nullable();
            $table->longText('draft_markdown')->nullable();
            $table->text('draft_summary')->nullable();
            $table->json('draft_json')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->string('draft_hash', 64)->nullable();
            $table->string('workspace_path')->nullable();
            $table->string('structured_data_workspace_path')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source_type', 'slug'], 'tenant_workspace_content_items_unique_source_slug');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_workspace_content_items');
    }
};
