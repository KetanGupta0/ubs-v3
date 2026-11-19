<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Chat\ChatApiController;
use App\Http\Controllers\Api\V1\Client\ClientApiController;
use App\Http\Controllers\Api\V1\LicenceCheckController;
use App\Http\Controllers\Api\V1\Student\StudentApiController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Client\DocumentController;
use App\Http\Controllers\Client\TransactionController;
use App\Http\Controllers\Student\CourseController;
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
    /*
     * Software we sold checks its licence key here (X18). The key is the
     * credential, so there is no token; it is throttled by address instead.
     */
    Route::post('licences/check', LicenceCheckController::class)
        ->middleware('throttle:licence-checks')
        ->name('licences.check');

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
    /*
     * A temporary password has to be replaced before anything else opens, as
     * on the web: every endpoint but these few answers 423 until it is
     * (password.owned). A GET answer carries an ETag, so an app holding a
     * copy asks with If-None-Match and gets an empty 304 when nothing moved.
     */
    Route::middleware([
        'auth:sanctum',
        'abilities:'.AuthController::ACCESS_ABILITY,
        'password.owned',
        'cache.headers:private;etag',
    ])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('auth/password', [AuthController::class, 'changePassword'])
            ->middleware('throttle:10,1')
            ->name('auth.password');
        Route::post('auth/refresh', [AuthController::class, 'refresh'])
            ->middleware('throttle:10,1')
            ->name('auth.refresh');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');

        Route::get('devices', [AuthController::class, 'devices'])->name('devices.index');
        Route::delete('devices/{device}', [AuthController::class, 'revokeDevice'])
            ->whereNumber('device')
            ->name('devices.destroy');
        Route::post('devices/push-token', [AuthController::class, 'registerPushToken'])->name('devices.push-token');

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

            // Files, by token: the portal's own actions, which scope every read
            // to the person asking.
            Route::get('documents/{document}/download', [DocumentController::class, 'download'])
                ->whereNumber('document')
                ->name('documents.download');
            Route::get('invoices/{invoice}/pdf', [TransactionController::class, 'invoicePdf'])
                ->whereNumber('invoice')
                ->name('invoices.pdf');
            Route::get('receipts/{reference}', [TransactionController::class, 'receipt'])
                ->name('receipts.show');
        });

        /*
         * Chat, for every role.
         *
         * Not inside a role group: which rooms somebody can open is a question
         * about them, and the answer lives on the conversation itself.
         */
        Route::prefix('chat')->name('chat.')->group(function () {
            Route::get('/', [ChatApiController::class, 'index'])->name('index');
            Route::get('{conversation}/messages', [ChatApiController::class, 'messages'])
                ->whereNumber('conversation')
                ->name('messages');
            Route::post('{conversation}/messages', [ChatApiController::class, 'send'])
                ->whereNumber('conversation')
                ->middleware('throttle:120,1')
                ->name('send');
            Route::post('{conversation}/read', [ChatApiController::class, 'read'])
                ->whereNumber('conversation')
                ->name('read');

            // A picture or a voice note: signed and expiring as on the web,
            // and checked against who is asking.
            Route::get('media/{message}', [ChatController::class, 'media'])
                ->whereNumber('message')
                ->middleware('signed')
                ->name('media');
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
            Route::get('courses/{course}/materials/{material}', [CourseController::class, 'downloadMaterial'])
                ->whereNumber('course')
                ->whereNumber('material')
                ->name('materials.download');
            Route::get('classes', [StudentApiController::class, 'classes'])->name('classes.index');
            Route::get('assignments', [StudentApiController::class, 'assignments'])->name('assignments.index');
            Route::get('results', [StudentApiController::class, 'results'])->name('results');
            Route::get('announcements', [StudentApiController::class, 'announcements'])->name('announcements');
            Route::get('certificates', [StudentApiController::class, 'certificates'])->name('certificates');
            Route::get('payments', [StudentApiController::class, 'payments'])->name('payments.index');
        });
    });
});
