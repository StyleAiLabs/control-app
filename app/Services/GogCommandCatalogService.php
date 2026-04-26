<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use Illuminate\Support\Carbon;

class GogCommandCatalogService
{
    /**
     * @return array<string, mixed>
     */
    public function commandContracts(): array
    {
        return [
            'gmail' => [
                'search' => [
                    'shape' => 'gog --json gmail search "in:inbox newer_than:30d" --max 5',
                    'note' => 'Use Gmail search to inspect recent inbox state. Sync360 inbox polling owns de-dupe and delivery.',
                ],
                'get' => [
                    'shape' => 'gog gmail get <gmail_message_id>',
                    'note' => 'Use the exact Gmail message id when reopening a lead or triage event.',
                ],
                'direct_reply' => [
                    'shape' => 'gog gmail send --reply-to-message-id <gmail_message_id> --subject "<subject>" --body "<plain-text-body>"',
                    'allowed_optional_flags' => ['--reply-all', '--quote'],
                    'forbidden_combinations' => ['Do not combine `--reply-to-message-id` with `--thread-id` in the standard Inbox Triage reply flow.'],
                    'note' => 'The upstream README shows broader compose examples, but Sync360 uses the smallest proven direct-reply shape first.',
                ],
                'draft_reply' => [
                    'shape' => 'gog gmail drafts create --reply-to-message-id <gmail_message_id> --subject "<subject>" --body "<plain-text-body>"',
                    'allowed_optional_flags' => ['--quote'],
                    'note' => 'Use draft-only flow only when the owner explicitly asks for a draft.',
                ],
            ],
            'drive' => [
                'search' => [
                    'shape' => 'gog drive search "<query>" --max 10',
                    'note' => 'Use direct search or `gog --json drive ls --max 10` for lightweight Drive lookup.',
                ],
                'upload' => [
                    'shape' => 'gog drive upload <localPath>',
                    'forbidden_flags' => ['--share', '--parent', '--replace', '--name', '--json'],
                    'note' => 'Use the plain upload shape unless a shared contract explicitly requires something broader.',
                ],
            ],
            'sheets' => [
                'metadata' => [
                    'shape' => 'gog sheets metadata <spreadsheetId>',
                ],
                'get' => [
                    'shape' => "gog sheets get <spreadsheetId> 'Qualified Leads!A:L'",
                ],
                'update' => [
                    'shape' => "gog sheets update <spreadsheetId> 'Qualified Leads!A1:L1' '<header-pipe-row>'",
                ],
                'append' => [
                    'shape' => "gog sheets append <spreadsheetId> 'Qualified Leads!A:L' '<pipe-delimited-row>'",
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $definition = config('sync360.runtime_capabilities.gog', []);

        return is_array($definition) ? $definition : [];
    }

    /**
     * @return list<string>
     */
    public function enabledCommands(): array
    {
        $commands = $this->definition()['enabled_commands'] ?? [];

        if (! is_array($commands)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $command): ?string => is_string($command) && trim($command) !== '' ? trim($command) : null,
            $commands,
        ))));
    }

    public function enabledCommandList(): string
    {
        return implode(',', $this->enabledCommands());
    }

    public function allowlistEnvKey(): string
    {
        $key = $this->definition()['allowlist_env_key'] ?? 'GOG_ENABLE_COMMANDS';

        return is_string($key) && trim($key) !== '' ? trim($key) : 'GOG_ENABLE_COMMANDS';
    }

    public function defaultAccountEnvKey(): string
    {
        $key = $this->definition()['default_account_env_key'] ?? 'GOG_ACCOUNT';

        return is_string($key) && trim($key) !== '' ? trim($key) : 'GOG_ACCOUNT';
    }

    /**
     * @return array<string, string>
     */
    public function runtimeEnvironmentFor(Tenant $tenant): array
    {
        $tenant->loadMissing('googleCredential');

        $environment = [
            $this->allowlistEnvKey() => $this->enabledCommandList(),
        ];

        $googleEmail = trim((string) ($tenant->googleCredential?->google_email ?? ''));

        if ($tenant->googleCredential?->isConnected() && $googleEmail !== '') {
            $environment[$this->defaultAccountEnvKey()] = $googleEmail;
        }

        return $environment;
    }

    public function ownerServiceSummary(): string
    {
        return 'Gmail, Calendar, Drive, Contacts, Tasks, Sheets, Docs, Slides, People, Chat, Classroom, Forms, Apps Script, and Groups';
    }

    /**
     * @return list<string>
     */
    public function helpProbeCommands(): array
    {
        $readProbeCommands = [
            'gmail',
            'calendar',
            'drive',
            'contacts',
        ];

        return array_values(array_filter(
            $this->enabledCommands(),
            static fn (string $command): bool => ! in_array($command, $readProbeCommands, true),
        ));
    }

    /**
     * @return list<array{id:string,label:string,argv:list<string>,expect_json:bool}>
     */
    public function cliSmokeProbes(): array
    {
        $now = Carbon::now('UTC');
        $weekAhead = $now->copy()->addDays(7);

        return [
            [
                'id' => 'gmail_cli_verified',
                'label' => 'Gmail CLI',
                'argv' => ['gog', '--json', 'gmail', 'search', 'newer_than:30d', '--max', '5'],
                'expect_json' => true,
            ],
            [
                'id' => 'calendar_cli_verified',
                'label' => 'Calendar CLI',
                'argv' => [
                    'gog',
                    '--json',
                    'calendar',
                    'events',
                    'primary',
                    '--from',
                    $now->toIso8601String(),
                    '--to',
                    $weekAhead->toIso8601String(),
                ],
                'expect_json' => true,
            ],
            [
                'id' => 'drive_cli_verified',
                'label' => 'Drive CLI',
                'argv' => ['gog', '--json', 'drive', 'ls', '--max', '1'],
                'expect_json' => true,
            ],
            [
                'id' => 'contacts_cli_verified',
                'label' => 'Contacts CLI',
                'argv' => ['gog', '--json', 'contacts', 'list', '--max', '1'],
                'expect_json' => true,
            ],
        ];
    }

    /**
     * @return list<array{id:string,label:string,argv:list<string>,expect_json:bool}>
     */
    public function helpProbes(): array
    {
        return array_map(
            static fn (string $command): array => [
                'id' => sprintf('%s_help_verified', $command),
                'label' => sprintf('%s help', $command),
                'argv' => ['gog', $command, '--help'],
                'expect_json' => false,
            ],
            $this->helpProbeCommands(),
        );
    }

    /**
     * @return list<string>
     */
    public function toolGuidanceLines(TenantGoogleCredential $credential): array
    {
        $googleEmail = $credential->google_email ?: 'the connected Google account';
        $contracts = $this->commandContracts();
        $gmailReply = $contracts['gmail']['direct_reply']['shape'];
        $gmailDraft = $contracts['gmail']['draft_reply']['shape'];
        $driveUpload = $contracts['drive']['upload']['shape'];
        $sheetAppend = $contracts['sheets']['append']['shape'];
        $sheetUpdate = $contracts['sheets']['update']['shape'];

        return [
            '- Google Workspace is connected for owner account '.$googleEmail.'.',
            '- The allowlisted `gog` surface in this workspace covers '.$this->ownerServiceSummary().'.',
            '- Runtime status is '.$this->runtimeStatusLabel($credential).'.',
            '- The `gog` CLI is preconfigured in this workspace. You do not need to run a fresh login when the connection is healthy.',
            '- Treat '.$googleEmail.' as the default Google account unless `gog` explicitly reports multiple configured accounts or no default account.',
            '- Sync360 owns OAuth and account configuration. Do not run `gog auth ...`, add/remove Google accounts, or tell the owner to replace `credentials.json` during a normal request.',
            '- When you need Google Workspace data or actions, use exec to run direct `gog` commands instead of replying with a generic refusal.',
            '- Prefer the native direct CLI path: inspect `gog --help` first, then inspect the exact service help such as `gog gmail --help`, `gog calendar --help`, `gog drive --help`, `gog contacts --help`, `gog docs --help`, `gog sheets --help`, or `gog slides --help`.',
            '- When an assigned custom skill gives an exact `gog` command contract, follow that contract exactly and avoid adding extra flags.',
            '- Recent email retrieval: use the native Gmail search path, for example `gog --json gmail search "in:inbox newer_than:30d" --max 5`, then summarize the results for the owner.',
            '- Calendar read flow: use the native calendar events path, for example `gog --json calendar events primary --tomorrow` or `gog --json calendar events primary --from <ISO-START> --to <ISO-END>`.',
            '- Calendar create/reminder flow: use the native create path with the calendar id as the positional argument, `--summary` for the title, `--from` / `--to` for times, and optional `--reminder`, for example `gog --json calendar create primary --summary "Check subscription renewal" --from 2026-04-22T09:00:00+12:00 --to 2026-04-22T09:15:00+12:00 --reminder popup:0m --no-input`.',
            '- Do not use unsupported calendar write shapes such as `gog calendar event create`, `--title`, `--start`, `--end`, or `--calendar`; those flags are not accepted by the pinned `gog` calendar create command.',
            '- Drive file lookup: use the native drive path, for example `gog --json drive ls --max 10` or `gog drive search "<query>" --max 10`.',
            '- Drive upload flow: if a skill asks for a plain upload, use the exact shape `'.$driveUpload.'` and do not add unverified flags such as `--share`, `--parent`, `--replace`, `--name`, or `--json`.',
            '- Contacts lookup: use the native contacts path, for example `gog --json contacts list --max 10` or inspect `gog contacts --help` for a narrower search/get command.',
            '- For Gmail send/reply/draft, calendar changes, docs, sheets, slides, tasks, people, chat, classroom, forms, apps script, and groups, inspect the exact native `gog` subcommand help first and then run the direct command only when the owner explicitly asks for that action.',
            '- Verified Gmail direct-reply surface: `'.$gmailReply.'`.',
            '- In Inbox Triage, do not combine `--reply-to-message-id` with `--thread-id` in the standard direct reply flow.',
            '- Optional Gmail reply flags are limited to `--reply-all` and `--quote` when the context clearly requires them.',
            '- Verified Gmail draft surface: `'.$gmailDraft.'` creates a reply draft without sending it.',
            '- Sheets qualified-lead header update flow: `'.$sheetUpdate.'`.',
            '- Sheets qualified-lead append flow: `'.$sheetAppend.'`.',
            '- If you are unsure which direct service command to use, inspect `gog --help` first, then inspect the exact service help for the family you need.',
            '- Some allowlisted services may still return insufficient-permission or missing-scope errors because the current Google grant is narrower than the full `gog` surface. Explain that as a scope or permission issue, not as a missing `credentials.json` issue.',
            '- If a `gog` command fails, explain the exact command-level error you observed. Suggest reconnecting only when the command explicitly reports invalid, expired, or unauthorized credentials.',
            '- Do not ask the owner to choose an account unless `gog` explicitly tells you there are multiple configured accounts or no default account.',
        ];
    }

    /**
     * @return list<string>
     */
    public function ownerAccessLines(TenantGoogleCredential $credential): array
    {
        $googleEmail = $credential->google_email ?: 'Connected';

        return [
            '- Google Workspace Account: '.$googleEmail,
            '- Runtime Status: '.$this->runtimeStatusLabel($credential, detailed: true),
            '- Allowlisted Tool Surface: '.$this->ownerServiceSummary().'.',
            '- Default Account Rule: Treat the connected Google account as the default unless a tool explicitly reports multiple configured accounts or no default account.',
            '- Do not ask the owner to pick an account unless a tool explicitly reports multiple configured accounts or no default account.',
            '- Sync360 owns OAuth and account configuration. Normal owner requests should use the existing `gog` runtime state instead of running `gog auth` or replacing credential files.',
            '- When the workspace owner asks for inboxes, calendars, drive files, contacts, sheets, docs, slides, tasks, people, chat, classroom, forms, apps script, or groups, use the available `gog` tools instead of giving a generic refusal.',
            '- If a tool reports insufficient permissions or missing scopes, explain that the current Google grant does not cover that action yet. Do not misreport it as a missing `credentials.json` problem.',
            '- If those tools fail during a request, explain the specific tool error you observed. Suggest reconnecting only when the error explicitly points to invalid, expired, or unauthorized credentials.',
        ];
    }

    private function runtimeStatusLabel(TenantGoogleCredential $credential, bool $detailed = false): string
    {
        return match ($credential->runtime_sync_status) {
            TenantGoogleCredential::RUNTIME_SYNC_VERIFIED => $detailed
                ? 'Verified inside the live tenant runtime, including direct `gog` CLI smoke checks.'
                : 'verified',
            TenantGoogleCredential::RUNTIME_SYNC_SYNCED => $detailed
                ? 'Credentials synced into the tenant runtime and awaiting full CLI verification.'
                : 'synced but not yet fully verified',
            TenantGoogleCredential::RUNTIME_SYNC_FAILED => $detailed
                ? 'Connection needs attention before owner workspace actions are reliable.'
                : 'connected but currently needs attention',
            default => $detailed
                ? 'Connection is still being prepared in the tenant runtime.'
                : 'still being prepared',
        };
    }
}
