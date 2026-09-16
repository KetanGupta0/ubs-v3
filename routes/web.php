<?php

use App\Http\Controllers\DesignController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

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
