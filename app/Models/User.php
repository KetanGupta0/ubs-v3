<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

/**
 * One identity for administrators, clients and students.
 *
 * `role` decides which dashboard a person lands in. Permissions decide what
 * they may do once inside, and only matter for staff accounts.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'mobile',
        'password',
        'role',
        'status',
        'avatar_path',
        'timezone',
        'locale',
        'must_change_password',
        // is_owner is deliberately absent: unrestricted access is not
        // something a submitted form should ever be able to grant.
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = ['avatar_url'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'is_owner' => 'boolean',
        ];
    }

    /* ------------------------------------------------------------ relations */

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function twoFactorSecret(): HasOne
    {
        return $this->hasOne(TwoFactorSecret::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function authAuditLogs(): HasMany
    {
        return $this->hasMany(AuthAuditLog::class);
    }

    /* -------------------------------------------------------------- scopes */

    public function scopeRole(Builder $query, Role|string $role): Builder
    {
        return $query->where('role', $role instanceof Role ? $role->value : $role);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active->value);
    }

    /* ------------------------------------------------------------- helpers */

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isClient(): bool
    {
        return $this->role === Role::Client;
    }

    public function isStudent(): bool
    {
        return $this->role === Role::Student;
    }

    public function canSignIn(): bool
    {
        return $this->status->canSignIn();
    }

    /**
     * The owner holds every permission implicitly, which saves a row per
     * capability for the one account that is never restricted. Everybody else,
     * staff included, holds exactly what has been granted: an administrator
     * hired to run the leads inbox is not thereby handed the bank details.
     */
    public function hasPermission(string $key): bool
    {
        if ($this->is_owner) {
            return true;
        }

        return $this->permissions->contains('key', $key);
    }

    /** Every capability this account holds, for the client side navigation. */
    public function permissionKeys(): array
    {
        return $this->is_owner
            ? Permission::query()->pluck('key')->all()
            : $this->permissions->pluck('key')->all();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->twoFactorSecret?->isConfirmed() ?? false;
    }

    public function hasPassword(): bool
    {
        return filled($this->password);
    }

    /** Where to send this person after they sign in. */
    public function homeRoute(): string
    {
        return $this->role->home();
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if (blank($this->avatar_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    /**
     * The channel a one time code should go to for a given destination.
     *
     * Matching on the stored value rather than trusting what was typed means a
     * code is only ever sent to an address already on the account.
     */
    public function channelFor(string $destination): ?string
    {
        return match (true) {
            $this->email === $destination => 'email',
            $this->mobile === $destination => 'sms',
            default => null,
        };
    }

    public function routeNotificationForSms(): ?string
    {
        return $this->mobile;
    }
}
