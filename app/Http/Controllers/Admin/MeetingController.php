<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\Project;
use App\Models\User;
use App\Services\Client\Meetings;
use App\Support\Markdown;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Meetings with clients and their minutes (CL11).
 *
 * Minutes are written as a draft, published as a version that never
 * changes, and acknowledged or questioned by the client. A correction is
 * the next version.
 */
class MeetingController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(Meeting::query()->with('client.profile', 'project:id,name', 'minutes'))
            ->searchable(['title'])
            ->defaultSort('-starts_at')
            ->exportName('meetings')
            ->columns([
                Column::make('starts_at', 'When')->sortable(),
                Column::make('title', 'Meeting')->sortable(),
                Column::make('client', 'Client'),
                Column::make('project', 'Project'),
                Column::make('status', 'Status')->sortable(),
                Column::make('minutes', 'Minutes'),
            ])
            ->filters([
                Filter::select('status', Meeting::STATUSES, 'Status')->placeholder('Any'),
            ])
            ->transform(fn (Meeting $meeting) => [
                'id' => $meeting->id,
                'starts_at' => $meeting->starts_at->format('j M Y, g:i a'),
                'title' => $meeting->title,
                'client' => $meeting->client?->organisationName(),
                'project' => $meeting->project?->name ?? '—',
                'status' => $meeting->status,
                'statusLabel' => $meeting->statusLabel(),
                'minutes' => $this->minutesState($meeting),
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/meetings/Index', ['table' => $table->toArray($request)]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/meetings/Form', ['meeting' => null, ...$this->options($request->integer('client') ?: null, $request->integer('project') ?: null)]);
    }

    public function store(Request $request, Meetings $meetings): RedirectResponse
    {
        $meeting = $meetings->schedule($request->user(), $this->validated($request));

        return redirect()->route('admin.meetings.show', $meeting)->with('success', $meeting->starts_at->isFuture()
            ? 'Scheduled. The client has been told.'
            : 'Recorded. Write the minutes next.');
    }

    public function show(Request $request, Meeting $meeting): Response
    {
        $meeting->load('client.profile', 'project:id,name', 'creator:id,name', 'minutes.publisher:id,name', 'minutes.acknowledger:id,name', 'minutes.disputer:id,name');
        $draft = $meeting->draftMinutes();
        $latest = $meeting->publishedMinutes();

        return Inertia::render('admin/meetings/Show', [
            'meeting' => [
                ...$this->meetingForScreen($meeting),
                'clientId' => $meeting->client_id,
                'createdBy' => $meeting->creator?->name,
                'cancelReason' => $meeting->cancel_reason,
            ],
            'versions' => $meeting->minutes->filter(fn (MeetingMinutes $minutes) => $minutes->published_at !== null)
                ->map(fn (MeetingMinutes $minutes) => $this->minutesForScreen($minutes))->reverse()->values(),
            // What the editor starts from: the draft, else the last version, else empty.
            'draft' => [
                'exists' => $draft !== null,
                'version' => $draft?->version ?? (($latest?->version ?? 0) + 1),
                'attendees' => ($draft ?? $latest)?->attendees ?? [],
                'body' => ($draft ?? $latest)?->body ?? '',
                'client_actions' => ($draft ?? $latest)?->client_actions ?? [],
                'team_actions' => ($draft ?? $latest)?->team_actions ?? [],
            ],
            'canWriteMinutes' => $meeting->status !== 'cancelled' && $meeting->starts_at->lte(now()->addHours(2)),
        ]);
    }

    public function edit(Meeting $meeting): Response
    {
        abort_unless($meeting->status === 'scheduled', 404);

        return Inertia::render('admin/meetings/Form', [
            'meeting' => [
                'id' => $meeting->id,
                'client_id' => $meeting->client_id,
                'project_id' => $meeting->project_id,
                'title' => $meeting->title,
                'agenda' => $meeting->agenda,
                'starts_at' => $meeting->starts_at->format('Y-m-d\TH:i'),
                'duration_minutes' => $meeting->duration_minutes,
                'link' => $meeting->link,
                'location' => $meeting->location,
            ],
            ...$this->options($meeting->client_id, $meeting->project_id),
        ]);
    }

    public function update(Request $request, Meeting $meeting, Meetings $meetings): RedirectResponse
    {
        try {
            $meetings->reschedule($meeting, $request->user(), $this->validated($request));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.meetings.show', $meeting)->with('success', 'Saved. If the time or place moved, the client has been told.');
    }

    public function cancel(Request $request, Meeting $meeting, Meetings $meetings): RedirectResponse
    {
        $validated = $this->validatedInput($request, ['reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $meetings->cancel($meeting, $request->user(), $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Cancelled.');
    }

    public function saveMinutes(Request $request, Meeting $meeting, Meetings $meetings): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'attendees' => ['required', 'array', 'min:1', 'max:30'],
            'attendees.*' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:20000'],
            'client_actions' => ['array', 'max:20'],
            'client_actions.*.title' => ['required', 'string', 'max:160'],
            'client_actions.*.due_on' => ['nullable', 'date'],
            'team_actions' => ['array', 'max:20'],
            'team_actions.*.text' => ['required', 'string', 'max:300'],
            'team_actions.*.owner' => ['nullable', 'string', 'max:120'],
        ], [
            'attendees.required' => 'List who was there.',
            'attendees.min' => 'List who was there.',
            'body.required' => 'Write what was discussed and decided.',
            'client_actions.*.title.required' => 'Fill in or remove the empty action.',
            'team_actions.*.text.required' => 'Fill in or remove the empty action.',
        ]);

        $data = [
            'attendees' => $validated['attendees'],
            'body' => $validated['body'],
            'client_actions' => collect($validated['client_actions'])->map(fn (array $action) => ['title' => trim($action['title']), 'due_on' => $action['due_on'] ?? null])->all(),
            'team_actions' => collect($validated['team_actions'])->map(fn (array $action) => ['text' => trim($action['text']), 'owner' => filled($action['owner'] ?? null) ? trim($action['owner']) : null])->all(),
        ];

        try {
            $meetings->saveDraft($meeting, $request->user(), $data);

            if ($request->boolean('publish')) {
                $meetings->publish($meeting->fresh(), $request->user());

                return back()->with('success', 'Published. The client has been asked to acknowledge them.');
            }
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Draft saved. The client cannot see it until you publish it.');
    }

    /* -------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    public static function meetingForScreen(Meeting $meeting): array
    {
        return [
            'id' => $meeting->id,
            'title' => $meeting->title,
            'agenda' => $meeting->agenda,
            'when' => $meeting->whenLabel(),
            'startsAt' => $meeting->starts_at->toIso8601String(),
            'past' => $meeting->starts_at->isPast(),
            'duration' => $meeting->duration_minutes,
            'link' => $meeting->link,
            'location' => $meeting->location,
            'status' => $meeting->status,
            'statusLabel' => $meeting->statusLabel(),
            'client' => $meeting->client?->organisationName(),
            'project' => $meeting->project ? ['id' => $meeting->project->id, 'name' => $meeting->project->name] : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function minutesForScreen(MeetingMinutes $minutes): array
    {
        return [
            'id' => $minutes->id,
            'version' => $minutes->version,
            'attendees' => $minutes->attendees ?? [],
            'html' => Markdown::html($minutes->body),
            'clientActions' => collect($minutes->client_actions ?? [])->map(fn (array $action) => [
                'title' => $action['title'],
                'due' => filled($action['due_on'] ?? null) ? Carbon::parse($action['due_on'])->format('j M Y') : null,
            ])->all(),
            'teamActions' => $minutes->team_actions ?? [],
            'publishedAt' => $minutes->published_at?->format('j M Y, g:i a'),
            'publishedBy' => $minutes->publisher?->name,
            'acknowledgedAt' => $minutes->acknowledged_at?->format('j M Y, g:i a'),
            'acknowledgedBy' => $minutes->acknowledger?->name,
            'disputedAt' => $minutes->disputed_at?->format('j M Y, g:i a'),
            'disputedBy' => $minutes->disputer?->name,
            'disputeNote' => $minutes->dispute_note,
        ];
    }

    protected function minutesState(Meeting $meeting): string
    {
        $latest = $meeting->publishedMinutes();

        return match (true) {
            $latest === null => $meeting->draftMinutes() ? 'Draft' : '—',
            $latest->disputed_at !== null => 'Questioned',
            $latest->acknowledged_at !== null => 'Acknowledged',
            default => 'Waiting for the client',
        };
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $validated = $this->validatedInput($request, [
            'client_id' => ['required', 'integer', User::clientAccountRule()],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'title' => ['required', 'string', 'max:160'],
            'agenda' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'link' => ['nullable', 'url:https', 'max:255'],
            'location' => ['nullable', 'string', 'max:160'],
        ], [
            'link.url' => 'A meeting link must be an https:// address.',
        ]);

        if ($validated['project_id'] && (int) Project::query()->whereKey($validated['project_id'])->value('client_id') !== (int) $validated['client_id']) {
            throw ValidationException::withMessages(['project_id' => 'That project belongs to another client.']);
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    protected function options(?int $clientId, ?int $projectId): array
    {
        return [
            'clients' => User::query()->clientAccounts()->with('profile')->orderBy('name')->get()
                ->map(fn (User $client) => ['value' => $client->id, 'label' => $client->organisationName(), 'description' => $client->email]),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name', 'client_id'])
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => $project->name, 'clientId' => $project->client_id]),
            'preselectedClient' => $clientId,
            'preselectedProject' => $projectId,
        ];
    }
}
