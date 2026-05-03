<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TypographyRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_layout_uses_dm_sans_and_jetbrains_mono(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('family=DM+Sans', false)
            ->assertSee('family=JetBrains+Mono', false)
            ->assertSee('font-family: "DM Sans", "Segoe UI", sans-serif;', false)
            ->assertSee('font-family: "JetBrains Mono", monospace;', false)
            ->assertSee('.type-label', false)
            ->assertSee('.type-value--technical', false)
            ->assertSee('.type-kicker', false)
            ->assertDontSee('Space+Mono', false)
            ->assertDontSee('"Space Mono", monospace', false);
    }

    public function test_app_layout_uses_dm_sans_and_jetbrains_mono(): void
    {
        [$user] = $this->seedTenant();

        $this->actingAs($user);

        $this->get('/profile')
            ->assertOk()
            ->assertSee('family=DM+Sans', false)
            ->assertSee('family=JetBrains+Mono', false)
            ->assertSee('font-family: "DM Sans", "Segoe UI", sans-serif;', false)
            ->assertSee('font-family: "JetBrains Mono", monospace;', false)
            ->assertSee('.type-label', false)
            ->assertSee('.type-value--technical', false)
            ->assertSee('.badge--technical', false)
            ->assertDontSee('Space+Mono', false)
            ->assertDontSee('"Space Mono", monospace', false);
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function seedTenant(): array
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_typography_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'server_id' => \App\Models\Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'channel' => 'telegram',
        ]);

        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_SKIPPED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
        ]);

        return [$user, $tenant];
    }
}
