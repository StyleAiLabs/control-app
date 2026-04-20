<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const OLD_KEY = 'appointment-booking';
    private const NEW_KEY = 'hello-world';
    private const OLD_LABEL = 'Appointment Booking';
    private const NEW_LABEL = 'Hello World (by Sync360)';
    private const OLD_CONVERSION_TYPE = 'appointment_booked';
    private const NEW_CONVERSION_TYPE = 'hello_world_completed';

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('skill_catalog_items')
                ->where('skill_key', self::OLD_KEY)
                ->update([
                    'skill_key' => self::NEW_KEY,
                    'label' => self::NEW_LABEL,
                    'description' => 'A simple hello world skill for testing purposes.',
                    'category' => 'testing',
                ]);

            foreach (DB::table('skill_catalog_versions')->where('skill_key', self::OLD_KEY)->get() as $version) {
                DB::table('skill_catalog_versions')
                    ->where('id', $version->id)
                    ->update([
                        'skill_key' => self::NEW_KEY,
                        'manifest_json' => json_encode(
                            $this->helloWorldManifest($this->decodeJson($version->manifest_json)['version'] ?? $version->version),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                        ),
                    ]);
            }

            DB::table('tenant_skill_assignments')
                ->where('skill_key', self::OLD_KEY)
                ->update(['skill_key' => self::NEW_KEY]);

            DB::table('tenant_skill_conversion_events')
                ->where('skill_key', self::OLD_KEY)
                ->update([
                    'skill_key' => self::NEW_KEY,
                    'conversion_type' => self::NEW_CONVERSION_TYPE,
                ]);

            $this->replaceJsonColumnValues('tenant_agent_customizations', 'agent_defaults_json', [
                self::OLD_KEY => self::NEW_KEY,
                self::OLD_LABEL => self::NEW_LABEL,
                self::OLD_CONVERSION_TYPE => self::NEW_CONVERSION_TYPE,
            ]);

            $this->replaceJsonColumnValues('tenant_agent_customizations', 'last_applied_input_snapshot_json', [
                self::OLD_KEY => self::NEW_KEY,
                self::OLD_LABEL => self::NEW_LABEL,
                self::OLD_CONVERSION_TYPE => self::NEW_CONVERSION_TYPE,
            ]);

            $this->replaceJsonColumnValues('tenant_agent_customization_applies', 'input_snapshot_json', [
                self::OLD_KEY => self::NEW_KEY,
                self::OLD_LABEL => self::NEW_LABEL,
                self::OLD_CONVERSION_TYPE => self::NEW_CONVERSION_TYPE,
            ]);

            $this->replaceJsonColumnValues('tenant_agent_customization_applies', 'composed_output_json', [
                self::OLD_KEY => self::NEW_KEY,
                self::OLD_LABEL => self::NEW_LABEL,
                self::OLD_CONVERSION_TYPE => self::NEW_CONVERSION_TYPE,
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('skill_catalog_items')
                ->where('skill_key', self::NEW_KEY)
                ->update([
                    'skill_key' => self::OLD_KEY,
                    'label' => self::OLD_LABEL,
                    'description' => 'Guides customers through booking requests and next-step confirmation.',
                    'category' => 'operations',
                ]);

            foreach (DB::table('skill_catalog_versions')->where('skill_key', self::NEW_KEY)->get() as $version) {
                DB::table('skill_catalog_versions')
                    ->where('id', $version->id)
                    ->update([
                        'skill_key' => self::OLD_KEY,
                        'manifest_json' => json_encode(
                            $this->appointmentBookingManifest($this->decodeJson($version->manifest_json)['version'] ?? $version->version),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                        ),
                    ]);
            }

            DB::table('tenant_skill_assignments')
                ->where('skill_key', self::NEW_KEY)
                ->update(['skill_key' => self::OLD_KEY]);

            DB::table('tenant_skill_conversion_events')
                ->where('skill_key', self::NEW_KEY)
                ->update([
                    'skill_key' => self::OLD_KEY,
                    'conversion_type' => self::OLD_CONVERSION_TYPE,
                ]);

            $this->replaceJsonColumnValues('tenant_agent_customizations', 'agent_defaults_json', [
                self::NEW_KEY => self::OLD_KEY,
                self::NEW_LABEL => self::OLD_LABEL,
                self::NEW_CONVERSION_TYPE => self::OLD_CONVERSION_TYPE,
            ]);

            $this->replaceJsonColumnValues('tenant_agent_customizations', 'last_applied_input_snapshot_json', [
                self::NEW_KEY => self::OLD_KEY,
                self::NEW_LABEL => self::OLD_LABEL,
                self::NEW_CONVERSION_TYPE => self::OLD_CONVERSION_TYPE,
            ]);

            $this->replaceJsonColumnValues('tenant_agent_customization_applies', 'input_snapshot_json', [
                self::NEW_KEY => self::OLD_KEY,
                self::NEW_LABEL => self::OLD_LABEL,
                self::NEW_CONVERSION_TYPE => self::OLD_CONVERSION_TYPE,
            ]);

            $this->replaceJsonColumnValues('tenant_agent_customization_applies', 'composed_output_json', [
                self::NEW_KEY => self::OLD_KEY,
                self::NEW_LABEL => self::OLD_LABEL,
                self::NEW_CONVERSION_TYPE => self::OLD_CONVERSION_TYPE,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function helloWorldManifest(mixed $version): array
    {
        return [
            'skill_id' => self::NEW_KEY,
            'version' => is_string($version) && trim($version) !== '' ? trim($version) : '1.0.4',
            'label' => self::NEW_LABEL,
            'description' => 'A simple hello world skill for testing purposes.',
            'category' => 'testing',
            'production_ready' => false,
            'openclaw_skill_ids' => [self::NEW_KEY],
            'default_agent_skill_ids' => [self::NEW_KEY],
            'applicable_industries' => [],
            'analytics' => [
                'enabled' => true,
                'conversion_type' => self::NEW_CONVERSION_TYPE,
                'success_event_type' => 'conversion_succeeded',
                'required_success_fields' => [
                    'event_id',
                    'customer_label',
                    'outcome.greeting',
                ],
                'roi_defaults' => [
                    'human_effort_minutes' => 1,
                    'agent_effort_minutes' => 1,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentBookingManifest(mixed $version): array
    {
        return [
            'skill_id' => self::OLD_KEY,
            'version' => is_string($version) && trim($version) !== '' ? trim($version) : '1.0.3',
            'label' => self::OLD_LABEL,
            'description' => 'Guides customers through booking requests and next-step confirmation.',
            'category' => 'operations',
            'production_ready' => true,
            'openclaw_skill_ids' => [self::OLD_KEY],
            'default_agent_skill_ids' => [self::OLD_KEY],
            'applicable_industries' => [],
            'analytics' => [
                'enabled' => true,
                'conversion_type' => self::OLD_CONVERSION_TYPE,
                'success_event_type' => 'conversion_succeeded',
                'required_success_fields' => [
                    'event_id',
                    'customer_label',
                    'outcome.scheduled_at',
                    'outcome.service_name',
                ],
                'roi_defaults' => [
                    'human_effort_minutes' => 10,
                    'agent_effort_minutes' => 1,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function replaceJsonColumnValues(string $table, string $column, array $replacements): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        foreach (DB::table($table)->whereNotNull($column)->get(['id', $column]) as $row) {
            $decoded = $this->decodeJson($row->{$column});

            if ($decoded === []) {
                continue;
            }

            $updated = $this->replaceStrings($decoded, $replacements);

            if ($updated === $decoded) {
                continue;
            }

            DB::table($table)
                ->where('id', $row->id)
                ->update([
                    $column => json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ]);
        }
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function replaceStrings(mixed $value, array $replacements): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replaceStrings($item, $replacements);
            }

            return $value;
        }

        if (is_string($value)) {
            return str_replace(array_keys($replacements), array_values($replacements), $value);
        }

        return $value;
    }
};
