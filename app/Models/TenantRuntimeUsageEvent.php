<?php

namespace App\Models;

use App\Enums\TenantRuntimeUsageUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantRuntimeUsageEvent extends Model
{
    protected $fillable = [
        'tenant_id',
        'tenant_runtime_dispatch_id',
        'use_case',
        'trigger_source',
        'effective_model',
        'request_count',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'cost_amount',
        'currency',
        'occurred_at',
        'litellm_call_id',
        'litellm_spend_log_id',
        'litellm_key_alias',
        'raw_payload_json',
    ];

    protected function casts(): array
    {
        return [
            'use_case' => TenantRuntimeUsageUseCase::class,
            'request_count' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost_amount' => 'decimal:6',
            'occurred_at' => 'datetime',
            'raw_payload_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(TenantRuntimeDispatch::class, 'tenant_runtime_dispatch_id');
    }
}
