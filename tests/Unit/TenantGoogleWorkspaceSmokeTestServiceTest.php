<?php

namespace Tests\Unit;

use App\Contracts\DockerComposeRunner;
use App\Services\GogCommandCatalogService;
use App\Services\TenantGoogleWorkspaceSmokeTestService;
use App\Services\TenantRuntimeCapabilityService;
use App\Services\TenantRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Filesystem\Filesystem;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TenantGoogleWorkspaceSmokeTestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_translate_smoke_failure_ignores_known_host_warning_and_surfaces_real_error(): void
    {
        $service = $this->makeService();

        $exception = $this->makeProcessFailedException(implode("\n", [
            "Warning: Permanently added '89.116.28.191' (ED25519) to the list of known hosts.",
            'gmail-cli failed: Gmail recent-email probe failed: unauthorized',
            '',
        ]));

        $translated = $this->translateFailure($service, $exception);

        $this->assertSame('gmail-cli failed: Gmail recent-email probe failed: unauthorized', $translated->getMessage());
    }

    public function test_translate_smoke_failure_returns_generic_message_when_process_output_is_only_known_host_noise(): void
    {
        $service = $this->makeService();

        $exception = $this->makeProcessFailedException(implode("\n", [
            "Warning: Permanently added '89.116.28.191' (ED25519) to the list of known hosts.",
            '',
        ]));

        $translated = $this->translateFailure($service, $exception);

        $this->assertSame('Google Workspace runtime verification failed during remote execution.', $translated->getMessage());
    }

    private function makeService(): TenantGoogleWorkspaceSmokeTestService
    {
        return new TenantGoogleWorkspaceSmokeTestService(
            app(Filesystem::class),
            Mockery::mock(DockerComposeRunner::class),
            Mockery::mock(TenantRuntimeService::class),
            Mockery::mock(TenantRuntimeCapabilityService::class),
            app(GogCommandCatalogService::class),
        );
    }

    private function makeProcessFailedException(string $stderr): ProcessFailedException
    {
        $process = new Process([PHP_BINARY, '-r', sprintf('fwrite(STDERR, %s); exit(1);', var_export($stderr, true))]);
        $process->run();

        return new ProcessFailedException($process);
    }

    private function translateFailure(TenantGoogleWorkspaceSmokeTestService $service, ProcessFailedException $exception): RuntimeException
    {
        $method = new ReflectionMethod($service, 'translateSmokeFailure');
        $method->setAccessible(true);

        /** @var RuntimeException $translated */
        $translated = $method->invoke($service, $exception, storage_path('framework/testing/non-existent-smoke-result.json'));

        return $translated;
    }
}
