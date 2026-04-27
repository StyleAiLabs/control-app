<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profile_files', function (Blueprint $table): void {
            $table->string('logo_storage_path')->nullable()->after('heartbeat_markdown');
            $table->string('logo_original_filename')->nullable()->after('logo_storage_path');
            $table->string('logo_mime_type', 100)->nullable()->after('logo_original_filename');
            $table->unsignedBigInteger('logo_size_bytes')->nullable()->after('logo_mime_type');
            $table->timestamp('logo_uploaded_at')->nullable()->after('logo_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('business_profile_files', function (Blueprint $table): void {
            $table->dropColumn([
                'logo_storage_path',
                'logo_original_filename',
                'logo_mime_type',
                'logo_size_bytes',
                'logo_uploaded_at',
            ]);
        });
    }
};
