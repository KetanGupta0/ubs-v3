<?php

use App\Http\Controllers\DesignController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/marketing.php';
require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/client.php';
require __DIR__.'/student.php';

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
