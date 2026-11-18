<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One send of promotional mail (§8): a broadcast, or one year's greeting for
 * an occasion. The words and the audience are fixed in it when it is made.
 */
class MailCampaign extends Model
{
    public const STATUSES = [
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'sending' => 'Sending',
        'sent' => 'Sent',
        'cancelled' => 'Cancelled',
    ];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function occasion(): BelongsTo
    {
        return $this->belongsTo(Occasion::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MailCampaignRecipient::class, 'campaign_id');
    }

    public function scopeSending(Builder $query): Builder
    {
        return $query->where('status', 'sending');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /** @return array{recipients: int, queued: int, sent: int, skipped: int, failed: int, unsubscribed: int} */
    public function counts(): array
    {
        $byStatus = $this->recipients()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'recipients' => (int) $byStatus->sum(),
            'queued' => (int) ($byStatus['queued'] ?? 0),
            'sent' => (int) ($byStatus['sent'] ?? 0),
            'skipped' => (int) ($byStatus['skipped'] ?? 0),
            'failed' => (int) ($byStatus['failed'] ?? 0),
            'unsubscribed' => MailSuppression::query()->where('campaign_id', $this->id)->count(),
        ];
    }
}
