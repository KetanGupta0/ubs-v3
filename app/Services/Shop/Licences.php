<?php

namespace App\Services\Shop;

use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\LicenceDownload;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Services\Admin\Auditor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Licences for source code (CL4, X18): issued when an order is paid, keys
 * shown once, sites activated up to the tier's limit, updates downloadable
 * through the update window, revoked by a refund.
 */
class Licences
{
    public function __construct(protected Auditor $auditor) {}

    /**
     * One licence for every licence line of a paid order, with the months of
     * support and updates its add-ons bought counted from the day it was paid.
     * Safe to run twice: a line that already has its licence is left alone.
     */
    public function issueFor(Order $order): void
    {
        $order->loadMissing('items');
        $from = ($order->paid_at ?? now())->copy()->startOfDay();

        foreach ($order->items->where('kind', 'licence') as $item) {
            if (Licence::query()->where('order_item_id', $item->id)->exists()) {
                continue;
            }

            $terms = $item->terms ?? [];
            $addons = $order->items->where('parent_id', $item->id);
            $months = fn (string $kind) => (int) $addons->filter(fn (OrderItem $addon) => ($addon->terms['kind'] ?? null) === $kind)->sum('quantity');

            $support = (int) ($terms['support_months'] ?? 0) + $months('support');

            Licence::query()->create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'client_id' => $order->client_id,
                'solution_id' => $item->solution_id,
                'product' => $terms['product'] ?? $item->description,
                'tier' => $terms['tier'] ?? '',
                'sites' => $terms['sites'] ?? null,
                'updates_until' => $from->copy()->addMonthsNoOverflow((int) ($terms['update_months'] ?? 0) + $months('updates')),
                'support_until' => $support > 0 ? $from->copy()->addMonthsNoOverflow($support) : null,
                'status' => 'active',
            ]);
        }
    }

    /**
     * Make the key and hand it over, once.
     *
     * @return string The key. It is not kept anywhere, so it cannot be shown again.
     */
    public function issueKey(Licence $licence, User $by): string
    {
        return DB::transaction(function () use ($licence, $by) {
            $locked = Licence::query()->lockForUpdate()->findOrFail($licence->id);

            if (! $locked->isActive()) {
                throw new InvalidArgumentException('This licence has been withdrawn, so it has no key.');
            }

            if ($locked->hasKey()) {
                throw new InvalidArgumentException('The key was shown when it was made and is not kept. If it is lost, make a new one: the old one stops working.');
            }

            return $this->newKey($locked, $by, 'licence.key_issued');
        });
    }

    /** A new key for a lost or leaked one. The old one stops working at once; sites keep their places. */
    public function rotateKey(Licence $licence, User $by): string
    {
        return DB::transaction(function () use ($licence, $by) {
            $locked = Licence::query()->lockForUpdate()->findOrFail($licence->id);

            if (! $locked->isActive()) {
                throw new InvalidArgumentException('This licence has been withdrawn, so it has no key.');
            }

            return $this->newKey($locked, $by, 'licence.key_rotated');
        });
    }

    protected function newKey(Licence $licence, User $by, string $action): string
    {
        $generated = Licence::generateKey();

        $licence->forceFill([
            'key_hash' => $generated['hash'],
            'key_prefix' => $generated['prefix'],
            'key_issued_at' => now(),
        ])->save();

        $this->auditor->actingAs($by)->action($action, $licence, ['prefix' => $generated['prefix']], "{$licence->product}, {$licence->tier}");

        return $generated['key'];
    }

    public function revoke(Licence $licence, string $reason, ?User $by = null): void
    {
        if (! $licence->isActive()) {
            return;
        }

        $licence->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => $reason])->save();
        $this->auditor->actingAs($by)->action('licence.revoked', $licence, ['reason' => $reason], "{$licence->product}, {$licence->tier}");
    }

    public function restore(Licence $licence, User $by): void
    {
        if ($licence->isActive()) {
            return;
        }

        $licence->forceFill(['status' => 'active', 'revoked_at' => null, 'revoked_reason' => null])->save();
        $this->auditor->actingAs($by)->action('licence.restored', $licence, label: "{$licence->product}, {$licence->tier}");
    }

    /** Free a site's place, so the licence can be used somewhere else. */
    public function release(LicenceActivation $activation, User $by): void
    {
        if ($activation->released_at) {
            return;
        }

        $activation->forceFill(['released_at' => now(), 'released_by' => $by->id])->save();
        $this->auditor->actingAs($by)->action('licence.site_released', $activation->licence, ['domain' => $activation->domain], $activation->domain);
    }

    /**
     * Every version this licence may download: available, and released no
     * later than the end of its update window.
     *
     * @return Collection<int, ProductVersion>
     */
    public function versionsFor(Licence $licence): Collection
    {
        if (! $licence->isActive() || ! $licence->solution_id) {
            return collect();
        }

        return ProductVersion::query()
            ->where('solution_id', $licence->solution_id)
            ->available()
            ->whereDate('released_on', '<=', $licence->updates_until)
            ->newestFirst()
            ->get();
    }

    public function downloadsLeft(Licence $licence, ProductVersion $version): int
    {
        $used = LicenceDownload::query()->where('licence_id', $licence->id)->where('product_version_id', $version->id)->count();

        return max(0, (int) config('company.shop.downloads_per_version') - $used);
    }

    /** Why this version cannot be downloaded under this licence, or null when it can. */
    public function downloadRefusal(Licence $licence, ProductVersion $version): ?string
    {
        return match (true) {
            ! $licence->isActive() => 'This licence has been withdrawn.',
            ! $this->versionsFor($licence)->contains('id', $version->id) => 'This version is not part of this licence\'s update window.',
            $this->downloadsLeft($licence, $version) === 0 => 'This version has been downloaded as many times as a licence allows. Write to us if you need it again.',
            default => null,
        };
    }

    /**
     * The software asks whether its key is good for the site it runs on (X18).
     *
     * A site takes a place the first time it checks in, while the tier has
     * places left, and keeps it until somebody frees it. Development hosts
     * (localhost, .test and the like) are answered without taking a place, so
     * trying the code out does not use up a licence.
     *
     * @return array<string, mixed> The answer, ready to send.
     */
    public function check(string $key, string $host, ?string $product, ?string $version, ?string $ip): array
    {
        $again = (int) config('company.shop.licence_check_hours') * 3600;
        $licence = Licence::findByKey($key);

        // A key that does not exist, and one for another product, get the same answer.
        if (! $licence || ($product !== null && $licence->solution?->slug !== $product)) {
            return ['valid' => false, 'status' => 'invalid', 'message' => 'This licence key is not valid.', 'check_again_after' => 3600];
        }

        if (! $licence->isActive()) {
            return ['valid' => false, 'status' => 'revoked', 'message' => 'This licence has been withdrawn.', 'check_again_after' => 3600];
        }

        $development = self::isDevelopmentHost($host);

        $refusal = $development ? null : DB::transaction(function () use ($licence, $host, $version, $ip) {
            $locked = Licence::query()->lockForUpdate()->findOrFail($licence->id);
            $site = LicenceActivation::query()->where('licence_id', $locked->id)->where('domain', $host)->first();

            if (! $site || $site->released_at) {
                $inUse = LicenceActivation::query()->where('licence_id', $locked->id)->whereNull('released_at')->count();

                if ($locked->sites !== null && $inUse >= $locked->sites) {
                    return $inUse;
                }
            }

            $site ??= new LicenceActivation(['licence_id' => $locked->id, 'domain' => $host]);
            $site->forceFill([
                'first_seen_at' => $site->released_at || ! $site->exists ? now() : $site->first_seen_at,
                'last_seen_at' => now(),
                'last_version' => $version !== null ? mb_substr($version, 0, 30) : $site->last_version,
                'last_ip' => $ip,
                'released_at' => null,
                'released_by' => null,
            ])->save();

            return null;
        });

        $licence->forceFill(['last_checked_at' => now()])->save();
        $licence->load('activations');

        $sites = ['used' => $licence->sitesInUse(), 'limit' => $licence->sites];

        if ($refusal !== null) {
            return [
                'valid' => false,
                'status' => 'site_limit',
                'message' => "This licence is in use on {$refusal} ".str('site')->plural($refusal).', which is all it allows. Free one in your account to use it here.',
                'sites' => $sites,
                'check_again_after' => 3600,
            ];
        }

        $latest = $this->versionsFor($licence)->first();

        return [
            'valid' => true,
            'status' => 'active',
            'product' => $licence->solution?->slug,
            'site' => $host,
            'development' => $development,
            'licence' => [
                'tier' => $licence->tier,
                'sites' => $sites,
                'updates_until' => $licence->updates_until->toDateString(),
                'support_until' => $licence->support_until?->toDateString(),
            ],
            'updates' => [
                'latest_version' => $latest?->version,
                'newer_than_yours' => $latest !== null && $version !== null && version_compare($latest->version, $version, '>'),
            ],
            'check_again_after' => $again,
        ];
    }

    /**
     * The host a site is reached at, from whatever the software sent: a URL, a
     * host with a port, upper case, a leading www. Null when it is not a host.
     */
    public static function hostOf(string $site): ?string
    {
        $site = trim(mb_strtolower($site));
        $host = parse_url(str_contains($site, '://') ? $site : "http://{$site}", PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = rtrim(preg_replace('/^www\./', '', $host) ?? $host, '.');

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return trim($host, '[]');
        }

        return preg_match('/^(?=.{1,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) ? $host : null;
    }

    /** Somewhere code is tried out, never a live site. */
    public static function isDevelopmentHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return $host === 'localhost' || (bool) preg_match('/\.(localhost|test|local|example|invalid)$/', $host);
    }

    public function recordDownload(Licence $licence, ProductVersion $version, ?User $user, ?string $ip): void
    {
        LicenceDownload::query()->create([
            'licence_id' => $licence->id,
            'product_version_id' => $version->id,
            'user_id' => $user?->id,
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
