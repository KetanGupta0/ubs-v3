<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailSuppression;
use App\Services\Mail\Suppressions;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Addresses that get no greetings or announcements (§8): those who stopped
 * them, and those we stopped because mail bounced or was marked as spam.
 * Until a mail provider reports bounces (X8), a bounce is added here by hand.
 */
class SuppressionController extends Controller
{
    public function index(Request $request): Response|SymfonyResponse
    {
        $table = Table::for(MailSuppression::query()->with('addedBy:id,name'))
            ->searchable(['email'])
            ->defaultSort('-created_at')
            ->exportName('do-not-mail')
            ->columns([
                Column::make('email', 'Address')->sortable(),
                Column::make('reason', 'Why')->sortable(),
                Column::make('note', 'Note'),
                Column::make('created_at', 'Since')->sortable(),
            ])
            ->filters([
                Filter::select('reason', MailSuppression::REASONS, 'Why')->placeholder('Any reason'),
            ])
            ->transform(fn (MailSuppression $suppression) => [
                'id' => $suppression->id,
                'email' => $suppression->email,
                'reason' => MailSuppression::REASONS[$suppression->reason] ?? $suppression->reason,
                'note' => $suppression->note ?: ($suppression->addedBy ? "Added by {$suppression->addedBy->name}" : '—'),
                'created_at' => $suppression->created_at->format('j M Y'),
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/mail/Suppressions', [
            'table' => $table->toArray($request),
            'reasons' => collect(MailSuppression::REASONS)->except('unsubscribed')->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request, Suppressions $suppressions): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'email' => ['required', 'email', 'max:255'],
            'reason' => ['required', Rule::in(['bounced', 'complained', 'manual'])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $suppressions->suppress($validated['email'], $validated['reason'], by: $request->user(), note: $validated['note']);

        return back()->with('success', 'Added. No greetings or announcements go to it from now on.');
    }

    public function destroy(Request $request, MailSuppression $suppression, Suppressions $suppressions): RedirectResponse
    {
        $suppressions->allow($suppression->email, $request->user());

        return back()->with('success', "{$suppression->email} can be sent greetings and announcements again.");
    }
}
