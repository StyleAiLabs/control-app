<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\User;
use App\Services\ConversationSummaryService;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_tenant_litellm_key_for_summary_generation(): void
    {
        $tenant = $this->makeTenant('sk-tenant-summary');

        Http::fake([
            'https://litellm.stylesoftware.co.nz/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => 'Alice asked for an urgent plumbing callback tonight.',
                    ],
                ]],
            ], 200),
        ]);

        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.virtual_key', 'sk-sync360-control-app-test');

        $summary = app(ConversationSummaryService::class)->summarise($tenant, [
            [
                'message_in' => 'Need an urgent plumbing callback tonight.',
                'message_out' => 'We can arrange that for tonight.',
            ],
        ], 'Alice');

        $this->assertSame('Alice asked for an urgent plumbing callback tonight.', $summary);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://litellm.stylesoftware.co.nz/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer sk-tenant-summary');
        });
    }

    public function test_it_uses_runtime_api_key_override_when_present(): void
    {
        $tenant = $this->makeTenant('sk-tenant-summary');
        TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'runtime_api_key_override' => 'sk-tenant-override',
        ]);

        Http::fake([
            'https://litellm.stylesoftware.co.nz/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => 'Summary.',
                    ],
                ]],
            ], 200),
        ]);

        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.virtual_key', 'sk-sync360-control-app-test');

        app(ConversationSummaryService::class)->summarise($tenant, [
            [
                'message_in' => 'Hello',
                'message_out' => 'Hi there',
            ],
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://litellm.stylesoftware.co.nz/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer sk-tenant-override');
        });
    }

    public function test_it_fails_closed_when_tenant_credentials_are_missing(): void
    {
        $tenant = $this->makeTenant(null);

        Http::fake();

        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.virtual_key', 'sk-sync360-control-app-test');

        $summary = app(ConversationSummaryService::class)->summarise($tenant, [
            [
                'message_in' => 'Hello',
                'message_out' => 'Hi there',
            ],
        ]);

        $this->assertNull($summary);
        Http::assertSentCount(0);
    }

    private function makeTenant(?string $litellmKey): Tenant
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'summary-owner-'.str()->lower(str()->random(6)).'@example.com',
            'password' => 'secret',
        ]);

        return Tenant::query()->create([
            'tenant_id' => 'tenant-summary-'.str()->lower(str()->random(6)),
            'slug' => 'summary-tenant-'.str()->lower(str()->random(6)),
            'business_name' => 'Summary Tenant',
            'industry' => 'Services',
            'skill_pack' => 'Core Modules',
            'channel' => 'telegram',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'litellm_virtual_key' => $litellmKey,
        ]);
    }
}
