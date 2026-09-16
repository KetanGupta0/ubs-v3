<?php

use App\Http\Controllers\Marketing\HomeController;
use App\Http\Controllers\Marketing\InternshipController;
use App\Http\Controllers\Marketing\LeadController;
use App\Http\Controllers\Marketing\PageController;
use App\Http\Controllers\Marketing\ServiceController;
use App\Http\Controllers\Marketing\SitemapController;
use App\Http\Controllers\Marketing\SolutionController;
use App\Http\Controllers\Marketing\TrainingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public site
|--------------------------------------------------------------------------
|
| Open to everyone. Catalogue filtering lives in the query string so a filtered
| view can be shared, and every page carries its own metadata and structured
| data rather than inheriting a generic one.
|
*/

Route::get('/', HomeController::class)->name('home');

Route::get('/solutions', [SolutionController::class, 'index'])->name('solutions.index');
Route::get('/solutions/{solution}', [SolutionController::class, 'show'])->name('solutions.show');

Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show');

Route::get('/training', [TrainingController::class, 'index'])->name('training.index');
Route::get('/training/{course}', [TrainingController::class, 'show'])->name('training.show');

/*
 * Internships are listed separately from training. They share a table, because
 * structurally they are the same object, but they are a different product sold
 * to a different person and the two listings must not bleed into each other.
 */
Route::get('/internships', [InternshipController::class, 'index'])->name('internships.index');
Route::get('/internships/{course}', [InternshipController::class, 'show'])->name('internships.show');
Route::get('/for-colleges', [InternshipController::class, 'forColleges'])->name('for-colleges');

Route::get('/about', [PageController::class, 'about'])->name('about');
Route::get('/process', [PageController::class, 'process'])->name('process');
Route::get('/technology', [PageController::class, 'technology'])->name('technology');
Route::get('/faq', [PageController::class, 'faq'])->name('faq');
Route::get('/contact', [PageController::class, 'contact'])->name('contact');
Route::get('/legal/{document}', [PageController::class, 'legal'])->name('legal');

// Tighter than the form needs, because this endpoint writes a row and sends
// two messages every time it succeeds.
Route::post('/enquiries', [LeadController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('enquiries.store');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
