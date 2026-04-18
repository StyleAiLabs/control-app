<?php

namespace App\Console\Commands;

use App\Services\SkillCatalogService;
use Illuminate\Console\Command;

class ScanSkillCatalogCommand extends Command
{
    protected $signature = 'sync360:skills:scan {--skill=}';

    protected $description = 'Scan repo-authored Sync360 skills without writing them to the DB catalog.';

    public function handle(SkillCatalogService $catalog): int
    {
        $result = $catalog->scanRepository($this->option('skill'));
        $hasInvalid = false;

        foreach ($result['rows'] as $row) {
            if (! ($row['manifest_valid'] ?? false)) {
                $this->error(sprintf('invalid %s: %s', $row['skill_key'], $row['error'] ?? 'manifest error'));
                $hasInvalid = true;

                continue;
            }

            $this->line(sprintf(
                '%s %s [%s]',
                $row['skill_key'],
                $row['version'] ?? '0.0.0',
                $row['status'] ?? 'new',
            ));
        }

        foreach ($result['missing'] as $missingSkillKey) {
            $this->warn(sprintf('missing %s', $missingSkillKey));
        }

        $this->info('Skill catalog scan complete.');

        return $hasInvalid ? self::FAILURE : self::SUCCESS;
    }
}
