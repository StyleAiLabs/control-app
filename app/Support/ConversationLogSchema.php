<?php

namespace App\Support;

use App\Models\ConversationLog;
use Illuminate\Support\Facades\Schema;

class ConversationLogSchema
{
    /**
     * @return list<string>
     */
    public static function requiredColumns(): array
    {
        return [
            'tenant_id',
            'channel',
            'external_message_id',
            'session_id',
            'from_identifier',
            'message_in',
            'message_out',
            'ai_summary',
            'meta_json',
            'responded_at',
            'created_at',
            'updated_at',
        ];
    }

    public static function isAvailable(): bool
    {
        return self::missingColumns() === [];
    }

    /**
     * @return list<string>
     */
    public static function missingColumns(): array
    {
        $table = self::table();

        if (! Schema::hasTable($table)) {
            return self::requiredColumns();
        }

        return array_values(array_filter(
            self::requiredColumns(),
            static fn (string $column): bool => ! Schema::hasColumn($table, $column),
        ));
    }

    public static function driftMessage(string $nextStep): string
    {
        $table = self::table();

        $detail = ! Schema::hasTable($table)
            ? sprintf('The `%s` table is missing.', $table)
            : sprintf(
                'Missing columns on `%s`: %s.',
                $table,
                implode(', ', self::missingColumns())
            );

        return sprintf(
            'Conversation log schema drift detected in the control-plane database. %s Run `php artisan migrate` on the control plane, then %s.',
            $detail,
            $nextStep,
        );
    }

    private static function table(): string
    {
        return (new ConversationLog())->getTable();
    }
}
