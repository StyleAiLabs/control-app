<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Support\GoogleWorkspaceFeature;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleWorkspaceOAuthService
{
    private const AUTH_BASE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function __construct(
        private readonly HttpFactory $http,
    ) {
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return [
            'openid',
            'email',
            'profile',
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
            'https://www.googleapis.com/auth/gmail.compose',
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/drive.file',
            'https://www.googleapis.com/auth/contacts.readonly',
            'https://www.googleapis.com/auth/spreadsheets',
            'https://www.googleapis.com/auth/documents',
        ];
    }

    public function begin(Tenant $tenant): string
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            throw new RuntimeException('Run the latest migrations before connecting Google Workspace.');
        }

        $credential = $tenant->googleCredential ?: $tenant->googleCredential()->create();
        $state = Str::random(64);
        $verifier = Str::random(96);
        $challenge = $this->base64UrlEncode(hash('sha256', $verifier, true));

        $credential->forceFill([
            'status' => TenantGoogleCredential::STATUS_PENDING,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
            'oauth_state' => $state,
            'oauth_code_verifier' => $verifier,
            'oauth_state_expires_at' => now()->addMinutes(10),
            'last_error' => null,
        ])->save();

        $query = http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);

        return self::AUTH_BASE_URL.'?'.$query;
    }

    /**
     * @return array{access_token:string, refresh_token:string, expires_at:Carbon|null, google_email:string, scopes:list<string>}
     */
    public function complete(Tenant $tenant, string $state, string $code): array
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            throw new RuntimeException('Run the latest migrations before connecting Google Workspace.');
        }

        $credential = $tenant->googleCredential;

        if (! $credential) {
            throw new RuntimeException('No Google Workspace connect session exists for this tenant.');
        }

        if (! filled($credential->oauth_state) || ! hash_equals((string) $credential->oauth_state, $state)) {
            throw new RuntimeException('Google OAuth state did not match the current tenant session.');
        }

        if (! $credential->oauth_state_expires_at || now()->gt($credential->oauth_state_expires_at)) {
            throw new RuntimeException('The Google connection attempt expired. Please try again.');
        }

        $response = $this->http
            ->asForm()
            ->acceptJson()
            ->post(self::TOKEN_URL, [
                'code' => $code,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
                'code_verifier' => (string) $credential->oauth_code_verifier,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Google token exchange failed. Please try connecting your account again.');
        }

        $tokenPayload = $response->json();
        $accessToken = (string) ($tokenPayload['access_token'] ?? '');
        $refreshToken = (string) ($tokenPayload['refresh_token'] ?? '');
        $scopeString = trim((string) ($tokenPayload['scope'] ?? ''));

        if ($accessToken === '' || $refreshToken === '') {
            throw new RuntimeException('Google did not return a usable access token and refresh token.');
        }

        $userInfoResponse = $this->http
            ->acceptJson()
            ->withToken($accessToken)
            ->get(self::USERINFO_URL);

        if ($userInfoResponse->failed()) {
            throw new RuntimeException('Google did not return the connected account profile.');
        }

        $googleEmail = trim((string) ($userInfoResponse->json('email') ?? ''));

        if ($googleEmail === '') {
            throw new RuntimeException('Google did not return the connected account email address.');
        }

        $expiresAt = isset($tokenPayload['expires_in']) && is_numeric($tokenPayload['expires_in'])
            ? now()->addSeconds((int) $tokenPayload['expires_in'])
            : null;
        $scopes = $scopeString === '' ? $this->scopes() : array_values(array_filter(explode(' ', $scopeString)));

        $credential->forceFill([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
            'google_email' => $googleEmail,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $expiresAt,
            'scopes' => $scopes,
            'oauth_state' => null,
            'oauth_code_verifier' => null,
            'oauth_state_expires_at' => null,
            'connected_at' => now(),
            'disconnected_at' => null,
            'last_error' => null,
        ])->save();

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $expiresAt,
            'google_email' => $googleEmail,
            'scopes' => $scopes,
        ];
    }

    public function markSkipped(Tenant $tenant): TenantGoogleCredential
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            throw new RuntimeException('Run the latest migrations before connecting Google Workspace.');
        }

        $credential = $tenant->googleCredential ?: $tenant->googleCredential()->create();

        $credential->forceFill([
            'status' => TenantGoogleCredential::STATUS_SKIPPED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
            'oauth_state' => null,
            'oauth_code_verifier' => null,
            'oauth_state_expires_at' => null,
            'last_error' => null,
        ])->save();

        return $credential;
    }

    /**
     * @return array{web: array<string, mixed>, installed: array<string, mixed>}
     */
    public function clientSecretPayload(): array
    {
        $base = array_filter([
            'client_id' => $this->clientId(),
            'project_id' => config('services.google.project_id'),
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => self::TOKEN_URL,
            'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
            'client_secret' => $this->clientSecret(),
            'redirect_uris' => [$this->redirectUri()],
            'javascript_origins' => [$this->redirectOrigin()],
        ], static fn (mixed $value): bool => $value !== null);

        return [
            'web' => $base,
            'installed' => array_merge($base, [
                'redirect_uris' => [
                    'http://localhost',
                    $this->redirectUri(),
                ],
            ]),
        ];
    }

    private function clientId(): string
    {
        $clientId = trim((string) config('services.google.client_id', ''));

        if ($clientId === '') {
            throw new RuntimeException('GOOGLE_CLIENT_ID is not configured.');
        }

        return $clientId;
    }

    private function clientSecret(): string
    {
        $clientSecret = trim((string) config('services.google.client_secret', ''));

        if ($clientSecret === '') {
            throw new RuntimeException('GOOGLE_CLIENT_SECRET is not configured.');
        }

        return $clientSecret;
    }

    private function redirectUri(): string
    {
        $redirectUri = trim((string) config('services.google.redirect_uri', ''));

        if ($redirectUri === '') {
            throw new RuntimeException('GOOGLE_REDIRECT_URI is not configured.');
        }

        return $redirectUri;
    }

    private function redirectOrigin(): string
    {
        return (string) parse_url($this->redirectUri(), PHP_URL_SCHEME).'://'.(string) parse_url($this->redirectUri(), PHP_URL_HOST);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
