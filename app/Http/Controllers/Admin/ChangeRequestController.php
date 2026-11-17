<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Services\Client\ChangeRequests;
use App\Services\Client\ClientDocuments;
use App\Support\Money;
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

/** Change requests from our side (CL12): estimate, send, follow up. */
class ChangeRequestController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(ChangeRequest::query()->with('client.profile', 'project:id,name'))
            ->searchable(['number', 'title'])
            ->defaultSort('-created_at')
            ->exportName('change-requests')
            ->columns([
                Column::make('number', 'Number')->sortable(),
                Column::make('title', 'Change')->sortable(),
                Column::make('project', 'Project'),
                Column::make('client', 'Client'),
                Column::make('status', 'Status')->sortable(),
                Column::make('amount', 'Estimate')->sortable(),
            ])
            ->filters([
                Filter::select('status', ChangeRequest::STATUSES, 'Status')->placeholder('Any'),
            ])
            ->transform(fn (ChangeRequest $change) => [
                'id' => $change->id,
                'number' => $change->number,
                'title' => $change->title,
                'project' => $change->project?->name,
                'client' => $change->client?->organisationName(),
                'status' => $change->status,
                'statusLabel' => $change->statusLabel(),
                'amount' => $change->amountLabel() ?? '—',
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/change-requests/Index', ['table' => $table->toArray($request)]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/change-requests/Create', [
            'projects' => Project::query()->with('client.profile')->orderBy('name')->get()
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => $project->name, 'description' => $project->client?->organisationName()]),
            'preselectedProject' => $request->integer('project') ?: null,
        ]);
    }

    public function store(Request $request, ChangeRequests $changes): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
        ]);

        $change = $changes->raise(Project::query()->findOrFail($validated['project_id']), $request->user(), $validated['title'], $validated['description']);

        return redirect()->route('admin.change-requests.show', $change)->with('success', 'Recorded. Estimate it next.');
    }

    public function show(ChangeRequest $changeRequest): Response
    {
        $change = $changeRequest->load('client.profile', 'project:id,name,code,budget,target_date', 'raiser:id,name', 'quoter:id,name', 'decider:id,name', 'viewer:id,name', 'previous:id,number', 'milestone:id,title', 'signatures');

        return Inertia::render('admin/change-requests/Show', [
            'change' => [
                ...self::forScreen($change),
                'client' => $change->client?->organisationName(),
                'raisedBy' => $change->raiser?->name,
                'quotedBy' => $change->quoter?->name,
                'viewedAt' => $change->viewed_at?->format('j M Y, g:i a'),
                'viewedBy' => $change->viewer?->name,
                'previous' => $change->previous ? ['id' => $change->previous->id, 'number' => $change->previous->number] : null,
                'next' => ChangeRequest::query()->where('previous_id', $change->id)->get(['id', 'number'])->map(fn ($next) => ['id' => $next->id, 'number' => $next->number]),
                'milestone' => $change->milestone?->title,
            ],
        ]);
    }

    public function quote(Request $request, ChangeRequest $changeRequest, ChangeRequests $changes): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'days' => ['required', 'integer', 'min:0', 'max:730'],
            'estimate_note' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $changes->quote($changeRequest, $request->user(), Money::toPaise($validated['amount']), (int) $validated['days'], $validated['estimate_note']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Sent as an addendum. Its text and PDF are fixed now.');
    }

    public function withdraw(Request $request, ChangeRequest $changeRequest, ChangeRequests $changes): RedirectResponse
    {
        try {
            $changes->withdraw($changeRequest, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Withdrawn.');
    }

    public function quoteAgain(Request $request, ChangeRequest $changeRequest, ChangeRequests $changes): RedirectResponse
    {
        try {
            $next = $changes->quoteAgain($changeRequest, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.change-requests.show', $next)->with('success', "Started {$next->number}. Estimate it again.");
    }

    /**
     * The addendum as sent, or, before it is, a preview with the estimate
     * being typed (from the query string, never saved).
     */
    public function pdf(Request $request, ChangeRequest $changeRequest, ClientDocuments $documents): HttpResponse
    {
        $draft = $changeRequest->pdf_path === null;

        if ($draft) {
            $changeRequest->forceFill([
                'amount' => Money::toPaise(is_numeric($request->query('amount')) ? max(0, (float) $request->query('amount')) : 0),
                'days' => max(0, min(730, (int) $request->query('days', 0))),
                'estimate_note' => str((string) $request->query('note', ''))->limit(5000, '')->toString() ?: null,
                'quoted_at' => now(),
            ]);
        }
        $name = str($changeRequest->number)->replace('/', '-').($draft ? '-preview' : '').'.pdf';

        return response($draft ? $documents->preview($changeRequest) : $documents->stored($changeRequest), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($draft ? 'inline' : 'attachment')."; filename=\"{$name}\"",
        ]);
    }

    public function signed(ChangeRequest $changeRequest): HttpResponse
    {
        abort_unless($changeRequest->signed_pdf_path !== null, 404);

        return response(Storage::disk('private')->get($changeRequest->signed_pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str($changeRequest->number)->replace('/', '-').'-signed.pdf"',
        ]);
    }

    /** @return array<string, mixed> The fields both sides' screens show. */
    public static function forScreen(ChangeRequest $change): array
    {
        return [
            'id' => $change->id,
            'number' => $change->number,
            'title' => $change->title,
            'description' => $change->description,
            'status' => $change->status,
            'statusLabel' => $change->statusLabel(),
            'project' => $change->project ? ['id' => $change->project->id, 'name' => $change->project->name] : null,
            'amount' => $change->amountLabel(),
            'days' => $change->daysLabel(),
            'estimateNote' => $change->estimate_note,
            'askedAt' => $change->created_at->format('j M Y'),
            'quotedAt' => $change->quoted_at?->format('j M Y, g:i a'),
            'decidedAt' => $change->decided_at?->format('j M Y, g:i a'),
            'decidedBy' => $change->decider?->name,
            'declineReason' => $change->decline_reason,
            'sha256' => $change->pdf_sha256,
            'hasPdf' => $change->pdf_path !== null,
            'signatures' => $change->signatures->map->toCertificateArray()->values(),
            'hasSignedCopy' => $change->signed_pdf_path !== null,
        ];
    }
}
