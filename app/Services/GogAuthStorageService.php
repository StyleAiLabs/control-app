<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use Illuminate\Support\Str;
use RuntimeException;

class GogAuthStorageService
{
    private const AES_KEY_WRAP_DEFAULT_IV = "\xA6\xA6\xA6\xA6\xA6\xA6\xA6\xA6";

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
        return $this->artifacts(
            $this->runtime->localGogConfigPath($tenant),
            $credential,
            $this->runtime->googleKeyringPassword($tenant),
        );
    }

    /**
     * @return array<string, string>
     */
    public function remoteArtifacts(Tenant $tenant, TenantGoogleCredential $credential): array
    {
        return $this->artifacts(
            $this->runtime->remoteGogConfigPath($tenant),
            $credential,
            $this->runtime->googleKeyringPassword($tenant),
        );
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
    private function artifacts(string $configRoot, TenantGoogleCredential $credential, string $keyringPassword): array
    {
        if (! $credential->isConnected()) {
            throw new RuntimeException('A connected Google Workspace credential is required before writing GOG auth artifacts.');
        }

        $email = (string) $credential->google_email;
        $refreshToken = (string) $credential->refresh_token;
        $accessToken = (string) ($credential->access_token ?? '');
        $scopes = is_array($credential->scopes) ? array_values($credential->scopes) : [];
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
        $keyringTokenPayload = [
            'refresh_token' => $refreshToken,
            'services' => ['all'],
            'scopes' => $scopes,
            'created_at' => ($credential->connected_at ?? now())->copy()->utc()->toIso8601String(),
        ];
        $encryptedKeyringToken = $this->encryptKeyringItem(
            key: 'token:default:'.$email,
            data: json_encode($keyringTokenPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            password: $keyringPassword,
            label: 'gogcli',
        );
        $tokenCacheJson = json_encode([
            'type' => 'authorized_user',
            'client_id' => $installedPayload['client_id'] ?? null,
            'client_secret' => $installedPayload['client_secret'] ?? null,
            'refresh_token' => $refreshToken,
            'access_token' => $accessToken,
            'account' => $email,
            'scopes' => $scopes,
            'expiry' => $credential->expires_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return [
            rtrim($configRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'credentials.json' => $credentialsJson.PHP_EOL,
            rtrim($configRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'config.json' => $configJson.PHP_EOL,
            $tokenPath => $encryptedKeyringToken,
            rtrim($configRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'token_'.$safeEmail.'.json' => $tokenCacheJson.PHP_EOL,
        ];
    }

    private function encryptKeyringItem(string $key, string $data, string $password, string $label = ''): string
    {
        $protectedHeader = [
            'alg' => 'PBES2-HS256+A128KW',
            'enc' => 'A256GCM',
            'p2c' => 8192,
            'p2s' => $this->base64UrlEncode(random_bytes(16)),
            'created' => now()->utc()->toIso8601String(),
        ];

        $protectedHeaderJson = json_encode($protectedHeader, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($protectedHeaderJson)) {
            throw new RuntimeException('Unable to encode the GOG keyring JWE header.');
        }

        $protectedHeaderEncoded = $this->base64UrlEncode($protectedHeaderJson);
        $kek = $this->derivePbes2Key(
            algorithm: (string) $protectedHeader['alg'],
            password: $password,
            salt: (string) $protectedHeader['p2s'],
            iterations: (int) $protectedHeader['p2c'],
            length: 16,
        );
        $cek = random_bytes(32);
        $encryptedKey = @openssl_encrypt($cek, 'aes-128-wrap', $kek, OPENSSL_RAW_DATA, self::AES_KEY_WRAP_DEFAULT_IV);

        if (! is_string($encryptedKey) || $encryptedKey === '') {
            throw new RuntimeException('Unable to wrap the GOG keyring content-encryption key.');
        }

        $itemPayload = json_encode([
            'Key' => $key,
            'Data' => base64_encode($data),
            'Label' => $label,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($itemPayload)) {
            throw new RuntimeException('Unable to encode the GOG keyring payload.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $itemPayload,
            'aes-256-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $protectedHeaderEncoded,
            16,
        );

        if (! is_string($ciphertext) || ! is_string($tag) || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt the GOG keyring payload.');
        }

        return implode('.', [
            $protectedHeaderEncoded,
            $this->base64UrlEncode($encryptedKey),
            $this->base64UrlEncode($iv),
            $this->base64UrlEncode($ciphertext),
            $this->base64UrlEncode($tag),
        ]);
    }

    private function derivePbes2Key(string $algorithm, string $password, string $salt, int $iterations, int $length): string
    {
        $decodedSalt = $this->base64UrlDecode($salt);

        return hash_pbkdf2(
            'sha256',
            $password,
            $algorithm."\x00".$decodedSalt,
            $iterations,
            $length,
            true,
        );
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);

        if (! is_string($decoded)) {
            throw new RuntimeException('Unable to decode the GOG keyring PBES2 salt.');
        }

        return $decoded;
    }
}
