<?php

namespace App\Services;

use App\Models\Tenant;
use RuntimeException;

class TenantWorkspaceMessenger
{
    public function __construct(
        private readonly TenantGatewayService $gateway,
    ) {
    }

    public function send(Tenant $tenant, string $channel, string $from, string $message): string
    {
        $chatPath = '/'.ltrim((string) config('sync360.workspace_gateway.chat_path', '/chat'), '/');

        $response = $this->gateway->request($tenant, 'POST', $chatPath, [
            'message' => $message,
            'from' => $from,
            'channel' => $channel,
            'tenant_id' => $tenant->tenant_id,
        ], (int) config('sync360.workspace_gateway.timeout_seconds', 15));

        if ($response['status'] >= 400) {
            throw new RuntimeException(sprintf(
                'Private gateway request failed with HTTP %d.',
                $response['status']
            ));
        }

        $decoded = json_decode($response['body'], true);

        return $this->extractReplyText(is_array($decoded) ? $decoded : null, $response['body']);
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
