<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Solution;
use App\Services\Admin\Auditor;
use App\Services\Shop\Licences;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Every licence sold (CL4), from our side: who holds it, how many sites use
 * it, when its updates and support end, and whether it still works. The key
 * itself is never here: only its holder ever saw it.
 */
class LicenceController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(Licence::query()->with('client.profile', 'order:id,number')->withCount([
            'activations as sites_in_use' => fn ($q) => $q->whereNull('released_at'),
            'downloads',
        ]))
            ->searchable(['key_prefix', 'product'])
            ->defaultSort('-created_at')
            ->exportName('licences')
            ->columns([
                Column::make('product', 'Product')->sortable(),
                Column::make('client', 'Holder'),
                Column::make('key', 'Key'),
                Column::make('sites', 'Sites'),
                Column::make('updates_until', 'Updates until')->sortable(),
                Column::make('last_checked_at', 'Last checked')->sortable(),
                Column::make('status', 'Status')->sortable(),
            ])
            ->filters([
                Filter::select('status', [['value' => 'active', 'label' => 'Active'], ['value' => 'revoked', 'label' => 'Withdrawn']], 'Status')->placeholder('Any status'),
                Filter::select('solution_id', Solution::query()->whereHas('tiers')->orderBy('title')->get(['id', 'title'])->map(fn (Solution $solution) => ['value' => $solution->id, 'label' => $solution->title])->all(), 'Product')->placeholder('Any product'),
            ])
            ->transform(fn (Licence $licence) => [
                'id' => $licence->id,
                'product' => "{$licence->product}, {$licence->tier}",
                'client' => $licence->client?->organisationName() ?? '—',
                'orderId' => $licence->order_id,
                'order' => $licence->order?->number,
                'key' => $licence->maskedKey() ?? 'Not made yet',
                'sites' => $licence->sites_in_use.' of '.($licence->sites ?? '∞'),
                'updates_until' => $licence->updates_until->format('j M Y'),
                'updatesUntilValue' => $licence->updates_until->toDateString(),
                'supportUntilValue' => $licence->support_until?->toDateString(),
                'last_checked_at' => $licence->last_checked_at?->diffForHumans() ?? 'Never',
                'downloads' => $licence->downloads_count,
                'status' => $licence->isActive() ? 'Active' : 'Withdrawn',
                'active' => $licence->isActive(),
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/shop/Licences', [
            'table' => $table->toArray($request),
            'mayManage' => $request->user()->hasPermission('orders.manage'),
        ]);
    }

    public function revoke(Request $request, Licence $licence, Licences $licences): RedirectResponse
    {
        $validated = $this->validatedInput($request, ['reason' => ['required', 'string', 'max:300']], [
            'reason.required' => 'Say why. The holder sees it on their order.',
        ]);

        $licences->revoke($licence, trim($validated['reason']), $request->user());

        return back()->with('success', 'Withdrawn. The key stops working and nothing more can be downloaded under it.');
    }

    public function restore(Request $request, Licence $licence, Licences $licences): RedirectResponse
    {
        $licences->restore($licence, $request->user());

        return back()->with('success', 'Restored. The key works again.');
    }

    /** Longer updates or support: goodwill, or something agreed outside the shop. */
    public function extend(Request $request, Licence $licence, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'updates_until' => ['required', 'date', 'after_or_equal:'.$licence->updates_until->toDateString()],
            'support_until' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:300'],
        ], [
            'updates_until.after_or_equal' => 'Updates can be extended, not cut short. Withdraw the licence instead.',
            'reason.required' => 'Say why, for the record.',
        ]);

        $licence->fill([
            'updates_until' => $validated['updates_until'],
            'support_until' => $validated['support_until'],
        ]);
        $auditor->updated($licence, label: "{$licence->product}, {$licence->tier}: {$validated['reason']}");
        $licence->save();

        return back()->with('success', 'Saved.');
    }

    public function releaseSite(Request $request, Licence $licence, int $activation, Licences $licences): RedirectResponse
    {
        $site = LicenceActivation::query()->where('licence_id', $licence->id)->whereKey($activation)->firstOrFail();
        $licences->release($site, $request->user());

        return back()->with('success', "{$site->domain} no longer holds a place.");
    }
}
