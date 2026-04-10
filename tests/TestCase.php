<?php

namespace Tests;

use App\Contracts\DockerComposeRunner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

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
        config()->set('sync360.openclaw.readiness_probe_host', 'localhost');
        config()->set('sync360.openclaw.readiness_timeout_seconds', 1);
        config()->set('sync360.openclaw.readiness_poll_interval_ms', 10);
        config()->set('sync360.openclaw.compose_timeout_seconds', 10);

        $this->instance(DockerComposeRunner::class, new class implements DockerComposeRunner
        {
            public function up(string $composeFile, string $projectName): void
            {
            }

            public function down(string $composeFile, string $projectName): void
            {
            }

            public function start(string $composeFile, string $projectName): void
            {
            }

            public function stop(string $composeFile, string $projectName): void
            {
            }

            public function isRunning(string $composeFile, string $projectName): bool
            {
                return false;
            }

            public function isHostPortInUse(int $port): bool
            {
                return false;
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProvisioningBase);

        parent::tearDown();
    }
}
