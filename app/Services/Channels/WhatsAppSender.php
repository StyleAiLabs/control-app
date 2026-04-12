<?php

namespace App\Services\Channels;

use App\Models\Tenant;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class WhatsAppSender
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {
    }

    public function send(Tenant $tenant, string $to, string $message): void
    {
        $config = is_array($tenant->channel_config) ? $tenant->channel_config : [];
        $phoneNumberId = (string) ($config['whatsapp_phone_number_id'] ?? '');
        $accessToken = (string) ($config['whatsapp_access_token'] ?? '');

        if ($phoneNumberId === '' || $accessToken === '') {
            throw new RuntimeException('WhatsApp channel configuration is incomplete.');
        }

        $baseUrl = rtrim((string) config('services.whatsapp.base_url', 'https://graph.facebook.com'), '/');
        $apiVersion = trim((string) config('services.whatsapp.api_version', 'v22.0'), '/');
        $endpoint = sprintf('%s/%s/%s/messages', $baseUrl, $apiVersion, $phoneNumberId);

        $response = $this->http
            ->acceptJson()
            ->withToken($accessToken)
            ->post($endpoint, [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $message,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'WhatsApp send failed with HTTP %d.',
                $response->status()
            ));
        }
    }
}
