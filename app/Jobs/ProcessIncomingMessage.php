<?php

namespace App\Jobs;

use App\Models\ConversationLog;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Logs an incoming channel message as a ConversationLog.
 *
 * NOTE: OpenClaw handles all Telegram message delivery and AI reply generation
 * autonomously via long-polling. This job fires only when a Telegram webhook
 * is registered to the control-app URL, which is not the default operating
 * mode. It is kept for completeness and future use.
 *
 * message_out is populated separately by the sync360:sync-replies command,
 * which reads OpenClaw's session memory files via SSH and upserts ConversationLog
 * records with both message_in and message_out.
 */
class ProcessIncomingMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

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
    ) {}

    public function handle(): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        // Idempotency: the sync command may have already created this log.
        $exists = ConversationLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('channel', $this->channel)
            ->where('external_message_id', $this->externalMessageId)
            ->exists();

        if ($exists) {
            return;
        }

        ConversationLog::query()->create([
            'tenant_id'           => $tenant->id,
            'channel'             => $this->channel,
            'external_message_id' => $this->externalMessageId,
            'from_identifier'     => $this->fromIdentifier,
            'message_in'          => $this->messageText,
            'message_out'         => null,
            'meta_json'           => $this->meta,
            'responded_at'        => null,
        ]);

        Log::info('[ProcessIncomingMessage] Conversation log created.', [
            'tenant_id'           => $tenant->id,
            'channel'             => $this->channel,
            'external_message_id' => $this->externalMessageId,
        ]);
    }
}
