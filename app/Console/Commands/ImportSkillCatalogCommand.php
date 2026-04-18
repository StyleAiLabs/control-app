<?php

namespace App\Console\Commands;

use App\Services\SkillCatalogService;
use Illuminate\Console\Command;

class ImportSkillCatalogCommand extends Command
{
    protected $signature = 'sync360:skills:import {--skill=}';

    protected $description = 'Import repo-authored Sync360 skills into the admin skill catalog.';

    public function handle(SkillCatalogService $catalog): int
    {
        $skillKey = $this->option('skill');
        $result = $catalog->importFromRepository(is_string($skillKey) && trim($skillKey) !== '' ? [trim($skillKey)] : null);

        foreach ($result['imported'] as $skillVersion) {
            $this->line('Imported '.$skillVersion);
        }

        foreach ($result['skipped'] as $skillKey) {
            $this->line('skipped '.$skillKey);
        }

        foreach ($result['missing'] as $skillKey) {
            $this->line('missing '.$skillKey);
        }

        foreach ($result['orphaned'] as $skillKey) {
            $this->line('orphaned '.$skillKey);
        }

        if ($result['orphaned'] !== []) {
            $this->info('orphaned warnings present');
        }

        $this->info('Skill catalog import complete.');

        return self::SUCCESS;
    }
}
