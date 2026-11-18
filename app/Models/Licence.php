<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The right to use a product's source code, bought from the shop (CL4).
 *
 * The key is made the first time somebody in the organisation asks for it and
 * is shown that once; only its hash and a prefix are kept, like an API key. A
 * lost key is rotated, never recovered, and the old one stops working.
 */
class Licence extends Model
{
    /** Crockford's alphabet: no I, L, O or U to misread. */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    protected $guarded = ['id'];

    /** The column defaults, on the instance too: a default is never written back to the model that inserted the row. */
    protected $attributes = ['status' => 'active'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'sites' => 'integer',
            'updates_until' => 'date',
            'support_until' => 'date',
            'key_issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class);
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenceActivation::class)->orderBy('first_seen_at');
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(LicenceDownload::class);
    }

    public function scopeForClient(Builder $query, User|int $client): Builder
    {
        return $query->where('client_id', $client instanceof User ? $client->id : $client);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasKey(): bool
    {
        return $this->key_hash !== null;
    }

    /** "UBS-7K3QX-•••••-•••••-•••••-•••••": enough to tell keys apart. */
    public function maskedKey(): ?string
    {
        return $this->key_prefix ? $this->key_prefix.str_repeat('-•••••', 4) : null;
    }

    public function sitesLabel(): string
    {
        return match (true) {
            $this->sites === null => 'Unlimited sites',
            $this->sites === 1 => 'One site',
            default => "Up to {$this->sites} sites",
        };
    }

    /** Sites holding a place: checked in and not released. */
    public function sitesInUse(): int
    {
        return $this->activations->whereNull('released_at')->count();
    }

    public function inUpdateWindow(): bool
    {
        return ! $this->updates_until->isBefore(today());
    }

    /**
     * A new key: "UBS-" and five groups of five, 125 random bits. Returned
     * with the hash that is stored and the prefix that is shown.
     *
     * @return array{key: string, hash: string, prefix: string}
     */
    public static function generateKey(): array
    {
        $characters = '';

        for ($i = 0; $i < 25; $i++) {
            $characters .= self::ALPHABET[random_int(0, 31)];
        }

        $key = 'UBS-'.implode('-', str_split($characters, 5));

        return ['key' => $key, 'hash' => self::hashOf($key), 'prefix' => substr($key, 0, 9)];
    }

    /**
     * The stored form of a key as somebody typed or pasted it: case, spaces,
     * dashes and the letters people mistake for digits do not matter.
     */
    public static function hashOf(string $key): string
    {
        $clean = strtr(strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $key) ?? ''), ['I' => '1', 'L' => '1', 'O' => '0']);

        return hash('sha256', $clean);
    }

    public static function findByKey(string $key): ?self
    {
        return static::query()->where('key_hash', self::hashOf($key))->first();
    }
}
