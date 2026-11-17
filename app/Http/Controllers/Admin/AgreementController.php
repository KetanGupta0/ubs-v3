<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\MaintenanceContract;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\AgreementSent;
use App\Services\Admin\Auditor;
use App\Services\Client\ClientDocuments;
use App\Support\Markdown;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Agreements with clients (CL1): written here, sent, and from then on fixed.
 *
 * A draft is edited freely and can be previewed as a PDF. Sending freezes the
 * text, the parties and the PDF with its hash, and tells the client's
 * signatories. A change after that is a new version; the old one is marked
 * superseded when the new one goes out, and stays readable.
 */
class AgreementController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(Agreement::query()->with('client.profile', 'project:id,name'))
            ->searchable(['number', 'title'])
            ->defaultSort('-created_at')
            ->exportName('agreements')
            ->columns([
                Column::make('number', 'Number')->sortable(),
                Column::make('title', 'Agreement')->sortable(),
                Column::make('client', 'Client'),
                Column::make('kind', 'Kind'),
                Column::make('status', 'Status')->sortable(),
                Column::make('sent_at', 'Sent')->sortable(),
            ])
            ->filters([
                Filter::select('status', Agreement::STATUSES, 'Status')->placeholder('Any'),
                Filter::select('kind', Agreement::KINDS, 'Kind')->placeholder('Any'),
            ])
            ->transform(fn (Agreement $agreement) => [
                'id' => $agreement->id,
                'number' => $agreement->number.($agreement->version > 1 ? " · v{$agreement->version}" : ''),
                'title' => $agreement->title,
                'client' => $agreement->client?->organisationName(),
                'kind' => $agreement->kindLabel(),
                'status' => $agreement->status,
                'statusLabel' => $agreement->statusLabel(),
                'sent_at' => $agreement->sent_at?->format('j M Y') ?? '—',
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/agreements/Index', ['table' => $table->toArray($request)]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/agreements/Form', [
            'agreement' => null,
            ...$this->options((int) $request->integer('client')),
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $agreement = new Agreement(['created_by' => $request->user()->id, 'number' => Agreement::nextNumber(), 'status' => 'draft']);
        $this->fill($request, $agreement);
        $agreement->save();
        $auditor->created($agreement, $agreement->number);

        return redirect()->route('admin.agreements.show', $agreement)->with('success', 'Saved as a draft. Preview the PDF before you send it.');
    }

    public function show(Agreement $agreement): Response
    {
        $agreement->load('client.profile', 'project:id,name,code', 'contract:id,reference,plan', 'subscription:id,name', 'previous:id,number,version', 'author:id,name');

        return Inertia::render('admin/agreements/Show', [
            'agreement' => [
                'id' => $agreement->id,
                'number' => $agreement->number,
                'version' => $agreement->version,
                'title' => $agreement->title,
                'kind' => $agreement->kindLabel(),
                'status' => $agreement->status,
                'statusLabel' => $agreement->statusLabel(),
                'client' => $agreement->client?->organisationName(),
                'clientId' => $agreement->client_id,
                'html' => Markdown::html($agreement->body),
                'project' => $agreement->project ? ['id' => $agreement->project->id, 'name' => $agreement->project->name] : null,
                'contract' => $agreement->contract?->reference,
                'subscription' => $agreement->subscription?->name,
                'effectiveOn' => $agreement->effective_on?->format('j M Y'),
                'expiresOn' => $agreement->expires_on?->format('j M Y'),
                'sentAt' => $agreement->sent_at?->format('j M Y, g:i a'),
                'sha256' => $agreement->pdf_sha256,
                'previous' => $agreement->previous ? ['id' => $agreement->previous->id, 'number' => $agreement->previous->number] : null,
                'later' => Agreement::query()->where('previous_id', $agreement->id)->get(['id', 'number', 'version', 'status'])
                    ->map(fn (Agreement $later) => ['id' => $later->id, 'number' => $later->number, 'status' => $later->statusLabel()]),
                'author' => $agreement->author?->name,
            ],
        ]);
    }

    public function edit(Agreement $agreement): Response
    {
        abort_unless($agreement->status === 'draft', 422, 'Only a draft can be changed. Start a new version.');

        return Inertia::render('admin/agreements/Form', [
            'agreement' => [
                'id' => $agreement->id,
                'number' => $agreement->number,
                'client_id' => $agreement->client_id,
                'kind' => $agreement->kind,
                'title' => $agreement->title,
                'body' => $agreement->body,
                'project_id' => $agreement->project_id,
                'maintenance_contract_id' => $agreement->maintenance_contract_id,
                'subscription_id' => $agreement->subscription_id,
                'effective_on' => $agreement->effective_on?->toDateString(),
                'expires_on' => $agreement->expires_on?->toDateString(),
            ],
            ...$this->options($agreement->client_id),
        ]);
    }

    public function update(Request $request, Agreement $agreement, Auditor $auditor): RedirectResponse
    {
        abort_unless($agreement->status === 'draft', 422, 'Only a draft can be changed. Start a new version.');

        $this->fill($request, $agreement);
        $auditor->updated($agreement, label: $agreement->number);
        $agreement->save();

        return redirect()->route('admin.agreements.show', $agreement)->with('success', 'Draft saved.');
    }

    public function destroy(Agreement $agreement, Auditor $auditor): RedirectResponse
    {
        abort_unless($agreement->status === 'draft', 422, 'A sent agreement is kept. Withdraw it instead.');

        $auditor->deleted($agreement, $agreement->number);
        $agreement->delete();

        return redirect()->route('admin.agreements.index')->with('success', 'Draft deleted.');
    }

    /** Send it: freeze the text, the parties and the PDF, and tell the signatories. */
    public function send(Agreement $agreement, ClientDocuments $documents, Auditor $auditor): RedirectResponse
    {
        abort_unless($agreement->status === 'draft', 422, 'This agreement has already been sent.');

        DB::transaction(function () use ($agreement, $documents) {
            $agreement->forceFill(['sent_at' => now()])->save();
            $documents->freezeAgreement($agreement);
            $agreement->forceFill(['status' => 'sent'])->save();

            // The version it replaces now says so, and stays readable.
            if ($agreement->previous && in_array($agreement->previous->status, ['sent', 'signed', 'countersigned'], true)) {
                $agreement->previous->forceFill(['status' => 'superseded'])->save();
            }
        });

        Notification::send($agreement->client->clientRecipients('signing'), new AgreementSent($agreement));
        $auditor->action('agreement.sent', $agreement, ['sha256' => $agreement->pdf_sha256], $agreement->number);

        return back()->with('success', "Sent. {$agreement->client->organisationName()}'s signatories have been told.");
    }

    /** A new draft from this one; this one stays exactly as it was. */
    public function revise(Agreement $agreement, Auditor $auditor): RedirectResponse
    {
        abort_if($agreement->status === 'draft', 422, 'Edit the draft itself.');

        $revision = Agreement::query()->create([
            'client_id' => $agreement->client_id,
            'number' => Agreement::nextNumber(),
            'version' => $agreement->version + 1,
            'previous_id' => $agreement->id,
            'kind' => $agreement->kind,
            'title' => $agreement->title,
            'body' => $agreement->body,
            'project_id' => $agreement->project_id,
            'maintenance_contract_id' => $agreement->maintenance_contract_id,
            'subscription_id' => $agreement->subscription_id,
            'effective_on' => $agreement->effective_on,
            'expires_on' => $agreement->expires_on,
            'status' => 'draft',
            'created_by' => request()->user()->id,
        ]);

        $auditor->action('agreement.revised', $revision, ['from' => $agreement->number], $revision->number);

        return redirect()->route('admin.agreements.edit', $revision)->with('success', "Version {$revision->version} started from {$agreement->number}.");
    }

    public function withdraw(Agreement $agreement, Auditor $auditor): RedirectResponse
    {
        abort_unless($agreement->status === 'sent', 422, 'Only an agreement waiting for signature can be withdrawn.');

        $agreement->forceFill(['status' => 'withdrawn'])->save();
        $auditor->action('agreement.withdrawn', $agreement, [], $agreement->number);

        return back()->with('success', 'Withdrawn. The client still sees it, marked withdrawn.');
    }

    /** The PDF: the stored one once sent, a preview of a draft. */
    public function pdf(Agreement $agreement, ClientDocuments $documents): HttpResponse
    {
        $draft = $agreement->status === 'draft';
        $bytes = $draft ? $documents->preview($agreement) : $documents->stored($agreement);
        $name = str($agreement->number)->replace('/', '-').($draft ? '-draft' : '').'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($draft ? 'inline' : 'attachment')."; filename=\"{$name}\"",
        ]);
    }

    /* -------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    protected function options(?int $clientId): array
    {
        return [
            'kinds' => collect(Agreement::KINDS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'clients' => User::query()->clientAccounts()->with('profile')->orderBy('name')->get()
                ->map(fn (User $client) => ['value' => $client->id, 'label' => $client->organisationName(), 'description' => $client->email]),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name', 'client_id'])
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => $project->name, 'clientId' => $project->client_id]),
            'contracts' => MaintenanceContract::query()->orderBy('reference')->get(['id', 'reference', 'plan', 'client_id'])
                ->map(fn (MaintenanceContract $contract) => ['value' => $contract->id, 'label' => "{$contract->reference} · {$contract->plan}", 'clientId' => $contract->client_id]),
            'subscriptions' => Subscription::query()->orderBy('name')->get(['id', 'name', 'client_id'])
                ->map(fn (Subscription $subscription) => ['value' => $subscription->id, 'label' => $subscription->name, 'clientId' => $subscription->client_id]),
            'preselectedClient' => $clientId ?: null,
        ];
    }

    protected function fill(Request $request, Agreement $agreement): void
    {
        $validated = $this->validatedInput($request, [
            'client_id' => ['required', 'integer', User::clientAccountRule()],
            'kind' => ['required', Rule::in(array_keys(Agreement::KINDS))],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:100000'],
            'project_id' => ['nullable', 'integer'],
            'maintenance_contract_id' => ['nullable', 'integer'],
            'subscription_id' => ['nullable', 'integer'],
            'effective_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after:effective_on'],
        ], [
            'client_id.exists' => 'Choose the client organisation.',
            'expires_on.after' => 'It has to end after it starts.',
        ]);

        $clientId = (int) $validated['client_id'];

        // Anything it is linked to has to be the same client's.
        $links = [
            'project_id' => [Project::class, 'project'],
            'maintenance_contract_id' => [MaintenanceContract::class, 'contract'],
            'subscription_id' => [Subscription::class, 'subscription'],
        ];

        foreach ($links as $field => [$model, $word]) {
            if ($validated[$field] && ! $model::query()->whereKey($validated[$field])->where('client_id', $clientId)->exists()) {
                throw ValidationException::withMessages([$field => "That {$word} belongs to another client."]);
            }
        }

        $agreement->fill([
            'client_id' => $clientId,
            'kind' => $validated['kind'],
            'title' => trim($validated['title']),
            'body' => trim($validated['body']),
            'project_id' => $validated['project_id'] ?: null,
            'maintenance_contract_id' => $validated['maintenance_contract_id'] ?: null,
            'subscription_id' => $validated['subscription_id'] ?: null,
            'effective_on' => $validated['effective_on'],
            'expires_on' => $validated['expires_on'],
        ]);
    }
}
