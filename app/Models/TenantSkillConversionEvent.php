<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSkillConversionEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'event_id',
        'skill_key',
        'skill_version',
        'event_type',
        'conversion_type',
        'conversion_id',
        'occurred_at',
        'session_id',
        'customer_label',
        'contact_masked',
        'estimated_value_amount',
        'currency',
        'human_effort_minutes',
        'agent_effort_minutes',
        'net_minutes_saved',
        'productivity_score',
        'effort_source',
        'effort_override_json',
        'outcome_json',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'estimated_value_amount' => 'decimal:2',
            'effort_override_json' => 'array',
            'outcome_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
