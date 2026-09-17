<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Client\ClientApiController;
use App\Http\Controllers\Api\V1\Student\StudentApiController;
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

        /*
         * The client module.
         *
         * Role gated as well as token gated: a student's token is a valid token,
         * and valid is not the same as entitled.
         */
        Route::middleware('role:client')->prefix('client')->name('client.')->group(function () {
            Route::get('overview', [ClientApiController::class, 'overview'])->name('overview');
            Route::get('projects', [ClientApiController::class, 'projects'])->name('projects.index');
            Route::get('projects/{project}', [ClientApiController::class, 'project'])
                ->whereNumber('project')
                ->name('projects.show');
            Route::get('documents', [ClientApiController::class, 'documents'])->name('documents.index');
            Route::get('tickets', [ClientApiController::class, 'tickets'])->name('tickets.index');
            Route::post('tickets', [ClientApiController::class, 'storeTicket'])->name('tickets.store');
            Route::get('payments', [ClientApiController::class, 'payments'])->name('payments.index');
            Route::get('transactions', [ClientApiController::class, 'transactions'])->name('transactions.index');
            Route::get('invoices', [ClientApiController::class, 'invoices'])->name('invoices.index');
        });

        /* ------------------------------------------------ the student app */
        Route::middleware('role:student')->prefix('student')->name('student.')->group(function () {
            Route::get('overview', [StudentApiController::class, 'overview'])->name('overview');
            Route::get('courses', [StudentApiController::class, 'courses'])->name('courses.index');
            Route::get('courses/{course}', [StudentApiController::class, 'course'])
                ->whereNumber('course')
                ->name('courses.show');
            Route::get('courses/{course}/lessons/{lesson}', [StudentApiController::class, 'lesson'])
                ->whereNumber('course')
                ->whereNumber('lesson')
                ->name('lessons.show');
            Route::post('courses/{course}/lessons/{lesson}/complete', [StudentApiController::class, 'completeLesson'])
                ->whereNumber('course')
                ->whereNumber('lesson')
                ->name('lessons.complete');
            Route::get('classes', [StudentApiController::class, 'classes'])->name('classes.index');
            Route::get('assignments', [StudentApiController::class, 'assignments'])->name('assignments.index');
            Route::get('results', [StudentApiController::class, 'results'])->name('results');
            Route::get('announcements', [StudentApiController::class, 'announcements'])->name('announcements');
            Route::get('certificates', [StudentApiController::class, 'certificates'])->name('certificates');
            Route::get('payments', [StudentApiController::class, 'payments'])->name('payments.index');
        });
    });
});
