<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BatchController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\CollegeController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\LeadController;
use App\Http\Controllers\Admin\PeopleController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\ProposalController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SolutionController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SupportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin panel
|--------------------------------------------------------------------------
|
| Everything here needs the admin role. Permissions narrow it further for staff
| accounts, since a full administrator holds every permission implicitly and a
| sub admin should be able to run the leads inbox without also being handed
| billing.
|
*/

Route::middleware(['auth', 'password.owned', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        /* ------------------------------------------------------------ leads */
        Route::middleware('permission:leads.view')->group(function () {
            Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
            Route::get('leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
            Route::put('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
            Route::post('leads/{lead}/notes', [LeadController::class, 'addNote'])->name('leads.notes.store');
            Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');
        });

        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])
            ->middleware('permission:leads.convert')
            ->name('leads.convert');

        /* ----------------------------------------------------------- people */
        Route::middleware('permission:clients.view,students.view')->group(function () {
            Route::get('{role}s', [PeopleController::class, 'index'])
                ->whereIn('role', ['client', 'student'])
                ->name('people.index');
            Route::get('{role}s/new', [PeopleController::class, 'create'])
                ->whereIn('role', ['client', 'student'])
                ->name('people.create');
            Route::post('{role}s', [PeopleController::class, 'store'])
                ->whereIn('role', ['client', 'student'])
                ->name('people.store');
            Route::get('{role}s/{user}', [PeopleController::class, 'show'])
                ->whereIn('role', ['client', 'student'])
                ->name('people.show');
            Route::put('{role}s/{user}', [PeopleController::class, 'update'])
                ->whereIn('role', ['client', 'student'])
                ->name('people.update');
            Route::post('{role}s/{user}/resend-credentials', [PeopleController::class, 'resendCredentials'])
                ->whereIn('role', ['client', 'student'])
                ->middleware('throttle:10,1')
                ->name('people.resend');
            Route::put('{role}s/{user}/status', [PeopleController::class, 'setStatus'])
                ->whereIn('role', ['client', 'student'])
                ->name('people.status');
        });

        /* -------------------------------------------------------- catalogue */
        Route::middleware('permission:catalogue.view')->group(function () {
            Route::get('solutions', [SolutionController::class, 'index'])->name('solutions.index');
            Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
            Route::get('batches', [BatchController::class, 'index'])->name('batches.index');
            Route::get('services', [ServiceController::class, 'index'])->name('services.index');
            Route::get('content', [ContentController::class, 'index'])->name('content.index');
        });

        Route::middleware('permission:catalogue.manage')->group(function () {
            Route::get('solutions/new', [SolutionController::class, 'create'])->name('solutions.create');
            Route::post('solutions', [SolutionController::class, 'store'])->name('solutions.store');
            Route::get('solutions/{solution}/edit', [SolutionController::class, 'edit'])->name('solutions.edit');
            Route::put('solutions/{solution}', [SolutionController::class, 'update'])->name('solutions.update');
            Route::post('solutions/{solution}/publish', [SolutionController::class, 'togglePublished'])->name('solutions.publish');
            Route::delete('solutions/{solution}', [SolutionController::class, 'destroy'])->name('solutions.destroy');

            Route::get('courses/new', [CourseController::class, 'create'])->name('courses.create');
            Route::post('courses', [CourseController::class, 'store'])->name('courses.store');
            Route::get('courses/{course}/edit', [CourseController::class, 'edit'])->name('courses.edit');
            Route::put('courses/{course}', [CourseController::class, 'update'])->name('courses.update');
            Route::post('courses/{course}/publish', [CourseController::class, 'togglePublished'])->name('courses.publish');
            Route::delete('courses/{course}', [CourseController::class, 'destroy'])->name('courses.destroy');

            Route::get('batches/new', [BatchController::class, 'create'])->name('batches.create');
            Route::post('batches', [BatchController::class, 'store'])->name('batches.store');
            Route::get('batches/{batch}/edit', [BatchController::class, 'edit'])->name('batches.edit');
            Route::put('batches/{batch}', [BatchController::class, 'update'])->name('batches.update');
            Route::delete('batches/{batch}', [BatchController::class, 'destroy'])->name('batches.destroy');

            Route::get('services/new', [ServiceController::class, 'create'])->name('services.create');
            Route::post('services', [ServiceController::class, 'store'])->name('services.store');
            Route::get('services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
            Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');
            Route::delete('services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

            Route::post('content/faqs', [ContentController::class, 'storeFaq'])->name('content.faqs.store');
            Route::put('content/faqs/{faq}', [ContentController::class, 'updateFaq'])->name('content.faqs.update');
            Route::delete('content/faqs/{faq}', [ContentController::class, 'destroyFaq'])->name('content.faqs.destroy');

            Route::post('content/testimonials', [ContentController::class, 'storeTestimonial'])->name('content.testimonials.store');
            Route::put('content/testimonials/{testimonial}', [ContentController::class, 'updateTestimonial'])->name('content.testimonials.update');
            Route::delete('content/testimonials/{testimonial}', [ContentController::class, 'destroyTestimonial'])->name('content.testimonials.destroy');
        });

        /* --------------------------------------------------------- delivery */
        Route::middleware('permission:projects.view')->group(function () {
            Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('projects/{project}', [ProjectController::class, 'show'])
                ->whereNumber('project')
                ->name('projects.show');
        });

        Route::middleware('permission:projects.manage')->group(function () {
            Route::get('projects/new', [ProjectController::class, 'create'])->name('projects.create');
            Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::get('projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
            Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
            Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

            Route::post('projects/{project}/milestones', [ProjectController::class, 'storeMilestone'])->name('projects.milestones.store');
            Route::put('projects/{project}/milestones/{milestone}', [ProjectController::class, 'updateMilestone'])->name('projects.milestones.update');
            Route::post('projects/{project}/milestones/{milestone}/complete', [ProjectController::class, 'completeMilestone'])->name('projects.milestones.complete');
            Route::delete('projects/{project}/milestones/{milestone}', [ProjectController::class, 'destroyMilestone'])->name('projects.milestones.destroy');

            Route::post('projects/{project}/updates', [ProjectController::class, 'storeUpdate'])->name('projects.updates.store');
            Route::delete('projects/{project}/updates/{update}', [ProjectController::class, 'destroyUpdate'])->name('projects.updates.destroy');
        });

        /* -------------------------------------------------------- proposals */
        Route::middleware('permission:proposals.manage')->group(function () {
            Route::get('proposals', [ProposalController::class, 'index'])->name('proposals.index');
            Route::get('proposals/new', [ProposalController::class, 'create'])->name('proposals.create');
            Route::post('proposals', [ProposalController::class, 'store'])->name('proposals.store');
            Route::get('proposals/{proposal}', [ProposalController::class, 'show'])
                ->whereNumber('proposal')
                ->name('proposals.show');
            Route::put('proposals/{proposal}', [ProposalController::class, 'update'])->name('proposals.update');
            Route::post('proposals/{proposal}/send', [ProposalController::class, 'send'])->name('proposals.send');
            Route::post('proposals/{proposal}/revise', [ProposalController::class, 'revise'])->name('proposals.revise');
            Route::post('proposals/{proposal}/withdraw', [ProposalController::class, 'withdraw'])->name('proposals.withdraw');
            Route::delete('proposals/{proposal}', [ProposalController::class, 'destroy'])->name('proposals.destroy');

            Route::put('proposals/{proposal}/quotation', [ProposalController::class, 'updateQuotation'])->name('proposals.quotation.update');
            Route::post('proposals/{proposal}/items', [ProposalController::class, 'storeItem'])->name('proposals.items.store');
            Route::put('proposals/{proposal}/items/{item}', [ProposalController::class, 'updateItem'])->name('proposals.items.update');
            Route::delete('proposals/{proposal}/items/{item}', [ProposalController::class, 'destroyItem'])->name('proposals.items.destroy');
        });

        /* -------------------------------------------------------- documents */
        Route::middleware('permission:documents.manage')->group(function () {
            Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
            Route::put('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
            Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
            Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
            Route::post('document-folders', [DocumentController::class, 'storeFolder'])->name('documents.folders.store');
        });

        /* ---------------------------------------------- support and contracts */
        Route::middleware('permission:support.manage')->group(function () {
            Route::get('tickets', [SupportController::class, 'index'])->name('tickets.index');
            Route::get('tickets/{ticket}', [SupportController::class, 'show'])->name('tickets.show');
            Route::post('tickets/{ticket}/reply', [SupportController::class, 'reply'])->name('tickets.reply');
            Route::put('tickets/{ticket}', [SupportController::class, 'update'])->name('tickets.update');

            Route::get('contracts', [SupportController::class, 'contracts'])->name('contracts.index');
            Route::get('contracts/new', [SupportController::class, 'createContract'])->name('contracts.create');
            Route::post('contracts', [SupportController::class, 'storeContract'])->name('contracts.store');
            Route::get('contracts/{contract}/edit', [SupportController::class, 'editContract'])->name('contracts.edit');
            Route::put('contracts/{contract}', [SupportController::class, 'updateContract'])->name('contracts.update');
            Route::delete('contracts/{contract}', [SupportController::class, 'destroyContract'])->name('contracts.destroy');
        });

        /* ----------------------------------------------- money, both lines */
        Route::middleware('permission:billing.view')->group(function () {
            Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
            Route::get('billing/{payment}', [BillingController::class, 'show'])
                ->whereNumber('payment')
                ->name('billing.show');
            Route::get('invoices', [BillingController::class, 'invoices'])->name('invoices.index');
            Route::get('invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])->name('invoices.pdf');
        });

        Route::middleware('permission:billing.manage')->group(function () {
            Route::get('billing/new', [BillingController::class, 'create'])->name('billing.create');
            Route::post('billing', [BillingController::class, 'store'])->name('billing.store');
            Route::post('billing/{payment}/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
            Route::post('billing/{payment}/offline', [BillingController::class, 'recordOffline'])->name('billing.offline');
        });

        Route::middleware('permission:billing.refund')->group(function () {
            Route::post('transactions/{transaction}/refund', [BillingController::class, 'refund'])->name('billing.refund');
            Route::post('refunds/{refund}/complete', [BillingController::class, 'completeRefund'])->name('billing.refund.complete');
        });

        /* -------------------------------- subscriptions and API key plans */
        Route::middleware('permission:billing.view')->group(function () {
            Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
        });

        Route::middleware('permission:billing.manage')->group(function () {
            Route::post('subscriptions', [SubscriptionController::class, 'store'])->name('subscriptions.store');
            Route::put('subscriptions/{subscription}', [SubscriptionController::class, 'update'])->name('subscriptions.update');
            Route::post('subscriptions/{subscription}/renew', [SubscriptionController::class, 'renew'])->name('subscriptions.renew');
            Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel'])->name('subscriptions.cancel');
        });

        Route::middleware('permission:api.manage')->group(function () {
            Route::get('api-plans', [SubscriptionController::class, 'plans'])->name('api-plans.index');
            Route::post('api-plans', [SubscriptionController::class, 'storePlan'])->name('api-plans.store');
            Route::put('api-plans/{plan}', [SubscriptionController::class, 'updatePlan'])->name('api-plans.update');
            Route::post('api-keys/{apiKey}/revoke', [SubscriptionController::class, 'revokeKey'])->name('api-keys.revoke');
        });

        /* --------------------------------------------------------- colleges */
        Route::middleware('permission:students.view')->group(function () {
            Route::get('colleges', [CollegeController::class, 'index'])->name('colleges.index');
            Route::get('colleges/new', [CollegeController::class, 'create'])->name('colleges.create');
            Route::post('colleges', [CollegeController::class, 'store'])->name('colleges.store');
            Route::get('colleges/{college}/edit', [CollegeController::class, 'edit'])->name('colleges.edit');
            Route::put('colleges/{college}', [CollegeController::class, 'update'])->name('colleges.update');
            Route::delete('colleges/{college}', [CollegeController::class, 'destroy'])->name('colleges.destroy');
        });

        /* ------------------------------------------------- staff and system */
        Route::middleware('permission:staff.manage')->group(function () {
            Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
            Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
            Route::put('staff/{user}/permissions', [StaffController::class, 'updatePermissions'])->name('staff.permissions');
            Route::put('staff/{user}/status', [StaffController::class, 'setStatus'])->name('staff.status');
        });

        Route::middleware('permission:settings.view')->group(function () {
            Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        });

        Route::middleware('permission:settings.manage')->group(function () {
            Route::put('settings/company', [SettingsController::class, 'updateCompany'])->name('settings.company');
            Route::put('settings/invoicing', [SettingsController::class, 'updateInvoicing'])->name('settings.invoicing');
            Route::put('settings/templates/{template}', [SettingsController::class, 'updateTemplate'])->name('settings.templates.update');
        });

        Route::get('audit-log', [AuditLogController::class, 'index'])
            ->middleware('permission:audit.view')
            ->name('audit.index');
    });
