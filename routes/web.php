<?php

use App\Http\Controllers\DesignController;
use App\Http\Controllers\SavedViewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/marketing.php';
require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/client.php';
require __DIR__.'/student.php';
require __DIR__.'/chat.php';

/*
 * Search and saved views.
 *
 * Both are cross panel: which records somebody can find, and which filter sets
 * they have kept, are questions about them rather than about the dashboard
 * they happen to be standing in.
 */
Route::middleware(['auth', 'password.owned'])->group(function () {
    Route::get('search', SearchController::class)
        ->middleware('throttle:60,1')
        ->name('search');

    Route::get('views', [SavedViewController::class, 'index'])->name('views.index');
    Route::post('views', [SavedViewController::class, 'store'])->name('views.store');
    Route::get('views/{view}', [SavedViewController::class, 'apply'])->whereNumber('view')->name('views.apply');
    Route::delete('views/{view}', [SavedViewController::class, 'destroy'])->whereNumber('view')->name('views.destroy');
});

/*
 * Checking a certificate.
 *
 * Public and unauthenticated on purpose: the person checking is an employer or
 * a college office, and asking them to make an account to verify a document we
 * issued would defeat the point of issuing it.
 */
Route::get('/verify/{code?}', VerificationController::class)->name('verify');

/*
 * The design system gallery.
 *
 * Available outside production only. It is a development tool, and leaving it
 * reachable on the live site would expose a page nobody is maintaining for an
 * audience.
 */
if (! app()->isProduction()) {
    Route::get('/design', DesignController::class)->name('design');
}
