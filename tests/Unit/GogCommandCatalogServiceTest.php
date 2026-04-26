<?php

namespace Tests\Unit;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\User;
use App\Services\GogCommandCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GogCommandCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_commands_match_the_allowlisted_gog_surface(): void
    {
        /** @var GogCommandCatalogService $service */
        $service = app(GogCommandCatalogService::class);

        $this->assertSame([
            'gmail',
            'calendar',
            'drive',
            'contacts',
            'tasks',
            'sheets',
            'docs',
            'slides',
            'people',
            'chat',
            'classroom',
            'forms',
            'appscript',
            'groups',
        ], $service->enabledCommands());
        $this->assertNotContains('auth', $service->enabledCommands());
        $this->assertNotContains('keep', $service->enabledCommands());
    }

    public function test_runtime_environment_includes_allowlist_and_optional_default_account(): void
    {
        /** @var GogCommandCatalogService $service */
        $service = app(GogCommandCatalogService::class);
        $tenant = $this->seedTenant();

        $this->assertSame([
            'GOG_ENABLE_COMMANDS' => 'gmail,calendar,drive,contacts,tasks,sheets,docs,slides,people,chat,classroom,forms,appscript,groups',
        ], $service->runtimeEnvironmentFor($tenant));

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        $this->assertSame([
            'GOG_ENABLE_COMMANDS' => 'gmail,calendar,drive,contacts,tasks,sheets,docs,slides,people,chat,classroom,forms,appscript,groups',
            'GOG_ACCOUNT' => 'owner@example.com',
        ], $service->runtimeEnvironmentFor($tenant->fresh('googleCredential')));
    }

    public function test_tool_guidance_includes_calendar_and_custom_skill_command_shapes(): void
    {
        /** @var GogCommandCatalogService $service */
        $service = app(GogCommandCatalogService::class);
        $tenant = $this->seedTenant();
        $credential = $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        $guidance = implode("\n", $service->toolGuidanceLines($credential));

        $this->assertStringContainsString('gog --json calendar create primary --summary', $guidance);
        $this->assertStringContainsString('--from 2026-04-22T09:00:00+12:00 --to 2026-04-22T09:15:00+12:00', $guidance);
        $this->assertStringContainsString('--reminder popup:0m --no-input', $guidance);
        $this->assertStringContainsString('Do not use unsupported calendar write shapes such as `gog calendar event create`, `--title`, `--start`, `--end`, or `--calendar`', $guidance);
        $this->assertStringContainsString('When an assigned custom skill gives an exact `gog` command contract, follow that contract exactly and avoid adding extra flags.', $guidance);
        $this->assertStringContainsString('Drive upload flow: if a skill asks for a plain upload, use the exact shape `gog drive upload <localPath>`', $guidance);
        $this->assertStringContainsString('do not add unverified flags such as `--share`, `--parent`, `--replace`, `--name`, or `--json`', $guidance);
        $this->assertStringContainsString('Verified Gmail direct-reply surface: `gog gmail send --reply-to-message-id <gmail_message_id> --subject "<subject>" --body "<plain-text-body>"`.', $guidance);
        $this->assertStringContainsString('do not combine `--reply-to-message-id` with `--thread-id` in the standard direct reply flow', strtolower($guidance));
        $this->assertStringContainsString('Sheets qualified-lead append flow: `gog sheets append <spreadsheetId> \'Qualified Leads!A:L\' \'<pipe-delimited-row>\'`.', $guidance);
    }

    public function test_command_contracts_expose_minimal_gmail_drive_and_sheets_shapes(): void
    {
        /** @var GogCommandCatalogService $service */
        $service = app(GogCommandCatalogService::class);

        $contracts = $service->commandContracts();

        $this->assertSame(
            'gog gmail send --reply-to-message-id <gmail_message_id> --subject "<subject>" --body "<plain-text-body>"',
            $contracts['gmail']['direct_reply']['shape']
        );
        $this->assertContains('--reply-all', $contracts['gmail']['direct_reply']['allowed_optional_flags']);
        $this->assertContains('--quote', $contracts['gmail']['direct_reply']['allowed_optional_flags']);
        $this->assertContains(
            'Do not combine `--reply-to-message-id` with `--thread-id` in the standard Inbox Triage reply flow.',
            $contracts['gmail']['direct_reply']['forbidden_combinations']
        );
        $this->assertSame('gog drive upload <localPath>', $contracts['drive']['upload']['shape']);
        $this->assertSame(
            "gog sheets append <spreadsheetId> 'Qualified Leads!A:L' '<pipe-delimited-row>'",
            $contracts['sheets']['append']['shape']
        );
    }

    private function seedTenant(): Tenant
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        return Tenant::query()->create([
            'tenant_id' => 'tenant_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'assigned_port' => 4100,
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'workspace_url' => 'https://acme-plumbing.workspace.test',
        ]);
    }
}
