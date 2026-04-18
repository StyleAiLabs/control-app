<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class DiscoverSkillAnalyticsCommand extends Command
{
    protected $signature = 'sync360:skills:discover-analytics';

    protected $description = 'Write the required analytics discovery finding before implementing skill usage ingestion.';

    public function handle(): int
    {
        $path = base_path('artifacts/skill-analytics-discovery.md');
        $runtimeRoot = config('sync360.runtime_root');
        $candidatePaths = [
            $runtimeRoot.'/*/.openclaw/logs',
            $runtimeRoot.'/*/.openclaw/workspace/logs',
            $runtimeRoot.'/*/logs',
        ];

        $content = implode(PHP_EOL, [
            '# Skill Analytics Discovery Finding',
            '',
            'Generated at: '.now()->toIso8601String(),
            '',
            '## Runtime log path',
            '- Candidate paths checked:',
            ...array_map(static fn (string $candidate): string => '  - '.$candidate, $candidatePaths),
            '- Verified path: not yet confirmed',
            '',
            '## Format sample',
            'No supported per-skill invocation log format has been verified yet.',
            '',
            '## Stable dedup ID',
            'Not yet confirmed.',
            '',
            '## Recommendation',
            'Do not implement skill usage ingestion until this finding is reviewed and approved.',
            '',
        ]);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);

        $this->info('Wrote analytics discovery finding to '.$path);

        return self::SUCCESS;
    }
}
