<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProfile extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'business_name',
        'trading_name',
        'website_url',
        'industry',
        'description',
        'tagline',
        'contact_email',
        'contact_phone',
        'contact_mobile',
        'physical_address',
        'postal_address',
        'city',
        'country',
        'tax_number',
        'company_reg_number',
        'owner_name',
        'owner_email',
        'owner_phone',
        'business_hours',
        'after_hours_policy',
        'primary_language',
        'services',
        'faqs',
        'target_customers',
        'tone_hint',
        'pricing_notes',
        'website_extracted_at',
        'website_extraction_raw',
        'profile_completeness',
        'last_synced_to_agent',
    ];

    protected function casts(): array
    {
        return [
            'business_hours' => 'array',
            'services' => 'array',
            'faqs' => 'array',
            'website_extraction_raw' => 'array',
            'website_extracted_at' => 'datetime',
            'last_synced_to_agent' => 'datetime',
            'profile_completeness' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
