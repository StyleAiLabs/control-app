<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Models\Tenant;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class TenantGoogleWorkspaceSmokeTestService
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DockerComposeRunner $dockerCompose,
        private readonly TenantRuntimeService $runtime,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(Tenant $tenant): array
    {
        $tenant->loadMissing(['server', 'googleCredential']);

        if (! $tenant->server) {
            throw new RuntimeException('Tenant server is missing.');
        }

        if ($tenant->provisioning_status !== TenantProvisioningStatus::Ready || ! filled($tenant->runtime_path)) {
            throw new RuntimeException('Tenant runtime is not ready yet.');
        }

        $credential = $tenant->googleCredential;

        if (! $credential?->isConnected()) {
            throw new RuntimeException('Tenant does not currently have a connected Google Workspace credential.');
        }

        $localConfigRoot = $this->runtime->localGogConfigPath($tenant);
        $composeFile = $this->composeFileFor($tenant);
        $projectName = $this->projectNameFor($tenant);
        $serviceName = (string) config('sync360.openclaw.service_name', 'openclaw-gateway');
        $containerSmokeScriptPath = rtrim((string) config('sync360.openclaw.container_home', '/home/node/.openclaw'), '/')
            .DIRECTORY_SEPARATOR.'.openclaw'.DIRECTORY_SEPARATOR.'google-workspace-smoke.mjs';
        $localSmokeScriptPath = $this->runtime->localRuntimePath($tenant)
            .DIRECTORY_SEPARATOR.'.openclaw'.DIRECTORY_SEPARATOR.'google-workspace-smoke.mjs';
        $localSmokeResultPath = $this->runtime->localRuntimePath($tenant)
            .DIRECTORY_SEPARATOR.'.openclaw'.DIRECTORY_SEPARATOR.'google-workspace-smoke-result.json';

        $requiredFiles = [
            $localConfigRoot.DIRECTORY_SEPARATOR.'config.json',
            $localConfigRoot.DIRECTORY_SEPARATOR.'credentials.json',
            $localConfigRoot.DIRECTORY_SEPARATOR.'keyring'.DIRECTORY_SEPARATOR.'token:default:'.$credential->google_email,
        ];

        foreach ($requiredFiles as $requiredFile) {
            if (! $this->files->exists($requiredFile)) {
                throw new RuntimeException(sprintf('Required Google runtime artifact [%s] is missing.', $requiredFile));
            }
        }

        $smokeScript = $this->renderSmokeScript();
        $this->files->put($localSmokeScriptPath, $smokeScript);

        if (! app()->environment('local')) {
            $remoteSmokeScriptPath = rtrim((string) $tenant->runtime_path, DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR.'.openclaw'.DIRECTORY_SEPARATOR.'google-workspace-smoke.mjs';

            $this->dockerCompose->putFile($tenant->server, $remoteSmokeScriptPath, $smokeScript);
        }

        if ($this->files->exists($localSmokeResultPath)) {
            $this->files->delete($localSmokeResultPath);
        }

        if ($this->shouldUseLocalComposeExecution($composeFile)) {
            $this->runLocalComposeCommand($tenant, $composeFile, $projectName, [
                'exec',
                '-T',
                $serviceName,
                'sh',
                '-lc',
                sprintf('node %s', $containerSmokeScriptPath),
            ]);
        } else {
            $command = sprintf(
                'docker compose -f %s -p %s exec -T %s sh -lc %s',
                escapeshellarg($composeFile),
                escapeshellarg($projectName),
                escapeshellarg($serviceName),
                escapeshellarg(sprintf('node %s', $containerSmokeScriptPath)),
            );

            $this->dockerCompose->runCommand($tenant->server, $command);
        }

        $result = [
            'tenant_slug' => $tenant->slug,
            'google_email' => $credential->google_email,
            'compose_file' => $composeFile,
            'container_script_path' => $containerSmokeScriptPath,
            'xdg_config_home_expected' => $this->runtime->containerGogConfigHome(),
            'runtime_artifacts_verified' => true,
            'container_smoke_passed' => true,
        ];

        if (app()->environment('local') && $this->files->exists($localSmokeResultPath)) {
            $decoded = json_decode($this->files->get($localSmokeResultPath), true);

            if (is_array($decoded)) {
                $result['container_result'] = $decoded;
            }
        }

        return $result;
    }

    private function composeFileFor(Tenant $tenant): string
    {
        return (app()->environment('local')
            ? $this->runtime->localRuntimePath($tenant)
            : rtrim((string) $tenant->runtime_path, DIRECTORY_SEPARATOR))
            .DIRECTORY_SEPARATOR
            .(string) config('sync360.openclaw.compose_filename', 'compose.yaml');
    }

    private function projectNameFor(Tenant $tenant): string
    {
        return substr('sync360-'.$tenant->slug, 0, 63);
    }

    private function shouldUseLocalComposeExecution(string $composeFile): bool
    {
        return app()->environment('local') && $this->files->exists($composeFile);
    }

    /**
     * @param  list<string>  $subCommand
     */
    private function runLocalComposeCommand(Tenant $tenant, string $composeFile, string $projectName, array $subCommand): void
    {
        $process = new Process([
            ...$this->runtime->localDockerComposeCommandParts(),
            '-f',
            $composeFile,
            '-p',
            $projectName,
            ...$subCommand,
        ], timeout: (int) config('sync360.openclaw.compose_timeout_seconds', 120));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function renderSmokeScript(): string
    {
        return <<<'NODE'
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const openclawHome = process.env.OPENCLAW_HOME;
const xdgConfigHome = process.env.XDG_CONFIG_HOME;
const expectedConfigHome = path.join(openclawHome, '.openclaw');
const resultPath = path.join(expectedConfigHome, 'google-workspace-smoke-result.json');

function fail(message, extra = {}) {
  writeFileSync(resultPath, JSON.stringify({ ok: false, message, ...extra }, null, 2) + '\n');
  console.error(message);
  process.exit(1);
}

if (!openclawHome) {
  fail('OPENCLAW_HOME is missing.');
}

if (!xdgConfigHome) {
  fail('XDG_CONFIG_HOME is missing.');
}

if (xdgConfigHome !== expectedConfigHome) {
  fail('XDG_CONFIG_HOME does not point at the mounted .openclaw config root.', {
    xdgConfigHome,
    expectedConfigHome,
  });
}

const configRoot = path.join(xdgConfigHome, 'gogcli');
const config = JSON.parse(readFileSync(path.join(configRoot, 'config.json'), 'utf8'));
const credentials = JSON.parse(readFileSync(path.join(configRoot, 'credentials.json'), 'utf8'));
const account = config.default_account;

if (!account) {
  fail('gogcli config does not include a default account.');
}

const keyringPath = path.join(configRoot, 'keyring', `token:default:${account}`);
const tokenCachePath = path.join(configRoot, `token_${account.replace(/[^A-Za-z0-9._-]+/g, '_')}.json`);
const keyring = JSON.parse(readFileSync(keyringPath, 'utf8'));
const tokenCache = JSON.parse(readFileSync(tokenCachePath, 'utf8'));
const refreshToken = keyring.refresh_token || tokenCache.refresh_token;
const clientId = credentials.installed?.client_id;
const clientSecret = credentials.installed?.client_secret;

if (!refreshToken || !clientId || !clientSecret) {
  fail('Runtime Google auth files are missing refresh token or client credentials.');
}

const tokenResponse = await fetch('https://oauth2.googleapis.com/token', {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({
    client_id: clientId,
    client_secret: clientSecret,
    refresh_token: refreshToken,
    grant_type: 'refresh_token',
  }),
});

if (!tokenResponse.ok) {
  fail('Refresh token exchange failed inside the tenant runtime.', {
    status: tokenResponse.status,
    body: await tokenResponse.text(),
  });
}

const tokenPayload = await tokenResponse.json();
const accessToken = tokenPayload.access_token;

if (!accessToken) {
  fail('Refresh token exchange did not return an access token.');
}

const gmailProfile = await fetch('https://gmail.googleapis.com/gmail/v1/users/me/profile', {
  headers: { Authorization: `Bearer ${accessToken}` },
});

if (!gmailProfile.ok) {
  fail('Gmail profile request failed inside the tenant runtime.', {
    status: gmailProfile.status,
    body: await gmailProfile.text(),
  });
}

const gmailPayload = await gmailProfile.json();

const calendarList = await fetch('https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=1', {
  headers: { Authorization: `Bearer ${accessToken}` },
});

if (!calendarList.ok) {
  fail('Calendar list request failed inside the tenant runtime.', {
    status: calendarList.status,
    body: await calendarList.text(),
  });
}

const calendarPayload = await calendarList.json();

writeFileSync(resultPath, JSON.stringify({
  ok: true,
  account,
  xdgConfigHome,
  gmail_email_address: gmailPayload.emailAddress,
  gmail_messages_total: gmailPayload.messagesTotal,
  calendar_items_returned: Array.isArray(calendarPayload.items) ? calendarPayload.items.length : null,
}, null, 2) + '\n');
NODE;
    }
}
