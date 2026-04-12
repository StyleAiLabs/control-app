<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name')->nullable();
            $table->string('trading_name')->nullable();
            $table->string('website_url', 500)->nullable();
            $table->string('industry')->nullable();
            $table->text('description')->nullable();
            $table->string('tagline', 500)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('contact_mobile', 50)->nullable();
            $table->text('physical_address')->nullable();
            $table->text('postal_address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->default('New Zealand');
            $table->string('tax_number', 100)->nullable();
            $table->string('company_reg_number', 100)->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_email')->nullable();
            $table->string('owner_phone', 50)->nullable();
            $table->json('business_hours')->nullable();
            $table->string('after_hours_policy', 255)->nullable();
            $table->string('primary_language', 50)->default('English');
            $table->json('services')->nullable();
            $table->json('faqs')->nullable();
            $table->text('target_customers')->nullable();
            $table->string('tone_hint', 50)->nullable();
            $table->text('pricing_notes')->nullable();
            $table->timestamp('website_extracted_at')->nullable();
            $table->json('website_extraction_raw')->nullable();
            $table->unsignedTinyInteger('profile_completeness')->default(0);
            $table->timestamp('last_synced_to_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
