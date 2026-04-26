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
        'agent_defaults_json',
        'runtime_api_key_override',
        'draft_version',
        'draft_updated_by',
        'draft_updated_at',
        'last_applied_input_snapshot_json',
        'last_applied_runtime_api_key_override',
        'applied_snapshot_hash',
        'last_applied_at',
        'last_apply_status',
        'last_apply_error',
    ];

    protected function casts(): array
    {
        return [
            'prompt_overrides_json' => 'array',
            'agent_defaults_json' => 'array',
            'runtime_api_key_override' => 'encrypted',
            'draft_version' => 'integer',
            'draft_updated_at' => 'datetime',
            'last_applied_input_snapshot_json' => 'array',
            'last_applied_runtime_api_key_override' => 'encrypted',
            'last_applied_at' => 'datetime',
        ];
    }

    public function hasRuntimeApiKeyOverride(): bool
    {
        return is_string($this->runtime_api_key_override) && trim($this->runtime_api_key_override) !== '';
    }

    public function maskedRuntimeApiKeyOverride(): ?string
    {
        $value = is_string($this->runtime_api_key_override) ? trim($this->runtime_api_key_override) : '';

        if ($value === '') {
            return null;
        }

        return 'Saved override: '.str_repeat('•', 8).substr($value, -2);
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
