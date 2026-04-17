<?php

namespace Tests\Unit;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\User;
use App\Services\GogAuthStorageService;
use App\Services\GoogleWorkspaceOAuthService;
use App\Services\TenantRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Filesystem\Filesystem;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GogAuthStorageServiceTest extends TestCase
{
    use RefreshDatabase;

    private const AES_KEY_WRAP_DEFAULT_IV = "\xA6\xA6\xA6\xA6\xA6\xA6\xA6\xA6";

    public function test_local_artifacts_write_gog_compatible_credentials_and_encrypted_keyring_token(): void
    {
        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');

        $tenant = $this->makeTenant();
        $credential = $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_SYNCED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email', 'https://www.googleapis.com/auth/gmail.readonly'],
            'connected_at' => now(),
        ]);

        $service = $this->makeService();
        $artifacts = $service->localArtifacts($tenant, $credential);
        $configRoot = rtrim((string) config('sync360.runtime_root'), '/').'/'.$tenant->slug.'/.openclaw/gogcli';
        $tokenPath = $configRoot.'/keyring/token:default:owner@example.com';

        $credentials = json_decode($artifacts[$configRoot.'/credentials.json'], true);
        $tokenCache = json_decode($artifacts[$configRoot.'/token_owner_example.com.json'], true);

        $this->assertIsArray($credentials);
        $this->assertSame('google-client-id', $credentials['client_id'] ?? null);
        $this->assertSame('google-client-secret', $credentials['client_secret'] ?? null);
        $this->assertSame('google-client-id', $credentials['installed']['client_id'] ?? null);
        $this->assertIsArray($tokenCache);
        $this->assertSame('google-refresh-token', $tokenCache['refresh_token'] ?? null);

        $encryptedToken = $artifacts[$tokenPath];

        $this->assertStringNotContainsString('google-refresh-token', $encryptedToken);

        $segments = explode('.', $encryptedToken);
        $this->assertCount(5, $segments);

        $header = json_decode($this->base64UrlDecode($segments[0]), true);

        $this->assertSame('PBES2-HS256+A128KW', $header['alg'] ?? null);
        $this->assertSame('A256GCM', $header['enc'] ?? null);
        $this->assertIsString($header['p2s'] ?? null);
        $this->assertIsInt($header['p2c'] ?? null);

        $kek = hash_pbkdf2(
            'sha256',
            app(TenantRuntimeService::class)->googleKeyringPassword($tenant),
            'PBES2-HS256+A128KW'."\x00".$this->base64UrlDecode((string) $header['p2s']),
            (int) $header['p2c'],
            16,
            true,
        );
        $cek = @openssl_decrypt(
            $this->base64UrlDecode($segments[1]),
            'aes-128-wrap',
            $kek,
            OPENSSL_RAW_DATA,
            self::AES_KEY_WRAP_DEFAULT_IV,
        );

        if (! is_string($cek) || $cek === '') {
            throw new RuntimeException('Unable to unwrap the generated test CEK.');
        }

        $tag = $this->base64UrlDecode($segments[4]);
        $payload = openssl_decrypt(
            $this->base64UrlDecode($segments[3]),
            'aes-256-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $this->base64UrlDecode($segments[2]),
            $tag,
            $segments[0],
        );

        $this->assertIsString($payload);

        $item = json_decode($payload, true);

        $this->assertSame('token:default:owner@example.com', $item['Key'] ?? null);
        $this->assertSame('gogcli', $item['Label'] ?? null);

        $storedToken = json_decode(base64_decode((string) ($item['Data'] ?? ''), true) ?: '', true);

        $this->assertSame('google-refresh-token', $storedToken['refresh_token'] ?? null);
        $this->assertSame(['all'], $storedToken['services'] ?? null);
        $this->assertSame(['openid', 'email', 'https://www.googleapis.com/auth/gmail.readonly'], $storedToken['scopes'] ?? null);
    }

    private function makeService(): GogAuthStorageService
    {
        return new GogAuthStorageService(
            app(GoogleWorkspaceOAuthService::class),
            new TenantRuntimeService(app(Filesystem::class), Mockery::mock(DockerComposeRunner::class)),
        );
    }

    private function makeTenant(): Tenant
    {
        $user = User::factory()->create();

        return Tenant::query()->create([
            'tenant_id' => 'tenant-test-123',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'trial_status' => TrialStatus::Active->value,
            'provisioning_status' => TenantProvisioningStatus::Ready->value,
            'onboarding_status' => 'pending',
            'onboarding_step' => 0,
            'agent_status' => 'offline',
        ]);
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);

        if (! is_string($decoded)) {
            throw new RuntimeException('Unable to decode base64url test fixture.');
        }

        return $decoded;
    }
}
