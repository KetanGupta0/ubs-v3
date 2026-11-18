<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LicenceDownload;
use App\Models\OrderItem;
use App\Models\ProductAddon;
use App\Models\ProductTier;
use App\Models\ProductVersion;
use App\Models\Solution;
use App\Services\Admin\Auditor;
use App\Services\Billing\Invoicer;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * How a solution is sold (§4): what it is offered as, its live demo, licence
 * tiers and hosted plans at fixed prices before tax (X17), add-ons, and every
 * release of its code, kept on private storage (X16).
 *
 * Tiers and add-ons are copied onto an order when it is placed, so changing or
 * retiring one here never rewrites a sale. What has been sold is retired
 * rather than deleted, so the order can still say what it was.
 */
class SolutionSellingController extends Controller
{
    public function show(Solution $solution, Invoicer $invoicer): Response
    {
        $solution->load('tiers', 'addons', 'versions.uploadedBy:id,name');

        return Inertia::render('admin/catalogue/solutions/Selling', [
            'solution' => [
                'id' => $solution->id,
                'title' => $solution->title,
                'slug' => $solution->slug,
                'published' => $solution->is_published,
                'publicUrl' => "/solutions/{$solution->slug}",
                'offerings' => $solution->offeringKeys(),
                'demo_url' => $solution->demo_url,
                'demo_username' => $solution->demo_username,
                'demo_password' => $solution->demo_password,
                'demo_notes' => $solution->demo_notes,
                'sac_code' => $solution->sac_code,
            ],
            'offerings' => collect(Solution::OFFERINGS)->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values(),
            'warnings' => $this->warnings($solution),
            'tiers' => $solution->tiers->map(fn (ProductTier $tier) => [
                'id' => $tier->id,
                'kind' => $tier->kind,
                'name' => $tier->name,
                'description' => $tier->description,
                'price' => Money::price($tier->price),
                'priceRupees' => $tier->price / 100,
                'sites' => $tier->sites,
                'update_months' => $tier->update_months,
                'support_months' => $tier->support_months,
                'interval' => $tier->interval,
                'terms' => $tier->terms(),
                'is_active' => $tier->is_active,
                'sort_order' => $tier->sort_order,
                'sold' => $this->tierSold($tier),
            ])->values(),
            'addons' => $solution->addons->map(fn (ProductAddon $addon) => [
                'id' => $addon->id,
                'name' => $addon->name,
                'description' => $addon->description,
                'kind' => $addon->kind,
                'kindLabel' => ProductAddon::KINDS[$addon->kind] ?? $addon->kind,
                'price' => Money::price($addon->price),
                'priceRupees' => $addon->price / 100,
                'unit' => $addon->unit,
                'max_quantity' => $addon->max_quantity,
                'sac_code' => $addon->sac_code,
                'is_active' => $addon->is_active,
                'sort_order' => $addon->sort_order,
                'sold' => $this->addonSold($addon),
            ])->values(),
            'versions' => $solution->versions->map(fn (ProductVersion $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'releasedOn' => $version->released_on->format('j M Y'),
                'changelog' => $version->changelog,
                'fileName' => $version->file_name,
                'size' => $version->sizeLabel(),
                'sha256' => $version->sha256,
                'is_available' => $version->is_available,
                'uploadedBy' => $version->uploadedBy?->name,
                'downloads' => $this->downloadsOf($version),
            ])->values(),
            'addonKinds' => collect(ProductAddon::KINDS)->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values(),
            'taxRate' => $invoicer->defaultTaxRate(),
            'maxUploadMb' => (int) config('company.shop.max_upload_mb'),
        ]);
    }

    public function update(Request $request, Solution $solution, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'offerings' => ['required', 'array', 'min:1'],
            'offerings.*' => ['string', Rule::in(array_keys(Solution::OFFERINGS))],
            'demo_url' => ['nullable', Rule::requiredIf(fn () => in_array('demo', (array) $request->input('offerings'), true)), 'string', 'max:255', 'url:https'],
            'demo_username' => ['nullable', 'string', 'max:120'],
            'demo_password' => ['nullable', 'string', 'max:120'],
            'demo_notes' => ['nullable', 'string', 'max:300'],
            'sac_code' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],
        ], [
            'offerings.required' => 'Choose at least one way this is offered.',
            'offerings.min' => 'Choose at least one way this is offered.',
            'demo_url.url' => 'Give the demo\'s full https:// address.',
            'demo_url.required' => 'Give the demo\'s address, or switch the demo off.',
            'sac_code.regex' => 'A SAC is four to eight digits.',
        ]);

        $solution->fill([
            ...$validated,
            // In the order they are listed, whatever order they were ticked in.
            'offerings' => array_values(array_intersect(array_keys(Solution::OFFERINGS), $validated['offerings'])),
        ]);
        $auditor->updated($solution);
        $solution->save();

        return back()->with('success', 'Saved. The product page shows it straight away.');
    }

    /* ----------------------------------------------------------- tiers */

    public function storeTier(Request $request, Solution $solution, Auditor $auditor): RedirectResponse
    {
        $tier = $solution->tiers()->create($this->tierInput($request));
        $auditor->created($tier, "{$solution->title}: {$tier->name}");

        return back()->with('success', $tier->isLicence() ? 'Licence tier added.' : 'Plan added.');
    }

    public function updateTier(Request $request, Solution $solution, int $tier, Auditor $auditor): RedirectResponse
    {
        $record = $solution->tiers()->findOrFail($tier);
        $record->fill($this->tierInput($request, $record));
        $auditor->updated($record, label: "{$solution->title}: {$record->name}");
        $record->save();

        return back()->with('success', 'Saved. Orders already placed keep the terms they were sold on.');
    }

    public function destroyTier(Solution $solution, int $tier, Auditor $auditor): RedirectResponse
    {
        $record = $solution->tiers()->findOrFail($tier);

        if ($this->tierSold($record)) {
            return back()->with('error', 'This has been sold, so it stays on record. Switch it off instead.');
        }

        $auditor->deleted($record, "{$solution->title}: {$record->name}");
        $record->delete();

        return back()->with('success', 'Removed.');
    }

    /** @return array<string, mixed> */
    protected function tierInput(Request $request, ?ProductTier $tier = null): array
    {
        $validated = $this->validatedInput($request, [
            'kind' => [$tier ? 'prohibited' : 'required', Rule::in(ProductTier::KINDS)],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
            'price' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'sites' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'update_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'support_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'interval' => ['nullable', Rule::requiredIf(fn () => ($tier?->kind ?? $request->input('kind')) === 'plan'), Rule::in(array_keys(ProductTier::INTERVALS))],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], [
            'kind.prohibited' => 'A licence cannot become a plan, or the other way round. Add a new one instead.',
            'price.min' => 'A price is at least one rupee.',
            'interval.required' => 'Say how often the plan is billed.',
        ]);

        $kind = $tier?->kind ?? $validated['kind'];

        return [
            ...($tier ? [] : ['kind' => $kind]),
            'name' => trim($validated['name']),
            'description' => $validated['description'],
            'price' => Money::toPaise($validated['price']),
            'sites' => $kind === 'licence' ? $validated['sites'] : null,
            'update_months' => $kind === 'licence' ? (int) ($validated['update_months'] ?? 0) : 0,
            'support_months' => $kind === 'licence' ? (int) ($validated['support_months'] ?? 0) : 0,
            'interval' => $kind === 'plan' ? $validated['interval'] : null,
            'is_active' => $validated['is_active'],
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }

    /* ---------------------------------------------------------- add-ons */

    public function storeAddon(Request $request, Solution $solution, Auditor $auditor): RedirectResponse
    {
        $addon = $solution->addons()->create($this->addonInput($request));
        $auditor->created($addon, "{$solution->title}: {$addon->name}");

        return back()->with('success', 'Add-on added.');
    }

    public function updateAddon(Request $request, Solution $solution, int $addon, Auditor $auditor): RedirectResponse
    {
        $record = $solution->addons()->findOrFail($addon);
        $record->fill($this->addonInput($request));
        $auditor->updated($record, label: "{$solution->title}: {$record->name}");
        $record->save();

        return back()->with('success', 'Saved. Orders already placed keep what they were sold.');
    }

    public function destroyAddon(Solution $solution, int $addon, Auditor $auditor): RedirectResponse
    {
        $record = $solution->addons()->findOrFail($addon);

        if ($this->addonSold($record)) {
            return back()->with('error', 'This has been sold, so it stays on record. Switch it off instead.');
        }

        $auditor->deleted($record, "{$solution->title}: {$record->name}");
        $record->delete();

        return back()->with('success', 'Removed.');
    }

    /** @return array<string, mixed> */
    protected function addonInput(Request $request): array
    {
        $validated = $this->validatedInput($request, [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:300'],
            'kind' => ['required', Rule::in(array_keys(ProductAddon::KINDS))],
            'price' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'unit' => ['nullable', Rule::in(ProductAddon::UNITS)],
            'max_quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'sac_code' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], [
            'price.min' => 'A price is at least one rupee.',
            'sac_code.regex' => 'A SAC is four to eight digits.',
        ]);

        // Months of support or updates are counted in months, whatever was sent.
        $unit = in_array($validated['kind'], ['support', 'updates'], true) ? 'month' : $validated['unit'];

        return [
            'name' => trim($validated['name']),
            'description' => $validated['description'],
            'kind' => $validated['kind'],
            'price' => Money::toPaise($validated['price']),
            'unit' => $unit,
            // Something done once is bought once.
            'max_quantity' => $unit === null ? 1 : (int) ($validated['max_quantity'] ?? 1),
            'sac_code' => $validated['sac_code'],
            'is_active' => $validated['is_active'],
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }

    /* --------------------------------------------------------- versions */

    public function storeVersion(Request $request, Solution $solution, Auditor $auditor): RedirectResponse
    {
        $maxKb = (int) config('company.shop.max_upload_mb') * 1024;

        $validated = $this->validatedInput($request, [
            'version' => ['required', 'string', 'max:30', 'regex:/^\d+(\.\d+){0,3}([-+][0-9A-Za-z.-]+)?$/', Rule::unique('product_versions', 'version')->where('solution_id', $solution->id)],
            'released_on' => ['required', 'date', 'before_or_equal:today'],
            'changelog' => ['nullable', 'string', 'max:20000'],
            // The type is read from the file's bytes, never from its name.
            'file' => ['required', 'file', 'mimetypes:application/zip,application/x-zip-compressed', "max:{$maxKb}"],
        ], [
            'version.regex' => 'Write the version as numbers with dots, such as 1.4.0.',
            'version.unique' => 'That version is already uploaded. Give the new one its own number.',
            'released_on.before_or_equal' => 'A release date cannot be in the future.',
            'file.mimetypes' => 'Upload the code as a .zip file.',
            'file.max' => 'The file can be up to '.config('company.shop.max_upload_mb').' MB.',
        ]);

        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $path = $file->storeAs("products/{$solution->id}", Str::uuid().'.zip', 'private');

        $version = $solution->versions()->create([
            'version' => $validated['version'],
            'released_on' => $validated['released_on'],
            'changelog' => $validated['changelog'],
            'path' => $path,
            'file_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'size' => $file->getSize(),
            'sha256' => $hash,
            'uploaded_by' => $request->user()->id,
        ]);

        $auditor->created($version, "{$solution->title} {$version->version}");

        return back()->with('success', "Version {$version->version} uploaded. Licence holders in their update window can download it now.");
    }

    public function updateVersion(Request $request, Solution $solution, int $version, Auditor $auditor): RedirectResponse
    {
        $record = $solution->versions()->findOrFail($version);

        $validated = $this->validatedInput($request, [
            'changelog' => ['nullable', 'string', 'max:20000'],
            'is_available' => ['boolean'],
        ]);

        $record->fill($validated);
        $auditor->updated($record, label: "{$solution->title} {$record->version}");
        $record->save();

        return back()->with('success', $record->is_available ? 'Saved.' : 'Withdrawn. Nobody can download it now; it stays on record.');
    }

    public function destroyVersion(Solution $solution, int $version, Auditor $auditor): RedirectResponse
    {
        $record = $solution->versions()->findOrFail($version);

        if ($this->downloadsOf($record) > 0) {
            return back()->with('error', 'Somebody has downloaded this version, so it stays on record. Withdraw it instead.');
        }

        DB::transaction(function () use ($record, $auditor, $solution) {
            $auditor->deleted($record, "{$solution->title} {$record->version}");
            $record->delete();
        });
        Storage::disk('private')->delete($record->path);

        return back()->with('success', 'Version removed.');
    }

    /** Staff check what buyers get. */
    public function downloadVersion(Solution $solution, int $version): StreamedResponse
    {
        $record = $solution->versions()->findOrFail($version);
        abort_unless(Storage::disk('private')->exists($record->path), 404);

        return Storage::disk('private')->download($record->path, $record->downloadName());
    }

    /* ---------------------------------------------------------- helpers */

    /** @return array<int, string> What is switched on but cannot be offered yet. */
    protected function warnings(Solution $solution): array
    {
        $on = $solution->offeringKeys();

        return array_values(array_filter([
            in_array('source_code', $on, true) && $solution->activeTiers('licence')->isEmpty()
                ? 'The source code is switched on, but there is no licence tier switched on, so the site does not sell it yet.' : null,
            in_array('source_code', $on, true) && $solution->versions->where('is_available', true)->isEmpty()
                ? 'No version of the code is uploaded, so a buyer would have nothing to download.' : null,
            in_array('subscription', $on, true) && $solution->activeTiers('plan')->isEmpty()
                ? 'Plans are switched on, but there is no plan switched on, so the site does not offer one yet.' : null,
            ! $solution->is_published && $solution->isSold()
                ? 'The solution is not published, so nobody outside the team can buy it.' : null,
        ]));
    }

    protected function tierSold(ProductTier $tier): bool
    {
        return OrderItem::query()->where('product_tier_id', $tier->id)->exists();
    }

    protected function addonSold(ProductAddon $addon): bool
    {
        return OrderItem::query()->where('product_addon_id', $addon->id)->exists();
    }

    protected function downloadsOf(ProductVersion $version): int
    {
        return LicenceDownload::query()->where('product_version_id', $version->id)->count();
    }
}
