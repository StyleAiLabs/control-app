<?php

namespace Tests;

use App\Contracts\DockerComposeRunner;
use App\Models\Server;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected string $testProvisioningBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProvisioningBase = storage_path('framework/testing/sync360');

        File::deleteDirectory($this->testProvisioningBase);
        File::ensureDirectoryExists($this->testProvisioningBase.'/template/config');
        File::ensureDirectoryExists($this->testProvisioningBase.'/template/data');
        File::ensureDirectoryExists($this->testProvisioningBase.'/template/logs');

        File::put($this->testProvisioningBase.'/template/README.md', 'test template');

        config()->set('sync360.host_project_root', base_path());
        config()->set('sync360.runtime_root', $this->testProvisioningBase.'/runtime');
        config()->set('sync360.template_root', $this->testProvisioningBase.'/template');
        config()->set('sync360.admin_local_only', false);
        config()->set('sync360.infrastructure.driver', 'local');
        config()->set('sync360.infrastructure.local_docker_compose_bin', 'docker compose');
        config()->set('sync360.infrastructure.ssh_bin', 'ssh');
        config()->set('sync360.infrastructure.scp_bin', 'scp');
        config()->set('sync360.infrastructure.sshpass_bin', 'sshpass');
        config()->set('sync360.provisioning.driver', 'local');
        config()->set('sync360.provisioning.fake_delay_seconds', 0);
        config()->set('sync360.port_range.start', 4100);
        config()->set('sync360.port_range.end', 4199);
        config()->set('sync360.openclaw.image', 'ghcr.io/openclaw/openclaw:latest');
        config()->set('sync360.openclaw.service_name', 'openclaw-gateway');
        config()->set('sync360.openclaw.compose_filename', 'compose.yaml');
        config()->set('sync360.openclaw.container_home', '/home/node/.openclaw');
        config()->set('sync360.openclaw.gateway_port', 18789);
        config()->set('sync360.openclaw.readiness_path', '/readyz');
        config()->set('sync360.openclaw.readiness_timeout_seconds', 1);
        config()->set('sync360.openclaw.readiness_poll_interval_ms', 10);
        config()->set('sync360.openclaw.compose_timeout_seconds', 10);
        config()->set('sync360.workspace_proxy.control_app_upstream', 'https://app.sync360.test');
        config()->set('sync360.workspace_proxy.public_readiness_timeout_seconds', 1);
        config()->set('sync360.workspace_proxy.public_readiness_poll_interval_ms', 10);

        Server::query()->create([
            'name' => 'test-vps',
            'host' => 'workspace.test',
            'ssh_host' => '127.0.0.1',
            'ssh_port' => 22,
            'ssh_user' => 'sync360',
            'ssh_private_key_path' => null,
            'ssh_auth_mode' => 'password',
            'ssh_password_env_key' => 'TEST_SSH_PASSWORD',
            'sudo_password_env_key' => 'TEST_SUDO_PASSWORD',
            'status' => 'active',
            'max_clients' => 100,
            'current_clients' => 0,
            'runtime_root' => '/srv/sync360/runtime',
            'workspace_scheme' => 'https',
            'workspace_base_domain' => 'workspace.test',
            'docker_compose_bin' => 'docker compose',
            'caddy_sites_path' => '/etc/caddy/sites',
            'caddy_reload_command' => 'systemctl reload caddy',
        ]);

        $this->instance(DockerComposeRunner::class, new class implements DockerComposeRunner
        {
            public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void
            {
            }

            public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15): array
            {
                $request = Http::timeout($timeoutSeconds)->acceptJson();

                if ($json !== null) {
                    $request = $request->asJson();
                }

                $response = $request->send($method, $url, $json !== null ? ['json' => $json] : []);

                return [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ];
            }

            public function putFile(Server $server, string $remotePath, string $contents, bool $sudo = false): void
            {
            }

            public function removeFile(Server $server, string $remotePath, bool $sudo = false): void
            {
            }

            public function removeDirectory(Server $server, string $remotePath, bool $sudo = false): void
            {
            }

            public function runCommand(Server $server, string $command, bool $sudo = false): void
            {
            }

            public function up(Server $server, string $composeFile, string $projectName): void
            {
            }

            public function down(Server $server, string $composeFile, string $projectName): void
            {
            }

            public function start(Server $server, string $composeFile, string $projectName): void
            {
            }

            public function stop(Server $server, string $composeFile, string $projectName): void
            {
            }

            public function isRunning(Server $server, string $composeFile, string $projectName): bool
            {
                return false;
            }

            public function isHostPortInUse(Server $server, int $port): bool
            {
                return false;
            }

            public function waitForHttpReady(Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void
            {
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProvisioningBase);

        parent::tearDown();
    }
}
