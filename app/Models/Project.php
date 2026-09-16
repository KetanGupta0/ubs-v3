<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A piece of work we are doing for one client.
 *
 * Progress is stored rather than derived from milestones, because a project can
 * be eighty per cent done with two of five milestones ticked, and the honest
 * number is the one the person running it puts there.
 */
class Project extends Model
{
    use SoftDeletes;

    public const STATUSES = ['planning', 'in_progress', 'review', 'on_hold', 'delivered', 'cancelled'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->code ??= self::nextCode();
        });
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'target_date' => 'date',
            'delivered_on' => 'date',
            'team' => 'array',
            'progress_percent' => 'integer',
        ];
    }

    /* ------------------------------------------------------------ relations */

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('sort_order');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ProjectUpdate::class)->latest('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(MaintenanceContract::class);
    }

    /* --------------------------------------------------------------- scopes */

    public function scopeForClient(Builder $query, User|int $client): Builder
    {
        return $query->where('client_id', $client instanceof User ? $client->id : $client);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['planning', 'in_progress', 'review']);
    }

    /* -------------------------------------------------------------- helpers */

    public function statusLabel(): string
    {
        return str($this->status)->replace('_', ' ')->title()->toString();
    }

    public function budgetLabel(): ?string
    {
        return $this->budget === null ? null : Money::display($this->budget);
    }

    public function isOverdue(): bool
    {
        return $this->target_date !== null
            && $this->target_date->isPast()
            && ! in_array($this->status, ['delivered', 'cancelled'], true);
    }

    /** UBS-P-0001, readable aloud on a call. */
    public static function nextCode(): string
    {
        $last = static::withTrashed()->orderByDesc('id')->value('code');
        $number = $last ? (int) str($last)->afterLast('-')->toString() : 0;

        return 'UBS-P-'.str_pad((string) ($number + 1), 4, '0', STR_PAD_LEFT);
    }
}
