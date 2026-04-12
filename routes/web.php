<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ConversationsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TenantSetupController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('landing');

Route::middleware('guest')->group(function (): void {
    Route::get('/signup', [RegisterController::class, 'create'])->name('signup');
    Route::post('/signup', [RegisterController::class, 'store'])->name('signup.store');
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::get('/webhooks/whatsapp/{tenantId}', [WebhookController::class, 'verifyWhatsApp'])->name('webhooks.whatsapp.verify');
Route::post('/webhooks/whatsapp/{tenantId}', [WebhookController::class, 'handleWhatsApp'])->name('webhooks.whatsapp.handle');
Route::post('/webhooks/telegram/{tenantId}', [WebhookController::class, 'handleTelegram'])->name('webhooks.telegram.handle');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
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
    Route::post('/onboarding/go-live', [OnboardingController::class, 'goLive'])->name('onboarding.go-live');
    Route::get('/tenant/setup', [TenantSetupController::class, 'show'])->name('tenant.setup');
    Route::get('/tenant/status', [TenantSetupController::class, 'status'])->name('tenant.status');
    Route::get('/tenant/workspace-ready', [TenantSetupController::class, 'ready'])->name('tenant.workspace-ready');
    Route::get('/workspace/{tenant:slug}', [TenantSetupController::class, 'workspace'])->name('workspace.show');

    Route::middleware(['local.only', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::get('/tenants', [AdminController::class, 'tenants'])->name('tenants');
        Route::get('/tenants/{tenant}', [AdminController::class, 'showTenant'])->name('tenants.show');
        Route::delete('/tenants/{tenant}', [AdminController::class, 'destroyTenant'])->name('tenants.destroy');
        Route::get('/jobs', [AdminController::class, 'jobs'])->name('jobs');
        Route::get('/deploy/control-app/status', [AdminController::class, 'controlAppDeployStatus'])->name('deploy.control-app.status');
        Route::post('/deploy/control-app', [AdminController::class, 'triggerControlAppDeploy'])->name('deploy.control-app');
        Route::post('/jobs/{tenant}/retry', [AdminController::class, 'retry'])->name('retry');
        Route::post('/tenants/{tenant}/health-check', [AdminController::class, 'healthCheck'])->name('tenants.health-check');
        Route::post('/tenants/{tenant}/resync-agent', [AdminController::class, 'resyncAgent'])->name('tenants.resync-agent');
        Route::post('/tenants/{tenant}/workspace/start', [AdminController::class, 'startWorkspace'])->name('workspace.start');
        Route::post('/tenants/{tenant}/workspace/stop', [AdminController::class, 'stopWorkspace'])->name('workspace.stop');
        Route::post('/tenants/{tenant}/workspace/restart', [AdminController::class, 'restartWorkspace'])->name('workspace.restart');
    });
});
