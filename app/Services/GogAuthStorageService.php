<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use Illuminate\Support\Str;
use RuntimeException;

class GogAuthStorageService
{
    public function __construct(
        private readonly GoogleWorkspaceOAuthService $googleOAuth,
        private readonly TenantRuntimeService $runtime,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function localArtifacts(Tenant $tenant, TenantGoogleCredential $credential): array
    {
        return $this->artifacts($this->runtime->localGogConfigPath($tenant), $credential);
    }

    /**
     * @return array<string, string>
     */
    public function remoteArtifacts(Tenant $tenant, TenantGoogleCredential $credential): array
    {
        return $this->artifacts($this->runtime->remoteGogConfigPath($tenant), $credential);
    }

    public function localConfigRoot(Tenant $tenant): string
    {
        return $this->runtime->localGogConfigPath($tenant);
    }

    public function remoteConfigRoot(Tenant $tenant): string
    {
        return $this->runtime->remoteGogConfigPath($tenant);
    }

    /**
     * @return array<string, string>
     */
    private function artifacts(string $configRoot, TenantGoogleCredential $credential): array
    {
        if (! $credential->isConnected()) {
            throw new RuntimeException('A connected Google Workspace credential is required before writing GOG auth artifacts.');
        }

        $email = (string) $credential->google_email;
        $refreshToken = (string) $credential->refresh_token;
        $accessToken = (string) ($credential->access_token ?? '');
        $safeEmail = Str::of($email)->replaceMatches('/[^A-Za-z0-9._-]+/', '_')->value();
        $tokenPath = rtrim($configRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'keyring'.DIRECTORY_SEPARATOR.'token:default:'.$email;

        $installedPayload = $this->googleOAuth->clientSecretPayload()['installed'];
        $credentialsJson = json_encode([
            ...$installedPayload,
            'installed' => $installedPayload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $configJson = json_encode([
            'default_account' => $email,
            'keyring_backend' => 'file',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $keyringTokenJson = json_encode([
            'service' => 'gogcli',
            'account' => $email,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expiry' => $credential->expires_at?->toIso8601String(),
            'scopes' => is_array($credential->scopes) ? $credential->scopes : [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tokenCacheJson = json_encode([
            'type' => 'authorized_user',
            'client_id' => $this->googleOAuth->clientSecretPayload()['installed']['client_id'] ?? null,
            'client_secret' => $this->googleOAuth->clientSecretPayload()['installed']['client_secret'] ?? null,
            'refresh_token' => $refreshToken,
            'access_token' => $accessToken,
            'account' => $email,
            'scopes' => is_array($credential->scopes) ? $credential->scopes : [],
            'expiry' => $credential->expires_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return [
            rtrim($configRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'credentials.json' => $credentialsJson.PHP_EOL,
            rtrim($configRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'config.json' => $configJson.PHP_EOL,
            $tokenPath => $keyringTokenJson.PHP_EOL,
            rtrim($configRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'token_'.$safeEmail.'.json' => $tokenCacheJson.PHP_EOL,
        ];
    }
}
