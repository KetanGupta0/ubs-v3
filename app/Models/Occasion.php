<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A day worth a greeting (§8, X7), set by the admin.
 *
 * A national day falls on the same date every year and moves on by itself. A
 * festival's date moves, so after it is sent next year's date is a guess the
 * admin confirms, and an unconfirmed date is never sent.
 */
class Occasion extends Model
{
    public const REPEATS = [
        'same_date' => 'The same date every year',
        'confirm_yearly' => 'A date we confirm each year',
    ];

    protected $guarded = ['id'];

    protected $attributes = ['repeats' => 'confirm_yearly', 'date_confirmed' => false, 'send_time' => '08:00', 'is_active' => false];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'date_confirmed' => 'boolean',
            'audience' => 'array',
            'is_active' => 'boolean',
            'last_sent_on' => 'date',
            'reminded_for' => 'date',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(MailCampaign::class);
    }

    /** Ready to go on its date: switched on, with a date a person stands behind. */
    public function isReady(): bool
    {
        return $this->is_active && $this->date !== null && $this->date_confirmed;
    }
}
