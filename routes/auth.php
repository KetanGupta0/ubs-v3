<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\GoogleOAuthController;
use App\Http\Controllers\Auth\MobileVerificationController;
use App\Http\Controllers\Auth\OtpLoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| One sign in form serves administrators, clients and students, with five ways
| in: password, an emailed code, an SMS code, Google, and a recovery code at
| the two factor step.
|
| Throttles are deliberately tight on anything that sends a message or checks a
| credential, because those are the endpoints worth abusing.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('login.store');

    // Signing in with a one time code.
    Route::post('login/code', [OtpLoginController::class, 'request'])
        ->middleware('throttle:10,1')
        ->name('login.code.request');
    Route::post('login/code/verify', [OtpLoginController::class, 'verify'])
        ->middleware('throttle:20,1')
        ->name('login.code.verify');

    // Students may create their own account. Clients are created by an admin.
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('register.store');

    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:6,1')
        ->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('password.update');

    Route::get('auth/google/redirect', [GoogleOAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleOAuthController::class, 'callback'])->name('google.callback');
});

/*
 * The two factor step sits outside both 'guest' and 'auth': the person has
 * proven one factor but is not signed in yet, so neither middleware describes
 * them. The controller checks for the pending session itself.
 */
Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('two-factor.challenge.store');
Route::delete('two-factor-challenge', [TwoFactorChallengeController::class, 'destroy'])
    ->name('two-factor.challenge.cancel');

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Reachable even while a password change is being forced, so a client can
    // confirm their email on the way through.
    Route::get('verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('verify-email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('password/change', [PasswordChangeController::class, 'edit'])->name('password.change');
    Route::put('password/change', [PasswordChangeController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('password.change.store');

    Route::middleware('password.owned')->group(function () {
        Route::post('verify-mobile/send', [MobileVerificationController::class, 'send'])
            ->middleware('throttle:6,1')
            ->name('verification.mobile.send');
        Route::post('verify-mobile/confirm', [MobileVerificationController::class, 'confirm'])
            ->middleware('throttle:10,1')
            ->name('verification.mobile.confirm');

        Route::get('settings/security', [SecurityController::class, 'show'])->name('settings.security');
        Route::delete('settings/sessions/{id}', [SecurityController::class, 'revokeSession'])->name('settings.sessions.revoke');
        Route::delete('settings/devices/{id}', [SecurityController::class, 'revokeDevice'])->name('settings.devices.revoke');

        Route::post('settings/two-factor', [TwoFactorController::class, 'store'])->name('two-factor.enable');
        Route::post('settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])
            ->middleware('throttle:10,1')
            ->name('two-factor.confirm');
        Route::post('settings/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])->name('two-factor.recovery');
        Route::delete('settings/two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.disable');
    });
});
