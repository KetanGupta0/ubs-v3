<?php

use App\Http\Controllers\Client\AgreementController;
use App\Http\Controllers\Client\ApiKeyController;
use App\Http\Controllers\Client\ChangeRequestController;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\DocumentController;
use App\Http\Controllers\Client\FeedbackController;
use App\Http\Controllers\Client\MeetingController;
use App\Http\Controllers\Client\PaymentController;
use App\Http\Controllers\Client\PeopleController;
use App\Http\Controllers\Client\ProjectController;
use App\Http\Controllers\Client\ProposalController;
use App\Http\Controllers\Client\ReportController;
use App\Http\Controllers\Client\SubscriptionController;
use App\Http\Controllers\Client\SupportController;
use App\Http\Controllers\Client\TransactionController;
use App\Http\Controllers\Client\WaitingController;
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
        Route::get('projects/{project}/status-report', [ProjectController::class, 'statusReport'])
            ->whereNumber('project')
            ->name('projects.status-report');
        Route::get('projects/{project}/milestones/{milestone}', [ProjectController::class, 'milestone'])
            ->whereNumber(['project', 'milestone'])
            ->name('projects.milestones.show');
        Route::post('projects/{project}/milestones/{milestone}/accept', [ProjectController::class, 'acceptMilestone'])
            ->whereNumber(['project', 'milestone'])
            ->name('projects.milestones.accept');
        Route::post('projects/{project}/milestones/{milestone}/reject', [ProjectController::class, 'rejectMilestone'])
            ->whereNumber(['project', 'milestone'])
            ->name('projects.milestones.reject');
        Route::post('projects/{project}/changes', [ChangeRequestController::class, 'store'])->whereNumber('project')->name('projects.changes.store');
        Route::get('projects/{project}/changes/{change}', [ChangeRequestController::class, 'show'])->whereNumber(['project', 'change'])->name('projects.changes.show');
        Route::get('projects/{project}/changes/{change}/pdf', [ChangeRequestController::class, 'pdf'])->whereNumber(['project', 'change'])->name('projects.changes.pdf');
        Route::get('projects/{project}/changes/{change}/signed', [ChangeRequestController::class, 'signed'])->whereNumber(['project', 'change'])->name('projects.changes.signed');
        Route::post('projects/{project}/changes/{change}/decline', [ChangeRequestController::class, 'decline'])->whereNumber(['project', 'change'])->name('projects.changes.decline');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('projects/{project}/changes/{change}/sign/code', [ChangeRequestController::class, 'signCode'])->whereNumber(['project', 'change'])->name('projects.changes.sign.code');
            Route::post('projects/{project}/changes/{change}/sign', [ChangeRequestController::class, 'sign'])->whereNumber(['project', 'change'])->name('projects.changes.sign');
        });
        Route::get('projects/{project}/feedback', [FeedbackController::class, 'index'])->whereNumber('project')->name('projects.feedback.index');
        Route::post('projects/{project}/feedback', [FeedbackController::class, 'store'])->whereNumber('project')->middleware('throttle:20,1')->name('projects.feedback.store');
        Route::get('projects/{project}/feedback/{item}', [FeedbackController::class, 'show'])->whereNumber(['project', 'item'])->name('projects.feedback.show');
        Route::get('projects/{project}/feedback/{item}/screenshots/{index}', [FeedbackController::class, 'screenshot'])->whereNumber(['project', 'item', 'index'])->name('projects.feedback.screenshot');
        Route::post('projects/{project}/feedback/{item}/confirm', [FeedbackController::class, 'confirm'])->whereNumber(['project', 'item'])->name('projects.feedback.confirm');
        Route::post('projects/{project}/feedback/{item}/comment', [FeedbackController::class, 'comment'])->whereNumber(['project', 'item'])->name('projects.feedback.comment');
        Route::get('projects/{project}/waiting/{item}', [WaitingController::class, 'show'])
            ->whereNumber(['project', 'item'])
            ->name('projects.waiting.show');
        Route::post('projects/{project}/waiting/{item}/answer', [WaitingController::class, 'answer'])
            ->whereNumber(['project', 'item'])
            ->middleware('throttle:20,1')
            ->name('projects.waiting.answer');

        /* -------------------------------------------------------- proposals */
        Route::get('proposals', [ProposalController::class, 'index'])->name('proposals.index');
        Route::get('proposals/{proposal}', [ProposalController::class, 'show'])
            ->whereNumber('proposal')
            ->name('proposals.show');
        Route::get('proposals/{proposal}/pdf', [ProposalController::class, 'pdf'])
            ->whereNumber('proposal')
            ->name('proposals.pdf');

        /* --------------------------------------------------------- meetings */
        Route::get('meetings', [MeetingController::class, 'index'])->name('meetings.index');
        Route::get('meetings/{meeting}', [MeetingController::class, 'show'])->whereNumber('meeting')->name('meetings.show');
        Route::post('meetings/{meeting}/acknowledge', [MeetingController::class, 'acknowledge'])->whereNumber('meeting')->name('meetings.acknowledge');
        Route::post('meetings/{meeting}/dispute', [MeetingController::class, 'dispute'])->whereNumber('meeting')->name('meetings.dispute');

        /* ------------------------------------------------------- agreements */
        Route::get('agreements', [AgreementController::class, 'index'])->name('agreements.index');
        Route::get('agreements/{agreement}', [AgreementController::class, 'show'])->whereNumber('agreement')->name('agreements.show');
        Route::get('agreements/{agreement}/pdf', [AgreementController::class, 'pdf'])->whereNumber('agreement')->name('agreements.pdf');
        Route::get('agreements/{agreement}/signed', [AgreementController::class, 'signed'])->whereNumber('agreement')->name('agreements.signed');
        Route::get('proposals/{proposal}/signed', [ProposalController::class, 'signed'])->whereNumber('proposal')->name('proposals.signed');

        Route::post('proposals/{proposal}/respond', [ProposalController::class, 'respond'])
            ->whereNumber('proposal')
            ->name('proposals.respond');

        // Signing (CL2): a one time code to the signer's own address, then
        // the signature. Accepting a proposal is signing it.
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('proposals/{proposal}/sign/code', [ProposalController::class, 'signCode'])->whereNumber('proposal')->name('proposals.sign.code');
            Route::post('proposals/{proposal}/sign', [ProposalController::class, 'sign'])->whereNumber('proposal')->name('proposals.sign');
            Route::post('agreements/{agreement}/sign/code', [AgreementController::class, 'signCode'])->whereNumber('agreement')->name('agreements.sign.code');
            Route::post('agreements/{agreement}/sign', [AgreementController::class, 'sign'])->whereNumber('agreement')->name('agreements.sign');
        });

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
        /* ----------------------------------------- people in the organisation */
        Route::get('people', [PeopleController::class, 'index'])->name('people.index');
        Route::post('people', [PeopleController::class, 'store'])->middleware('throttle:20,1')->name('people.store');
        Route::put('people/{member}', [PeopleController::class, 'update'])->whereNumber('member')->name('people.update');
        Route::delete('people/{member}', [PeopleController::class, 'destroy'])->whereNumber('member')->name('people.destroy');
        Route::post('invitations/{member}/accept', [PeopleController::class, 'accept'])->whereNumber('member')->name('invitations.accept');
        Route::post('invitations/{member}/decline', [PeopleController::class, 'decline'])->whereNumber('member')->name('invitations.decline');

        Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
        Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
        Route::post('api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate'])
            ->whereNumber('apiKey')
            ->name('api-keys.rotate');
        Route::post('api-keys/{apiKey}/revoke', [ApiKeyController::class, 'revoke'])
            ->whereNumber('apiKey')
            ->name('api-keys.revoke');
    });
