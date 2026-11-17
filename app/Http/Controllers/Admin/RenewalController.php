<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\RenewalItem;
use App\Models\User;
use App\Services\Admin\Auditor;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Domains, hosting, certificates and update windows we watch for clients
 * (CL16). Reminders go at 30, 14 and 3 days from `renewals:remind`.
 */
class RenewalController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(RenewalItem::query()->with('client.profile', 'project:id,name'))
            ->searchable(['name', 'provider'])
            ->defaultSort('expires_on')
            ->exportName('renewals')
            ->columns([
                Column::make('name', 'What')->sortable(),
                Column::make('kind', 'Kind'),
                Column::make('client', 'Client'),
                Column::make('expires_on', 'Lapses')->sortable(),
                Column::make('status', 'Status')->sortable(),
            ])
            ->filters([
                Filter::select('kind', RenewalItem::KINDS, 'Kind')->placeholder('Any'),
                Filter::select('status', ['active' => 'Watching', 'cancelled' => 'Not watching'], 'Status')->placeholder('Any'),
            ])
            ->transform(fn (RenewalItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'kind' => $item->kindLabel(),
                'kindValue' => $item->kind,
                'client' => $item->client?->organisationName(),
                'clientId' => $item->client_id,
                'projectId' => $item->project_id,
                'provider' => $item->provider,
                'notes' => $item->notes,
                'autoRenew' => $item->auto_renew,
                'expires_on' => $item->expires_on->format('j M Y'),
                'expiresOn' => $item->expires_on->toDateString(),
                'days' => $item->daysToExpiry(),
                'status' => $item->status,
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/renewals/Index', [
            'table' => $table->toArray($request),
            'kinds' => collect(RenewalItem::KINDS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'clients' => User::query()->clientAccounts()->with('profile')->orderBy('name')->get()
                ->map(fn (User $client) => ['value' => $client->id, 'label' => $client->organisationName()]),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name', 'client_id'])
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => $project->name, 'clientId' => $project->client_id]),
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $item = RenewalItem::query()->create($this->validated($request) + ['created_by' => $request->user()->id, 'status' => 'active']);
        $auditor->created($item, $item->name);

        return back()->with('success', "Watching {$item->name}. Reminders go at 30, 14 and 3 days before it lapses.");
    }

    public function update(Request $request, RenewalItem $renewal, Auditor $auditor): RedirectResponse
    {
        $renewal->fill($this->validated($request));

        // A new date is a renewal: the reminders start again for it.
        if ($renewal->isDirty('expires_on')) {
            $renewal->reminders_sent = [];
        }

        $auditor->updated($renewal, label: $renewal->name);
        $renewal->save();

        return back()->with('success', 'Saved.');
    }

    public function cancel(RenewalItem $renewal, Auditor $auditor): RedirectResponse
    {
        $renewal->forceFill(['status' => 'cancelled'])->save();
        $auditor->action('renewal.cancelled', $renewal, [], $renewal->name);

        return back()->with('success', "No longer watching {$renewal->name}.");
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $validated = $this->validatedInput($request, [
            'client_id' => ['required', 'integer', User::clientAccountRule()],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'kind' => ['required', Rule::in(array_keys(RenewalItem::KINDS))],
            'name' => ['required', 'string', 'max:160'],
            'provider' => ['nullable', 'string', 'max:120'],
            'expires_on' => ['required', 'date'],
            'auto_renew' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validated['project_id'] && (int) Project::query()->whereKey($validated['project_id'])->value('client_id') !== (int) $validated['client_id']) {
            throw ValidationException::withMessages(['project_id' => 'That project belongs to another client.']);
        }

        return $validated;
    }
}
