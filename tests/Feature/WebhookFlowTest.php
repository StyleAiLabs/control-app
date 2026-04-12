<?php

namespace Tests\Feature;

use App\Models\ConversationLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_verification_returns_challenge_when_verify_token_matches(): void
    {
        $tenant = $this->seedTenantForChannel('whatsapp', [
            'whatsapp_phone_number_id' => '1234567890',
            'whatsapp_access_token' => 'wa-access-token',
            'whatsapp_verify_token' => 'verify-me',
        ]);

        $this->get('/webhooks/whatsapp/'.$tenant->tenant_id.'?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=challenge-token')
            ->assertOk()
            ->assertSeeText('challenge-token');
    }

    public function test_whatsapp_webhook_forwards_message_sends_reply_and_logs_conversation(): void
    {
        $tenant = $this->seedTenantForChannel('whatsapp', [
            'whatsapp_phone_number_id' => '1234567890',
            'whatsapp_access_token' => 'wa-access-token',
            'whatsapp_verify_token' => 'verify-me',
        ]);

        Http::fake([
            'https://acme-plumbing.workspace.test/chat' => Http::response([
                'reply' => 'Thanks, we can help with that.',
            ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.outbound-1']],
            ], 200),
        ]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.inbound-1',
                            'from' => '64215550101',
                            'type' => 'text',
                            'text' => [
                                'body' => 'Do you handle urgent leaks?',
                            ],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/webhooks/whatsapp/'.$tenant->tenant_id, $payload)
            ->assertOk()
            ->assertJson([
                'received' => true,
            ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://acme-plumbing.workspace.test/chat'
                && $request['message'] === 'Do you handle urgent leaks?'
                && $request['channel'] === 'whatsapp';
        });

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/1234567890/messages')
                && $request->hasHeader('Authorization', 'Bearer wa-access-token')
                && $request['to'] === '64215550101'
                && data_get($request->data(), 'text.body') === 'Thanks, we can help with that.';
        });

        $this->assertDatabaseHas('conversation_logs', [
            'tenant_id' => $tenant->id,
            'channel' => 'whatsapp',
            'external_message_id' => 'wamid.inbound-1',
            'from_identifier' => '64215550101',
            'message_in' => 'Do you handle urgent leaks?',
            'message_out' => 'Thanks, we can help with that.',
        ]);
    }

    public function test_duplicate_whatsapp_message_is_ignored_after_first_log(): void
    {
        $tenant = $this->seedTenantForChannel('whatsapp', [
            'whatsapp_phone_number_id' => '1234567890',
            'whatsapp_access_token' => 'wa-access-token',
            'whatsapp_verify_token' => 'verify-me',
        ]);

        Http::fake([
            'https://acme-plumbing.workspace.test/chat' => Http::response([
                'reply' => 'We can help.',
            ], 200),
            'https://graph.facebook.com/*' => Http::response(['ok' => true], 200),
        ]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.duplicate-1',
                            'from' => '64215550101',
                            'type' => 'text',
                            'text' => [
                                'body' => 'Hello there',
                            ],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/webhooks/whatsapp/'.$tenant->tenant_id, $payload)->assertOk();
        $this->postJson('/webhooks/whatsapp/'.$tenant->tenant_id, $payload)->assertOk();

        $this->assertSame(1, ConversationLog::query()->count());
    }

    public function test_telegram_webhook_forwards_message_sends_reply_and_logs_conversation(): void
    {
        $tenant = $this->seedTenantForChannel('telegram', [
            'telegram_bot_token' => 'telegram-bot-token',
        ]);

        Http::fake([
            'https://acme-plumbing.workspace.test/chat' => Http::response([
                'message' => 'Yes, we do after-hours callouts.',
            ], 200),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9001],
            ], 200),
        ]);

        $payload = [
            'update_id' => 7001,
            'message' => [
                'message_id' => 88,
                'chat' => [
                    'id' => 'telegram-chat-1',
                ],
                'text' => 'Are you available tonight?',
            ],
        ];

        $this->postJson('/webhooks/telegram/'.$tenant->tenant_id, $payload)
            ->assertOk()
            ->assertJson([
                'received' => true,
            ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-bot-token/sendMessage'
                && $request['chat_id'] === 'telegram-chat-1'
                && $request['text'] === 'Yes, we do after-hours callouts.';
        });

        $this->assertDatabaseHas('conversation_logs', [
            'tenant_id' => $tenant->id,
            'channel' => 'telegram',
            'external_message_id' => '88',
            'from_identifier' => 'telegram-chat-1',
            'message_in' => 'Are you available tonight?',
            'message_out' => 'Yes, we do after-hours callouts.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $channelConfig
     */
    private function seedTenantForChannel(string $channel, array $channelConfig): Tenant
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        return Tenant::query()->create([
            'tenant_id' => 'tenant_channel_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'server_id' => \App\Models\Server::query()->firstOrFail()->id,
            'channel' => $channel,
            'channel_config' => $channelConfig,
            'workspace_url' => 'https://acme-plumbing.workspace.test',
            'agent_status' => 'live',
            'onboarding_status' => 'complete',
            'onboarding_step' => 6,
            'trial_status' => \App\Enums\TrialStatus::Active,
            'provisioning_status' => \App\Enums\TenantProvisioningStatus::Ready,
        ]);
    }
}
