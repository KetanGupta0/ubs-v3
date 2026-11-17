<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * An agreement with a client (CL1): a master service agreement, a statement
 * of work, an NDA, a licence.
 *
 * Versioned like proposals. A draft can be edited; once sent, what it says is
 * permanent, whatever code path asks, because a signature (CL2) is a
 * signature of exactly that text. Changing it means a new version, and the
 * one it replaces is marked superseded when the new one goes out.
 */
class Agreement extends Model
{
    public const KINDS = [
        'msa' => 'Master service agreement',
        'sow' => 'Statement of work',
        'nda' => 'Non-disclosure agreement',
        'licence' => 'Licence agreement',
        'other' => 'Agreement',
    ];

    public const STATUSES = [
        'draft' => 'Draft',
        'sent' => 'Waiting for signature',
        'signed' => 'Signed by the client',
        'countersigned' => 'Signed by both',
        'superseded' => 'Replaced by a later version',
        'withdrawn' => 'Withdrawn',
    ];

    /** What a sent agreement says, which nothing may change afterwards. */
    public const FROZEN = [
        'client_id', 'number', 'version', 'kind', 'title', 'body', 'project_id',
        'maintenance_contract_id', 'subscription_id', 'effective_on', 'expires_on',
        'parties', 'pdf_path', 'pdf_sha256', 'sent_at',
    ];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (Agreement $agreement) {
            if ($agreement->getOriginal('status') !== 'draft' && $agreement->isDirty(self::FROZEN)) {
                throw new LogicException("Agreement {$agreement->number} has been sent; its text cannot change. Start a new version.");
            }
        });

        static::deleting(function (Agreement $agreement) {
            if ($agreement->status !== 'draft') {
                throw new LogicException("Agreement {$agreement->number} has been sent and is kept.");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
            'effective_on' => 'date',
            'expires_on' => 'date',
            'sent_at' => 'datetime',
            'parties' => 'array',
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

    public function contract(): BelongsTo
    {
        return $this->belongsTo(MaintenanceContract::class, 'maintenance_contract_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The first person in the client's organisation to open it. */
    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewed_by');
    }

    public function scopeForClient(Builder $query, User|int $client): Builder
    {
        return $query->where('client_id', $client instanceof User ? $client->id : $client);
    }

    /** What a client sees: everything that was ever sent to them, never drafts. */
    public function scopeVisibleToClient(Builder $query): Builder
    {
        return $query->where('status', '!=', 'draft');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Agreement';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public static function nextNumber(): string
    {
        $year = now()->year;
        $last = static::query()->where('number', 'like', "UBS/A/{$year}/%")->orderByDesc('id')->value('number');
        $sequence = $last ? (int) str($last)->afterLast('/')->toString() : 0;

        return sprintf('UBS/A/%d/%03d', $year, $sequence + 1);
    }

    /** Who has signed it, oldest first (CL2). */
    public function signatures(): MorphMany
    {
        return $this->morphMany(DocumentSignature::class, 'signable')->orderBy('signed_at');
    }
}
