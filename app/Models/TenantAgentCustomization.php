<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantAgentCustomization extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'prompt_overrides_json',
        'assigned_skill_pack_ids',
        'agent_defaults_json',
        'draft_version',
        'draft_updated_by',
        'draft_updated_at',
        'last_applied_input_snapshot_json',
        'applied_snapshot_hash',
        'last_applied_at',
        'last_apply_status',
        'last_apply_error',
    ];

    protected function casts(): array
    {
        return [
            'prompt_overrides_json' => 'array',
            'assigned_skill_pack_ids' => 'array',
            'agent_defaults_json' => 'array',
            'draft_version' => 'integer',
            'draft_updated_at' => 'datetime',
            'last_applied_input_snapshot_json' => 'array',
            'last_applied_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function draftUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'draft_updated_by');
    }

    public function applies(): HasMany
    {
        return $this->hasMany(TenantAgentCustomizationApply::class);
    }
}
