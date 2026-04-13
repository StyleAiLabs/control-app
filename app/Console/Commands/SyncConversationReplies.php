<?php

namespace App\Console\Commands;

use App\Models\ConversationLog;
use App\Models\Tenant;
use App\Services\WorkspaceSessionLogReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncConversationReplies extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sync360:sync-replies
                            {tenant? : Tenant ID (ULID) to sync — omit to process all live tenants}';

    /**
     * @var string
     */
    protected $description = 'Upsert ConversationLog records from OpenClaw workspace session logs (message_in + message_out)';

    public function handle(WorkspaceSessionLogReader $reader): int
    {
        $tenantArg = $this->argument('tenant');

        $query = Tenant::query()
            ->with('server')
            ->where('agent_status', 'live')
            ->whereNotNull('runtime_path')
            ->whereNotNull('server_id');

        if ($tenantArg) {
            $query->where('tenant_id', $tenantArg);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->components->info('No live tenants found for reply sync.');

            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($tenants as $tenant) {
            try {
                $conversations = $reader->readConversations($tenant);

                if (empty($conversations)) {
                    $this->components->twoColumnDetail(
                        sprintf('%s (%s)', $tenant->business_name, $tenant->tenant_id),
                        'No session log entries found',
                    );
                    continue;
                }

                $tenantCreated = 0;
                $tenantUpdated = 0;
                $tenantSkipped = 0;

                foreach ($conversations as $messageId => $entry) {
                    $messageId = (string) $messageId;

                    /** @var ConversationLog|null $existing */
                    $existing = ConversationLog::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('channel', $tenant->channel ?? 'telegram')
                        ->where('external_message_id', $messageId)
                        ->first();

                    if ($existing) {
                        // Only update if message_out is still missing.
                        if ($existing->message_out === null && $entry['message_out'] !== null) {
                            $existing->update([
                                'message_out'  => $entry['message_out'],
                                'responded_at' => now(),
                            ]);
                            $updated++;
                            $tenantUpdated++;

                            Log::info('[SyncConversationReplies] Updated message_out.', [
                                'tenant_id'  => $tenant->tenant_id,
                                'message_id' => $messageId,
                            ]);
                        } else {
                            $skipped++;
                            $tenantSkipped++;
                        }

                        continue;
                    }

                    // No ConversationLog exists — create one from the session log data.
                    // This covers conversations that happened while OpenClaw was in
                    // polling mode and our webhook was not registered.
                    ConversationLog::query()->create([
                        'tenant_id'           => $tenant->id,
                        'channel'             => $tenant->channel ?? 'telegram',
                        'external_message_id' => $messageId,
                        'from_identifier'     => $entry['sender_id'],
                        'message_in'          => $entry['message_in'],
                        'message_out'         => $entry['message_out'],
                        'meta_json'           => [
                            'sender_name' => $entry['sender_name'],
                            'source'      => 'session_log_sync',
                        ],
                        'responded_at' => $entry['message_out'] ? now() : null,
                    ]);

                    $created++;
                    $tenantCreated++;

                    Log::info('[SyncConversationReplies] Created from session log.', [
                        'tenant_id'  => $tenant->tenant_id,
                        'message_id' => $messageId,
                    ]);
                }

                $this->components->twoColumnDetail(
                    sprintf('%s (%s)', $tenant->business_name, $tenant->tenant_id),
                    sprintf('Created: %d  Updated: %d  Skipped: %d', $tenantCreated, $tenantUpdated, $tenantSkipped),
                );
            } catch (Throwable $e) {
                $errors++;

                Log::warning('[SyncConversationReplies] Failed for tenant.', [
                    'tenant_id' => $tenant->tenant_id,
                    'error'     => $e->getMessage(),
                ]);

                $this->components->twoColumnDetail(
                    sprintf('%s (%s)', $tenant->business_name, $tenant->tenant_id),
                    'Error: '.$e->getMessage(),
                );
            }
        }

        $this->components->info(sprintf(
            'Sync complete. Created: %d. Updated: %d. Skipped: %d. Errors: %d.',
            $created,
            $updated,
            $skipped,
            $errors,
        ));

        return self::SUCCESS;
    }
}
