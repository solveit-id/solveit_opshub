<?php

use App\Http\Controllers\ClientTemplateController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistryPageController;
use App\Http\Controllers\RenewalPageController;
use App\Http\Controllers\TelegramBindingController;
use App\Http\Controllers\TelegramConfigurationController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => false,
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified', 'owner.mfa'])->name('dashboard');

Route::middleware(['auth', 'verified', 'owner.mfa', 'active.user', 'organization.access:project.read'])->group(function (): void {
    Route::get('/organizations/{organization}/renewals', [RenewalPageController::class, 'index'])->name('renewals.index');
    Route::get('/organizations/{organization}/telegram', [TelegramConfigurationController::class, 'index'])->name('telegram.settings');
    Route::get('/organizations/{organization}/telegram-binding', [TelegramBindingController::class, 'index'])->name('telegram.binding');
    Route::get('/organizations/{organization}/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/organizations/{organization}/follow-ups/{followup}', [RenewalPageController::class, 'show'])->name('followups.show');
    Route::get('/organizations/{organization}/client-templates', [ClientTemplateController::class, 'page'])->name('client-templates.page');
    Route::get('/organizations/{organization}/overview', [MonitoringController::class, 'overview'])->name('monitoring.overview');
    Route::get('/organizations/{organization}/health', [MonitoringController::class, 'runtimeHealth'])->name('monitoring.health');
    Route::get('/organizations/{organization}/incidents/{incident}', [MonitoringController::class, 'incident'])->name('monitoring.incidents.show');
    Route::get('/organizations/{organization}/assets/{asset}', [MonitoringController::class, 'asset'])->name('monitoring.assets.show');
    Route::get('/organizations/{organization}/registry', [RegistryPageController::class, 'index'])->name('registry.page');
    Route::get('/organizations/{organization}/registry/projects/{project}', [RegistryPageController::class, 'project'])->name('registry.projects.show');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
