<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Something that renews: a maintenance contract, an API plan, a hosted service.
 *
 * `reminders_sent` records which reminder windows have already gone out, so a
 * daily job cannot send the same thirty day warning thirty times.
 */
class Subscription extends Model
{
    public const INTERVALS = ['monthly', 'quarterly', 'half_yearly', 'yearly'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'renews_on' => 'date',
            'ends_on' => 'date',
            'auto_renew' => 'boolean',
            'reminders_sent' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForClient(Builder $query, User|int $client): Builder
    {
        return $query->where('client_id', $client instanceof User ? $client->id : $client);
    }

    public function scopeRenewingWithin(Builder $query, int $days): Builder
    {
        return $query->active()->whereBetween('renews_on', [today(), today()->addDays($days)]);
    }

    public function daysToRenewal(): int
    {
        return (int) today()->diffInDays($this->renews_on, false);
    }

    public function hasLapsed(): bool
    {
        return $this->status === 'active' && $this->renews_on->isPast();
    }

    public function amountLabel(): string
    {
        return Money::display($this->amount);
    }

    public function intervalLabel(): string
    {
        return str($this->interval)->replace('_', ' ')->title()->toString();
    }

    /** The next renewal date, one interval on from the current one. */
    public function nextRenewalDate(): Carbon
    {
        return match ($this->interval) {
            'monthly' => $this->renews_on->copy()->addMonth(),
            'quarterly' => $this->renews_on->copy()->addMonths(3),
            'half_yearly' => $this->renews_on->copy()->addMonths(6),
            default => $this->renews_on->copy()->addYear(),
        };
    }
}
