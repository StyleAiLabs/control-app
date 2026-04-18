<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CanonicalDocsHookTest extends TestCase
{
    public function test_docs_check_reports_missing_docs_without_bash_unbound_variable_errors(): void
    {
        $repoPath = sys_get_temp_dir().'/canonical-docs-hook-'.bin2hex(random_bytes(6));

        @mkdir($repoPath.'/scripts', 0777, true);
        @mkdir($repoPath.'/app', 0777, true);
        @mkdir($repoPath.'/artifacts', 0777, true);

        copy(dirname(__DIR__, 2).'/scripts/check-canonical-docs.sh', $repoPath.'/scripts/check-canonical-docs.sh');

        file_put_contents($repoPath.'/app/Foo.php', "<?php\n");
        file_put_contents($repoPath.'/artifacts/MEMORY.md', "memory\n");
        file_put_contents($repoPath.'/artifacts/ARCHITECTURE.md', "architecture\n");
        file_put_contents($repoPath.'/artifacts/RELEASE_NOTES.md', "release notes\n");

        try {
            $this->runProcess(['git', 'init', '-q'], $repoPath);
            $this->runProcess(['git', 'config', 'user.name', 'Test User'], $repoPath);
            $this->runProcess(['git', 'config', 'user.email', 'test@example.com'], $repoPath);
            $this->runProcess(['git', 'add', '.'], $repoPath);
            $this->runProcess(['git', 'commit', '-qm', 'init'], $repoPath);

            file_put_contents($repoPath.'/app/Foo.php', "<?php // changed\n");

            $result = $this->runProcess(['bash', './scripts/check-canonical-docs.sh', '--worktree'], $repoPath, expectSuccess: false);
            $combinedOutput = $result->getOutput().$result->getErrorOutput();

            $this->assertSame(1, $result->getExitCode());
            $this->assertStringContainsString('docs-check: failed.', $combinedOutput);
            $this->assertStringContainsString('Missing required canonical doc updates:', $combinedOutput);
            $this->assertStringNotContainsString('unbound variable', $combinedOutput);
        } finally {
            $this->deleteDirectory($repoPath);
        }
    }

    private function runProcess(array $command, string $cwd, bool $expectSuccess = true): Process
    {
        $process = new Process($command, $cwd, timeout: 20);
        $process->run();

        if ($expectSuccess) {
            $this->assertTrue(
                $process->isSuccessful(),
                sprintf(
                    "Command failed: %s\nSTDOUT:\n%s\nSTDERR:\n%s",
                    $process->getCommandLine(),
                    $process->getOutput(),
                    $process->getErrorOutput(),
                ),
            );
        }

        return $process;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path.'/'.$item;

            if (is_dir($itemPath)) {
                $this->deleteDirectory($itemPath);
                continue;
            }

            @unlink($itemPath);
        }

        @rmdir($path);
    }
}
