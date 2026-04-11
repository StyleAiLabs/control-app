<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ControlAppDeploymentService
{
    public function isEnabled(): bool
    {
        return (bool) config('sync360.control_app_deploy.enabled', false);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $status = [
            'enabled' => $this->isEnabled(),
            'configured' => false,
            'host' => (string) config('sync360.control_app_deploy.ssh_host', ''),
            'user' => (string) config('sync360.control_app_deploy.ssh_user', ''),
            'branch' => (string) config('sync360.control_app_deploy.branch', ''),
            'primary_server' => sprintf(
                '%s@%s',
                (string) config('sync360.control_app_deploy.ssh_user', '—'),
                (string) config('sync360.control_app_deploy.ssh_host', '—'),
            ),
            'repo_path' => (string) config('sync360.control_app_deploy.repo_path', ''),
            'script_path' => (string) config('sync360.control_app_deploy.script_path', ''),
            'state' => 'not_configured',
            'started_at' => null,
            'finished_at' => null,
            'message' => null,
            'pid' => null,
            'latest_commit_full' => null,
            'latest_commit_short' => null,
            'latest_commit_subject' => null,
            'branch_head_commit_full' => null,
            'branch_head_commit_short' => null,
            'is_up_to_date' => false,
            'log_tail' => '',
        ];

        if (! $this->isConfigured()) {
            return $status;
        }

        $status['configured'] = true;

        $command = $this->buildStatusCommand();

        try {
            $process = $this->runRemoteCommand($command, throwOnFailure: false);
        } catch (\Throwable $exception) {
            $status['state'] = 'failed';
            $status['message'] = $exception->getMessage();

            return $status;
        }

        if (! $process->isSuccessful()) {
            $status['state'] = 'unreachable';
            $status['message'] = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'Unable to read deploy status.';

            return $status;
        }

        [$rawStatus, $rawLog] = array_pad(explode("\n__SYNC360_DEPLOY_LOG__\n", $process->getOutput(), 2), 2, '');

        foreach (preg_split('/\r?\n/', trim($rawStatus)) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key !== '') {
                $status[$key] = $value !== '' ? $value : null;
            }
        }

        $status['log_tail'] = trim($rawLog);
        $status['state'] = (string) ($status['state'] ?? 'idle');
        $status['primary_server'] = trim(sprintf('%s@%s', $status['user'] ?? '—', $status['host'] ?? '—'), '@');
        $status['is_up_to_date'] = $this->resolveUpToDateState($status);

        return $status;
    }

    public function trigger(): string
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Control app deployment is disabled.');
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('Control app deployment is not fully configured.');
        }

        $command = $this->buildTriggerCommand();

        $process = $this->runRemoteCommand($command);
        $pid = trim($process->getOutput());

        return $pid !== '' ? $pid : 'started';
    }

    private function isConfigured(): bool
    {
        return $this->host() !== ''
            && $this->user() !== ''
            && $this->repoPath() !== ''
            && (string) config('sync360.control_app_deploy.branch', '') !== ''
            && (string) config('sync360.control_app_deploy.compose_file', '') !== ''
            && (string) config('sync360.control_app_deploy.script_path', '') !== ''
            && $this->statusFile() !== ''
            && $this->logFile() !== '';
    }

    private function host(): string
    {
        return (string) config('sync360.control_app_deploy.ssh_host', '');
    }

    private function user(): string
    {
        return (string) config('sync360.control_app_deploy.ssh_user', '');
    }

    private function repoPath(): string
    {
        return rtrim((string) config('sync360.control_app_deploy.repo_path', ''), '/');
    }

    private function statusFile(): string
    {
        return (string) config('sync360.control_app_deploy.status_file', '');
    }

    private function logFile(): string
    {
        return (string) config('sync360.control_app_deploy.log_file', '');
    }

    private function resolveSecret(?string $envKey): string
    {
        if (! $envKey) {
            throw new RuntimeException('Control app deployment secret environment key is not configured.');
        }

        $value = env($envKey);

        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('The required secret [%s] is not set in the app environment.', $envKey));
        }

        return (string) $value;
    }

    /**
     * @return list<string>
     */
    private function authPrefix(): array
    {
        if ((string) config('sync360.control_app_deploy.ssh_auth_mode', 'password') !== 'password') {
            return [];
        }

        return [
            (string) config('sync360.infrastructure.sshpass_bin', 'sshpass'),
            '-p',
            $this->resolveSecret((string) config('sync360.control_app_deploy.ssh_password_env_key')),
        ];
    }

    /**
     * @return list<string>
     */
    private function sshOptions(): array
    {
        $options = [];

        foreach ((array) config('sync360.infrastructure.ssh_options', []) as $option) {
            $options[] = '-o';
            $options[] = (string) $option;
        }

        return $options;
    }

    private function target(): string
    {
        return sprintf('%s@%s', $this->user(), $this->host());
    }

    private function runRemoteCommand(string $remoteCommand, bool $throwOnFailure = true): Process
    {
        $command = $this->buildRemoteSshCommand($remoteCommand);

        $process = new Process($command, timeout: (int) config('sync360.control_app_deploy.timeout_seconds', 30));
        $process->run();

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    /**
     * @return list<string>
     */
    private function buildRemoteSshCommand(string $remoteCommand): array
    {
        return [
            ...$this->authPrefix(),
            (string) config('sync360.infrastructure.ssh_bin', 'ssh'),
            ...$this->sshOptions(),
            '-p',
            (string) config('sync360.control_app_deploy.ssh_port', 22),
            $this->target(),
            $remoteCommand,
        ];
    }

    private function buildStatusCommand(): string
    {
        $repoPath = escapeshellarg($this->repoPath());
        $statusFile = escapeshellarg($this->statusFile());
        $logFile = escapeshellarg($this->logFile());
        $branch = escapeshellarg((string) config('sync360.control_app_deploy.branch', ''));

        return implode('; ', [
            'if [ -f '.$statusFile.' ]; then cat '.$statusFile.'; fi',
            'if [ ! -f '.$statusFile.' ] && [ -d '.escapeshellarg($this->repoPath().'/.git').' ]; then printf "latest_commit_full=%s\n" "$(git -C '.$repoPath.' rev-parse HEAD 2>/dev/null || true)"; printf "latest_commit_short=%s\n" "$(git -C '.$repoPath.' rev-parse --short HEAD 2>/dev/null || true)"; printf "latest_commit_subject=%s\n" "$(git -C '.$repoPath.' log -1 --pretty=%s 2>/dev/null || true)"; fi',
            'if [ -d '.escapeshellarg($this->repoPath().'/.git').' ]; then branch_head_commit_full="$(git -C '.$repoPath.' ls-remote --heads origin '.$branch.' 2>/dev/null | awk \'NR==1 {print $1}\')"; printf "branch_head_commit_full=%s\n" "$branch_head_commit_full"; printf "branch_head_commit_short=%s\n" "$(printf "%s" "$branch_head_commit_full" | cut -c1-7)"; fi',
            'printf "\\n__SYNC360_DEPLOY_LOG__\\n"',
            'if [ -f '.$logFile.' ]; then tail -n '.(int) config('sync360.control_app_deploy.log_tail_lines', 20).' '.$logFile.'; fi',
        ]);
    }

    private function buildTriggerCommand(): string
    {
        $exports = [
            'SYNC360_CONTROL_DEPLOY_REPO_PATH' => $this->repoPath(),
            'SYNC360_CONTROL_DEPLOY_BRANCH' => (string) config('sync360.control_app_deploy.branch'),
            'SYNC360_CONTROL_DEPLOY_COMPOSE_FILE' => (string) config('sync360.control_app_deploy.compose_file'),
            'SYNC360_CONTROL_DEPLOY_STATUS_FILE' => $this->statusFile(),
            'SYNC360_CONTROL_DEPLOY_LOG_FILE' => $this->logFile(),
        ];

        $envArguments = collect($exports)
            ->flatMap(fn (string $value, string $key): array => [$key.'='.escapeshellarg($value)])
            ->implode(' ');

        $repoPath = escapeshellarg($this->repoPath());
        $branch = escapeshellarg((string) config('sync360.control_app_deploy.branch', ''));
        $scriptPath = escapeshellarg($this->repoRelativeScriptPath());

        return implode(' && ', [
            'cd '.$repoPath,
            'git fetch --prune origin '.$branch,
            sprintf(
                '(git show FETCH_HEAD:%s | env %s /bin/sh) >/dev/null 2>&1 < /dev/null & echo $!',
                $scriptPath,
                $envArguments,
            ),
        ]);
    }

    private function repoRelativeScriptPath(): string
    {
        $scriptPath = (string) config('sync360.control_app_deploy.script_path', '');
        $repoPath = $this->repoPath();

        if ($repoPath !== '' && str_starts_with($scriptPath, $repoPath.'/')) {
            return ltrim(substr($scriptPath, strlen($repoPath)), '/');
        }

        return ltrim($scriptPath, '/');
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function resolveUpToDateState(array $status): bool
    {
        $deployedFull = trim((string) ($status['latest_commit_full'] ?? ''));
        $branchFull = trim((string) ($status['branch_head_commit_full'] ?? ''));

        if ($deployedFull !== '' && $branchFull !== '') {
            return hash_equals($deployedFull, $branchFull);
        }

        $deployedShort = trim((string) ($status['latest_commit_short'] ?? ''));
        $branchShort = trim((string) ($status['branch_head_commit_short'] ?? ''));

        return $deployedShort !== '' && $branchShort !== '' && hash_equals($deployedShort, $branchShort);
    }
}
