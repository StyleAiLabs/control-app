<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Services\SshDockerComposeRunner;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SshDockerComposeRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_authenticated_servers_use_sshpass_and_sudo_commands(): void
    {
        $filesystem = new Filesystem();
        $binDirectory = $this->testProvisioningBase.'/fake-bin';
        $logPath = $this->testProvisioningBase.'/ssh-runner.log';

        $filesystem->ensureDirectoryExists($binDirectory);
        $filesystem->put($binDirectory.'/sshpass', <<<'SH'
#!/bin/sh
printf 'SSHPASS\n' >> "$SYNC360_TEST_LOG"
shift 2
exec "$@"
SH);
        $filesystem->put($binDirectory.'/ssh', <<<'SH'
#!/bin/sh
printf 'SSH:%s\n' "$*" >> "$SYNC360_TEST_LOG"
exit 0
SH);
        $filesystem->put($binDirectory.'/scp', <<<'SH'
#!/bin/sh
printf 'SCP:%s\n' "$*" >> "$SYNC360_TEST_LOG"
exit 0
SH);

        chmod($binDirectory.'/sshpass', 0755);
        chmod($binDirectory.'/ssh', 0755);
        chmod($binDirectory.'/scp', 0755);

        config()->set('sync360.infrastructure.ssh_bin', $binDirectory.'/ssh');
        config()->set('sync360.infrastructure.scp_bin', $binDirectory.'/scp');
        config()->set('sync360.infrastructure.sshpass_bin', $binDirectory.'/sshpass');

        putenv('SYNC360_TEST_LOG='.$logPath);
        $_ENV['SYNC360_TEST_LOG'] = $logPath;
        $_SERVER['SYNC360_TEST_LOG'] = $logPath;
        putenv('TEST_SSH_PASSWORD=ssh-secret');
        $_ENV['TEST_SSH_PASSWORD'] = 'ssh-secret';
        $_SERVER['TEST_SSH_PASSWORD'] = 'ssh-secret';
        putenv('TEST_SUDO_PASSWORD=sudo-secret');
        $_ENV['TEST_SUDO_PASSWORD'] = 'sudo-secret';
        $_SERVER['TEST_SUDO_PASSWORD'] = 'sudo-secret';

        $server = Server::query()->firstOrFail();
        $server->forceFill([
            'ssh_host' => 'ssh.test',
            'ssh_user' => 'deploy',
            'ssh_auth_mode' => 'password',
            'ssh_password_env_key' => 'TEST_SSH_PASSWORD',
            'sudo_password_env_key' => 'TEST_SUDO_PASSWORD',
        ])->save();

        $runner = new SshDockerComposeRunner(new Filesystem());

        $runner->runCommand($server, 'echo hello', sudo: true);
        $runner->putFile($server, '/etc/caddy/sites/acme.caddy', 'acme {}', sudo: true);

        $log = $filesystem->get($logPath);

        $this->assertStringContainsString('SSHPASS', $log);
        $this->assertStringContainsString('SSH:', $log);
        $this->assertStringContainsString('deploy@ssh.test', $log);
        $this->assertStringContainsString("sudo -S -p '' sh -lc 'echo hello'", $log);
        $this->assertStringContainsString('SCP:', $log);
        $this->assertStringContainsString('/etc/caddy/sites/acme.caddy', $log);
        $this->assertStringContainsString('install -m 0644', $log);
    }
}
