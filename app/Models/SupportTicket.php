<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A support request against a maintenance contract.
 *
 * The response and resolution deadlines are copied onto the ticket when it is
 * raised rather than read from the contract later, so editing a contract cannot
 * retrospectively change whether we met the promise we made at the time.
 */
class SupportTicket extends Model
{
    public const STATUSES = ['open', 'in_progress', 'waiting_on_client', 'resolved', 'closed'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket) {
            $ticket->reference ??= self::nextReference();
        });
    }

    protected function casts(): array
    {
        return [
            'response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(MaintenanceContract::class, 'contract_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'in_progress', 'waiting_on_client']);
    }

    public function scopeForClient(Builder $query, User|int $client): Builder
    {
        return $query->where('client_id', $client instanceof User ? $client->id : $client);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'in_progress', 'waiting_on_client'], true);
    }

    /**
     * Whether we answered inside the promised window.
     *
     * Unanswered and past the deadline is a breach now, not once somebody
     * finally replies, so an open ticket counts against us the moment it is late.
     */
    public function breachedResponse(): bool
    {
        if ($this->response_due_at === null) {
            return false;
        }

        return ($this->first_response_at ?? now())->greaterThan($this->response_due_at);
    }

    public function breachedResolution(): bool
    {
        if ($this->resolution_due_at === null) {
            return false;
        }

        return ($this->resolved_at ?? now())->greaterThan($this->resolution_due_at);
    }

    public function slaLabel(): string
    {
        return match (true) {
            $this->breachedResolution() => 'Resolution overdue',
            $this->breachedResponse() => 'Response overdue',
            $this->resolved_at !== null => 'Met',
            default => 'On track',
        };
    }

    public static function nextReference(): string
    {
        $last = static::query()->orderByDesc('id')->value('reference');
        $number = $last ? (int) str($last)->afterLast('-')->toString() : 0;

        return 'TKT-'.str_pad((string) ($number + 1), 5, '0', STR_PAD_LEFT);
    }
}
