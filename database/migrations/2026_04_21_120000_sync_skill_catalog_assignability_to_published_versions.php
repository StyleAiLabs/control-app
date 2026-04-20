<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->syncAssignability();
    }

    public function down(): void
    {
        $this->syncAssignability();
    }

    private function syncAssignability(): void
    {
        if (! Schema::hasTable('skill_catalog_items') || ! Schema::hasTable('skill_catalog_versions')) {
            return;
        }

        foreach (DB::table('skill_catalog_items')->get(['id', 'is_orphaned']) as $item) {
            $hasActivePublishedVersion = DB::table('skill_catalog_versions')
                ->where('skill_catalog_item_id', $item->id)
                ->where('is_active_published', true)
                ->where('is_archived', false)
                ->exists();

            DB::table('skill_catalog_items')
                ->where('id', $item->id)
                ->update([
                    'is_assignable' => ! (bool) $item->is_orphaned && $hasActivePublishedVersion,
                ]);
        }
    }
};
