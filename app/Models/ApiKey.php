<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A key a client holds for one of our products.
 *
 * The secret is shown exactly once, at issue. After that only a hash and the
 * prefix are stored: enough to identify which key somebody is using, not enough
 * to use it. A lost key is rotated, not recovered, because a key we could read
 * back to a client is a key anybody with the database can read too.
 */
class ApiKey extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'quota_period_start' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ApiKeyPlan::class, 'api_key_plan_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function usage(): HasMany
    {
        return $this->hasMany(ApiKeyUsageLog::class);
    }

    public function rotatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rotated_from_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForClient(Builder $query, User|int $client): Builder
    {
        return $query->where('client_id', $client instanceof User ? $client->id : $client);
    }

    /**
     * Generate a key, returning the secret once and storing only its hash.
     *
     * @return array{key: string, prefix: string, hash: string, lastFour: string}
     */
    public static function generate(string $environment = 'live'): array
    {
        $prefix = 'ubs_'.($environment === 'test' ? 'test' : 'live');
        $secret = Str::random(40);
        $key = "{$prefix}_{$secret}";

        return [
            'key' => $key,
            'prefix' => $prefix,
            'hash' => hash('sha256', $key),
            'lastFour' => mb_substr($secret, -4),
        ];
    }

    public static function findByKey(string $key): ?self
    {
        return static::query()->where('key_hash', hash('sha256', $key))->first();
    }

    /** What is safe to print on a screen: never the key itself. */
    public function masked(): string
    {
        return "{$this->key_prefix}_".str_repeat('•', 8).$this->last_four;
    }

    public function isUsable(): bool
    {
        return $this->status === 'active'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function quota(): int
    {
        return $this->plan?->monthly_quota ?? 0;
    }

    public function quotaPercent(): int
    {
        $quota = $this->quota();

        return $quota === 0 ? 0 : min(100, (int) round($this->quota_used / $quota * 100));
    }

    public function hasExceededQuota(): bool
    {
        return $this->quota() > 0 && $this->quota_used >= $this->quota();
    }
}
