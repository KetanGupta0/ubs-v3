<?php

use App\Http\Controllers\Chat\ChatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Chat
|--------------------------------------------------------------------------
|
| One set of routes for all three panels rather than one per role. Which rooms
| a person can see is a question about them, not about the URL they came in on,
| and answering it in one place is what stops a client's thread turning up in
| somebody else's list.
|
*/

Route::middleware(['auth', 'password.owned'])
    ->prefix('chat')
    ->name('chat.')
    ->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::get('{conversation}', [ChatController::class, 'show'])
            ->whereNumber('conversation')
            ->name('show');

        // What the fallback poll asks for: anything after the id it already has.
        Route::get('{conversation}/messages', [ChatController::class, 'messages'])
            ->whereNumber('conversation')
            ->name('messages');

        Route::post('{conversation}/messages', [ChatController::class, 'send'])
            ->whereNumber('conversation')
            ->middleware('throttle:120,1')
            ->name('send');

        Route::post('{conversation}/read', [ChatController::class, 'read'])
            ->whereNumber('conversation')
            ->name('read');

        Route::delete('{conversation}/messages/{message}', [ChatController::class, 'destroy'])
            ->whereNumber('conversation')
            ->whereNumber('message')
            ->name('messages.destroy');

        Route::get('{conversation}/gallery', [ChatController::class, 'gallery'])
            ->whereNumber('conversation')
            ->name('gallery');
    });

/*
 * Media.
 *
 * Signed and expiring, and checked again on arrival against who is asking.
 * The signature stops a link being useful for long if it leaks; the check stops
 * it being useful at all to somebody outside the room.
 */
Route::get('chat/media/{message}', [ChatController::class, 'media'])
    ->middleware(['auth', 'signed'])
    ->whereNumber('message')
    ->name('chat.media');
