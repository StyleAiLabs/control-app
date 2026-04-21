<?php

namespace Tests\Feature;

use App\Contracts\DockerComposeRunner;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Jobs\ProcessInitialGoogleWorkspaceSync;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\ProvisioningJob;
use App\Models\Server;
use App\Models\SkillCatalogItem;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\TenantSkillAssignment;
use App\Models\User;
use App\Services\TenantAgentSyncService;
use App\Services\TenantGoogleWorkspaceSmokeTestService;
use Illuminate\Support\Facades\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class OnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_tenant_can_view_onboarding_shell(): void
    {
        [$user] = $this->seedTenantWithProfile();

        $this->actingAs($user);

        $this->get('/onboarding')
            ->assertOk()
            ->assertSee('Guided Setup')
            ->assertSee('Set up your digital employee')
            ->assertSee('Wizard Progress')
            ->assertSee('class="wizard-step-label"', false)
            ->assertSee('class="type-section-title"', false)
            ->assertSee('Behind The Scenes')
            ->assertSee('Read your business website')
            ->assertSee('Confirm your business details')
            ->assertSee('Connect your messaging channel')
            ->assertSee('Connect Google Workspace')
            ->assertSee('id="channel-step-next"', false)
            ->assertSee('Bring it live')
            ->assertSee('WhatsApp')
            ->assertSee('Coming Soon')
            ->assertSee('Bot Token');
    }

    public function test_onboarding_shell_and_state_do_not_regenerate_or_mutate_existing_tenant_litellm_key(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        $tenant->forceFill([
            'litellm_virtual_key' => 'existing-tenant-key',
            'litellm_key_alias' => 'openclaw-'.$tenant->tenant_id,
            'litellm_plan_name' => 'trial',
            'litellm_max_budget' => 25,
            'litellm_budget_duration' => 'monthly',
            'litellm_last_synced_at' => now()->subHour(),
        ])->save();

        $originalLastSyncedAt = $tenant->litellm_last_synced_at?->toISOString();

        $this->actingAs($user);

        $this->get('/onboarding')->assertOk();
        $this->get('/onboarding/state')->assertOk();

        $tenant->refresh();

        $this->assertSame('existing-tenant-key', $tenant->litellm_virtual_key);
        $this->assertSame('openclaw-'.$tenant->tenant_id, $tenant->litellm_key_alias);
        $this->assertSame('trial', $tenant->litellm_plan_name);
        $this->assertSame('25.00', $tenant->litellm_max_budget);
        $this->assertSame('monthly', $tenant->litellm_budget_duration);
        $this->assertSame($originalLastSyncedAt, $tenant->litellm_last_synced_at?->toISOString());
    }

    public function test_mutating_onboarding_steps_do_not_regenerate_or_mutate_existing_tenant_litellm_key(): void
    {
        [$user, $tenant, $profile, $files] = $this->seedTenantWithProfile();

        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.master_key', 'litellm-master');
        config()->set('services.litellm.virtual_key', null);
        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');

        Http::fake([
            'https://litellm.stylesoftware.co.nz/key/generate' => Http::response(['key' => 'sk-unexpected-new-key'], 200),
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'refresh_token' => 'google-refresh-token',
                'expires_in' => 3600,
                'scope' => implode(' ', ['openid', 'email', 'profile']),
                'token_type' => 'Bearer',
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'email' => 'owner@example.com',
            ], 200),
        ]);

        $tenant->forceFill([
            'litellm_virtual_key' => 'existing-tenant-key',
            'litellm_key_alias' => 'openclaw-'.$tenant->tenant_id,
            'litellm_plan_name' => 'trial',
            'litellm_max_budget' => 25,
            'litellm_budget_duration' => 'monthly',
            'litellm_last_synced_at' => now()->subHour(),
        ])->save();
        $originalLastSyncedAt = $tenant->litellm_last_synced_at?->toISOString();

        $this->actingAs($user);

        $this->postJson('/onboarding/business-info', [
            'business_name' => 'Acme Plumbing & Drainage',
            'description' => 'We help homeowners with urgent callouts, maintenance, and installs.',
            'industry' => 'Trades',
            'services' => ['Emergency plumbing', 'Drain unblocking'],
            'contact_email' => 'support@acme.example',
            'contact_phone' => '+64 21 999 9999',
            'website_url' => 'https://acme.example',
        ])->assertOk();

        $this->postJson('/onboarding/personality', [
            'tone' => 'friendly',
        ])->assertOk();

        $this->postJson('/onboarding/capabilities', [
            'capabilities' => ['faqs', 'messages'],
        ])->assertOk();

        $this->postJson('/onboarding/channel', [
            'channel' => 'telegram',
            'telegram_bot_token' => 'telegram-bot-token',
        ])
            ->assertOk()
            ->assertJsonPath('state.channel_setup.status', 'saved')
            ->assertJsonPath('state.channel_setup.telegram.bot_token_saved', true)
            ->assertJsonPath('state.channel_setup.telegram.runtime_configured', false);

        $this->get('/onboarding/google/connect')->assertRedirect();
        $credential = $tenant->fresh('googleCredential')->googleCredential;
        $this->get('/auth/google/callback?state='.urlencode((string) $credential?->oauth_state).'&code=test-code')
            ->assertRedirect('/onboarding?step=6');

        $this->post('/onboarding/google/skip')->assertRedirect('/onboarding?step=6');
        $this->post('/onboarding/google/disconnect')->assertRedirect('/onboarding?step=6');

        $files->refresh()->forceFill([
            'identity_markdown' => '# Identity',
            'soul_markdown' => '# Soul',
            'user_markdown' => '# User',
            'bootstrap_markdown' => '# Bootstrap',
            'generated_at' => now(),
        ])->save();
        $profile->refresh()->forceFill([
            'website_url' => 'https://acme.example',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs.',
            'services' => ['Emergency plumbing'],
        ])->save();
        $tenant->refresh()->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'assigned_port' => 4100,
            'workspace_url' => 'https://acme-plumbing.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
        ])->save();
        $tenant->googleCredential()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'status' => TenantGoogleCredential::STATUS_CONNECTED,
                'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
                'google_email' => 'owner@example.com',
                'access_token' => 'google-access-token',
                'refresh_token' => 'google-refresh-token',
                'scopes' => ['openid', 'email'],
                'connected_at' => now(),
            ],
        );

        $localRuntimePath = config('sync360.runtime_root').'/'.$tenant->slug;
        File::ensureDirectoryExists($localRuntimePath.'/.openclaw/workspace');
        File::ensureDirectoryExists($localRuntimePath.'/config');
        File::put($localRuntimePath.'/.env', implode(PHP_EOL, [
            'OPENCLAW_GATEWAY_TOKEN=test-token',
            'OPENAI_API_KEY=existing-tenant-key',
            'OPENAI_BASE_URL=https://litellm.stylesoftware.co.nz',
            '',
        ]));
        File::put($localRuntimePath.'/config/openclaw.json', json_encode([
            'gateway' => [
                'auth' => [
                    'mode' => 'token',
                    'token' => 'test-token',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        File::put($localRuntimePath.'/compose.yaml', 'services: {}'.PHP_EOL);

        $this->postJson('/onboarding/go-live')->assertOk();

        $tenant->refresh();

        $this->assertSame('existing-tenant-key', $tenant->litellm_virtual_key);
        $this->assertSame('openclaw-'.$tenant->tenant_id, $tenant->litellm_key_alias);
        $this->assertSame('trial', $tenant->litellm_plan_name);
        $this->assertSame('25.00', $tenant->litellm_max_budget);
        $this->assertSame('monthly', $tenant->litellm_budget_duration);
        $this->assertSame($originalLastSyncedAt, $tenant->litellm_last_synced_at?->toISOString());
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://litellm.stylesoftware.co.nz/key/generate');
    }

    public function test_onboarding_shell_contains_shared_operation_lock_hooks(): void
    {
        [$user] = $this->seedTenantWithProfile();

        $this->actingAs($user);

        $this->get('/onboarding')
            ->assertOk()
            ->assertSee('id="wizard-operation-note"', false)
            ->assertSee('function startWizardOperation', false)
            ->assertSee('function finishWizardOperation', false)
            ->assertSee('wizardOperation.active', false);
    }

    public function test_onboarding_shell_enables_resync_button_for_live_agent(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        $tenant->forceFill([
            'agent_status' => 'live',
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
        ])->save();

        $this->actingAs($user);

        $response = $this->get('/onboarding?step=7')
            ->assertOk()
            ->assertSee('Resync Assistant', false);

        $this->assertDoesNotMatchRegularExpression('/<button[^>]*disabled[^>]*>Resync Assistant<\/button>/', $response->getContent());
    }

    public function test_onboarding_gracefully_degrades_when_google_credentials_table_is_missing(): void
    {
        [$user] = $this->seedTenantWithProfile();

        Schema::dropIfExists('tenant_google_credentials');

        $this->actingAs($user);

        $this->get('/onboarding')
            ->assertOk()
            ->assertSee('Connect Google Workspace')
            ->assertSee('temporarily unavailable in this environment');

        $this->get('/onboarding/state')
            ->assertOk()
            ->assertJsonPath('google_workspace.status', 'unavailable')
            ->assertJsonPath('google_workspace.available', false)
            ->assertJsonPath('steps.6.status', 'complete');
    }

    public function test_onboarding_state_returns_resume_information_for_new_signup_data(): void
    {
        [$user] = $this->seedTenantWithProfile();

        $this->actingAs($user);

        $this->get('/onboarding/state')
            ->assertOk()
            ->assertJson([
                'onboarding_status' => 'pending',
                'onboarding_step' => 0,
                'provisioning_status' => 'pending',
                'agent_status' => 'offline',
                'resume_from_step' => 1,
                'tenant' => [
                    'business_name' => 'Acme Plumbing',
                    'industry' => 'Trades',
                    'skill_pack' => 'Operations Core',
                ],
                'business' => [
                    'business_name' => 'Acme Plumbing',
                    'industry' => 'Trades',
                    'contact_email' => 'alice@example.com',
                    'contact_phone' => '+64 21 555 0101',
                    'owner_name' => 'Alice Admin',
                    'services' => [],
                ],
                'steps' => [
                    '1' => ['label' => 'Website', 'status' => 'incomplete'],
                    '2' => ['label' => 'Business Info', 'status' => 'incomplete'],
                    '3' => ['label' => 'Tone', 'status' => 'incomplete'],
                    '4' => ['label' => 'Skills', 'status' => 'incomplete'],
                    '5' => ['label' => 'Channel', 'status' => 'incomplete'],
                    '6' => ['label' => 'Google Workspace', 'status' => 'incomplete'],
                    '7' => ['label' => 'Go Live', 'status' => 'incomplete'],
                ],
            ]);
    }

    public function test_onboarding_state_advances_when_profile_tone_and_capabilities_exist(): void
    {
        [$user, $tenant, $profile, $files] = $this->seedTenantWithProfile();

        $profile->forceFill([
            'website_url' => 'https://acme.example',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs and scheduled installs.',
            'services' => ['Emergency plumbing', 'Hot water cylinder installs'],
        ])->save();

        $tenant->forceFill([
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 3,
            'tone' => 'friendly',
            'capabilities' => ['faqs', 'messages'],
        ])->save();

        $files->forceFill([
            'generated_at' => now(),
        ])->save();

        $this->actingAs($user);

        $this->get('/onboarding/state')
            ->assertOk()
            ->assertJson([
                'onboarding_status' => 'in_progress',
                'resume_from_step' => 4,
                'tone' => 'friendly',
                'capabilities' => ['faqs', 'messages'],
                'steps' => [
                    '1' => ['label' => 'Website', 'status' => 'complete'],
                    '2' => ['label' => 'Business Info', 'status' => 'complete'],
                    '3' => ['label' => 'Tone', 'status' => 'complete'],
                    '4' => ['label' => 'Skills', 'status' => 'incomplete'],
                    '5' => ['label' => 'Channel', 'status' => 'incomplete'],
                    '6' => ['label' => 'Google Workspace', 'status' => 'incomplete'],
                    '7' => ['label' => 'Go Live', 'status' => 'incomplete'],
                ],
            ]);
    }

    public function test_extract_business_updates_profile_and_marks_step_one_in_progress(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        $homepageMarkdown = implode("\n", [
            '# Acme Plumbing',
            'Auckland plumbers you can trust.',
            '',
            '[About Us](/about)',
            '[Our Services](/services)',
            '[Contact](/contact)',
        ]);

        Http::fake([
            // Jina Reader: homepage fetch.
            'https://r.jina.ai/https://acme.example' => Http::response($homepageMarkdown, 200),
            // Jina Reader: any discovered sub-pages (scored links like /about, /services).
            'https://r.jina.ai/*' => Http::response('Additional page content.', 200),
            // LiteLLM structured extraction.
            'https://litellm.stylesoftware.co.nz/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'business_name' => 'Acme Plumbing',
                            'trading_name' => null,
                            'tagline' => 'Fast local plumbing help',
                            'description' => 'Acme Plumbing helps homeowners with urgent repairs and planned plumbing jobs.',
                            'industry' => 'Trades',
                            'services' => ['Emergency plumbing', 'Hot water repairs'],
                            'target_customers' => 'Homeowners and property managers in Auckland.',
                            'tone_hint' => 'friendly',
                            'contact_email' => 'hello@acme.example',
                            'contact_phone' => '+64 21 000 0000',
                            'contact_mobile' => null,
                            'physical_address' => '123 Main Street',
                            'city' => 'Auckland',
                            'country' => 'New Zealand',
                            'pricing_notes' => null,
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.virtual_key', 'sk-sync360-control-app-test');
        config()->set('services.litellm.master_key', 'sk-sync360-master-test');

        $this->actingAs($user);

        $this->postJson('/onboarding/extract-business', [
            'url' => 'https://acme.example',
        ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'state' => [
                    'onboarding_status' => 'in_progress',
                    'onboarding_step' => 1,
                    'business' => [
                        'website_url' => 'https://acme.example',
                        'description' => 'Acme Plumbing helps homeowners with urgent repairs and planned plumbing jobs.',
                    ],
                ],
            ]);

        $tenant->refresh();

        $this->assertSame('in_progress', $tenant->onboarding_status);
        $this->assertSame(1, $tenant->onboarding_step);
        $this->assertSame('https://acme.example', $tenant->businessProfile->website_url);
        $this->assertSame('Fast local plumbing help', $tenant->businessProfile->tagline);
        $this->assertSame(['Emergency plumbing', 'Hot water repairs'], $tenant->businessProfile->services);
    }

    public function test_save_business_info_updates_profile_tenant_and_step_two(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        $this->actingAs($user);

        $this->postJson('/onboarding/business-info', [
            'business_name' => 'Acme Plumbing & Drainage',
            'trading_name' => 'Acme Plumbing',
            'description' => 'We help homeowners and property managers with urgent callouts, maintenance, and installs.',
            'industry' => 'Trades',
            'services' => ['Emergency plumbing', 'Drain unblocking'],
            'contact_email' => 'support@acme.example',
            'contact_phone' => '+64 21 999 9999',
            'physical_address' => '123 Main Street',
            'city' => 'Auckland',
            'tagline' => 'Fast local plumbing help',
            'website_url' => 'https://acme.example',
            'owner_name' => 'Alice Admin',
        ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'state' => [
                    'onboarding_status' => 'in_progress',
                    'onboarding_step' => 2,
                    'resume_from_step' => 3,
                    'tenant' => [
                        'business_name' => 'Acme Plumbing & Drainage',
                        'industry' => 'Trades',
                    ],
                    'steps' => [
                        '1' => ['label' => 'Website', 'status' => 'complete'],
                        '2' => ['label' => 'Business Info', 'status' => 'complete'],
                    ],
                ],
            ]);

        $tenant->refresh();

        $this->assertSame('Acme Plumbing & Drainage', $tenant->business_name);
        $this->assertSame('Trades', $tenant->industry);
        $this->assertSame('in_progress', $tenant->onboarding_status);
        $this->assertSame(2, $tenant->onboarding_step);
        $this->assertSame('Acme Plumbing & Drainage', $tenant->businessProfile->business_name);
        $this->assertSame('Acme Plumbing', $tenant->businessProfile->trading_name);
        $this->assertSame(['Emergency plumbing', 'Drain unblocking'], $tenant->businessProfile->services);
        $this->assertSame('support@acme.example', $tenant->businessProfile->contact_email);
        $this->assertSame('https://acme.example', $tenant->businessProfile->website_url);
    }

    public function test_save_personality_updates_tenant_and_profile_tone(): void
    {
        [$user, $tenant, $profile] = $this->seedTenantWithProfile();

        $profile->forceFill([
            'website_url' => 'https://acme.example',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs and scheduled installs.',
            'services' => ['Emergency plumbing', 'Hot water cylinder installs'],
        ])->save();

        $tenant->forceFill([
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 2,
        ])->save();

        $this->actingAs($user);

        $this->postJson('/onboarding/personality', [
            'tone' => 'professional',
        ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'state' => [
                    'onboarding_status' => 'in_progress',
                    'onboarding_step' => 3,
                    'resume_from_step' => 4,
                    'tone' => 'professional',
                    'steps' => [
                        '3' => ['label' => 'Tone', 'status' => 'complete'],
                        '4' => ['label' => 'Skills', 'status' => 'incomplete'],
                    ],
                ],
            ]);

        $tenant->refresh();

        $this->assertSame('professional', $tenant->tone);
        $this->assertSame(3, $tenant->onboarding_step);
        $this->assertSame('professional', $tenant->businessProfile->tone_hint);
    }

    public function test_save_capabilities_generates_internal_files_and_marks_step_four_complete(): void
    {
        [$user, $tenant, $profile, $files] = $this->seedTenantWithProfile();

        config()->set('services.litellm.virtual_key', null);
        config()->set('services.litellm.master_key', null);

        $profile->forceFill([
            'website_url' => 'https://acme.example',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs and scheduled installs.',
            'services' => ['Emergency plumbing', 'Hot water cylinder installs'],
            'target_customers' => 'Homeowners and property managers who need prompt plumbing help.',
            'pricing_notes' => 'Pricing depends on the job scope and should be confirmed when details are clear.',
        ])->save();

        $tenant->forceFill([
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 3,
            'tone' => 'friendly',
        ])->save();

        $this->actingAs($user);

        $this->postJson('/onboarding/capabilities', [
            'capabilities' => ['faqs', 'messages', 'after_hours'],
        ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'state' => [
                    'onboarding_status' => 'in_progress',
                    'onboarding_step' => 4,
                    'resume_from_step' => 5,
                    'tone' => 'friendly',
                    'capabilities' => ['faqs', 'messages', 'after_hours'],
                    'steps' => [
                        '4' => ['label' => 'Skills', 'status' => 'complete'],
                        '5' => ['label' => 'Channel', 'status' => 'incomplete'],
                        '6' => ['label' => 'Google Workspace', 'status' => 'incomplete'],
                    ],
                ],
            ]);

        $tenant->refresh();
        $files->refresh();

        $this->assertSame(['faqs', 'messages', 'after_hours'], $tenant->capabilities);
        $this->assertSame(4, $tenant->onboarding_step);
        $this->assertNotNull($files->generated_at);
        $this->assertIsString($files->identity_markdown);
        $this->assertIsString($files->soul_markdown);
        $this->assertIsString($files->user_markdown);
        $this->assertIsString($files->bootstrap_markdown);
        $this->assertStringContainsString('Acme Plumbing', $files->identity_markdown);
        $this->assertStringContainsString('Communication Style', $files->soul_markdown);
        $this->assertStringContainsString('Answer common questions', $files->soul_markdown);
    }

    public function test_save_channel_persists_telegram_configuration_and_marks_step_five_complete(): void
    {
        [$user, $tenant, $profile] = $this->seedTenantWithProfile();

        $profile->forceFill([
            'website_url' => 'https://acme.example',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs and scheduled installs.',
            'services' => ['Emergency plumbing', 'Hot water cylinder installs'],
        ])->save();

        $tenant->forceFill([
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 4,
            'tone' => 'friendly',
            'capabilities' => ['faqs', 'messages'],
        ])->save();

        $tenant->businessProfileFiles->forceFill([
            'identity_markdown' => '# Identity',
            'soul_markdown' => '# Soul',
            'user_markdown' => '# User',
            'bootstrap_markdown' => '# Bootstrap',
            'generated_at' => now(),
        ])->save();

        $this->actingAs($user);

        $this->postJson('/onboarding/channel', [
            'channel' => 'telegram',
            'telegram_bot_token' => 'telegram-bot-token',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('state.onboarding_status', 'in_progress')
            ->assertJsonPath('state.onboarding_step', 5)
            ->assertJsonPath('state.resume_from_step', 6)
            ->assertJsonPath('state.channel', 'telegram')
            ->assertJsonPath('state.steps.5.label', 'Channel')
            ->assertJsonPath('state.steps.5.status', 'complete')
            ->assertJsonPath('state.steps.6.label', 'Google Workspace')
            ->assertJsonPath('state.steps.6.status', 'incomplete')
            ->assertJsonPath('state.steps.7.label', 'Go Live')
            ->assertJsonPath('state.steps.7.status', 'incomplete')
            ->assertJsonPath('state.channel_setup.selected_channel', 'telegram')
            ->assertJsonPath('state.channel_setup.status', 'saved')
            ->assertJsonPath('state.channel_setup.telegram.bot_token_saved', true)
            ->assertJsonPath('state.channel_setup.telegram.runtime_configured', false);

        $tenant->refresh();

        $this->assertSame('telegram', $tenant->channel);
        $this->assertSame(5, $tenant->onboarding_step);
        $this->assertSame('telegram-bot-token', $tenant->channel_config['telegram_bot_token']);
    }

    public function test_google_connect_redirect_persists_pending_oauth_state(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');
        $this->actingAs($user);

        $this->get('/onboarding/google/connect')
            ->assertRedirect();

        $tenant->refresh();
        $credential = $tenant->googleCredential;

        $this->assertNotNull($credential);
        $this->assertSame(TenantGoogleCredential::STATUS_PENDING, $credential->status);
        $this->assertSame(TenantGoogleCredential::RUNTIME_SYNC_PENDING, $credential->runtime_sync_status);
        $this->assertNotEmpty($credential->oauth_state);
        $this->assertNotEmpty($credential->oauth_code_verifier);
        $this->assertNotNull($credential->oauth_state_expires_at);
    }

    public function test_google_callback_stores_tokens_and_marks_runtime_sync_pending_when_workspace_is_not_ready(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');

        $this->actingAs($user)->get('/onboarding/google/connect');

        $credential = $tenant->fresh('googleCredential')->googleCredential;
        $state = $credential?->oauth_state;

        Http::fake([
            'https://litellm.stylesoftware.co.nz/key/generate' => Http::response(['key' => 'sk-unexpected-new-key'], 200),
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'refresh_token' => 'google-refresh-token',
                'expires_in' => 3600,
                'scope' => implode(' ', [
                    'openid',
                    'email',
                    'profile',
                    'https://www.googleapis.com/auth/gmail.readonly',
                ]),
                'token_type' => 'Bearer',
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'email' => 'owner@example.com',
            ], 200),
        ]);

        $this->get('/auth/google/callback?state='.urlencode((string) $state).'&code=test-code')
            ->assertRedirect('/onboarding?step=6');

        $credential = $tenant->fresh('googleCredential')->googleCredential;

        $this->assertSame(TenantGoogleCredential::STATUS_CONNECTED, $credential?->status);
        $this->assertSame(TenantGoogleCredential::RUNTIME_SYNC_PENDING, $credential?->runtime_sync_status);
        $this->assertSame('owner@example.com', $credential?->google_email);
        $this->assertSame(6, $tenant->fresh()->onboarding_step);
        $this->assertNotNull($credential?->connected_at);
        $this->assertNull($credential?->oauth_state);
    }

    public function test_google_callback_queues_initial_sync_and_exposes_queued_progress_when_workspace_is_ready(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'assigned_port' => 4100,
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'litellm_virtual_key' => 'sk-tenant-acme',
        ])->save();

        Queue::fake([ProcessInitialGoogleWorkspaceSync::class]);

        $this->actingAs($user)->get('/onboarding/google/connect');

        $credential = $tenant->fresh('googleCredential')->googleCredential;

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'refresh_token' => 'google-refresh-token',
                'expires_in' => 3600,
                'scope' => implode(' ', [
                    'openid',
                    'email',
                    'profile',
                    'https://www.googleapis.com/auth/gmail.readonly',
                ]),
                'token_type' => 'Bearer',
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'email' => 'owner@example.com',
            ], 200),
        ]);

        $this->get('/auth/google/callback?state='.urlencode((string) $credential?->oauth_state).'&code=test-code')
            ->assertRedirect('/onboarding?step=6');

        Queue::assertPushed(ProcessInitialGoogleWorkspaceSync::class, function (ProcessInitialGoogleWorkspaceSync $job) use ($tenant): bool {
            return $job->tenantId === $tenant->id;
        });

        $credential = $tenant->fresh('googleCredential')->googleCredential;
        $syncJob = ProvisioningJob::query()
            ->where('tenant_id', $tenant->id)
            ->where('job_type', ProcessInitialGoogleWorkspaceSync::JOB_TYPE)
            ->latest('id')
            ->first();

        $this->assertNotNull($syncJob);
        $this->assertSame('queued', $syncJob?->status?->value);
        $this->assertSame(TenantGoogleCredential::RUNTIME_SYNC_PENDING, $credential?->runtime_sync_status);

        $this->actingAs($user);

        $this->get('/onboarding/state')
            ->assertOk()
            ->assertJsonPath('google_workspace.sync_job_status', 'queued')
            ->assertJsonPath('google_workspace.sync_queued', true)
            ->assertJsonPath('google_workspace.sync_in_progress', false)
            ->assertJsonPath('google_workspace.runtime_sync_label', 'Queued');
    }

    public function test_google_callback_syncs_runtime_artifacts_when_workspace_is_ready(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'assigned_port' => 4100,
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'litellm_virtual_key' => 'sk-tenant-acme',
        ])->save();

        $localRuntimePath = config('sync360.runtime_root').'/'.$tenant->slug;
        File::ensureDirectoryExists($localRuntimePath);
        File::ensureDirectoryExists($localRuntimePath.'/config');
        File::put($localRuntimePath.'/config/openclaw.json', json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        File::put($localRuntimePath.'/compose.yaml', implode(PHP_EOL, [
            'services:',
            '  openclaw-gateway:',
            '    environment:',
            '      OPENCLAW_HOME: "/home/node/.openclaw"',
            '      OPENCLAW_STATE_DIR: "/home/node/.openclaw/data"',
            '      OPENCLAW_CONFIG_PATH: "/home/node/.openclaw/config/openclaw.json"',
            '      OPENCLAW_GATEWAY_TOKEN: "test-token"',
            '',
        ]));
        File::put($localRuntimePath.'/.env', implode(PHP_EOL, [
            'OPENCLAW_GATEWAY_TOKEN=test-token',
            'OPENAI_API_KEY=sk-tenant-acme',
            'OPENAI_BASE_URL=https://litellm.stylesoftware.co.nz',
            '',
        ]));
        File::ensureDirectoryExists($localRuntimePath.'/.openclaw/workspace/memory');

        $staleMemoryPath = $localRuntimePath.'/.openclaw/workspace/memory/'.now()->format('Y-m-d').'-email-access-issue.md';
        $otherMemoryPath = $localRuntimePath.'/.openclaw/workspace/memory/'.now()->format('Y-m-d').'-welcome-session.md';

        File::put($staleMemoryPath, '# Email access issue'.PHP_EOL);
        File::put($otherMemoryPath, '# Welcome session'.PHP_EOL);

        $this->mock(TenantGoogleWorkspaceSmokeTestService::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn([
                    'tenant_slug' => 'acme-plumbing',
                    'google_email' => 'owner@example.com',
                    'runtime_artifacts_verified' => true,
                    'container_smoke_passed' => true,
                ]);
        });

        $this->actingAs($user)->get('/onboarding/google/connect');
        $credential = $tenant->fresh('googleCredential')->googleCredential;

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'refresh_token' => 'google-refresh-token',
                'expires_in' => 3600,
                'scope' => implode(' ', [
                    'openid',
                    'email',
                    'profile',
                    'https://www.googleapis.com/auth/gmail.readonly',
                    'https://www.googleapis.com/auth/gmail.send',
                ]),
                'token_type' => 'Bearer',
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'email' => 'owner@example.com',
            ], 200),
        ]);

        $this->get('/auth/google/callback?state='.urlencode((string) $credential?->oauth_state).'&code=test-code')
            ->assertRedirect('/onboarding?step=6');

        $credential = $tenant->fresh('googleCredential')->googleCredential;
        $localGogPath = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/gogcli';

        $this->assertSame(TenantGoogleCredential::RUNTIME_SYNC_VERIFIED, $credential?->runtime_sync_status);
        $this->assertFileExists($localGogPath.'/credentials.json');
        $this->assertFileExists($localGogPath.'/config.json');
        $this->assertFileExists($localGogPath.'/keyring/token:default:owner@example.com');
        $credentialsJson = File::get($localGogPath.'/credentials.json');
        $this->assertStringContainsString('"installed"', $credentialsJson);
        $this->assertStringContainsString('"client_id": "google-client-id"', $credentialsJson);
        $this->assertStringContainsString('"client_secret": "google-client-secret"', $credentialsJson);
        $this->assertStringContainsString('owner@example.com', File::get($localGogPath.'/config.json'));
        $this->assertDoesNotMatchRegularExpression('/^\s*\\{/', File::get($localGogPath.'/keyring/token:default:owner@example.com'));
        $this->assertStringContainsString('XDG_CONFIG_HOME: "/home/node/.openclaw/.openclaw"', File::get($localRuntimePath.'/compose.yaml'));
        $this->assertStringContainsString('GOG_KEYRING_BACKEND: "file"', File::get($localRuntimePath.'/compose.yaml'));
        $this->assertStringContainsString('source: "/usr/local/bin/gog"', File::get($localRuntimePath.'/compose.yaml'));
        $this->assertStringContainsString('"gog"', File::get($localRuntimePath.'/config/openclaw.json'));
        $this->assertStringContainsString('"enabled": true', File::get($localRuntimePath.'/config/openclaw.json'));
        $this->assertFileDoesNotExist($staleMemoryPath);
        $this->assertFileExists($otherMemoryPath);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://litellm.stylesoftware.co.nz/key/generate');
    }

    public function test_initial_google_workspace_sync_job_does_not_generate_or_rotate_litellm_key(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'assigned_port' => 4100,
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'litellm_virtual_key' => 'existing-tenant-key',
            'litellm_key_alias' => 'openclaw-'.$tenant->tenant_id,
            'litellm_plan_name' => 'trial',
            'litellm_max_budget' => 25,
            'litellm_budget_duration' => 'monthly',
            'litellm_last_synced_at' => now()->subHour(),
        ])->save();
        $originalLastSyncedAt = $tenant->litellm_last_synced_at?->toISOString();

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        $localRuntimePath = config('sync360.runtime_root').'/'.$tenant->slug;
        File::ensureDirectoryExists($localRuntimePath.'/config');
        File::ensureDirectoryExists($localRuntimePath.'/.openclaw/workspace/memory');
        File::put($localRuntimePath.'/.env', implode(PHP_EOL, [
            'OPENCLAW_GATEWAY_TOKEN=test-token',
            'OPENAI_API_KEY=existing-tenant-key',
            'OPENAI_BASE_URL=https://litellm.stylesoftware.co.nz',
            '',
        ]));
        File::put($localRuntimePath.'/config/openclaw.json', json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                ],
            ],
            'gateway' => [
                'auth' => [
                    'mode' => 'token',
                    'token' => 'test-token',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        File::put($localRuntimePath.'/compose.yaml', 'services: {}'.PHP_EOL);

        Http::fake([
            'https://litellm.stylesoftware.co.nz/key/generate' => Http::response(['key' => 'sk-unexpected-new-key'], 200),
        ]);

        $this->mock(TenantGoogleWorkspaceSmokeTestService::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn([
                    'tenant_slug' => 'acme-plumbing',
                    'google_email' => 'owner@example.com',
                    'runtime_artifacts_verified' => true,
                    'container_smoke_passed' => true,
                ]);
        });

        $job = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ProcessInitialGoogleWorkspaceSync::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => ['trigger' => 'test'],
        ]);

        ProcessInitialGoogleWorkspaceSync::dispatchSync($tenant->id, $job->id);

        $tenant->refresh();

        $this->assertSame('existing-tenant-key', $tenant->litellm_virtual_key);
        $this->assertSame('openclaw-'.$tenant->tenant_id, $tenant->litellm_key_alias);
        $this->assertSame('trial', $tenant->litellm_plan_name);
        $this->assertSame('25.00', $tenant->litellm_max_budget);
        $this->assertSame('monthly', $tenant->litellm_budget_duration);
        $this->assertSame($originalLastSyncedAt, $tenant->litellm_last_synced_at?->toISOString());
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://litellm.stylesoftware.co.nz/key/generate');
    }

    public function test_onboarding_state_exposes_google_workspace_attention_details(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_FAILED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
            'last_error' => 'Refresh token exchange failed inside the tenant runtime.',
        ]);

        $this->actingAs($user);

        $this->get('/onboarding/state')
            ->assertOk()
            ->assertJsonPath('google_workspace.status', 'connected')
            ->assertJsonPath('google_workspace.runtime_sync_status', 'failed')
            ->assertJsonPath('google_workspace.needs_attention', true)
            ->assertJsonPath('google_workspace.verified', false)
            ->assertJsonPath('google_workspace.last_error', 'Refresh token exchange failed inside the tenant runtime.');
    }

    public function test_onboarding_state_preserves_runtime_ready_but_blocks_customer_ready_until_google_is_verified(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 5,
        ])->save();

        $this->actingAs($user);

        $this->get('/onboarding/state')
            ->assertOk()
            ->assertJsonPath('workspace.ready', true)
            ->assertJsonPath('workspace.runtime_ready', true)
            ->assertJsonPath('workspace.customer_ready', false)
            ->assertJsonPath('workspace.go_live_ready', false)
            ->assertJsonPath('workspace.blocking_code', 'google_connect_required')
            ->assertJsonPath('steps.6.status', 'incomplete');
    }

    public function test_google_skip_keeps_step_six_incomplete_for_non_live_tenants(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');
        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
        ])->save();
        $this->actingAs($user);

        $this->post('/onboarding/google/skip')
            ->assertRedirect('/onboarding?step=6');

        $tenant->refresh();

        $this->assertSame(6, $tenant->onboarding_step);
        $this->assertSame(TenantGoogleCredential::STATUS_SKIPPED, $tenant->googleCredential?->status);

        $this->get('/onboarding/state')
            ->assertOk()
            ->assertJsonPath('steps.6.status', 'incomplete')
            ->assertJsonPath('workspace.customer_ready', false)
            ->assertJsonPath('workspace.go_live_ready', false)
            ->assertJsonPath('workspace.blocking_code', 'google_connect_required');
    }

    public function test_google_disconnect_clears_runtime_artifacts_and_marks_status_disconnected(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'assigned_port' => 4100,
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'litellm_virtual_key' => 'sk-tenant-acme',
        ])->save();

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_SYNCED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        $localRuntimePath = config('sync360.runtime_root').'/'.$tenant->slug;
        $localGogPath = $localRuntimePath.'/.openclaw/gogcli';
        File::ensureDirectoryExists($localRuntimePath.'/config');
        File::ensureDirectoryExists($localGogPath.'/keyring');
        File::put($localRuntimePath.'/.env', implode(PHP_EOL, [
            'OPENCLAW_GATEWAY_TOKEN=test-token',
            'OPENAI_API_KEY=sk-tenant-acme',
            'OPENAI_BASE_URL=https://litellm.stylesoftware.co.nz',
            '',
        ]));
        File::put($localRuntimePath.'/config/openclaw.json', json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        File::put($localRuntimePath.'/compose.yaml', implode(PHP_EOL, [
            'services:',
            '  openclaw-gateway:',
            '    image: ghcr.io/openclaw/openclaw:latest',
            '    restart: unless-stopped',
            '    container_name: sync360-acme-plumbing',
            '    command:',
            '      - /bin/sh',
            '      - -lc',
            '      - "openclaw gateway --allow-unconfigured --port=18789"',
            '    ports:',
            '      - "127.0.0.1:4100:18789"',
            '    volumes:',
            '      - type: bind',
            '        source: "/srv/sync360/runtime/tenants/acme-plumbing"',
            '        target: "/home/node/.openclaw"',
            '      - type: bind',
            '        source: "/usr/local/bin/gog"',
            '        target: "/usr/local/bin/gog"',
            '        read_only: true',
            '    environment:',
            '      OPENCLAW_HOME: "/home/node/.openclaw"',
            '      OPENCLAW_STATE_DIR: "/home/node/.openclaw/data"',
            '      OPENCLAW_CONFIG_PATH: "/home/node/.openclaw/config/openclaw.json"',
            '      OPENCLAW_GATEWAY_TOKEN: "test-token"',
            '      OPENAI_API_KEY: "sk-tenant-acme"',
            '      OPENAI_BASE_URL: "https://litellm.stylesoftware.co.nz"',
            '      XDG_CONFIG_HOME: "/home/node/.openclaw/.openclaw"',
            '      GOG_KEYRING_BACKEND: "file"',
            '      GOG_KEYRING_PASSWORD: "test-password"',
            '      GOG_ENABLE_COMMANDS: "gmail,calendar,drive,contacts,tasks,sheets,docs,slides,people,chat,classroom,forms,appscript,groups"',
            '      GOG_ACCOUNT: "owner@example.com"',
            '',
        ]));
        File::put($localGogPath.'/credentials.json', '{}');
        File::put($localGogPath.'/config.json', '{}');
        File::put($localGogPath.'/keyring/token:default:owner@example.com', '{}');

        $this->actingAs($user)
            ->post('/onboarding/google/disconnect')
            ->assertRedirect('/onboarding?step=6');

        $credential = $tenant->fresh('googleCredential')->googleCredential;

        $this->assertSame(TenantGoogleCredential::STATUS_DISCONNECTED, $credential?->status);
        $this->assertNull($credential?->access_token);
        $this->assertNull($credential?->refresh_token);
        $this->assertDirectoryDoesNotExist($localGogPath);
        $this->assertStringNotContainsString('GOG_ACCOUNT: "owner@example.com"', File::get($localRuntimePath.'/compose.yaml'));
    }

    public function test_connected_google_workspace_hides_connect_and_skip_actions_in_initial_render(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        $this->actingAs($user);

        $this->get('/onboarding?step=6')
            ->assertOk()
            ->assertSee('Google Workspace is connected and ready in your live workspace.')
            ->assertSee('id="google-workspace-connect-wrapper" style="display: none;"', false)
            ->assertSee('id="google-workspace-skip-form" style="display: none;"', false)
            ->assertSee('id="google-workspace-disconnect-form" style="display: block;"', false);

        $this->get('/onboarding?step=5')
            ->assertOk()
            ->assertSee('id="channel-step-next"', false)
            ->assertSee('Continue To Google Workspace');
    }

    public function test_save_channel_rejects_unsupported_whatsapp_configuration(): void
    {
        [$user, $tenant, $profile] = $this->seedTenantWithProfile();

        $profile->forceFill([
            'website_url' => 'https://acme.example',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs and scheduled installs.',
            'services' => ['Emergency plumbing', 'Hot water cylinder installs'],
        ])->save();

        $tenant->forceFill([
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 4,
            'tone' => 'friendly',
            'capabilities' => ['faqs', 'messages'],
        ])->save();

        $tenant->businessProfileFiles->forceFill([
            'identity_markdown' => '# Identity',
            'soul_markdown' => '# Soul',
            'user_markdown' => '# User',
            'bootstrap_markdown' => '# Bootstrap',
            'generated_at' => now(),
        ])->save();

        $this->actingAs($user);

        $this->postJson('/onboarding/channel', [
            'channel' => 'whatsapp',
            'whatsapp_phone_number_id' => '1234567890',
            'whatsapp_access_token' => 'wa-access-token',
        ])->assertStatus(422);

        $tenant->refresh();

        $this->assertNull($tenant->channel);
        $this->assertNull($tenant->channel_config);
    }

    public function test_go_live_requires_workspace_to_be_ready(): void
    {
        [$user, $tenant, $profile, $files] = $this->seedTenantWithProfile();

        $profile->forceFill([
            'website_url' => 'https://acme.example',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs and scheduled installs.',
            'services' => ['Emergency plumbing', 'Hot water cylinder installs'],
        ])->save();

        $files->forceFill([
            'identity_markdown' => '# Identity',
            'soul_markdown' => '# Soul',
            'user_markdown' => '# User',
            'bootstrap_markdown' => '# Bootstrap',
            'generated_at' => now(),
        ])->save();

        $tenant->forceFill([
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 6,
            'tone' => 'friendly',
            'capabilities' => ['faqs'],
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
            'provisioning_status' => TenantProvisioningStatus::Pending,
        ])->save();

        $this->actingAs($user);

        $this->postJson('/onboarding/go-live')
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_go_live_rejects_synced_but_unverified_google_workspace_state(): void
    {
        [$user, $tenant, $profile, $files] = $this->seedTenantWithProfile();

        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');

        $profile->forceFill([
            'website_url' => 'https://acme.example',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs and scheduled installs.',
            'services' => ['Emergency plumbing', 'Hot water cylinder installs'],
        ])->save();

        $files->forceFill([
            'identity_markdown' => '# Identity',
            'soul_markdown' => '# Soul',
            'user_markdown' => '# User',
            'bootstrap_markdown' => '# Bootstrap',
            'generated_at' => now(),
        ])->save();

        $tenant->forceFill([
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 6,
            'tone' => 'friendly',
            'capabilities' => ['faqs'],
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
        ])->save();

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_SYNCED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        $this->actingAs($user);

        $this->postJson('/onboarding/go-live')
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'google_verifying');
    }

    public function test_go_live_writes_runtime_files_syncs_and_marks_agent_live(): void
    {
        [$user, $tenant, $profile, $files] = $this->seedTenantWithProfile();

        $profile->forceFill([
            'website_url' => 'https://acme.example',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs and scheduled installs.',
            'services' => ['Emergency plumbing', 'Hot water cylinder installs'],
            'contact_email' => 'support@acme.example',
            'contact_phone' => '+64 21 999 9999',
            'physical_address' => '123 Main Street',
            'city' => 'Auckland',
        ])->save();

        $files->forceFill([
            'identity_markdown' => "# Identity\n\nAcme Plumbing",
            'soul_markdown' => "# Soul\n\nFriendly and helpful.",
            'user_markdown' => "# User\n\nSupport homeowners.",
            'bootstrap_markdown' => "# Bootstrap\n\nStart with the business profile.",
            'generated_at' => now(),
        ])->save();

        $tenant->forceFill([
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 6,
            'tone' => 'friendly',
            'capabilities' => ['faqs', 'after_hours'],
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'workspace_url' => 'https://acme-plumbing.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
        ])->save();
        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        $localRuntimePath = config('sync360.runtime_root').'/'.$tenant->slug;
        File::ensureDirectoryExists($localRuntimePath.'/.openclaw/workspace');
        File::ensureDirectoryExists($localRuntimePath.'/config');
        File::put($localRuntimePath.'/config/openclaw.json', json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        File::put($localRuntimePath.'/compose.yaml', implode(PHP_EOL, [
            'services:',
            '  openclaw-gateway:',
            '    environment:',
            '      OPENCLAW_HOME: "/home/node/.openclaw"',
            '      OPENCLAW_STATE_DIR: "/home/node/.openclaw/data"',
            '      OPENCLAW_CONFIG_PATH: "/home/node/.openclaw/config/openclaw.json"',
            '      OPENCLAW_GATEWAY_TOKEN: "test-token"',
            '',
        ]));

        $runnerSpy = new class implements DockerComposeRunner
        {
            /** @var array<int, array<string, mixed>> */
            public array $syncCalls = [];

            /** @var array<int, array<string, mixed>> */
            public array $upCalls = [];

            public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void
            {
                $this->syncCalls[] = [
                    'server_id' => $server->id,
                    'local' => $localRuntimePath,
                    'remote' => $remoteRuntimePath,
                ];
            }

            public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void
            {
                $this->syncCalls[] = [
                    'server_id' => $server->id,
                    'local' => $localWorkspacePath,
                    'remote' => $remoteWorkspacePath,
                ];
            }

            public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array
            {
                $response = Http::timeout($timeoutSeconds)->acceptJson()->send($method, $url, $json !== null ? ['json' => $json] : []);

                return ['status' => $response->status(), 'body' => $response->body()];
            }

            public function putFile(Server $server, string $remotePath, string $contents, bool $sudo = false): void
            {
            }

            public function removeFile(Server $server, string $remotePath, bool $sudo = false): void
            {
            }

            public function removeDirectory(Server $server, string $remotePath, bool $sudo = false): void
            {
            }

            public function runCommand(Server $server, string $command, bool $sudo = false): void
            {
            }

            public function up(Server $server, string $composeFile, string $projectName): void
            {
                $this->upCalls[] = [
                    'server_id' => $server->id,
                    'compose_file' => $composeFile,
                    'project' => $projectName,
                ];
            }

            public function down(Server $server, string $composeFile, string $projectName): void
            {
            }

            public function start(Server $server, string $composeFile, string $projectName): void
            {
            }

            public function stop(Server $server, string $composeFile, string $projectName): void
            {
            }

            public function isRunning(Server $server, string $composeFile, string $projectName): bool
            {
                return false;
            }

            public function isHostPortInUse(Server $server, int $port): bool
            {
                return false;
            }

            public function waitForHttpReady(Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void
            {
            }
        };

        $this->instance(DockerComposeRunner::class, $runnerSpy);
        $this->actingAs($user);

        $this->postJson('/onboarding/go-live')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'state' => [
                    'onboarding_status' => 'complete',
                    'onboarding_step' => 7,
                    'agent_status' => 'live',
                    'resume_from_step' => 7,
                    'steps' => [
                        '5' => ['label' => 'Channel', 'status' => 'complete'],
                        '6' => ['label' => 'Google Workspace', 'status' => 'complete'],
                        '7' => ['label' => 'Go Live', 'status' => 'complete'],
                    ],
                ],
            ]);

        $tenant->refresh();
        $profile->refresh();
        $files->refresh();

        $this->assertSame('live', $tenant->agent_status);
        $this->assertSame('complete', $tenant->onboarding_status);
        $this->assertSame(7, $tenant->onboarding_step);
        $this->assertNotNull($tenant->agent_last_synced_at);
        $this->assertNotNull($profile->last_synced_to_agent);
        $this->assertNotNull($files->synced_at);
        $this->assertStringContainsString('Acme Plumbing', File::get($localRuntimePath.'/.openclaw/workspace/IDENTITY.md'));
        $this->assertStringContainsString('Business Profile', File::get($localRuntimePath.'/.openclaw/workspace/PROFILE.md'));
        $this->assertStringContainsString('Heartbeat Rules', File::get($localRuntimePath.'/.openclaw/workspace/HEARTBEAT.md'));
        $this->assertCount(1, $runnerSpy->syncCalls);
        $this->assertCount(1, $runnerSpy->upCalls);
        $this->assertSame('/srv/sync360/runtime/tenants/acme-plumbing/.openclaw/workspace', $runnerSpy->syncCalls[0]['remote']);
        $this->assertSame('/srv/sync360/runtime/tenants/acme-plumbing/compose.yaml', $runnerSpy->upCalls[0]['compose_file']);
    }

    public function test_live_agent_can_use_go_live_endpoint_to_resync_assistant(): void
    {
        [$user, $tenant] = $this->seedTenantWithProfile();

        $tenant->forceFill([
            'agent_status' => 'live',
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
        ])->save();

        $agentSync = Mockery::mock(TenantAgentSyncService::class);
        $agentSync->shouldReceive('goLive')
            ->once()
            ->withArgs(fn (Tenant $syncedTenant): bool => $syncedTenant->is($tenant))
            ->andReturnNull();
        $agentSync->shouldReceive('isSavedChannelRuntimeConfigured')
            ->andReturnFalse();
        $this->instance(TenantAgentSyncService::class, $agentSync);

        $this->actingAs($user);

        $this->postJson('/onboarding/go-live')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Your digital employee has been resynced with the latest setup details.')
            ->assertJsonPath('state.agent_status', 'live')
            ->assertJsonPath('state.workspace.go_live_ready', false);
    }

    public function test_go_live_includes_owner_google_workspace_guidance_when_connected(): void
    {
        [$user, $tenant, $profile, $files] = $this->seedTenantWithProfile();

        $profile->forceFill([
            'website_url' => 'https://acme.example',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs and scheduled installs.',
            'services' => ['Emergency plumbing'],
        ])->save();

        $files->forceFill([
            'identity_markdown' => "# Identity\n\nAcme Plumbing",
            'soul_markdown' => "# Soul\n\nFriendly and helpful.",
            'user_markdown' => "# User\n\nSupport homeowners.",
            'bootstrap_markdown' => "# Bootstrap\n\nStart with the business profile.",
            'generated_at' => now(),
        ])->save();

        $tenant->forceFill([
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 6,
            'tone' => 'friendly',
            'capabilities' => ['faqs', 'messages'],
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'workspace_url' => 'https://acme-plumbing.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
        ])->save();

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        $skillItem = SkillCatalogItem::query()->create([
            'skill_key' => 'inbox-triage',
            'label' => 'Inbox Triage (by Sync360)',
            'description' => 'Inbox Triage',
            'category' => 'operations',
            'is_assignable' => true,
            'is_orphaned' => false,
        ]);
        $skillVersion = SkillCatalogVersion::query()->create([
            'skill_catalog_item_id' => $skillItem->id,
            'skill_key' => 'inbox-triage',
            'version' => '1.5.3',
            'manifest_json' => [
                'skill_id' => 'inbox-triage',
                'version' => '1.5.3',
                'label' => 'Inbox Triage (by Sync360)',
                'description' => 'Inbox Triage',
                'runtime_type' => 'sync360_workspace',
                'openclaw_skill_ids' => ['inbox-triage'],
                'default_agent_skill_ids' => ['inbox-triage'],
            ],
            'is_active_published' => true,
            'is_archived' => false,
            'is_available' => true,
            'discovered_at' => now(),
            'last_imported_at' => now(),
        ]);
        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $skillVersion->id,
            'skill_key' => 'inbox-triage',
            'assigned_by' => $user->id,
            'assigned_at' => now(),
            'is_enabled' => true,
            'last_apply_status' => 'completed',
        ]);

        $localRuntimePath = config('sync360.runtime_root').'/'.$tenant->slug;
        File::ensureDirectoryExists($localRuntimePath.'/.openclaw/workspace');
        File::ensureDirectoryExists($localRuntimePath.'/config');
        File::put($localRuntimePath.'/config/openclaw.json', json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        File::put($localRuntimePath.'/compose.yaml', implode(PHP_EOL, [
            'services:',
            '  openclaw-gateway:',
            '    environment:',
            '      OPENCLAW_HOME: "/home/node/.openclaw"',
            '      OPENCLAW_STATE_DIR: "/home/node/.openclaw/data"',
            '      OPENCLAW_CONFIG_PATH: "/home/node/.openclaw/config/openclaw.json"',
            '      OPENCLAW_GATEWAY_TOKEN: "test-token"',
            '',
        ]));

        $runnerSpy = new class implements DockerComposeRunner
        {
            public array $syncCalls = [];
            public array $upCalls = [];

            public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}

            public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void
            {
                $this->syncCalls[] = compact('localWorkspacePath', 'remoteWorkspacePath');
            }

            public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array
            {
                $response = Http::timeout($timeoutSeconds)->acceptJson()->send($method, $url, $json !== null ? ['json' => $json] : []);

                return ['status' => $response->status(), 'body' => $response->body()];
            }

            public function putFile(Server $server, string $remotePath, string $contents, bool $sudo = false): void {}
            public function removeFile(Server $server, string $remotePath, bool $sudo = false): void {}
            public function removeDirectory(Server $server, string $remotePath, bool $sudo = false): void {}
            public function runCommand(Server $server, string $command, bool $sudo = false): void {}
            public function up(Server $server, string $composeFile, string $projectName): void
            {
                $this->upCalls[] = compact('composeFile', 'projectName');
            }
            public function down(Server $server, string $composeFile, string $projectName): void {}
            public function start(Server $server, string $composeFile, string $projectName): void {}
            public function stop(Server $server, string $composeFile, string $projectName): void {}
            public function isRunning(Server $server, string $composeFile, string $projectName): bool { return false; }
            public function isHostPortInUse(Server $server, int $port): bool { return false; }
            public function waitForHttpReady(Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void {}
        };

        $this->instance(DockerComposeRunner::class, $runnerSpy);
        $this->actingAs($user)
            ->postJson('/onboarding/go-live')
            ->assertOk();

        $this->assertStringContainsString('Owner Workspace Access', File::get($localRuntimePath.'/.openclaw/workspace/PROFILE.md'));
        $this->assertStringContainsString('Allowlisted Tool Surface: Gmail, Calendar, Drive, Contacts, Tasks, Sheets, Docs, Slides, People, Chat, Classroom, Forms, Apps Script, and Groups.', File::get($localRuntimePath.'/.openclaw/workspace/PROFILE.md'));
        $this->assertStringContainsString('Default Account Rule: Treat the connected Google account as the default', File::get($localRuntimePath.'/.openclaw/workspace/PROFILE.md'));
        $this->assertStringContainsString('Do not ask the owner to pick an account unless a tool explicitly reports multiple configured accounts or no default account.', File::get($localRuntimePath.'/.openclaw/workspace/PROFILE.md'));
        $this->assertStringContainsString('Treat messages from the workspace owner as internal operating requests', File::get($localRuntimePath.'/.openclaw/workspace/HEARTBEAT.md'));
        $this->assertStringContainsString('Do not run `gog auth ...`', File::get($localRuntimePath.'/.openclaw/workspace/HEARTBEAT.md'));
        $this->assertStringContainsString('Do not say you are fundamentally unable to check emails or calendars', File::get($localRuntimePath.'/.openclaw/workspace/HEARTBEAT.md'));
        $this->assertStringContainsString('Do not ask the owner which Google account to use unless a tool explicitly reports multiple configured accounts or a missing default account.', File::get($localRuntimePath.'/.openclaw/workspace/HEARTBEAT.md'));
        $this->assertStringContainsString('includes `Lead ref: <gmail_message_id>`', File::get($localRuntimePath.'/.openclaw/workspace/HEARTBEAT.md'));
        $this->assertStringContainsString('use `gog gmail get <gmail_message_id>` to reopen the exact email', File::get($localRuntimePath.'/.openclaw/workspace/HEARTBEAT.md'));
        $this->assertStringContainsString('Do not guess with Gmail searches from company labels or notification summaries when an exact `Lead ref` is present', File::get($localRuntimePath.'/.openclaw/workspace/HEARTBEAT.md'));
        $this->assertStringContainsString('Lead ref: <gmail_message_id>', File::get($localRuntimePath.'/.openclaw/workspace/skills/inbox-triage/SKILL.md'));
        $this->assertStringContainsString('reply to the original lead notification again', File::get($localRuntimePath.'/.openclaw/workspace/skills/inbox-triage/SKILL.md'));
        $this->assertStringContainsString('The `gog` CLI is preconfigured in this workspace.', File::get($localRuntimePath.'/.openclaw/workspace/TOOLS.md'));
        $this->assertStringContainsString('Treat owner@example.com as the default Google account', File::get($localRuntimePath.'/.openclaw/workspace/TOOLS.md'));
        $this->assertStringContainsString('gog gmail --help', File::get($localRuntimePath.'/.openclaw/workspace/TOOLS.md'));
        $this->assertStringContainsString('Recent email retrieval: use the native Gmail search path', File::get($localRuntimePath.'/.openclaw/workspace/TOOLS.md'));
        $this->assertStringContainsString('Sync360 owns OAuth and account configuration. Do not run `gog auth ...`', File::get($localRuntimePath.'/.openclaw/workspace/TOOLS.md'));
        $this->assertStringContainsString('Calendar read flow: use the native calendar events path', File::get($localRuntimePath.'/.openclaw/workspace/TOOLS.md'));
        $this->assertStringContainsString('Calendar create/reminder flow: use the native create path', File::get($localRuntimePath.'/.openclaw/workspace/TOOLS.md'));
        $this->assertStringContainsString('Do not use unsupported calendar write shapes such as `gog calendar event create`, `--title`, `--start`, `--end`, or `--calendar`', File::get($localRuntimePath.'/.openclaw/workspace/TOOLS.md'));
        $this->assertStringContainsString('Do not ask the owner to choose an account unless `gog` explicitly tells you there are multiple configured accounts or no default account.', File::get($localRuntimePath.'/.openclaw/workspace/TOOLS.md'));
        $this->assertStringContainsString('Explain that as a scope or permission issue, not as a missing `credentials.json` issue.', File::get($localRuntimePath.'/.openclaw/workspace/TOOLS.md'));
    }

    public function test_go_live_replays_saved_channel_config_before_syncing_workspace(): void
    {
        [$user, $tenant, $profile, $files] = $this->seedTenantWithProfile();

        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');

        $profile->forceFill([
            'website_url' => 'https://acme.example',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs.',
            'services' => ['Emergency plumbing'],
        ])->save();

        $files->forceFill([
            'identity_markdown' => '# Identity',
            'soul_markdown' => '# Soul',
            'user_markdown' => '# User',
            'bootstrap_markdown' => '# Bootstrap',
            'generated_at' => now(),
        ])->save();

        $tenant->forceFill([
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 6,
            'tone' => 'friendly',
            'capabilities' => ['faqs'],
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'assigned_port' => 4100,
            'workspace_url' => 'https://acme-plumbing.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'litellm_virtual_key' => 'sk-tenant-acme',
        ])->save();

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        $localRuntimePath = config('sync360.runtime_root').'/'.$tenant->slug;
        File::ensureDirectoryExists($localRuntimePath.'/.openclaw/workspace');
        File::ensureDirectoryExists($localRuntimePath.'/config');
        File::put($localRuntimePath.'/.env', implode(PHP_EOL, [
            'OPENCLAW_GATEWAY_TOKEN=test-token',
            'OPENAI_API_KEY=sk-tenant-acme',
            'OPENAI_BASE_URL=https://litellm.stylesoftware.co.nz',
            '',
        ]));
        File::put($localRuntimePath.'/config/openclaw.json', json_encode([
            'gateway' => [
                'auth' => [
                    'mode' => 'token',
                    'token' => 'test-token',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        File::put($localRuntimePath.'/compose.yaml', 'services: {}'.PHP_EOL);

        $this->actingAs($user)
            ->postJson('/onboarding/go-live')
            ->assertOk()
            ->assertJsonPath('state.channel_setup.status', 'connected')
            ->assertJsonPath('state.channel_setup.telegram.runtime_configured', true);

        $config = json_decode(File::get($localRuntimePath.'/config/openclaw.json'), true);

        $this->assertSame('telegram-bot-token', $config['channels']['telegram']['botToken'] ?? null);
    }

    /**
     * @return array{0: User, 1: Tenant, 2: BusinessProfile, 3: BusinessProfileFiles}
     */
    private function seedTenantWithProfile(): array
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'onboarding_status' => 'pending',
            'onboarding_step' => 0,
            'agent_status' => 'offline',
            'user_id' => $user->id,
            'server_id' => \App\Models\Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Pending,
        ]);

        $profile = BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'contact_email' => 'alice@example.com',
            'contact_phone' => '+64 21 555 0101',
            'owner_name' => 'Alice Admin',
            'owner_email' => 'alice@example.com',
            'owner_phone' => '+64 21 555 0101',
        ]);

        $files = BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
        ]);

        return [$user, $tenant, $profile, $files];
    }
}
