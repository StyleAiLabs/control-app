<?php

namespace App\Models;

use App\Enums\TenantRuntimeUsageUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantRuntimeDispatch extends Model
{
    protected $fillable = [
        'tenant_id',
        'use_case',
        'trigger_source',
        'source_channel',
        'source_name',
        'effective_model',
        'request_correlation_key',
        'dispatch_status',
        'workspace_run_id',
        'last_error',
        'occurred_at',
        'dispatched_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'use_case' => TenantRuntimeUsageUseCase::class,
            'occurred_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(TenantRuntimeUsageEvent::class);
    }
}
