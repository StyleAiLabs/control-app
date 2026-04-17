<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Models\Tenant;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

class TenantGoogleWorkspaceSmokeTestService
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DockerComposeRunner $dockerCompose,
        private readonly TenantRuntimeService $runtime,
        private readonly TenantRuntimeCapabilityService $runtimeCapabilities,
        private readonly GogCommandCatalogService $gogCommands,
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

        if ((string) config('sync360.infrastructure.driver', 'local') !== 'local') {
            $this->runtimeCapabilities->verifyHostCapabilities($tenant->server, ['gog']);
            $this->runtimeCapabilities->verifyContainerCapabilities($tenant, ['gog']);
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

        $smokeScript = $this->renderSmokeScript($tenant);
        $this->files->put($localSmokeScriptPath, $smokeScript);

        if (! app()->environment('local')) {
            $remoteSmokeScriptPath = rtrim((string) $tenant->runtime_path, DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR.'.openclaw'.DIRECTORY_SEPARATOR.'google-workspace-smoke.mjs';

            $this->dockerCompose->putFile($tenant->server, $remoteSmokeScriptPath, $smokeScript);
        }

        if ($this->files->exists($localSmokeResultPath)) {
            $this->files->delete($localSmokeResultPath);
        }

        try {
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
        } catch (Throwable $exception) {
            throw $this->translateSmokeFailure($exception, $localSmokeResultPath);
        }

        $containerResult = null;

        if (app()->environment('local') && $this->files->exists($localSmokeResultPath)) {
            $decoded = json_decode($this->files->get($localSmokeResultPath), true);

            if (is_array($decoded)) {
                $containerResult = $decoded;
            }
        }

        $result = [
            'tenant_slug' => $tenant->slug,
            'google_email' => $credential->google_email,
            'compose_file' => $composeFile,
            'container_script_path' => $containerSmokeScriptPath,
            'xdg_config_home_expected' => $this->runtime->containerGogConfigHome(),
            'host_capability_verified' => (string) config('sync360.infrastructure.driver', 'local') !== 'local',
            'container_binary_verified' => (string) config('sync360.infrastructure.driver', 'local') !== 'local',
            'gog_env_verified' => true,
            'gmail_cli_verified' => true,
            'calendar_cli_verified' => true,
            'drive_cli_verified' => true,
            'contacts_cli_verified' => true,
            'help_probes_verified' => true,
            'runtime_artifacts_verified' => true,
            'container_smoke_passed' => true,
        ];

        if (is_array($containerResult)) {
            $result['gog_env_verified'] = (bool) ($containerResult['gog_env_verified'] ?? true);
            $result['gmail_cli_verified'] = (bool) ($containerResult['gmail_cli_verified'] ?? true);
            $result['calendar_cli_verified'] = (bool) ($containerResult['calendar_cli_verified'] ?? true);
            $result['drive_cli_verified'] = (bool) ($containerResult['drive_cli_verified'] ?? true);
            $result['contacts_cli_verified'] = (bool) ($containerResult['contacts_cli_verified'] ?? true);
            $result['help_probes_verified'] = (bool) ($containerResult['help_probes_verified'] ?? true);
            $result['container_result'] = $containerResult;
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

    private function renderSmokeScript(Tenant $tenant): string
    {
        $tenant->loadMissing('googleCredential');

        $expectedAllowlist = json_encode($this->gogCommands->enabledCommandList(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expectedAccount = json_encode($tenant->googleCredential?->google_email, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $cliProbes = json_encode($this->gogCommands->cliSmokeProbes(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $helpProbes = json_encode($this->gogCommands->helpProbes(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $template = <<<'NODE'
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

const openclawHome = process.env.OPENCLAW_HOME;
const xdgConfigHome = process.env.XDG_CONFIG_HOME;
const expectedConfigHome = openclawHome ? path.join(openclawHome, '.openclaw') : null;
const resultPath = path.join(expectedConfigHome, 'google-workspace-smoke-result.json');
const expectedAllowlist = __EXPECTED_ALLOWLIST__;
const expectedAccount = __EXPECTED_ACCOUNT__;
const cliProbes = __CLI_PROBES__;
const helpProbes = __HELP_PROBES__;
const result = {
  gog_env_verified: false,
  gmail_cli_verified: false,
  calendar_cli_verified: false,
  drive_cli_verified: false,
  contacts_cli_verified: false,
  help_probes_verified: false,
  container_smoke_passed: false,
};

function writeResult(payload) {
  writeFileSync(resultPath, JSON.stringify(payload, null, 2) + '\n');
}

function fail(stage, message, extra = {}) {
  writeResult({ ok: false, stage, message, ...result, ...extra });
  console.error(`[${stage}] ${message}`);
  process.exit(1);
}

if (!openclawHome) {
  fail('env', 'OPENCLAW_HOME is missing.');
}

if (!xdgConfigHome) {
  fail('env', 'XDG_CONFIG_HOME is missing.');
}

if (xdgConfigHome !== expectedConfigHome) {
  fail('env', 'XDG_CONFIG_HOME does not point at the mounted .openclaw config root.', {
    xdgConfigHome,
    expectedConfigHome,
  });
}

if ((process.env.GOG_ENABLE_COMMANDS || '') !== expectedAllowlist) {
  fail('gog-env', 'GOG_ENABLE_COMMANDS does not match the expected allowlist.', {
    actualAllowlist: process.env.GOG_ENABLE_COMMANDS || '',
    expectedAllowlist,
  });
}

if (!expectedAccount) {
  fail('gog-env', 'The connected Google email is missing, so GOG_ACCOUNT cannot be verified.');
}

if ((process.env.GOG_ACCOUNT || '') !== expectedAccount) {
  fail('gog-env', 'GOG_ACCOUNT does not match the connected Google account.', {
    actualAccount: process.env.GOG_ACCOUNT || '',
    expectedAccount,
  });
}

const configRoot = path.join(xdgConfigHome, 'gogcli');
const config = JSON.parse(readFileSync(path.join(configRoot, 'config.json'), 'utf8'));
const credentials = JSON.parse(readFileSync(path.join(configRoot, 'credentials.json'), 'utf8'));
const account = config.default_account;

if (!account) {
  fail('gog-env', 'gogcli config does not include a default account.');
}

if (account !== expectedAccount) {
  fail('gog-env', 'gogcli default_account does not match the connected Google email.', {
    account,
    expectedAccount,
  });
}

const keyringPath = path.join(configRoot, 'keyring', `token:default:${account}`);
const tokenCachePath = path.join(configRoot, `token_${account.replace(/[^A-Za-z0-9._-]+/g, '_')}.json`);
const keyring = JSON.parse(readFileSync(keyringPath, 'utf8'));
const tokenCache = existsSync(tokenCachePath) ? JSON.parse(readFileSync(tokenCachePath, 'utf8')) : {};
const refreshToken = keyring.refresh_token || tokenCache.refresh_token;
const clientId = credentials.installed?.client_id;
const clientSecret = credentials.installed?.client_secret;

if (!refreshToken || !clientId || !clientSecret) {
  fail('runtime-artifacts', 'Runtime Google auth files are missing refresh token or client credentials.');
}

result.gog_env_verified = true;

function runCommand(stage, label, argv, expectJson = false) {
  const execution = spawnSync(argv[0], argv.slice(1), { encoding: 'utf8', env: process.env });
  const stdout = execution.stdout || '';
  const stderr = execution.stderr || '';

  if (execution.status !== 0) {
    fail(stage, `${label} failed: ${(stderr || stdout || `exit ${execution.status}`).trim()}`, {
      command: argv.join(' '),
      exitCode: execution.status,
      stdout,
      stderr,
    });
  }

  if (expectJson) {
    const trimmed = stdout.trim();

    if (trimmed === '') {
      fail(stage, `${label} returned no JSON output.`, { command: argv.join(' ') });
    }

    try {
      JSON.parse(trimmed);
    } catch (error) {
      fail(stage, `${label} returned invalid JSON output.`, {
        command: argv.join(' '),
        stdout,
        parseError: error instanceof Error ? error.message : String(error),
      });
    }
  }

  result[stage] = true;
}

for (const probe of cliProbes) {
  runCommand(probe.id, probe.label, probe.argv, probe.expect_json === true);
}

for (const probe of helpProbes) {
  runCommand(probe.id, probe.label, probe.argv, probe.expect_json === true);
}

result.help_probes_verified = true;

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
  fail('google-api', 'Refresh token exchange failed inside the tenant runtime.', {
    status: tokenResponse.status,
    body: await tokenResponse.text(),
  });
}

const tokenPayload = await tokenResponse.json();
const accessToken = tokenPayload.access_token;

if (!accessToken) {
  fail('google-api', 'Refresh token exchange did not return an access token.');
}

const gmailProfile = await fetch('https://gmail.googleapis.com/gmail/v1/users/me/profile', {
  headers: { Authorization: `Bearer ${accessToken}` },
});

if (!gmailProfile.ok) {
  fail('google-api', 'Gmail profile request failed inside the tenant runtime.', {
    status: gmailProfile.status,
    body: await gmailProfile.text(),
  });
}

const gmailPayload = await gmailProfile.json();

const calendarList = await fetch('https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=1', {
  headers: { Authorization: `Bearer ${accessToken}` },
});

if (!calendarList.ok) {
  fail('google-api', 'Calendar list request failed inside the tenant runtime.', {
    status: calendarList.status,
    body: await calendarList.text(),
  });
}

const calendarPayload = await calendarList.json();
result.container_smoke_passed = true;

writeResult({
  ok: true,
  ...result,
  account,
  xdgConfigHome,
  gmail_email_address: gmailPayload.emailAddress,
  gmail_messages_total: gmailPayload.messagesTotal,
  calendar_items_returned: Array.isArray(calendarPayload.items) ? calendarPayload.items.length : null,
});
NODE;

        return str_replace(
            ['__EXPECTED_ALLOWLIST__', '__EXPECTED_ACCOUNT__', '__CLI_PROBES__', '__HELP_PROBES__'],
            [$expectedAllowlist, $expectedAccount, $cliProbes, $helpProbes],
            $template,
        );
    }

    private function translateSmokeFailure(Throwable $exception, string $localSmokeResultPath): RuntimeException
    {
        if ($this->files->exists($localSmokeResultPath)) {
            $decoded = json_decode($this->files->get($localSmokeResultPath), true);

            if (is_array($decoded)) {
                $stage = trim((string) ($decoded['stage'] ?? 'google-workspace'));
                $message = trim((string) ($decoded['message'] ?? ''));

                if ($message !== '') {
                    return new RuntimeException(sprintf('%s failed: %s', $stage, $message), previous: $exception);
                }
            }
        }

        if ($exception instanceof ProcessFailedException) {
            $process = $exception->getProcess();
            $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            if ($output !== '') {
                $line = trim(strtok($output, "\n")) ?: $output;

                return new RuntimeException($line, previous: $exception);
            }
        }

        return new RuntimeException($exception->getMessage(), previous: $exception);
    }
}
