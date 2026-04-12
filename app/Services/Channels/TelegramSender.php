<?php

namespace App\Services\Channels;

use App\Models\Tenant;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class TelegramSender
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {
    }

    public function send(Tenant $tenant, string $chatId, string $message): void
    {
        $config = is_array($tenant->channel_config) ? $tenant->channel_config : [];
        $botToken = (string) ($config['telegram_bot_token'] ?? '');

        if ($botToken === '') {
            throw new RuntimeException('Telegram channel configuration is incomplete.');
        }

        $baseUrl = rtrim((string) config('services.telegram.base_url', 'https://api.telegram.org'), '/');
        $endpoint = sprintf('%s/bot%s/sendMessage', $baseUrl, $botToken);

        $response = $this->http
            ->acceptJson()
            ->post($endpoint, [
                'chat_id' => $chatId,
                'text' => $message,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Telegram send failed with HTTP %d.',
                $response->status()
            ));
        }
    }
}
