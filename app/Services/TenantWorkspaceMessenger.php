<?php

namespace App\Services;

use App\Enums\TenantRuntimeUsageUseCase;
use App\Models\Tenant;
use App\Support\ConversationLogSchema;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

class TenantWorkspaceMessenger
{
    public function __construct(
        private readonly TenantGatewayService $gateway,
        private readonly TenantRuntimeService $runtime,
        private readonly Filesystem $files,
        private readonly TenantRuntimeSkillActivationService $runtimeSkillActivation,
        private readonly CommercialAccessPolicy $commercialAccess,
        private readonly TenantRuntimeDispatchRecorder $dispatchRecorder,
    ) {
    }

    public function send(Tenant $tenant, string $channel, string $from, string $message, array $requiredSkillIds = []): string
    {
        return $this->dispatchAgentWork(
            $tenant,
            $channel,
            $from,
            $message,
            $requiredSkillIds,
            enforceCustomerFacingPolicy: true,
            attribution: [
                'use_case' => TenantRuntimeUsageUseCase::ManualRuntimeHook,
                'trigger_source' => 'tenant_workspace_messenger.send',
            ],
        );
    }

    /**
     * @param  array{use_case?:TenantRuntimeUsageUseCase|string|null,trigger_source?:string|null}|array{}  $attribution
     */
    public function sendOperational(Tenant $tenant, string $channel, string $from, string $message, array $requiredSkillIds = [], array $attribution = []): string
    {
        return $this->dispatchAgentWork(
            $tenant,
            $channel,
            $from,
            $message,
            $requiredSkillIds,
            enforceCustomerFacingPolicy: false,
            attribution: $attribution,
        );
    }

    /**
     * @param  array{use_case?:TenantRuntimeUsageUseCase|string|null,trigger_source?:string|null}|array{}  $attribution
     */
    private function dispatchAgentWork(
        Tenant $tenant,
        string $channel,
        string $from,
        string $message,
        array $requiredSkillIds,
        bool $enforceCustomerFacingPolicy,
        array $attribution = [],
    ): string
    {
        if (! $this->commercialAccess->canDispatchAiRuntimeWork($tenant)) {
            throw new RuntimeException(
                match ($this->commercialAccess->runtimePauseReason($tenant)) {
                    'interaction_limit_reached' => 'AI-credit-consuming runtime work is paused because this tenant has reached its monthly interaction limit.',
                    'subscription_suspended' => 'AI-credit-consuming runtime work is paused because this tenant subscription is suspended.',
                    'subscription_cancelled' => 'AI-credit-consuming runtime work is paused because this tenant subscription is cancelled.',
                    default => 'AI-credit-consuming runtime work is paused because this tenant trial has expired. Enable the expired-trial LiteLLM override in admin to resume runtime execution.',
                }
            );
        }

        if ($enforceCustomerFacingPolicy && ! $this->commercialAccess->canSendCustomerFacingRuntimeWork($tenant)) {
            $reason = $this->commercialAccess->runtimePauseReason($tenant);

            throw new RuntimeException(
                match ($reason) {
                    'interaction_limit_reached' => 'Customer-facing runtime work is paused because this tenant has reached its monthly interaction limit.',
                    'subscription_suspended' => 'Customer-facing runtime work is paused because this tenant subscription is suspended.',
                    'subscription_cancelled' => 'Customer-facing runtime work is paused because this tenant subscription is cancelled.',
                    default => 'Customer-facing runtime work is paused because this tenant trial has expired. Enable the expired-trial runtime reply override in admin to resume replies.',
                }
            );
        }

        if (! ConversationLogSchema::isAvailable()) {
            throw new RuntimeException(
                ConversationLogSchema::driftMessage('rerun the blocked runtime action')
            );
        }

        $this->runtimeSkillActivation->ensureRequiredSkillsReady($tenant, $requiredSkillIds);

        $hookPath = '/'.ltrim((string) config('sync360.workspace_gateway.agent_hook_path', '/hooks/agent'), '/');
        $hookToken = $this->hookToken($tenant);
        $idempotencyKey = $this->idempotencyKey($tenant, $channel, $from, $message);
        $dispatch = $this->dispatchRecorder->recordPending($tenant, [
            'use_case' => $attribution['use_case'] ?? TenantRuntimeUsageUseCase::ManualRuntimeHook,
            'trigger_source' => $attribution['trigger_source'] ?? 'tenant_workspace_messenger.send',
            'source_channel' => $channel,
            'source_name' => $from,
            'effective_model' => $this->effectiveModel($tenant),
            'request_correlation_key' => $idempotencyKey,
            'occurred_at' => now(),
        ]);
        $useCase = $attribution['use_case'] ?? TenantRuntimeUsageUseCase::ManualRuntimeHook;
        $useCaseValue = $useCase instanceof TenantRuntimeUsageUseCase ? $useCase->value : (string) $useCase;

        try {
            $response = $this->gateway->request($tenant, 'POST', $hookPath, [
                'message' => $message,
                'name' => $from,
                'wakeMode' => 'now',
                'deliver' => false,
                'idempotencyKey' => $idempotencyKey,
                'tenant_id' => $tenant->tenant_id,
                'source_channel' => $channel,
                'sync360_attribution' => [
                    'tenant_id' => $tenant->tenant_id,
                    'use_case' => $useCaseValue,
                    'trigger_source' => (string) ($attribution['trigger_source'] ?? 'tenant_workspace_messenger.send'),
                    'request_correlation_key' => $idempotencyKey,
                ],
            ], (int) config('sync360.workspace_gateway.timeout_seconds', 15), [
                'Authorization' => 'Bearer '.$hookToken,
            ]);

            if ($response['status'] >= 400) {
                throw new RuntimeException(sprintf(
                    'Private gateway request failed with HTTP %d.',
                    $response['status']
                ));
            }

            $decoded = json_decode($response['body'], true);
            $this->dispatchRecorder->markSent(
                $dispatch,
                is_array($decoded) ? (string) (data_get($decoded, 'runId') ?? data_get($decoded, 'data.runId') ?? '') : null,
            );

            return $this->extractReplyText(is_array($decoded) ? $decoded : null, $response['body']);
        } catch (Throwable $exception) {
            $this->dispatchRecorder->markFailed($dispatch, $exception->getMessage());

            throw $exception;
        }
    }

    private function hookToken(Tenant $tenant): string
    {
        $configPath = $this->runtime->localOpenClawConfigPath($tenant);
        $config = $this->files->exists($configPath)
            ? json_decode($this->files->get($configPath), true)
            : [];

        $token = data_get(is_array($config) ? $config : [], 'hooks.token')
            ?: data_get(is_array($config) ? $config : [], 'gateway.auth.token');

        $token = is_string($token) ? trim($token) : '';

        if ($token === '') {
            throw new RuntimeException('Tenant private hook token is missing.');
        }

        return $token;
    }

    private function idempotencyKey(Tenant $tenant, string $channel, string $from, string $message): string
    {
        return 'sync360-'.hash('sha256', implode("\n", [
            (string) $tenant->tenant_id,
            $channel,
            $from,
            $message,
        ]));
    }

    private function effectiveModel(Tenant $tenant): ?string
    {
        $configPath = $this->runtime->localOpenClawConfigPath($tenant);
        $config = $this->files->exists($configPath)
            ? json_decode($this->files->get($configPath), true)
            : [];

        $model = data_get(is_array($config) ? $config : [], 'agents.defaults.model')
            ?: data_get(is_array($config) ? $config : [], 'models.providers.openai.models.0.id')
            ?: config('sync360.openclaw.default_agent_model', 'gpt-4o');

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /**
     * @param  mixed  $json
     */
    private function extractReplyText(mixed $json, string $body): string
    {
        $candidates = [];

        if (is_array($json)) {
            $candidates = [
                data_get($json, 'reply'),
                data_get($json, 'message'),
                data_get($json, 'text'),
                data_get($json, 'response'),
                data_get($json, 'data.reply'),
                data_get($json, 'data.message'),
                data_get($json, 'content'),
            ];
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        if (trim($body) !== '') {
            return trim($body);
        }

        throw new RuntimeException('Workspace reply did not contain a usable message.');
    }
}
