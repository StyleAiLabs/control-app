<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profile_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('identity_markdown')->nullable();
            $table->longText('soul_markdown')->nullable();
            $table->longText('user_markdown')->nullable();
            $table->longText('bootstrap_markdown')->nullable();
            $table->longText('profile_markdown')->nullable();
            $table->longText('heartbeat_markdown')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profile_files');
    }
};
