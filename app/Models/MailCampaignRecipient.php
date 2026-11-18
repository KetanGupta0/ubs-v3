<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who one send went to, or why it did not: unsubscribed in the meantime, the
 * weekly limit reached, or a greeting whose day had passed.
 */
class MailCampaignRecipient extends Model
{
    public const SKIP_REASONS = [
        'suppressed' => 'On the do-not-mail list',
        'weekly_cap' => 'Already had enough mail from us this week',
        'expired' => 'The day had passed before it could go',
        'cancelled' => 'The send was cancelled',
    ];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MailCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function firstName(): string
    {
        return str($this->name ?: 'there')->before(' ')->toString();
    }
}
