<?php

namespace Tests\Unit;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\User;
use App\Services\GogCommandCatalogService;
use App\Services\TenantGoogleWorkspaceSmokeTestService;
use App\Services\TenantRuntimeCapabilityService;
use App\Services\TenantRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Filesystem\Filesystem;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TenantGoogleWorkspaceSmokeTestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_translate_smoke_failure_ignores_known_host_warning_and_surfaces_real_error(): void
    {
        $service = $this->makeService();

        $exception = $this->makeProcessFailedException(implode("\n", [
            "Warning: Permanently added '89.116.28.191' (ED25519) to the list of known hosts.",
            'gmail-cli failed: Gmail recent-email probe failed: unauthorized',
            '',
        ]));

        $translated = $this->translateFailure($service, $exception);

        $this->assertSame('gmail-cli failed: Gmail recent-email probe failed: unauthorized', $translated->getMessage());
    }

    public function test_translate_smoke_failure_returns_generic_message_when_process_output_is_only_known_host_noise(): void
    {
        $service = $this->makeService();

        $exception = $this->makeProcessFailedException(implode("\n", [
            "Warning: Permanently added '89.116.28.191' (ED25519) to the list of known hosts.",
            '',
        ]));

        $translated = $this->translateFailure($service, $exception);

        $this->assertSame('Google Workspace runtime verification failed during remote execution.', $translated->getMessage());
    }

    public function test_render_smoke_script_uses_token_cache_for_refresh_preflight_and_accepts_top_level_credentials(): void
    {
        $tenant = $this->makeTenant();
        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_SYNCED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        $service = $this->makeService();
        $method = new ReflectionMethod($service, 'renderSmokeScript');
        $method->setAccessible(true);

        $script = $method->invoke($service, $tenant->fresh('googleCredential'));

        $this->assertIsString($script);
        $this->assertStringContainsString('const refreshToken = tokenCache.refresh_token;', $script);
        $this->assertStringContainsString("const clientId = credentials.client_id || credentials.installed?.client_id;", $script);
        $this->assertStringContainsString("const clientSecret = credentials.client_secret || credentials.installed?.client_secret;", $script);
        $this->assertStringContainsString("if (!existsSync(keyringPath)) {", $script);
        $this->assertStringNotContainsString("const keyring = JSON.parse(readFileSync(keyringPath, 'utf8'));", $script);
    }

    private function makeService(): TenantGoogleWorkspaceSmokeTestService
    {
        return new TenantGoogleWorkspaceSmokeTestService(
            app(Filesystem::class),
            Mockery::mock(DockerComposeRunner::class),
            Mockery::mock(TenantRuntimeService::class),
            Mockery::mock(TenantRuntimeCapabilityService::class),
            app(GogCommandCatalogService::class),
        );
    }

    private function makeTenant(): Tenant
    {
        $user = User::factory()->create();

        return Tenant::query()->create([
            'tenant_id' => 'tenant-smoke-123',
            'slug' => 'smoke-tenant',
            'business_name' => 'Smoke Tenant',
            'industry' => 'Testing',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'trial_status' => TrialStatus::Active->value,
            'provisioning_status' => TenantProvisioningStatus::Ready->value,
            'onboarding_status' => 'pending',
            'onboarding_step' => 0,
            'agent_status' => 'offline',
        ]);
    }

    private function makeProcessFailedException(string $stderr): ProcessFailedException
    {
        $process = new Process([PHP_BINARY, '-r', sprintf('fwrite(STDERR, %s); exit(1);', var_export($stderr, true))]);
        $process->run();

        return new ProcessFailedException($process);
    }

    private function translateFailure(TenantGoogleWorkspaceSmokeTestService $service, ProcessFailedException $exception): RuntimeException
    {
        $method = new ReflectionMethod($service, 'translateSmokeFailure');
        $method->setAccessible(true);

        /** @var RuntimeException $translated */
        $translated = $method->invoke($service, $exception, storage_path('framework/testing/non-existent-smoke-result.json'));

        return $translated;
    }
}
