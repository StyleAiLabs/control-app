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
                            {tenant? : Tenant ID or internal DB id (omit to process all live tenants)}';

    /**
     * @var string
     */
    protected $description = 'Backfill ConversationLog.message_out from OpenClaw workspace session logs';

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

        $updated = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($tenants as $tenant) {
            // Only look at logs missing a reply, created in the last 7 days.
            $pendingLogs = ConversationLog::query()
                ->where('tenant_id', $tenant->id)
                ->whereNull('message_out')
                ->where('created_at', '>=', now()->subDays(7))
                ->get();

            if ($pendingLogs->isEmpty()) {
                continue;
            }

            try {
                $replies = $reader->readReplies($tenant);

                if (empty($replies)) {
                    $skipped += $pendingLogs->count();
                    $this->components->twoColumnDetail(
                        sprintf('%s (%s)', $tenant->business_name, $tenant->tenant_id),
                        'No session log replies found',
                    );
                    continue;
                }

                $tenantUpdated = 0;
                $tenantSkipped = 0;

                foreach ($pendingLogs as $log) {
                    $reply = $replies[$log->external_message_id] ?? null;

                    if ($reply !== null) {
                        $log->update(['message_out' => $reply, 'responded_at' => now()]);
                        $updated++;
                        $tenantUpdated++;

                        Log::info('[SyncConversationReplies] Reply synced.', [
                            'tenant_id'           => $tenant->tenant_id,
                            'external_message_id' => $log->external_message_id,
                        ]);
                    } else {
                        $skipped++;
                        $tenantSkipped++;
                    }
                }

                $this->components->twoColumnDetail(
                    sprintf('%s (%s)', $tenant->business_name, $tenant->tenant_id),
                    sprintf('Updated: %d  Skipped: %d', $tenantUpdated, $tenantSkipped),
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
            'Reply sync complete. Updated: %d. Skipped: %d. Errors: %d.',
            $updated,
            $skipped,
            $errors,
        ));

        return self::SUCCESS;
    }
}
