<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person in a client organisation, and what they may do there.
 *
 * Everybody in an organisation sees its work. What differs is what they may
 * do: only a signatory signs and approves, only finance pays, only technical
 * people manage API keys, and owners do all of it and decide who else is in.
 * The account holder is always an owner, without a row here.
 */
class ClientMember extends Model
{
    public const ROLES = [
        'owner' => ['Owner', 'Everything, including who else is in the organisation.'],
        'signatory' => ['Authorised signatory', 'Signs agreements, accepts proposals and milestones.'],
        'finance' => ['Finance', 'Receives invoices and pays them.'],
        'technical' => ['Technical', 'Raises tickets, gives feedback on builds, manages API keys.'],
    ];

    /** What each role may do. Everybody may view and raise tickets. */
    public const ABILITIES = [
        'owner' => ['*'],
        'signatory' => ['sign', 'approve', 'accept'],
        'finance' => ['pay'],
        'technical' => ['api_keys', 'feedback'],
    ];

    protected $fillable = ['account_id', 'user_id', 'role', 'invited_by', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopeJoined(Builder $query): Builder
    {
        return $query->whereNotNull('joined_at');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('joined_at');
    }

    public static function roleMay(string $role, string $ability): bool
    {
        $abilities = self::ABILITIES[$role] ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role][0] ?? ucfirst($this->role);
    }
}
