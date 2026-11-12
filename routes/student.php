<?php

use App\Http\Controllers\Student\AssignmentController;
use App\Http\Controllers\Student\BillingController;
use App\Http\Controllers\Student\CatalogueController;
use App\Http\Controllers\Student\ClassController;
use App\Http\Controllers\Student\CourseController;
use App\Http\Controllers\Student\DashboardController;
use App\Http\Controllers\Student\DoubtController;
use App\Http\Controllers\Student\PreferencesController;
use App\Http\Controllers\Student\ProgressController;
use App\Http\Controllers\Student\QuizController;
use App\Http\Controllers\Student\RegistrationController;
use App\Http\Controllers\Student\StudyController;
use App\Http\Controllers\Student\SupportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The learning management system
|--------------------------------------------------------------------------
|
| Everything a student sees. Same rule as the client portal: records are
| addressed by plain id and resolved through this student's own enrolments, so
| a course somebody else is on is not found rather than found and refused.
|
*/

Route::middleware(['auth', 'password.owned', 'role:student'])
    ->prefix('student')
    ->name('student.')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        /* ---------------------------------------------------------- courses */
        Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
        Route::get('courses/{course}', [CourseController::class, 'show'])
            ->whereNumber('course')
            ->name('courses.show');
        Route::post('courses/{course}/review', [CourseController::class, 'review'])
            ->whereNumber('course')
            ->middleware('throttle:10,1')
            ->name('courses.review');
        Route::get('courses/{course}/materials', [CourseController::class, 'materials'])
            ->whereNumber('course')
            ->name('courses.materials');
        Route::get('courses/{course}/materials/{material}', [CourseController::class, 'downloadMaterial'])
            ->whereNumber('course')
            ->whereNumber('material')
            ->name('courses.materials.download');
        Route::get('courses/{course}/lessons/{lesson}', [CourseController::class, 'lesson'])
            ->whereNumber('course')
            ->whereNumber('lesson')
            ->name('lessons.show');
        Route::post('courses/{course}/lessons/{lesson}/complete', [CourseController::class, 'complete'])
            ->whereNumber('course')
            ->whereNumber('lesson')
            ->name('lessons.complete');
        Route::post('courses/{course}/lessons/{lesson}/time', [StudyController::class, 'time'])
            ->whereNumber('course')
            ->whereNumber('lesson')
            ->middleware('throttle:20,1')
            ->name('lessons.time');
        Route::put('courses/{course}/lessons/{lesson}/note', [StudyController::class, 'note'])
            ->whereNumber('course')
            ->whereNumber('lesson')
            ->middleware('throttle:60,1')
            ->name('lessons.note');
        Route::get('notes', [StudyController::class, 'notes'])->name('notes');
        Route::get('preferences', [PreferencesController::class, 'show'])->name('preferences');
        Route::put('preferences', [PreferencesController::class, 'update'])->name('preferences.update');
        Route::post('courses/{course}/lessons/{lesson}/doubts', [DoubtController::class, 'store'])
            ->whereNumber(['course', 'lesson'])
            ->middleware('throttle:10,1')
            ->name('lessons.doubts.store');
        Route::post('courses/{course}/lessons/{lesson}/doubts/{doubt}/reply', [DoubtController::class, 'reply'])
            ->whereNumber(['course', 'lesson', 'doubt'])
            ->middleware('throttle:20,1')
            ->name('lessons.doubts.reply');

        /* ----------------------------------------------------- live classes */
        Route::get('classes', [ClassController::class, 'index'])->name('classes.index');
        Route::get('classes/{session}/join', [ClassController::class, 'join'])
            ->whereNumber('session')
            ->name('classes.join');
        Route::get('attendance', [ClassController::class, 'attendance'])->name('attendance');

        /* ---------------------------------------------------------- quizzes */
        Route::get('quizzes/{quiz}', [QuizController::class, 'show'])
            ->whereNumber('quiz')
            ->name('quizzes.show');
        Route::post('quizzes/{quiz}/start', [QuizController::class, 'start'])
            ->whereNumber('quiz')
            ->name('quizzes.start');
        Route::get('quizzes/{quiz}/attempts/{attempt}', [QuizController::class, 'attempt'])
            ->whereNumber('quiz')
            ->whereNumber('attempt')
            ->name('quizzes.attempt');
        Route::post('quizzes/{quiz}/attempts/{attempt}', [QuizController::class, 'submit'])
            ->whereNumber('quiz')
            ->whereNumber('attempt')
            ->name('quizzes.submit');
        Route::get('quizzes/{quiz}/attempts/{attempt}/review', [QuizController::class, 'review'])
            ->whereNumber('quiz')
            ->whereNumber('attempt')
            ->name('quizzes.review');

        /* ------------------------------------------------------ assignments */
        Route::get('assignments', [AssignmentController::class, 'index'])->name('assignments.index');
        Route::get('assignments/{assignment}', [AssignmentController::class, 'show'])
            ->whereNumber('assignment')
            ->name('assignments.show');
        Route::post('assignments/{assignment}', [AssignmentController::class, 'submit'])
            ->whereNumber('assignment')
            ->name('assignments.submit');
        Route::get('assignments/{assignment}/files/{index}', [AssignmentController::class, 'downloadFile'])
            ->whereNumber('assignment')
            ->whereNumber('index')
            ->name('assignments.files');

        /* ------------------------------------------- results and standing */
        Route::get('results', [ProgressController::class, 'results'])->name('results');
        Route::get('leaderboard', [ProgressController::class, 'leaderboard'])->name('leaderboard');
        Route::get('certificates', [ProgressController::class, 'certificates'])->name('certificates');
        Route::get('certificates/{certificate}/download', [ProgressController::class, 'downloadCertificate'])
            ->whereNumber('certificate')
            ->name('certificates.download');
        Route::get('documents/{document}/download', [ProgressController::class, 'downloadInternshipDocument'])
            ->whereNumber('document')
            ->name('documents.download');
        Route::get('announcements', [ProgressController::class, 'announcements'])->name('announcements');
        Route::get('warnings', [ProgressController::class, 'warnings'])->name('warnings');
        Route::post('warnings/{warning}/acknowledge', [ProgressController::class, 'acknowledgeWarning'])
            ->whereNumber('warning')
            ->name('warnings.acknowledge');

        /* -------------------------------------------------------- enrolling */
        Route::get('catalogue', [CatalogueController::class, 'index'])->name('catalogue');
        Route::post('enrol', [CatalogueController::class, 'enrol'])->name('enrol');

        /* --------------------------------------------------- fees and money */
        // Support tickets, on the same desk staff work for clients (decision D2).
        /* ---------------------------------------- registrations from forms */
        Route::get('registrations', [RegistrationController::class, 'index'])->name('registrations.index');
        Route::post('registrations/{registration}/confirm', [RegistrationController::class, 'confirm'])->whereNumber('registration')->name('registrations.confirm');
        Route::post('registrations/{registration}/decline', [RegistrationController::class, 'decline'])->whereNumber('registration')->name('registrations.decline');

        Route::get('support', [SupportController::class, 'index'])->name('support.index');
        Route::get('support/new', [SupportController::class, 'create'])->name('support.create');
        Route::post('support', [SupportController::class, 'store'])->middleware('throttle:10,1')->name('support.store');
        Route::get('support/{ticket}', [SupportController::class, 'show'])->whereNumber('ticket')->name('support.show');
        Route::post('support/{ticket}/reply', [SupportController::class, 'reply'])->whereNumber('ticket')->name('support.reply');
        Route::post('support/{ticket}/close', [SupportController::class, 'close'])->whereNumber('ticket')->name('support.close');

        Route::get('payments', [BillingController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}', [BillingController::class, 'show'])
            ->whereNumber('payment')
            ->name('payments.show');
        Route::post('payments/{payment}/checkout', [BillingController::class, 'begin'])
            ->whereNumber('payment')
            ->middleware('throttle:20,1')
            ->name('payments.begin');
        Route::post('payments/{reference}/confirm', [BillingController::class, 'confirm'])
            ->name('payments.confirm');
        Route::get('invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])
            ->whereNumber('invoice')
            ->name('invoices.pdf');
        Route::get('receipts/{reference}', [BillingController::class, 'receipt'])->name('receipts.show');
    });
