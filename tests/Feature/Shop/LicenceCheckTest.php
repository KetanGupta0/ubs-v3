<?php

use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Order;
use App\Models\User;
use App\Services\Shop\Licences;
use Tests\Support\ShopCatalogue;

/** A paid licence for a made up product, with a key in hand. */
function licenceWithKey(int $sites = 1): array
{
    $shop = ShopCatalogue::product('Clinic desk '.uniqid());
    $client = User::factory()->client()->create();
    $order = Order::query()->create([
        'client_id' => $client->id, 'user_id' => $client->id, 'status' => 'paid', 'subtotal' => 100, 'taxable' => 100,
        'tax_rate' => 18, 'tax_breakup' => [], 'tax' => 18, 'total' => 118, 'billing' => ['name' => 'Acme', 'state' => 'Maharashtra'], 'paid_at' => now(),
    ]);
    $item = $order->items()->create(['solution_id' => $shop['solution']->id, 'kind' => 'licence', 'description' => 'Clinic desk, Single site licence', 'unit_price' => 100, 'amount' => 100]);
    $generated = Licence::generateKey();

    $licence = Licence::query()->create([
        'order_id' => $order->id, 'order_item_id' => $item->id, 'client_id' => $client->id, 'solution_id' => $shop['solution']->id,
        'product' => 'Clinic desk', 'tier' => 'Single site', 'sites' => $sites, 'updates_until' => today()->addYear(),
        'key_hash' => $generated['hash'], 'key_prefix' => $generated['prefix'], 'key_issued_at' => now(),
    ]);

    return [$licence, $generated['key'], $shop['solution']];
}

it('answers a good key with what the licence allows, and takes a place for the site', function () {
    [$licence, $key, $solution] = licenceWithKey();
    $solution->versions()->create(['version' => '1.2.0', 'released_on' => today(), 'path' => 'x.zip', 'file_name' => 'x.zip', 'size' => 1, 'sha256' => str_repeat('a', 64)]);

    $this->postJson('/api/v1/licences/check', ['key' => $key, 'site' => 'https://www.Clinic.Example.com/admin', 'product' => $solution->slug, 'version' => '1.0.0'])
        ->assertOk()
        ->assertJson([
            'valid' => true,
            'status' => 'active',
            'site' => 'clinic.example.com',
            'development' => false,
            'licence' => ['tier' => 'Single site', 'sites' => ['used' => 1, 'limit' => 1]],
            'updates' => ['latest_version' => '1.2.0', 'newer_than_yours' => true],
            'check_again_after' => 86400,
        ]);

    // The same site again keeps its place.
    $this->postJson('/api/v1/licences/check', ['key' => $key, 'site' => 'clinic.example.com', 'version' => '1.2.0'])->assertJson(['valid' => true, 'updates' => ['newer_than_yours' => false]]);
    expect(LicenceActivation::query()->sole()->last_version)->toBe('1.2.0');
});

it('turns away another site once the places are taken, until one is freed', function () {
    [$licence, $key] = licenceWithKey(sites: 1);

    $this->postJson('/api/v1/licences/check', ['key' => $key, 'site' => 'clinic.example.com'])->assertJson(['valid' => true]);
    $this->postJson('/api/v1/licences/check', ['key' => $key, 'site' => 'second.example.com'])
        ->assertJson(['valid' => false, 'status' => 'site_limit', 'sites' => ['used' => 1, 'limit' => 1]]);

    // Trying the code out does not use a place.
    foreach (['localhost:8000', 'clinic.test', '192.168.1.20', 'http://127.0.0.1/'] as $development) {
        $this->postJson('/api/v1/licences/check', ['key' => $key, 'site' => $development])->assertJson(['valid' => true, 'development' => true]);
    }

    app(Licences::class)->release(LicenceActivation::query()->sole(), $licence->client);
    $this->postJson('/api/v1/licences/check', ['key' => $key, 'site' => 'second.example.com'])->assertJson(['valid' => true, 'licence' => ['sites' => ['used' => 1]]]);
    expect(LicenceActivation::query()->whereNull('released_at')->sole()->domain)->toBe('second.example.com');
});

it('says the same about a key that does not exist and one for another product, and says when one is withdrawn', function () {
    [$licence, $key] = licenceWithKey();
    $unknown = ['valid' => false, 'status' => 'invalid', 'message' => 'This licence key is not valid.'];

    $this->postJson('/api/v1/licences/check', ['key' => 'UBS-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA', 'site' => 'clinic.example.com'])->assertExactJson([...$unknown, 'check_again_after' => 3600]);
    $this->postJson('/api/v1/licences/check', ['key' => $key, 'site' => 'clinic.example.com', 'product' => 'some-other-product'])->assertExactJson([...$unknown, 'check_again_after' => 3600]);

    app(Licences::class)->revoke($licence, 'Refunded.');
    $this->postJson('/api/v1/licences/check', ['key' => $key, 'site' => 'clinic.example.com'])->assertJson(['valid' => false, 'status' => 'revoked']);

    $this->postJson('/api/v1/licences/check', ['key' => $key, 'site' => 'not a host!'])->assertStatus(422)->assertJsonValidationErrors('site');
    expect(LicenceActivation::query()->count())->toBe(0);
});

it('is throttled by address', function () {
    [, $key] = licenceWithKey();

    foreach (range(1, 30) as $ignored) {
        $this->postJson('/api/v1/licences/check', ['key' => $key, 'site' => 'clinic.test']);
    }

    $this->postJson('/api/v1/licences/check', ['key' => $key, 'site' => 'clinic.test'])->assertStatus(429);
});
