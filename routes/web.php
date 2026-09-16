<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\DesignController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/marketing.php';
require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/client.php';

/*
 * Role dashboards.
 *
 * Each is gated by role, and by the forced password change, so a client who
 * still holds an administrator generated password cannot reach anything until
 * they have replaced it.
 *
 * Email verification is offered but not required to get in. Locking a paying
 * client out of their own project because they have not clicked a link costs
 * more than it protects.
 */
Route::middleware(['auth', 'password.owned'])->group(function () {
    Route::get('/student', [DashboardController::class, 'student'])
        ->middleware('role:student')
        ->name('student.dashboard');
});

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
