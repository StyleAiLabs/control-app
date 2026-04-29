<?php

namespace App\Services;

use App\Enums\TenantRuntimeUsageUseCase;
use App\Models\Tenant;
use App\Models\TenantRuntimeDispatch;

class TenantRuntimeDispatchRecorder
{
    /**
     * @param  array{use_case?:TenantRuntimeUsageUseCase|string|null,trigger_source?:string|null,source_channel?:string|null,source_name?:string|null,effective_model?:string|null,request_correlation_key:string,occurred_at?:\DateTimeInterface|string|null}  $payload
     */
    public function recordPending(Tenant $tenant, array $payload): TenantRuntimeDispatch
    {
        return TenantRuntimeDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'use_case' => $this->normalizeUseCase($payload['use_case'] ?? null)->value,
            'trigger_source' => trim((string) ($payload['trigger_source'] ?? 'tenant_workspace_messenger.send')),
            'source_channel' => $this->nullableString($payload['source_channel'] ?? null),
            'source_name' => $this->nullableString($payload['source_name'] ?? null),
            'effective_model' => $this->nullableString($payload['effective_model'] ?? null),
            'request_correlation_key' => $payload['request_correlation_key'],
            'dispatch_status' => 'pending',
            'occurred_at' => $payload['occurred_at'] ?? now(),
        ]);
    }

    public function markSent(TenantRuntimeDispatch $dispatch, ?string $workspaceRunId = null): TenantRuntimeDispatch
    {
        $dispatch->forceFill([
            'dispatch_status' => 'sent',
            'workspace_run_id' => $this->nullableString($workspaceRunId),
            'dispatched_at' => now(),
            'last_error' => null,
        ])->save();

        return $dispatch;
    }

    public function markFailed(TenantRuntimeDispatch $dispatch, string $error): TenantRuntimeDispatch
    {
        $dispatch->forceFill([
            'dispatch_status' => 'failed',
            'failed_at' => now(),
            'last_error' => $error,
        ])->save();

        return $dispatch;
    }

    private function normalizeUseCase(TenantRuntimeUsageUseCase|string|null $useCase): TenantRuntimeUsageUseCase
    {
        if ($useCase instanceof TenantRuntimeUsageUseCase) {
            return $useCase;
        }

        return TenantRuntimeUsageUseCase::tryFrom((string) $useCase) ?? TenantRuntimeUsageUseCase::ManualRuntimeHook;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
