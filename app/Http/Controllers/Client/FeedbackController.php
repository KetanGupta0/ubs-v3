<?php

namespace App\Http\Controllers\Client;

use App\Models\FeedbackItem;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Services\Client\StagingFeedback;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Feedback on the staging preview, from the client's side (CL13). The
 * organisation's technical people and owners report and check fixes;
 * everybody in it can read.
 */
class FeedbackController extends ClientController
{
    public function index(Request $request, int $project): Response|HttpResponse
    {
        $owned = $this->ownProject($request, $project);

        $table = Table::for(FeedbackItem::query()->where('project_id', $owned->id)->with('reporter:id,name', 'milestone:id,title'))
            ->searchable(['title'])
            ->defaultSort('-updated_at')
            ->exportName('feedback')
            ->columns([
                Column::make('title', 'Feedback')->sortable(),
                Column::make('kind', 'Kind'),
                Column::make('status', 'Status')->sortable(),
                Column::make('milestone', 'Holds up'),
                Column::make('updated_at', 'Last change')->sortable(),
            ])
            ->filters([
                Filter::select('status', collect(FeedbackItem::STATUSES)->mapWithKeys(fn (string $status) => [$status => FeedbackItem::statusLabel($status)])->all(), 'Status')->placeholder('Any'),
                Filter::select('kind', FeedbackItem::KINDS, 'Kind')->placeholder('Any'),
            ])
            ->transform(fn (FeedbackItem $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'kind' => $item->kindLabel(),
                'status' => $item->status,
                'statusLabel' => FeedbackItem::statusLabel($item->status),
                'milestone' => $item->milestone?->title ?? '—',
                'updated_at' => $item->updated_at->format('j M Y'),
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('client/projects/Feedback', [
            'project' => ['id' => $owned->id, 'name' => $owned->name, 'code' => $owned->code, 'stagingUrl' => $owned->staging_url],
            'table' => $table->toArray($request),
            'milestones' => $owned->milestones()->whereNull('accepted_at')->get(['id', 'title'])
                ->map(fn (ProjectMilestone $milestone) => ['value' => $milestone->id, 'label' => $milestone->title]),
            'kinds' => collect(FeedbackItem::KINDS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'canReport' => $request->user()->canAsClient('feedback'),
        ]);
    }

    public function store(Request $request, int $project, StagingFeedback $feedback): RedirectResponse
    {
        $this->mayAsClient($request, 'feedback');
        $owned = $this->ownProject($request, $project);

        $validated = $this->validatedInput($request, [
            'kind' => ['required', Rule::in(array_keys(FeedbackItem::KINDS))],
            'title' => ['required', 'string', 'max:160'],
            'details' => ['required', 'string', 'max:5000'],
            'page_url' => ['nullable', 'url:http,https', 'max:255'],
            'milestone_id' => ['nullable', 'integer', Rule::exists('project_milestones', 'id')->where('project_id', $owned->id)],
            'screenshots' => ['array', 'max:3'],
            'screenshots.*' => ['file', 'max:8192', 'mimes:jpg,jpeg,png,webp'],
        ], [
            'details.required' => 'Say what happened and what you expected, so we can find it.',
            'screenshots.max' => 'Attach up to three screenshots.',
            'screenshots.*.mimes' => 'A screenshot must be a JPG, PNG or WebP.',
            'screenshots.*.max' => 'Each screenshot can be up to 8 MB.',
        ]);

        $item = $feedback->report($owned, $request->user(), $validated, $request->file('screenshots', []));

        return redirect("/client/projects/{$owned->id}/feedback/{$item->id}")->with('success', 'Reported. We will let you know as it moves.');
    }

    public function show(Request $request, int $project, int $item): Response
    {
        $record = $this->ownItem($request, $project, $item);
        $record->load('events.user:id,name', 'milestone:id,title', 'reporter:id,name');
        $mayAct = $request->user()->canAsClient('feedback');

        return Inertia::render('client/projects/FeedbackItem', [
            'project' => ['id' => $record->project->id, 'name' => $record->project->name],
            'item' => self::forScreen($record, "/client/projects/{$project}/feedback/{$record->id}/screenshots"),
            'canConfirm' => $mayAct && $record->status === 'fixed',
            'canComment' => $mayAct && in_array($record->status, FeedbackItem::OPEN, true),
        ]);
    }

    public function confirm(Request $request, int $project, int $item, StagingFeedback $feedback): RedirectResponse
    {
        $this->mayAsClient($request, 'feedback');
        $record = $this->ownItem($request, $project, $item);
        $validated = $this->validatedInput($request, ['works' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:3000']]);

        try {
            $feedback->confirm($record, $request->user(), (bool) $validated['works'], $validated['note']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return back()->with('success', $validated['works'] ? 'Thank you. Marked done.' : 'Sent back to us. We will look again.');
    }

    public function comment(Request $request, int $project, int $item, StagingFeedback $feedback): RedirectResponse
    {
        $this->mayAsClient($request, 'feedback');
        $record = $this->ownItem($request, $project, $item);
        $validated = $this->validatedInput($request, ['body' => ['required', 'string', 'max:3000']]);

        try {
            $feedback->comment($record, $request->user(), $validated['body']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['body' => $e->getMessage()]);
        }

        return back()->with('success', 'Added.');
    }

    public function screenshot(Request $request, int $project, int $item, int $index): HttpResponse
    {
        $record = $this->ownItem($request, $project, $item);

        return self::serveScreenshot($record, $index);
    }

    /* -------------------------------------------------------------------- */

    /** @return array<string, mixed> What both sides' screens show of an item. */
    public static function forScreen(FeedbackItem $item, string $screenshotBase): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'details' => $item->details,
            'kind' => $item->kindLabel(),
            'status' => $item->status,
            'statusLabel' => FeedbackItem::statusLabel($item->status),
            'pageUrl' => $item->page_url,
            'milestone' => $item->milestone?->title,
            'reportedBy' => $item->reporter?->name,
            'reportedAt' => $item->created_at->format('j M Y, g:i a'),
            'screenshots' => collect($item->screenshots ?? [])->keys()->map(fn (int $index) => "{$screenshotBase}/{$index}")->values(),
            'events' => $item->events->map(fn ($event) => [
                'status' => $event->status,
                'statusLabel' => $event->status ? FeedbackItem::statusLabel($event->status) : null,
                'by' => $event->user?->name,
                'at' => $event->created_at->format('j M Y, g:i a'),
                'body' => $event->body,
            ])->values(),
        ];
    }

    public static function serveScreenshot(FeedbackItem $item, int $index): HttpResponse
    {
        $path = ($item->screenshots ?? [])[$index] ?? null;
        abort_unless($path !== null && Storage::disk('private')->exists($path), 404);

        return response(Storage::disk('private')->get($path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function ownProject(Request $request, int $project): Project
    {
        return $this->own($request, Project::query()->forClient($this->client($request)), $project);
    }

    protected function ownItem(Request $request, int $project, int $item): FeedbackItem
    {
        $owned = $this->ownProject($request, $project);

        return FeedbackItem::query()->where('project_id', $owned->id)->whereKey($item)->firstOrFail()->setRelation('project', $owned);
    }
}
