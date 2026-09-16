<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Built alongside every phase rather than bolted on at the end, so the three
| mobile applications planned for Phase 8 have a stable surface to develop
| against. Versioned from the first endpoint, because a published app cannot
| be asked to update in step with the server.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:20,1')
            ->name('login');

        Route::post('code', [AuthController::class, 'requestCode'])
            ->middleware('throttle:10,1')
            ->name('code.request');

        Route::post('code/verify', [AuthController::class, 'verifyCode'])
            ->middleware('throttle:20,1')
            ->name('code.verify');

        // Authenticated by the short lived challenge token issued at login.
        Route::post('two-factor', [AuthController::class, 'twoFactor'])
            ->middleware(['auth:sanctum', 'throttle:20,1'])
            ->name('two-factor');
    });

    /*
     * Everything past here needs a full access token. A token issued with only
     * the `two-factor` ability fails the check, so the half finished sign in it
     * represents cannot reach anything else.
     */
    Route::middleware(['auth:sanctum', 'abilities:'.AuthController::ACCESS_ABILITY])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('devices/push-token', [AuthController::class, 'registerPushToken'])->name('devices.push-token');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');
    });
});
