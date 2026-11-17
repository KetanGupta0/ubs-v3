<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Client\FeedbackController as ClientFeedback;
use App\Http\Controllers\Controller;
use App\Models\FeedbackItem;
use App\Services\Client\StagingFeedback;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Staging feedback from every client, for our side to work through (CL13). */
class FeedbackController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(FeedbackItem::query()->with('client.profile', 'project:id,name', 'milestone:id,title'))
            ->searchable(['title'])
            ->defaultSort('-updated_at')
            ->exportName('staging-feedback')
            ->columns([
                Column::make('title', 'Feedback')->sortable(),
                Column::make('project', 'Project'),
                Column::make('client', 'Client'),
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
                'project' => $item->project?->name,
                'client' => $item->client?->organisationName(),
                'kind' => $item->kindLabel(),
                'status' => $item->status,
                'statusLabel' => FeedbackItem::statusLabel($item->status),
                'milestone' => $item->milestone?->title ?? '—',
                'updated_at' => $item->updated_at->format('j M Y'),
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/feedback/Index', ['table' => $table->toArray($request)]);
    }

    public function show(FeedbackItem $feedback): Response
    {
        $feedback->load('events.user:id,name', 'milestone:id,title', 'reporter:id,name', 'project:id,name,staging_url', 'client.profile');

        return Inertia::render('admin/feedback/Show', [
            'item' => [
                ...ClientFeedback::forScreen($feedback, "/admin/feedback/{$feedback->id}/screenshots"),
                'client' => $feedback->client?->organisationName(),
                'project' => ['id' => $feedback->project_id, 'name' => $feedback->project?->name, 'stagingUrl' => $feedback->project?->staging_url],
            ],
            'moves' => collect(StagingFeedback::STAFF_MOVES)->filter(fn (array $from) => in_array($feedback->status, $from, true))->keys()
                ->map(fn (string $to) => ['value' => $to, 'label' => FeedbackItem::statusLabel($to)])->values(),
        ]);
    }

    public function move(Request $request, FeedbackItem $feedback, StagingFeedback $service): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'to' => ['required', Rule::in(array_keys(StagingFeedback::STAFF_MOVES))],
            'note' => ['nullable', 'string', 'max:3000'],
        ]);

        try {
            $service->move($feedback, $request->user(), $validated['to'], $validated['note']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return back()->with('success', 'Updated.');
    }

    public function comment(Request $request, FeedbackItem $feedback, StagingFeedback $service): RedirectResponse
    {
        $validated = $this->validatedInput($request, ['body' => ['required', 'string', 'max:3000']]);

        try {
            $service->comment($feedback, $request->user(), $validated['body']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['body' => $e->getMessage()]);
        }

        return back()->with('success', 'Added. The person who reported it has been told.');
    }

    public function screenshot(FeedbackItem $feedback, int $index): HttpResponse
    {
        return ClientFeedback::serveScreenshot($feedback, $index);
    }
}
