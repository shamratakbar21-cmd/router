<?php

use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LicenseActivationController;
use App\Http\Controllers\LicenseAdminController;
use App\Http\Controllers\PasswordRotationController;
use App\Http\Controllers\RouterBackupController;
use App\Http\Controllers\RouterBrasController;
use App\Http\Controllers\RouterDesiredStateController;
use App\Http\Controllers\RouterDesktopMutationController;
use App\Http\Controllers\RouterDiagnosticController;
use App\Http\Controllers\RouterNodeController;
use App\Http\Controllers\RouterRevisionController;
use App\Http\Controllers\RouterStateController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/password-rotation', [PasswordRotationController::class, 'edit'])->name('password-rotation.edit');
    Route::put('/password-rotation', [PasswordRotationController::class, 'update'])->name('password-rotation.update');
    Route::delete('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

Route::middleware(['auth', 'require-password-rotation'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::resource('routers', RouterNodeController::class)->except(['destroy']);
    Route::get('/routers/{router}/bras', [RouterBrasController::class, 'edit'])->name('routers.bras.edit');
    Route::put('/routers/{router}/bras', [RouterBrasController::class, 'update'])->name('routers.bras.update');
    Route::get('/routers/{router}/configure', [RouterDesiredStateController::class, 'edit'])->name('routers.desired-state.edit');
    Route::get('/routers/{router}/desired-state', [RouterDesiredStateController::class, 'show'])->name('routers.desired-state.show');
    Route::put('/routers/{router}/desired-state', [RouterDesiredStateController::class, 'update'])->name('routers.desired-state.update');
    Route::post('/routers/{router}/desktop-config', RouterDesktopMutationController::class)->name('routers.desktop.mutate');
    Route::post('/routers/{router}/diagnostics', RouterDiagnosticController::class)->middleware('throttle:12,1')->name('routers.diagnostics');
    Route::get('/routers/{router}/state', RouterStateController::class)->name('routers.state.show');
    Route::post('/routers/{router}/apply', [RouterRevisionController::class, 'apply'])->name('routers.apply');
    Route::post('/routers/{router}/safe-mode/recover', [RouterRevisionController::class, 'recover'])->name('routers.safe-mode.recover');
    Route::post('/routers/{router}/safe-mode/confirm', [RouterRevisionController::class, 'confirm'])
        ->name('routers.safe-mode.confirm');
    Route::post('/routers/{router}/revisions/{revision}/rollback', [RouterRevisionController::class, 'rollback'])
        ->scopeBindings()
        ->name('routers.revisions.rollback');
    Route::get('/routers/{router}/backup', [RouterBackupController::class, 'show'])->name('routers.backup.show');
    Route::post('/routers/{router}/backup/export', [RouterBackupController::class, 'export'])->name('routers.backup.export');
    Route::post('/routers/{router}/backup/preview', [RouterBackupController::class, 'preview'])->name('routers.backup.preview');
    Route::post('/routers/{router}/backup/restore', [RouterBackupController::class, 'restore'])->name('routers.backup.restore');

    Route::get('/licensing', [LicenseAdminController::class, 'index'])->name('licensing.admin');
    Route::post('/licensing/keys', [LicenseAdminController::class, 'issueKey'])->name('licensing.keys.issue');
    Route::post('/licensing/keys/{key}/revoke', [LicenseAdminController::class, 'revokeKey'])->name('licensing.keys.revoke');
    Route::post('/licensing/activations/{activation}/revoke', [LicenseAdminController::class, 'revokeActivation'])
        ->name('licensing.activations.revoke');
    Route::post('/licensing/transfer', [LicenseActivationController::class, 'transfer'])
        ->middleware('throttle:licensing-activation')
        ->name('licensing.transfer');
    Route::post('/licensing/offline-activate', [LicenseActivationController::class, 'offlineActivate'])
        ->middleware('throttle:licensing-activation')
        ->name('licensing.offline.activate');
    Route::post('/licensing/verify', [LicenseActivationController::class, 'verifyPayload'])->name('licensing.verify');

    Route::get('/routers/{router}/license', [LicenseActivationController::class, 'show'])->name('licensing.status');
    Route::post('/routers/{router}/license/activate', [LicenseActivationController::class, 'activate'])
        ->middleware('throttle:licensing-activation')
        ->name('licensing.activate');
    Route::post('/routers/{router}/license/refresh', [LicenseActivationController::class, 'refresh'])
        ->middleware('throttle:licensing-activation')
        ->name('licensing.refresh');
    Route::get('/routers/{router}/license/offline-request', [LicenseActivationController::class, 'offlineRequest'])
        ->name('licensing.offline.request');
});
