<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class TenantWorkspaceMessenger
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {
    }

    public function send(Tenant $tenant, string $channel, string $from, string $message): string
    {
        $workspaceUrl = rtrim((string) $tenant->workspace_url, '/');

        if ($workspaceUrl === '') {
            throw new RuntimeException('Workspace URL is missing for this tenant.');
        }

        $chatPath = '/'.ltrim((string) config('sync360.workspace_gateway.chat_path', '/chat'), '/');

        $response = $this->http
            ->timeout((int) config('sync360.workspace_gateway.timeout_seconds', 15))
            ->acceptJson()
            ->post($workspaceUrl.$chatPath, [
                'message' => $message,
                'from' => $from,
                'channel' => $channel,
                'tenant_id' => $tenant->tenant_id,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Workspace request failed with HTTP %d.',
                $response->status()
            ));
        }

        return $this->extractReplyText($response->json(), $response->body());
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
