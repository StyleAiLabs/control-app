<?php

namespace Tests\Unit;

use App\Services\ControlAppDeploymentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

class ControlAppDeploymentServiceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_remote_ssh_command_uses_raw_remote_command_without_shell_rewrapping(): void
    {
        putenv('SYNC360_TEST_DEPLOY_PASSWORD=swordfish');
        $_ENV['SYNC360_TEST_DEPLOY_PASSWORD'] = 'swordfish';
        $_SERVER['SYNC360_TEST_DEPLOY_PASSWORD'] = 'swordfish';

        Config::set('sync360.control_app_deploy.ssh_auth_mode', 'password');
        Config::set('sync360.control_app_deploy.ssh_password_env_key', 'SYNC360_TEST_DEPLOY_PASSWORD');
        Config::set('sync360.control_app_deploy.ssh_host', '161.97.74.128');
        Config::set('sync360.control_app_deploy.ssh_user', 'serveradmin');
        Config::set('sync360.control_app_deploy.ssh_port', 22);
        Config::set('sync360.infrastructure.ssh_bin', 'ssh');
        Config::set('sync360.infrastructure.sshpass_bin', 'sshpass');
        Config::set('sync360.infrastructure.ssh_options', [
            'StrictHostKeyChecking=no',
            'UserKnownHostsFile=/dev/null',
            'ConnectTimeout=10',
        ]);

        $service = app(ControlAppDeploymentService::class);

        $method = new ReflectionMethod($service, 'buildRemoteSshCommand');
        $method->setAccessible(true);

        $remoteCommand = "SYNC360_CONTROL_DEPLOY_REPO_PATH='/opt/sync360/control-app' nohup /bin/sh '/opt/sync360/control-app/deploy/scripts/run-control-app-deploy.sh' >/dev/null 2>&1 < /dev/null & echo $!";
        $command = $method->invoke($service, $remoteCommand);

        $this->assertSame($remoteCommand, $command[array_key_last($command)]);
        $this->assertNotSame(sprintf('sh -lc %s', escapeshellarg($remoteCommand)), $command[array_key_last($command)]);
        $this->assertSame('serveradmin@161.97.74.128', $command[array_key_last($command) - 1]);
    }

    public function test_status_command_uses_statement_separators_for_remote_shell_parsing(): void
    {
        Config::set('sync360.control_app_deploy.repo_path', '/opt/sync360/control-app');
        Config::set('sync360.control_app_deploy.status_file', '/opt/sync360/control-app/storage/logs/control-app-deploy.status');
        Config::set('sync360.control_app_deploy.log_file', '/opt/sync360/control-app/storage/logs/control-app-deploy.log');
        Config::set('sync360.control_app_deploy.log_tail_lines', 20);

        $service = app(ControlAppDeploymentService::class);

        $method = new ReflectionMethod($service, 'buildStatusCommand');
        $method->setAccessible(true);

        $command = $method->invoke($service);

        $this->assertStringContainsString('; printf "\\n__SYNC360_DEPLOY_LOG__\\n";', $command);
        $this->assertStringContainsString('latest_commit_full=', $command);
        $this->assertStringContainsString('latest_commit_short=', $command);
        $this->assertStringContainsString('latest_commit_subject=', $command);
        $this->assertStringContainsString('branch_head_commit_full=', $command);
        $this->assertStringContainsString('branch_head_commit_short=', $command);
        $this->assertStringContainsString("ls-remote --heads origin 'codex/control-app-prod-deploy'", $command);
        $this->assertStringContainsString("if [ ! -f '/opt/sync360/control-app/storage/logs/control-app-deploy.status' ] && [ -d '/opt/sync360/control-app/.git' ]; then", $command);
        $this->assertStringContainsString("; if [ -f '/opt/sync360/control-app/storage/logs/control-app-deploy.log' ]; then tail -n 20 '/opt/sync360/control-app/storage/logs/control-app-deploy.log'; fi", $command);
        $this->assertStringNotContainsString(' fi printf ', $command);
    }
}
