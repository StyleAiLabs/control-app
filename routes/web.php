<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TenantSetupController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('landing');

Route::middleware('guest')->group(function (): void {
    Route::get('/signup', [RegisterController::class, 'create'])->name('signup');
    Route::post('/signup', [RegisterController::class, 'store'])->name('signup.store');
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/tenant/setup', [TenantSetupController::class, 'show'])->name('tenant.setup');
    Route::get('/tenant/status', [TenantSetupController::class, 'status'])->name('tenant.status');
    Route::get('/tenant/workspace-ready', [TenantSetupController::class, 'ready'])->name('tenant.workspace-ready');
    Route::get('/workspace/{tenant:slug}', [TenantSetupController::class, 'workspace'])->name('workspace.show');

    Route::middleware(['local.only', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::get('/tenants', [AdminController::class, 'tenants'])->name('tenants');
        Route::get('/jobs', [AdminController::class, 'jobs'])->name('jobs');
        Route::get('/deploy/control-app/status', [AdminController::class, 'controlAppDeployStatus'])->name('deploy.control-app.status');
        Route::post('/deploy/control-app', [AdminController::class, 'triggerControlAppDeploy'])->name('deploy.control-app');
        Route::post('/jobs/{tenant}/retry', [AdminController::class, 'retry'])->name('retry');
        Route::post('/tenants/{tenant}/workspace/start', [AdminController::class, 'startWorkspace'])->name('workspace.start');
        Route::post('/tenants/{tenant}/workspace/stop', [AdminController::class, 'stopWorkspace'])->name('workspace.stop');
    });
});
