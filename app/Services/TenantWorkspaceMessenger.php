<?php

namespace App\Services;

use App\Models\Tenant;
use App\Support\ConversationLogSchema;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class TenantWorkspaceMessenger
{
    public function __construct(
        private readonly TenantGatewayService $gateway,
        private readonly TenantRuntimeService $runtime,
        private readonly Filesystem $files,
        private readonly TenantRuntimeSkillActivationService $runtimeSkillActivation,
        private readonly ExpiredTrialAccessPolicy $expiredTrialAccess,
    ) {
    }

    public function send(Tenant $tenant, string $channel, string $from, string $message, array $requiredSkillIds = []): string
    {
        return $this->dispatchAgentWork($tenant, $channel, $from, $message, $requiredSkillIds, enforceCustomerFacingPolicy: true);
    }

    public function sendOperational(Tenant $tenant, string $channel, string $from, string $message, array $requiredSkillIds = []): string
    {
        return $this->dispatchAgentWork($tenant, $channel, $from, $message, $requiredSkillIds, enforceCustomerFacingPolicy: false);
    }

    private function dispatchAgentWork(
        Tenant $tenant,
        string $channel,
        string $from,
        string $message,
        array $requiredSkillIds,
        bool $enforceCustomerFacingPolicy,
    ): string
    {
        if ($enforceCustomerFacingPolicy && ! $this->expiredTrialAccess->canSendCustomerFacingRuntimeWork($tenant)) {
            throw new RuntimeException(
                'Customer-facing runtime work is paused because this tenant trial has expired. '
                .'Enable the expired-trial runtime reply override in admin to resume replies.'
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

        $response = $this->gateway->request($tenant, 'POST', $hookPath, [
            'message' => $message,
            'name' => $from,
            'wakeMode' => 'now',
            'deliver' => false,
            'idempotencyKey' => $this->idempotencyKey($tenant, $channel, $from, $message),
            'tenant_id' => $tenant->tenant_id,
            'source_channel' => $channel,
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

        return $this->extractReplyText(is_array($decoded) ? $decoded : null, $response['body']);
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
