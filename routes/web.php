<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\ConversationsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TenantSetupController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'show'])->name('landing');

Route::middleware('guest')->group(function (): void {
    Route::get('/signup', [RegisterController::class, 'create'])->name('signup');
    Route::post('/signup', [RegisterController::class, 'store'])->name('signup.store');
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->name('password.update');
});

Route::get('/auth/google/callback', [GoogleOAuthController::class, 'callback'])->name('google.callback');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::middleware('workspace.access')->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/dashboard/refresh-trial-usage', [DashboardController::class, 'refreshTrialUsage'])->name('dashboard.refresh-trial-usage');
        Route::get('/conversations', [ConversationsController::class, 'index'])->name('conversations.index');
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/sync-agent', [ProfileController::class, 'syncAgent'])->name('profile.sync-agent');
        Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
        Route::get('/onboarding/state', [OnboardingController::class, 'state'])->name('onboarding.state');
        Route::post('/onboarding/extract-business', [OnboardingController::class, 'extractBusiness'])->name('onboarding.extract-business');
        Route::post('/onboarding/business-info', [OnboardingController::class, 'saveBusinessInfo'])->name('onboarding.business-info');
        Route::post('/onboarding/personality', [OnboardingController::class, 'savePersonality'])->name('onboarding.personality');
        Route::post('/onboarding/capabilities', [OnboardingController::class, 'saveCapabilities'])->name('onboarding.capabilities');
        Route::post('/onboarding/channel', [OnboardingController::class, 'saveChannel'])->name('onboarding.channel');
        Route::post('/onboarding/channel/disconnect', [OnboardingController::class, 'disconnectChannel'])->name('onboarding.channel.disconnect');
        Route::get('/onboarding/google/connect', [GoogleOAuthController::class, 'redirect'])->name('onboarding.google.connect');
        Route::post('/onboarding/google/skip', [GoogleOAuthController::class, 'skip'])->name('onboarding.google.skip');
        Route::post('/onboarding/google/disconnect', [GoogleOAuthController::class, 'disconnect'])->name('onboarding.google.disconnect');
        Route::post('/onboarding/go-live', [OnboardingController::class, 'goLive'])->name('onboarding.go-live');
        Route::get('/tenant/setup', [TenantSetupController::class, 'show'])->name('tenant.setup');
        Route::get('/tenant/status', [TenantSetupController::class, 'status'])->name('tenant.status');
        Route::get('/tenant/workspace-ready', [TenantSetupController::class, 'ready'])->name('tenant.workspace-ready');
        Route::get('/workspace/{tenant:slug}', [TenantSetupController::class, 'workspace'])->name('workspace.show');
    });

    Route::middleware(['local.only', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::get('/tenants', [AdminController::class, 'tenants'])->name('tenants');
        Route::get('/analytics/skills', [AdminController::class, 'skillAnalytics'])->name('analytics.skills');
        Route::get('/skills', [AdminController::class, 'skillsCatalog'])->name('skills.index');
        Route::post('/skills/scan', [AdminController::class, 'scanSkillCatalog'])->name('skills.scan');
        Route::post('/skills/import', [AdminController::class, 'importSkillCatalog'])->name('skills.import');
        Route::get('/skills/{skill}', [AdminController::class, 'showSkillCatalog'])->name('skills.show');
        Route::post('/skills/{skill}/versions/{version}/publish', [AdminController::class, 'publishSkillCatalogVersion'])->name('skills.versions.publish');
        Route::post('/skills/{skill}/versions/{version}/archive', [AdminController::class, 'archiveSkillCatalogVersion'])->name('skills.versions.archive');
        Route::post('/skills/{skill}/versions/{version}/rollout', [AdminController::class, 'rolloutSkillCatalogVersion'])->name('skills.versions.rollout');
        Route::get('/skills/{skill}/versions/{version}/rollout-progress', [AdminController::class, 'skillRolloutProgress'])->name('skills.versions.rollout-progress');
        Route::get('/system-health/status', [AdminController::class, 'systemHealthStatus'])->name('system-health.status');
        Route::get('/tenants/{tenant}', [AdminController::class, 'showTenant'])->name('tenants.show');
        Route::delete('/tenants/{tenant}', [AdminController::class, 'destroyTenant'])->name('tenants.destroy');
        Route::get('/jobs', [AdminController::class, 'jobs'])->name('jobs');
        Route::get('/deploy/control-app/status', [AdminController::class, 'controlAppDeployStatus'])->name('deploy.control-app.status');
        Route::post('/deploy/control-app', [AdminController::class, 'triggerControlAppDeploy'])->name('deploy.control-app');
        Route::post('/jobs/{tenant}/retry', [AdminController::class, 'retry'])->name('retry');
        Route::post('/tenants/{tenant}/health-check', [AdminController::class, 'healthCheck'])->name('tenants.health-check');
        Route::post('/tenants/{tenant}/resync-agent', [AdminController::class, 'resyncAgent'])->name('tenants.resync-agent');
        Route::post('/tenants/{tenant}/runtime/bootstrap', [AdminController::class, 'bootstrapRuntimeHost'])->name('tenants.runtime.bootstrap');
        Route::post('/tenants/{tenant}/runtime/capabilities/sync', [AdminController::class, 'syncRuntimeCapabilities'])->name('tenants.runtime-capabilities.sync');
        Route::post('/tenants/{tenant}/google/sync', [AdminController::class, 'retryGoogleWorkspaceSync'])->name('tenants.google.sync');
        Route::post('/tenants/{tenant}/google/test', [AdminController::class, 'testGoogleWorkspace'])->name('tenants.google.test');
        Route::post('/tenants/{tenant}/skills/runtime-refresh', [AdminController::class, 'refreshRuntimeAvailableSkills'])->name('tenants.skills.runtime-refresh');
        Route::get('/tenants/{tenant}/skills/progress', [AdminController::class, 'tenantSkillsProgress'])->name('tenants.skills.progress');
        Route::patch('/tenants/{tenant}/agent-customization', [AdminController::class, 'updateAgentCustomization'])->name('tenants.agent-customization.update');
        Route::post('/tenants/{tenant}/agent-customization/preview', [AdminController::class, 'previewAgentCustomization'])->name('tenants.agent-customization.preview');
        Route::post('/tenants/{tenant}/agent-customization/apply', [AdminController::class, 'applyAgentCustomization'])->name('tenants.agent-customization.apply');
        Route::post('/tenants/{tenant}/agent-customization/revert', [AdminController::class, 'revertAgentCustomization'])->name('tenants.agent-customization.revert');
        Route::get('/tenants/{tenant}/agent-customization/apply-log', [AdminController::class, 'agentCustomizationApplyLog'])->name('tenants.agent-customization.apply-log');
        Route::post('/tenants/{tenant}/workspace/start', [AdminController::class, 'startWorkspace'])->name('workspace.start');
        Route::post('/tenants/{tenant}/workspace/stop', [AdminController::class, 'stopWorkspace'])->name('workspace.stop');
        Route::post('/tenants/{tenant}/workspace/restart', [AdminController::class, 'restartWorkspace'])->name('workspace.restart');
    });
});
