<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill trial_ends_at for tenants that pre-date the trial lifecycle feature.
 *
 * For any tenant where trial_ends_at is NULL, we derive it from created_at + 14 days.
 * Uses a PHP loop for DB-driver portability (SQLite in tests, PostgreSQL in production).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')
            ->whereNull('trial_ends_at')
            ->orderBy('id')
            ->select(['id', 'created_at'])
            ->lazyById()
            ->each(function (object $row): void {
                $endsAt = \Illuminate\Support\Carbon::parse($row->created_at)->addDays(14);

                DB::table('tenants')
                    ->where('id', $row->id)
                    ->update(['trial_ends_at' => $endsAt]);
            });
    }

    public function down(): void
    {
        // Not reversible — cannot distinguish backfilled from originally-set rows.
    }
};
