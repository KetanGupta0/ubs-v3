<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something of a client's that lapses unless renewed: a domain, hosting, a certificate (CL16). */
class RenewalItem extends Model
{
    public const KINDS = [
        'domain' => 'Domain',
        'hosting' => 'Hosting',
        'ssl' => 'SSL certificate',
        'update_window' => 'Update window',
        'other' => 'Something else',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'auto_renew' => 'boolean',
            'reminders_sent' => 'array',
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForClient(Builder $query, User $account): Builder
    {
        return $query->where('client_id', $account->id);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Something else';
    }

    public function daysToExpiry(): int
    {
        return (int) today()->diffInDays($this->expires_on, false);
    }

    /** Who hears about it: money matters go to finance, certificates and updates to technical people. */
    public function audience(): string
    {
        return in_array($this->kind, ['ssl', 'update_window'], true) ? 'technical' : 'billing';
    }

    /** A lapsed domain or certificate takes a site down: our side hears too. */
    public function isEmergencyIfMissed(): bool
    {
        return in_array($this->kind, ['domain', 'ssl'], true);
    }
}
