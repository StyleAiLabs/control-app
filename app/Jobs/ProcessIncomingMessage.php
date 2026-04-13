<?php

namespace App\Jobs;

use App\Models\ConversationLog;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Logs an incoming channel message and schedules a reply-sync job.
 *
 * Message delivery and AI reply are handled entirely by the OpenClaw workspace
 * (via its own webhook/polling integration). This job's sole responsibility is:
 *
 *   1. Deduplicate — skip if we already logged this external_message_id.
 *   2. Create a ConversationLog with message_out = null.
 *   3. Dispatch SyncReplyFromWorkspace (delayed 45 s) to backfill the reply
 *      once OpenClaw has flushed its session log to disk.
 *
 * The former TenantWorkspaceMessenger::send() call that tried POST /chat on
 * the workspace has been removed — OpenClaw does not expose that endpoint.
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

        // Idempotency: skip if we already have a log for this message.
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

        // Dispatch the reply-sync job with a 45-second delay.
        // OpenClaw needs time to process the message and flush its session log.
        // SyncReplyFromWorkspace will retry up to 3× (backoff: 30 s, 60 s) if
        // the reply is not yet present in the session log on first attempt.
        SyncReplyFromWorkspace::dispatch(
            $this->tenantId,
            $this->channel,
            $this->externalMessageId,
        )->delay(now()->addSeconds(45));
    }
}
