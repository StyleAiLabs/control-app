<?php

namespace Tests\Unit;

use App\Services\TenantRuntimeSkillDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class TenantRuntimeSkillDiscoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_skill_output_prefers_json_and_filters_to_eligible_skills(): void
    {
        $service = app(TenantRuntimeSkillDiscoveryService::class);
        $method = (new ReflectionClass($service))->getMethod('parseSkillOutput');
        $method->setAccessible(true);

        $skills = $method->invoke($service, json_encode([
            'skills' => [
                ['name' => 'gog', 'eligible' => true],
                ['name' => 'inbox-triage', 'eligible' => true],
                ['name' => 'draft-only-skill', 'eligible' => false],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $this->assertSame(['gog', 'inbox-triage'], $skills);
    }

    public function test_parse_skill_output_normalizes_workspace_display_names_from_json(): void
    {
        $service = app(TenantRuntimeSkillDiscoveryService::class);
        $method = (new ReflectionClass($service))->getMethod('parseSkillOutput');
        $method->setAccessible(true);

        $skills = $method->invoke($service, json_encode([
            'skills' => [
                ['name' => 'Inbox Triage', 'eligible' => true, 'source' => 'openclaw-workspace'],
                ['name' => 'Appointment Booking', 'eligible' => true, 'source' => 'openclaw-workspace'],
                ['name' => 'gog', 'eligible' => true, 'source' => 'openclaw-bundled'],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $this->assertSame(['appointment-booking', 'gog', 'inbox-triage'], $skills);
    }

    public function test_parse_skill_output_understands_openclaw_table_output(): void
    {
        $service = app(TenantRuntimeSkillDiscoveryService::class);
        $method = (new ReflectionClass($service))->getMethod('parseSkillOutput');
        $method->setAccessible(true);

        $output = <<<TEXT
Skills (2/2 ready)
┌──────────┬────────────────────────┬────────────────────┐
│ Status   │ Skill                  │ Source             │
├──────────┼────────────────────────┼────────────────────┤
│ ✓ ready  │ 🎮 gog                 │ openclaw-bundled   │
│ ✓ ready  │ 📦 Inbox Triage        │ openclaw-workspace │
└──────────┴────────────────────────┴────────────────────┘
TEXT;

        $skills = $method->invoke($service, $output);

        $this->assertSame(['gog', 'inbox-triage'], $skills);
    }
}
