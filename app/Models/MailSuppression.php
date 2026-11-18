<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address that gets no greetings or announcements (§8). Service mail, such
 * as sign in codes, receipts and class reminders, never reads this list.
 */
class MailSuppression extends Model
{
    public const REASONS = [
        'unsubscribed' => 'Unsubscribed',
        'bounced' => 'Bounced',
        'complained' => 'Marked as spam',
        'manual' => 'Added by us',
    ];

    protected $guarded = ['id'];

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function has(string $email): bool
    {
        return static::query()->where('email', self::normalise($email))->exists();
    }
}
