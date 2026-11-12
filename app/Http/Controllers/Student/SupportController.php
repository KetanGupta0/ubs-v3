<?php

namespace App\Http\Controllers\Student;

use App\Enums\Role;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketRaised;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A student's support tickets, as on the old site (decision D2).
 *
 * The same desk the staff already work for clients, without a contract and
 * so without a promised response time. A student sees only their own
 * tickets, and somebody else's is not found rather than refused. Internal
 * staff notes never reach them.
 */
class SupportController extends StudentController
{
    public const CATEGORIES = ['course' => 'A course or class', 'payment' => 'Fees and payments', 'technical' => 'Signing in or the site', 'other' => 'Something else'];

    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(SupportTicket::query()->raisedBy($this->student($request)))
            ->searchable(['subject', 'reference'])
            ->defaultSort('-created_at')
            ->exportName('my-tickets')
            ->columns([
                Column::make('subject', 'Ticket')->sortable(),
                Column::make('status', 'Status')->sortable(),
                Column::make('updated_at', 'Last change')->sortable(),
            ])
            ->filters([
                Filter::multi('status', collect(SupportTicket::STATUSES)->mapWithKeys(fn ($s) => [$s => $this->statusLabel($s)])->all(), 'Status'),
            ])
            ->transform(fn (SupportTicket $ticket) => [
                'id' => $ticket->id,
                'reference' => $ticket->reference,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'statusLabel' => $this->statusLabel($ticket->status),
                'open' => $ticket->isOpen(),
                'updated_at' => $ticket->updated_at?->diffForHumans(),
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('student/support/Index', ['table' => $table->toArray($request)]);
    }

    public function create(): Response
    {
        return Inertia::render('student/support/Create', [
            'categories' => collect(self::CATEGORIES)->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
        ]);

        $ticket = SupportTicket::query()->create([
            'requester_id' => $this->student($request)->id,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'category' => $validated['category'],
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $staff = User::query()->role(Role::Admin)->active()->get()->filter(fn (User $user) => $user->hasPermission('support.manage'));

        if ($staff->isNotEmpty()) {
            Notification::send($staff, new TicketRaised($ticket));
        }

        return redirect()->route('student.support.show', $ticket->id)
            ->with('success', "Ticket {$ticket->reference} raised. You will get a reply here and by email.");
    }

    public function show(Request $request, int $ticket): Response
    {
        $record = $this->own($request, $ticket);

        return Inertia::render('student/support/Show', [
            'ticket' => [
                'id' => $record->id,
                'reference' => $record->reference,
                'subject' => $record->subject,
                'body' => $record->body,
                'category' => self::CATEGORIES[$record->category] ?? $record->category,
                'status' => $record->status,
                'statusLabel' => $this->statusLabel($record->status),
                'open' => $record->isOpen(),
                'at' => $record->created_at->format('j M Y, g:i a'),
                'canReply' => $record->isOpen(),
                'canClose' => $record->status === 'resolved',
            ],
            'messages' => $record->messages()
                ->visibleToClient()
                ->with('author:id,name,role')
                ->get()
                ->map(fn (TicketMessage $message) => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'author' => $message->author?->name ?? 'Unboundbyte',
                    'fromUs' => $message->author?->isAdmin() ?? true,
                    'at' => $message->created_at->format('j M Y, g:i a'),
                    'ago' => $message->created_at->diffForHumans(),
                ]),
        ]);
    }

    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $record = $this->own($request, $ticket);
        abort_unless($record->isOpen(), 422, 'This ticket is closed.');

        $validated = $this->validatedInput($request, ['body' => ['required', 'string', 'max:5000']]);

        $record->messages()->create(['author_id' => $request->user()->id, 'body' => $validated['body']]);

        // A reply puts the ball back with us, whatever it was before.
        if ($record->status === 'waiting_on_client') {
            $record->forceFill(['status' => 'in_progress'])->save();
        }

        return back()->with('success', 'Reply sent.');
    }

    public function close(Request $request, int $ticket): RedirectResponse
    {
        $record = $this->own($request, $ticket);
        $validated = $this->validatedInput($request, ['satisfaction' => ['nullable', 'integer', 'min:1', 'max:5']]);

        $record->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
            'resolved_at' => $record->resolved_at ?? now(),
            'satisfaction' => $validated['satisfaction'] ?? null,
        ])->save();

        return back()->with('success', 'Ticket closed. Thank you.');
    }

    /** Only the student's own: anybody else's is not found, which confirms nothing. */
    protected function own(Request $request, int $ticket): SupportTicket
    {
        return SupportTicket::query()->raisedBy($this->student($request))->whereKey($ticket)->firstOrFail();
    }

    protected function statusLabel(string $status): string
    {
        return $status === 'waiting_on_client' ? 'Waiting for you' : str($status)->replace('_', ' ')->ucfirst()->toString();
    }
}
