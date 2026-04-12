<?php

namespace App\Jobs;

use App\Models\ConversationLog;
use App\Models\Tenant;
use App\Services\Channels\TelegramSender;
use App\Services\Channels\WhatsAppSender;
use App\Services\TenantWorkspaceMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessIncomingMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $channel,
        public readonly string $externalMessageId,
        public readonly string $fromIdentifier,
        public readonly string $messageText,
        public readonly array $meta = [],
    ) {
    }

    public function handle(
        TenantWorkspaceMessenger $workspaceMessenger,
        WhatsAppSender $whatsAppSender,
        TelegramSender $telegramSender,
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        $existing = ConversationLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('channel', $this->channel)
            ->where('external_message_id', $this->externalMessageId)
            ->first();

        if ($existing) {
            return;
        }

        $reply = null;
        $meta = $this->meta;

        try {
            $reply = $workspaceMessenger->send($tenant, $this->channel, $this->fromIdentifier, $this->messageText);

            match ($this->channel) {
                'whatsapp' => $whatsAppSender->send($tenant, $this->fromIdentifier, $reply),
                'telegram' => $telegramSender->send($tenant, $this->fromIdentifier, $reply),
                default => null,
            };
        } catch (Throwable $exception) {
            $meta['error'] = $exception->getMessage();

            Log::warning('Incoming channel message processing failed.', [
                'tenant_id' => $tenant->id,
                'channel' => $this->channel,
                'external_message_id' => $this->externalMessageId,
                'error' => $exception->getMessage(),
            ]);
        }

        ConversationLog::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => $this->channel,
            'external_message_id' => $this->externalMessageId,
            'from_identifier' => $this->fromIdentifier,
            'message_in' => $this->messageText,
            'message_out' => $reply,
            'meta_json' => $meta,
            'responded_at' => $reply ? now() : null,
        ]);
    }
}
