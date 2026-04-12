<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIncomingMessage;
use App\Models\ConversationLog;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WebhookController extends Controller
{
    public function verifyWhatsApp(Request $request, string $tenantId): Response
    {
        $tenant = $this->tenantFor($tenantId);
        $config = $this->channelConfig($tenant);

        if (($request->query('hub_mode') ?? $request->query('hub.mode')) !== 'subscribe') {
            return response('Unsupported verification mode.', SymfonyResponse::HTTP_BAD_REQUEST);
        }

        if (($config['whatsapp_verify_token'] ?? null) !== ($request->query('hub_verify_token') ?? $request->query('hub.verify_token'))) {
            return response('Invalid verify token.', SymfonyResponse::HTTP_FORBIDDEN);
        }

        return response((string) ($request->query('hub_challenge') ?? $request->query('hub.challenge') ?? ''), SymfonyResponse::HTTP_OK)
            ->header('Content-Type', 'text/plain');
    }

    public function handleWhatsApp(Request $request, string $tenantId): JsonResponse
    {
        $tenant = $this->tenantFor($tenantId);
        $payload = $request->all();
        $config = $this->channelConfig($tenant);

        if (! $this->validWhatsAppSignature($request, $config)) {
            return response()->json(['received' => false], SymfonyResponse::HTTP_FORBIDDEN);
        }

        $messages = data_get($payload, 'entry.0.changes.0.value.messages');

        if (! is_array($messages) || $messages === []) {
            return response()->json(['received' => true]);
        }

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $type = (string) ($message['type'] ?? '');
            $text = trim((string) data_get($message, 'text.body', ''));
            $externalMessageId = trim((string) ($message['id'] ?? ''));
            $from = trim((string) ($message['from'] ?? ''));

            if ($type !== 'text' || $text === '' || $externalMessageId === '' || $from === '') {
                continue;
            }

            if ($this->alreadyLogged($tenant, 'whatsapp', $externalMessageId)) {
                continue;
            }

            ProcessIncomingMessage::dispatch(
                tenantId: $tenant->id,
                channel: 'whatsapp',
                externalMessageId: $externalMessageId,
                fromIdentifier: $from,
                messageText: $text,
                meta: [
                    'provider' => 'whatsapp',
                    'payload' => $payload,
                ],
            );
        }

        return response()->json(['received' => true]);
    }

    public function handleTelegram(Request $request, string $tenantId): JsonResponse
    {
        $tenant = $this->tenantFor($tenantId);
        $payload = $request->all();
        $message = data_get($payload, 'message');

        if (! is_array($message)) {
            return response()->json(['received' => true]);
        }

        $text = trim((string) data_get($message, 'text', ''));
        $externalMessageId = trim((string) data_get($message, 'message_id', ''));
        $chatId = trim((string) data_get($message, 'chat.id', ''));

        if ($text === '' || $externalMessageId === '' || $chatId === '') {
            return response()->json(['received' => true]);
        }

        if (! $this->alreadyLogged($tenant, 'telegram', $externalMessageId)) {
            ProcessIncomingMessage::dispatch(
                tenantId: $tenant->id,
                channel: 'telegram',
                externalMessageId: $externalMessageId,
                fromIdentifier: $chatId,
                messageText: $text,
                meta: [
                    'provider' => 'telegram',
                    'payload' => $payload,
                ],
            );
        }

        return response()->json(['received' => true]);
    }

    private function tenantFor(string $tenantId): Tenant
    {
        return Tenant::query()
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function channelConfig(Tenant $tenant): array
    {
        return is_array($tenant->channel_config) ? $tenant->channel_config : [];
    }

    private function alreadyLogged(Tenant $tenant, string $channel, string $externalMessageId): bool
    {
        return ConversationLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('channel', $channel)
            ->where('external_message_id', $externalMessageId)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validWhatsAppSignature(Request $request, array $config): bool
    {
        $appSecret = (string) ($config['whatsapp_app_secret'] ?? '');
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if ($appSecret === '' || $signature === '') {
            return true;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $signature);
    }
}
