<?php

declare(strict_types=1);

use App\Http\Controllers\ConnectedAppsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'bfc.auth'])->prefix('apps')->name('apps.')->group(function (): void {
    Route::get('/', [ConnectedAppsController::class, 'index'])->name('index');
    Route::post('/', [ConnectedAppsController::class, 'store'])->name('store');
    Route::get('/{app}', [ConnectedAppsController::class, 'show'])->name('show');
    Route::post('/{app}/credential/rotate', [ConnectedAppsController::class, 'rotate'])->name('credential.rotate');
    Route::delete('/{app}/credential', [ConnectedAppsController::class, 'revoke'])->name('credential.revoke');
});
