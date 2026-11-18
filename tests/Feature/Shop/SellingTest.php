<?php

use App\Models\Permission;
use App\Models\ProductVersion;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ShopCatalogue;

/** A real zip's bytes, so the type is read from what the file is. */
function zipUpload(string $name = 'clinic-desk.zip'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('README.md', 'Clinic desk source');
    $zip->close();

    return new UploadedFile($path, $name, 'application/zip', null, true);
}

beforeEach(function () {
    Storage::fake('private');
    $this->seed(PermissionSeeder::class);
    Setting::put('invoicing.default_tax_rate', 18, 'invoicing');

    $this->editor = User::factory()->admin()->create();
    $this->editor->permissions()->attach(Permission::query()->whereIn('key', ['catalogue.view', 'catalogue.manage'])->get());

    $this->shop = ShopCatalogue::product();
    $this->solution = $this->shop['solution'];
    $this->base = "/admin/solutions/{$this->solution->id}";
});

it('sells the source code at a price before tax, and says so on the page', function () {
    $this->get("/solutions/{$this->solution->slug}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('marketing/solutions/Show')
            ->where('offer.customBuild', true)
            ->where('offer.licences.0.name', 'Single site')
            ->where('offer.licences.0.price', '₹49,999')
            ->where('offer.licences.0.terms', ['One site', 'One year of updates', '3 months of support'])
            ->where('offer.licences.1.terms.0', 'Up to 5 sites')
            ->where('offer.addons.2.needsLicence', true)
            ->where('offer.taxRate', 18)
            ->where('offer.demo', null)
            ->where('seo.structuredData.@type', 'SoftwareApplication')
            ->where('seo.structuredData.offers.0.priceSpecification.valueAddedTaxIncluded', false));

    $this->get('/solutions')->assertInertia(fn ($page) => $page
        ->where('solutions.0.fromPrice', '₹49,999')
        ->where('solutions.0.priceBand', '₹3L – ₹9L'));
});

it('shows a band only for what is built to order, and nothing it cannot sell', function () {
    $this->solution->update(['offerings' => ['source_code']]);
    $this->get('/solutions')->assertInertia(fn ($page) => $page->where('solutions.0.priceBand', null)->where('solutions.0.fromPrice', '₹49,999'));

    // Switched on with nothing on sale behind it offers nothing.
    $this->solution->tiers()->update(['is_active' => false]);
    $this->solution->update(['offerings' => ['custom_build', 'source_code']]);

    $this->get("/solutions/{$this->solution->slug}")
        ->assertInertia(fn ($page) => $page->has('offer.licences', 0)->has('offer.addons', 0)->where('seo.structuredData.@type', 'Service'));
    $this->actingAs($this->editor)->get("{$this->base}/selling")
        ->assertInertia(fn ($page) => $page->where('warnings.0', fn ($text) => str_contains($text, 'no licence tier switched on')));
});

it('shows a live demo with its public sign in, only with an https address', function () {
    $this->actingAs($this->editor)->put("{$this->base}/selling", ['offerings' => ['source_code', 'demo'], 'demo_url' => ''])
        ->assertSessionHasErrors('demo_url');
    $this->actingAs($this->editor)->put("{$this->base}/selling", ['offerings' => ['demo'], 'demo_url' => 'http://demo.example.com'])
        ->assertSessionHasErrors('demo_url');

    $this->actingAs($this->editor)->put("{$this->base}/selling", [
        'offerings' => ['demo', 'source_code'], 'demo_url' => 'https://clinic-demo.example.com',
        'demo_username' => 'demo@clinic.test', 'demo_password' => 'try-it-out', 'demo_notes' => 'Resets every night.',
    ])->assertSessionHas('success');

    expect($this->solution->fresh()->offerings)->toBe(['source_code', 'demo']);
    $this->get("/solutions/{$this->solution->slug}")
        ->assertInertia(fn ($page) => $page
            ->where('offer.demo.url', 'https://clinic-demo.example.com')
            ->where('offer.demo.password', 'try-it-out')
            ->where('offer.customBuild', false));
});

it('keeps licence tiers and add-ons, priced in paise, for staff with the catalogue', function () {
    $clerk = User::factory()->admin()->create();
    $clerk->permissions()->attach(Permission::query()->where('key', 'catalogue.view')->get());
    $this->actingAs($clerk)->post("{$this->base}/tiers", ['kind' => 'licence', 'name' => 'Agency', 'price' => 1])->assertForbidden();

    $this->actingAs($this->editor)->post("{$this->base}/tiers", ['kind' => 'plan', 'name' => 'Hosted', 'price' => 2499])
        ->assertSessionHasErrors('interval');
    $this->actingAs($this->editor)->post("{$this->base}/tiers", ['kind' => 'licence', 'name' => 'Agency', 'price' => '2,49,999'])
        ->assertSessionHasErrors('price');
    $this->actingAs($this->editor)->post("{$this->base}/tiers", [
        'kind' => 'licence', 'name' => 'Agency', 'price' => 249999.50, 'sites' => '', 'update_months' => 24, 'support_months' => 12, 'is_active' => true,
    ])->assertSessionHas('success');

    $agency = $this->solution->tiers()->where('name', 'Agency')->sole();
    expect($agency->price)->toBe(2_49_999_50)
        ->and($agency->sites)->toBeNull()
        ->and($agency->terms())->toBe(['Unlimited sites', '2 years of updates', 'One year of support']);

    // A kind is for life: a licence never becomes a plan.
    $this->actingAs($this->editor)->put("{$this->base}/tiers/{$agency->id}", ['kind' => 'plan', 'name' => 'Agency', 'price' => 1])->assertSessionHasErrors('kind');

    $this->actingAs($this->editor)->post("{$this->base}/addons", ['name' => 'More updates', 'kind' => 'updates', 'price' => 1000, 'unit' => 'hour', 'max_quantity' => 12, 'is_active' => true])
        ->assertSessionHas('success');
    expect($this->solution->addons()->where('name', 'More updates')->sole()->unit)->toBe('month');

    $this->actingAs($this->editor)->post("{$this->base}/addons", ['name' => 'Training', 'kind' => 'service', 'price' => 3000, 'max_quantity' => 9, 'is_active' => true]);
    expect($this->solution->addons()->where('name', 'Training')->sole()->max_quantity)->toBe(1);

    // Another solution's tier is not this one's to edit.
    $other = ShopCatalogue::product('Pharmacy desk');
    $this->actingAs($this->editor)->put("{$this->base}/tiers/{$other['single']->id}", ['name' => 'Taken', 'price' => 1])->assertNotFound();
});

it('keeps every version of the code privately, with its hash, and lets staff check it', function () {
    // Named .zip and sent as one, but the bytes say otherwise.
    $path = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($path, 'not a zip at all');
    $fake = new UploadedFile($path, 'clinic.zip', 'application/zip', null, true);
    $this->actingAs($this->editor)->post("{$this->base}/versions", ['version' => '1.0.0', 'released_on' => today()->toDateString(), 'file' => $fake])
        ->assertSessionHasErrors('file');

    $this->actingAs($this->editor)->post("{$this->base}/versions", ['version' => 'v1', 'released_on' => today()->toDateString(), 'file' => zipUpload()])
        ->assertSessionHasErrors('version');
    $this->actingAs($this->editor)->post("{$this->base}/versions", ['version' => '1.0.0', 'released_on' => today()->addDay()->toDateString(), 'file' => zipUpload()])
        ->assertSessionHasErrors('released_on');

    $upload = zipUpload();
    $hash = hash_file('sha256', $upload->getRealPath());
    $this->actingAs($this->editor)->post("{$this->base}/versions", ['version' => '1.0.0', 'released_on' => today()->toDateString(), 'changelog' => 'First release.', 'file' => $upload])
        ->assertSessionHas('success');
    $this->actingAs($this->editor)->post("{$this->base}/versions", ['version' => '1.0.0', 'released_on' => today()->toDateString(), 'file' => zipUpload()])
        ->assertSessionHasErrors('version');

    $version = ProductVersion::query()->sole();
    expect($version->sha256)->toBe($hash)
        ->and($version->path)->toStartWith("products/{$this->solution->id}/");
    Storage::disk('private')->assertExists($version->path);

    $this->actingAs($this->editor)->get("{$this->base}/versions/{$version->id}/download")->assertOk()->assertDownload('clinic-desk-1.0.0.zip');
    $this->get("/solutions/{$this->solution->slug}")->assertInertia(fn ($page) => $page->where('offer.latestVersion.version', '1.0.0'));

    // Withdrawn, it is no longer the latest anybody is offered.
    $this->actingAs($this->editor)->put("{$this->base}/versions/{$version->id}", ['changelog' => 'First release.', 'is_available' => false])->assertSessionHas('success');
    $this->get("/solutions/{$this->solution->slug}")->assertInertia(fn ($page) => $page->where('offer.latestVersion', null));

    $this->actingAs($this->editor)->delete("{$this->base}/versions/{$version->id}")->assertSessionHas('success');
    Storage::disk('private')->assertMissing($version->path);
});
