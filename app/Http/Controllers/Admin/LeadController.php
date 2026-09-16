<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use App\Services\Admin\AccountCreator;
use App\Services\Admin\Auditor;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The enquiries inbox.
 *
 * Built on the shared table, so search, filtering, sorting and export behave
 * exactly as they do everywhere else in the application.
 */
class LeadController extends Controller
{
    public const STATUSES = ['new', 'contacted', 'qualified', 'converted', 'lost'];

    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->table();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/leads/Index', [
            'table' => $table->toArray($request),
            'statuses' => self::STATUSES,
            'assignees' => $this->assignees(),
            'counts' => [
                'all' => Lead::query()->count(),
                'open' => Lead::query()->open()->count(),
                'unassigned' => Lead::query()->open()->whereNull('assigned_to')->count(),
            ],
        ]);
    }

    public function show(Lead $lead): Response
    {
        $lead->load(['solution:id,title,slug', 'service:id,title,slug', 'course:id,title,slug,type', 'assignee:id,name', 'convertedUser:id,name,email']);

        return Inertia::render('admin/leads/Show', [
            'lead' => [
                'id' => $lead->id,
                'reference' => $lead->reference,
                'name' => $lead->name,
                'email' => $lead->email,
                'mobile' => $lead->mobile,
                'company' => $lead->company,
                'collegeName' => $lead->college_name,
                'studentCount' => $lead->student_count,
                'message' => $lead->message,
                'interest' => $lead->interest,
                'subject' => $lead->subject(),
                'budgetBand' => $lead->budget_band,
                'timeline' => $lead->timeline,
                'sourcePage' => $lead->source_page,
                'status' => $lead->status,
                'assignedTo' => $lead->assigned_to,
                'assigneeName' => $lead->assignee?->name,
                'convertedUser' => $lead->convertedUser ? [
                    'id' => $lead->convertedUser->id,
                    'name' => $lead->convertedUser->name,
                    'email' => $lead->convertedUser->email,
                ] : null,
                'receivedAt' => $lead->created_at->format('j M Y, g:i a'),
                'receivedAgo' => $lead->created_at->diffForHumans(),
                'ipAddress' => $lead->ip_address,
            ],
            'notes' => $lead->notes()
                ->with('author:id,name')
                ->latest('id')
                ->get()
                ->map(fn (LeadNote $note) => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'author' => $note->author?->name ?? 'System',
                    'at' => $note->created_at->diffForHumans(),
                ]),
            'statuses' => self::STATUSES,
            'assignees' => $this->assignees(),
        ]);
    }

    public function update(Request $request, Lead $lead, Auditor $auditor): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', Role::Admin->value)],
        ]);

        $lead->fill($validated);
        $auditor->updated($lead, label: $lead->reference);
        $lead->save();

        return back()->with('success', 'Enquiry updated.');
    }

    public function addNote(Request $request, Lead $lead): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $lead->notes()->create([
            'author_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        return back()->with('success', 'Note added.');
    }

    /**
     * Turn an enquiry into a client account.
     *
     * The enquiry keeps a link to the account it became, so a year later the
     * original message is still attached to the client it produced.
     */
    public function convert(Request $request, Lead $lead, AccountCreator $creator, Auditor $auditor): RedirectResponse
    {
        if ($lead->converted_user_id) {
            return back()->with('info', 'This enquiry has already been converted.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'mobile' => ['nullable', 'string', 'regex:/^\+\d{10,15}$/', Rule::unique('users', 'mobile')],
            'company' => ['nullable', 'string', 'max:160'],
            'role' => ['required', Rule::in([Role::Client->value, Role::Student->value])],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'email.unique' => 'An account already uses that email address.',
            'mobile.unique' => 'An account already uses that mobile number.',
        ]);

        $result = $creator->create(
            [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'mobile' => $validated['mobile'] ?? null,
                'company' => $validated['company'] ?? null,
            ],
            Role::from($validated['role']),
            $validated['note'] ?? null,
        );

        $lead->forceFill([
            'converted_user_id' => $result['user']->id,
            'status' => 'converted',
        ])->save();

        $auditor->action('lead.converted', $lead, [
            'user_id' => $result['user']->id,
            'role' => $validated['role'],
        ], $lead->reference);

        $failed = collect($result['deliveries'])->where('status', 'failed');

        return redirect()
            ->route('admin.people.show', ['role' => $validated['role'], 'user' => $result['user']])
            ->with($failed->isEmpty() ? 'success' : 'warning', $failed->isEmpty()
                ? 'Account created. Credentials sent to '.$result['user']->email.'.'
                : 'Account created, but the welcome message could not be sent. Use resend on this page.');
    }

    public function destroy(Lead $lead, Auditor $auditor): RedirectResponse
    {
        $auditor->deleted($lead, $lead->reference);
        $lead->delete();

        return redirect()->route('admin.leads.index')->with('success', 'Enquiry removed.');
    }

    protected function table(): Table
    {
        return Table::for(Lead::query()->with(['assignee:id,name', 'solution:id,title', 'service:id,title', 'course:id,title,type']))
            ->searchable(['reference', 'name', 'email', 'mobile', 'company', 'college_name', 'message'])
            ->defaultSort('-created_at')
            ->exportName('enquiries')
            ->columns([
                Column::make('reference', 'Ref')->sortable()->width('7rem'),
                Column::make('name', 'From')->sortable(),
                Column::make('subject', 'About'),
                Column::make('contact', 'Contact'),
                Column::make('status', 'Status')->sortable(),
                Column::make('assignee', 'Assigned to'),
                Column::make('waiting', 'Waiting')->hidden(),
                Column::make('created_at', 'Received')->sortable(),
            ])
            ->filters([
                Filter::select('status', self::STATUSES, 'Status')->placeholder('Any status'),
                Filter::select('interest', ['solution', 'service', 'training', 'internship', 'college', 'general'], 'Interested in')
                    ->placeholder('Anything'),
                Filter::select('assigned_to', $this->assignees()->map(fn ($a) => ['value' => $a['id'], 'label' => $a['name']])->all(), 'Assigned to')
                    ->placeholder('Anyone'),
                Filter::boolean('unassigned', 'Only unassigned')
                    ->using(fn ($query) => $query->whereNull('assigned_to')),
                Filter::dateRange('created_at', 'Received between'),
            ])
            ->transform(fn (Lead $lead) => [
                'id' => $lead->id,
                'reference' => $lead->reference,
                'name' => $lead->name,
                'subject' => $lead->subject(),
                'contact' => $lead->email.($lead->mobile ? ' · '.$lead->mobile : ''),
                'status' => $lead->status,
                'assignee' => $lead->assignee?->name,
                'waiting' => $lead->created_at->diffInDays(now()).' days',
                'created_at' => $lead->created_at->format('j M Y'),
                'isStale' => $lead->status === 'new' && $lead->created_at->lt(now()->subDays(2)),
            ]);
    }

    protected function assignees()
    {
        return User::query()
            ->role(Role::Admin)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);
    }
}
