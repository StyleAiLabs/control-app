<?php

namespace App\Support;

use App\Models\TenantGoogleCredential;
use Illuminate\Support\Facades\Schema;

class GoogleWorkspaceFeature
{
    public static function isAvailable(): bool
    {
        return Schema::hasTable((new TenantGoogleCredential())->getTable());
    }

    /**
     * @param  array<int, string>  $relations
     * @return array<int, string>
     */
    public static function tenantRelations(array $relations = []): array
    {
        if (! self::isAvailable()) {
            return $relations;
        }

        $relations[] = 'googleCredential';

        return array_values(array_unique($relations));
    }
}
