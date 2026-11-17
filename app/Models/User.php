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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Laravel\Sanctum\HasApiTokens;

/**
 * One identity for administrators, clients and students.
 *
 * `role` decides which dashboard a person lands in. Permissions decide what
 * they may do once inside, and only matter for staff accounts.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** Memo for clientAccount(), for the life of this model instance. */
    protected ?User $resolvedClientAccount = null;

    /** Memo for clientRole(). */
    protected ?string $resolvedClientRole = null;

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
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'is_owner' => 'boolean',
            'reminder_preferences' => 'array',
            'show_on_leaderboard' => 'boolean',
        ];
    }

    /** Seen in the last five minutes: what "online now" means here. */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(5));
    }

    /** Whether this person wants a kind of reminder. Every kind is on until switched off. */
    public function wantsReminder(string $kind): bool
    {
        return ! in_array($kind, $this->reminder_preferences['off'] ?? [], true);
    }

    /* --------------------------------------------- client organisations */

    /** This person's place in a client organisation they joined, if any. */
    public function clientMembership(): HasOne
    {
        return $this->hasOne(ClientMember::class);
    }

    /** The people this account holder has brought into their organisation. */
    public function clientMembers(): HasMany
    {
        return $this->hasMany(ClientMember::class, 'account_id');
    }

    /**
     * The account whose records this person works on: the organisation they
     * joined, or their own. Every client read starts here (through
     * `ClientController::client()`), which is how one rule — a client's own
     * relations are the only way in — covers a whole organisation.
     */
    public function clientAccount(): self
    {
        if (! $this->isClient()) {
            return $this;
        }

        return $this->resolvedClientAccount ??= (function () {
            $membership = $this->clientMembership()->joined()->first();

            return $membership && $membership->account_id !== $this->id
                ? ($membership->account ?? $this)
                : $this;
        })();
    }

    /** owner, signatory, finance or technical. An account holder is an owner. */
    public function clientRole(): ?string
    {
        if (! $this->isClient()) {
            return null;
        }

        return $this->resolvedClientRole ??= $this->clientMembership()->joined()->value('role') ?? 'owner';
    }

    /** Whether this person may do something on behalf of their organisation. */
    public function canAsClient(string $ability): bool
    {
        $role = $this->clientRole();

        return $role !== null && ClientMember::roleMay($role, $ability);
    }

    /** Is this account holder's organisation run from this account (not a member's)? */
    public function isClientAccountHolder(): bool
    {
        return $this->isClient() && $this->clientAccount()->is($this);
    }

    /**
     * Everybody who works on this account's records: the holder and every
     * member who has joined.
     *
     * @return array<int, int>
     */
    public function organisationUserIds(): array
    {
        $account = $this->clientAccount();

        return [$account->id, ...$account->clientMembers()->joined()->pluck('user_id')->all()];
    }

    /**
     * Who in the organisation hears about something: invoices go to finance,
     * proposals and agreements to signatories, the rest to everybody. Owners
     * hear about what nobody else in a role is there for.
     *
     * @return Collection<int, User>
     */
    public function clientRecipients(string $purpose = 'all')
    {
        if (! $this->isClient()) {
            return collect([$this]);
        }

        $account = $this->clientAccount();
        $members = $account->clientMembers()->joined()->with('user')->get()
            ->filter(fn (ClientMember $member) => $member->user?->canSignIn());

        $role = match ($purpose) {
            'billing' => 'finance',
            'signing' => 'signatory',
            'technical' => 'technical',
            default => null,
        };

        if ($role === null) {
            return collect([$account])->concat($members->pluck('user'))->unique('id')->values();
        }

        $inRole = $members->where('role', $role)->pluck('user');
        $owners = collect([$account])->concat($members->where('role', 'owner')->pluck('user'));

        // Owners always hear about signing and money; for technical matters
        // they hear only when there is nobody technical to tell.
        return ($role === 'technical' && $inRole->isNotEmpty() ? $inRole : $inRole->concat($owners))
            ->unique('id')
            ->values();
    }

    /**
     * Client accounts that hold records: every client except those who joined
     * somebody else's organisation. What staff pick when they bill, propose or
     * start a project, so nothing is filed under a member and lost to the
     * organisation.
     */
    public function scopeClientAccounts(Builder $query): Builder
    {
        return $query->role(Role::Client)
            ->whereDoesntHave('clientMembership', fn (Builder $membership) => $membership->whereNotNull('joined_at'));
    }

    /** The validation rule for a client_id: an account that holds records. */
    public static function clientAccountRule(): Exists
    {
        return Rule::exists('users', 'id')
            ->where('role', Role::Client->value)
            ->where(fn ($query) => $query->whereNotIn('id', fn ($members) => $members->select('user_id')->from('client_members')->whereNotNull('joined_at')));
    }

    /** The organisation's name: the account holder's company, or their name. */
    public function organisationName(): string
    {
        $account = $this->clientAccount();

        return $account->profile?->company ?: $account->name;
    }

    /**
     * Whether this client account has anything of its own: an account with
     * history cannot quietly become somebody else's member.
     */
    public function ownsClientRecords(): bool
    {
        return $this->projects()->exists()
            || $this->proposals()->exists()
            || $this->paymentRequests()->exists()
            || $this->maintenanceContracts()->exists()
            || $this->subscriptions()->exists()
            || $this->apiKeys()->exists()
            || $this->clientMembers()->exists();
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

    public function credentialDeliveries(): HasMany
    {
        return $this->hasMany(CredentialDelivery::class);
    }

    /** The most recent time credentials were sent, on any channel. */
    public function latestCredentialDelivery(): HasOne
    {
        return $this->hasOne(CredentialDelivery::class)->latestOfMany();
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

    /* ------------------------------------------- client module (Phase 4) */

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'client_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'client_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'client_id');
    }

    public function maintenanceContracts(): HasMany
    {
        return $this->hasMany(MaintenanceContract::class, 'client_id');
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'requester_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'client_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'client_id');
    }

    /* ---------------------------------------------- learning (Phase 5) */

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function internshipDocuments(): HasMany
    {
        return $this->hasMany(InternshipDocument::class);
    }

    public function mentorReviews(): HasMany
    {
        return $this->hasMany(MentorReview::class);
    }

    public function warnings(): HasMany
    {
        return $this->hasMany(StudentWarning::class);
    }

    public function activity(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(LeaderboardPoint::class);
    }

    /** Batches this account teaches, for a trainer's own screens. */
    public function batchesTaught(): HasMany
    {
        return $this->hasMany(Batch::class, 'trainer_id');
    }

    /* ------------------------------------- money, shared with the LMS */

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
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
