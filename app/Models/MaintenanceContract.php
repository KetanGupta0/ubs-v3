<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An annual maintenance contract: what is covered, and how fast we answer.
 */
class MaintenanceContract extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (MaintenanceContract $contract) {
            $contract->reference ??= self::nextReference();
        });
    }

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'exclusions' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'contract_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereDate('ends_on', '>=', today());
    }

    public function scopeForClient(Builder $query, User|int $client): Builder
    {
        return $query->where('client_id', $client instanceof User ? $client->id : $client);
    }

    public function hasExpired(): bool
    {
        return $this->ends_on->isPast();
    }

    public function isExpiring(int $days = 45): bool
    {
        return ! $this->hasExpired() && $this->ends_on->diffInDays(today()) <= $days;
    }

    public function daysRemaining(): int
    {
        return max(0, (int) today()->diffInDays($this->ends_on, false));
    }

    public function ticketsUsed(): int
    {
        return $this->tickets()->whereYear('created_at', now()->year)->count();
    }

    public function amountLabel(): string
    {
        return Money::display($this->amount);
    }

    public static function nextReference(): string
    {
        $last = static::query()->orderByDesc('id')->value('reference');
        $number = $last ? (int) str($last)->afterLast('-')->toString() : 0;

        return 'UBS-AMC-'.str_pad((string) ($number + 1), 4, '0', STR_PAD_LEFT);
    }
}
