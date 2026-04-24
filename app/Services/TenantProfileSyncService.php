<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\Tenant;
use App\Support\GoogleWorkspaceFeature;
use RuntimeException;

class TenantProfileSyncService
{
    public function __construct(
        private readonly BusinessExtractionService $businessExtraction,
        private readonly TenantAgentSyncService $agentSync,
        private readonly TenantOnboardingSkillService $onboardingSkills,
    ) {
    }

    public function regenerateFiles(Tenant $tenant): void
    {
        $tenant->loadMissing(['businessProfile', 'businessProfileFiles']);

        $profile = $tenant->businessProfile;
        $files = $tenant->businessProfileFiles;

        if (! $profile || ! $files) {
            throw new RuntimeException('We still need a business profile before we can sync the assistant.');
        }

        if (! filled($tenant->tone)) {
            throw new RuntimeException('Choose the assistant communication style before syncing changes.');
        }

        $this->onboardingSkills->ensureCoreAssignments($tenant, $tenant->user_id);
        $modules = $this->onboardingSkills->enabledModules($tenant);

        if ($modules === []) {
            throw new RuntimeException('Choose at least one enabled module before syncing changes.');
        }

        $generated = $this->businessExtraction->generateAgentFiles(
            $profile,
            (string) $tenant->tone,
            $modules,
        );

        $files->forceFill([
            'identity_markdown' => $generated['identity'],
            'soul_markdown' => $generated['soul'],
            'user_markdown' => $generated['user'],
            'bootstrap_markdown' => $generated['bootstrap'],
            'generated_at' => now(),
        ])->save();
    }

    public function regenerateAndSync(Tenant $tenant): void
    {
        $this->regenerateFiles($tenant);
        $tenant = $tenant->fresh(GoogleWorkspaceFeature::tenantRelations(['businessProfile', 'businessProfileFiles', 'server']));
        $this->agentSync->goLive($tenant);
        $this->agentSync->syncConnectedGoogleWorkspace($tenant->fresh(GoogleWorkspaceFeature::tenantRelations(['server'])));
    }

    public function regenerateAndSyncWorkspaceOnly(Tenant $tenant): void
    {
        $this->regenerateFiles($tenant);
        $tenant = $tenant->fresh(GoogleWorkspaceFeature::tenantRelations(['businessProfile', 'businessProfileFiles', 'server']));
        $this->agentSync->goLive($tenant);
    }

}
