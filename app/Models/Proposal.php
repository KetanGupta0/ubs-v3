<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What we proposed, and what the client said about it.
 *
 * A proposal is only visible to the client once it is sent, and the answer is
 * recorded with a timestamp and the address it came from, because "we never
 * agreed that" is a conversation worth being able to end.
 */
class Proposal extends Model
{
    use SoftDeletes;

    public const STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'withdrawn'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (Proposal $proposal) {
            $proposal->number ??= self::nextNumber();
        });
    }

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
            'assumptions' => 'array',
            'deliverables' => 'array',
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

    public function quotation(): HasOne
    {
        return $this->hasOne(Quotation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What the client is allowed to see: sent, and everything after. */
    public function scopeVisibleToClient(Builder $query): Builder
    {
        return $query->whereIn('status', ['sent', 'accepted', 'rejected', 'expired']);
    }

    public function scopeAwaitingResponse(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    public function hasExpired(): bool
    {
        return $this->status === 'sent'
            && $this->valid_until !== null
            && $this->valid_until->isPast();
    }

    /** Sent, not yet answered, and still inside its validity. */
    public function canBeAnswered(): bool
    {
        return $this->status === 'sent' && ! $this->hasExpired();
    }

    public static function nextNumber(): string
    {
        $year = now()->year;
        $last = static::withTrashed()
            ->where('number', 'like', "UBS/P/{$year}/%")
            ->orderByDesc('id')
            ->value('number');

        $sequence = $last ? (int) str($last)->afterLast('/')->toString() : 0;

        return sprintf('UBS/P/%d/%03d', $year, $sequence + 1);
    }
}
