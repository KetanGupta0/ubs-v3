<?php

use App\Http\Controllers\Client\ApiKeyController;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\DocumentController;
use App\Http\Controllers\Client\PaymentController;
use App\Http\Controllers\Client\ProjectController;
use App\Http\Controllers\Client\ProposalController;
use App\Http\Controllers\Client\ReportController;
use App\Http\Controllers\Client\SubscriptionController;
use App\Http\Controllers\Client\SupportController;
use App\Http\Controllers\Client\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Client portal
|--------------------------------------------------------------------------
|
| Everything a paying client sees, and nothing else.
|
| Records are addressed by plain id rather than by route model binding, because
| every controller here resolves them through the signed in client's own
| relations. Somebody else's project is not found rather than found and then
| refused: a 403 would confirm that the record exists, which is a fact worth
| nothing to them and worth something to whoever is walking the ids.
|
*/

Route::middleware(['auth', 'password.owned', 'role:client'])
    ->prefix('client')
    ->name('client.')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('reports', ReportController::class)->name('reports');

        /* --------------------------------------------------------- projects */
        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::get('projects/{project}', [ProjectController::class, 'show'])
            ->whereNumber('project')
            ->name('projects.show');

        /* -------------------------------------------------------- proposals */
        Route::get('proposals', [ProposalController::class, 'index'])->name('proposals.index');
        Route::get('proposals/{proposal}', [ProposalController::class, 'show'])
            ->whereNumber('proposal')
            ->name('proposals.show');
        Route::post('proposals/{proposal}/respond', [ProposalController::class, 'respond'])
            ->whereNumber('proposal')
            ->name('proposals.respond');

        /* -------------------------------------------------------- documents */
        Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])
            ->whereNumber('document')
            ->name('documents.download');
        Route::get('documents/{document}/preview', [DocumentController::class, 'preview'])
            ->whereNumber('document')
            ->name('documents.preview');
        Route::get('documents/{document}/versions', [DocumentController::class, 'versions'])
            ->whereNumber('document')
            ->name('documents.versions');

        /* ---------------------------------------------- maintenance and SLA */
        Route::get('support', [SupportController::class, 'index'])->name('support.index');
        Route::get('tickets/new', [SupportController::class, 'create'])->name('tickets.create');
        Route::post('tickets', [SupportController::class, 'store'])->name('tickets.store');
        Route::get('tickets/{ticket}', [SupportController::class, 'show'])
            ->whereNumber('ticket')
            ->name('tickets.show');
        Route::post('tickets/{ticket}/reply', [SupportController::class, 'reply'])
            ->whereNumber('ticket')
            ->name('tickets.reply');
        Route::post('tickets/{ticket}/close', [SupportController::class, 'close'])
            ->whereNumber('ticket')
            ->name('tickets.close');

        /* --------------------------------------------------------- payments */
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])
            ->whereNumber('payment')
            ->name('payments.show');
        Route::post('payments/{payment}/checkout', [PaymentController::class, 'begin'])
            ->whereNumber('payment')
            ->middleware('throttle:20,1')
            ->name('payments.begin');
        Route::post('payments/{reference}/confirm', [PaymentController::class, 'confirm'])
            ->name('payments.confirm');

        /* ------------------------------------------------------ the ledger */
        Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('invoices/{invoice}', [TransactionController::class, 'invoice'])
            ->whereNumber('invoice')
            ->name('invoices.show');
        Route::get('invoices/{invoice}/pdf', [TransactionController::class, 'invoicePdf'])
            ->whereNumber('invoice')
            ->name('invoices.pdf');
        Route::get('receipts/{reference}', [TransactionController::class, 'receipt'])->name('receipts.show');

        /* ---------------------------------------------------- subscriptions */
        Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::put('subscriptions/{subscription}/auto-renew', [SubscriptionController::class, 'setAutoRenew'])
            ->whereNumber('subscription')
            ->name('subscriptions.auto-renew');

        /* --------------------------------------------------------- API keys */
        Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
        Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
        Route::post('api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate'])
            ->whereNumber('apiKey')
            ->name('api-keys.rotate');
        Route::post('api-keys/{apiKey}/revoke', [ApiKeyController::class, 'revoke'])
            ->whereNumber('apiKey')
            ->name('api-keys.revoke');
    });
