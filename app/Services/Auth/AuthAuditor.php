<?php

namespace App\Services\Auth;

use App\Enums\AuthEvent;
use App\Models\AuthAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Records authentication events.
 *
 * Writes what was attempted and from where, never the credential that was
 * tried, so the log is useful for spotting an attack without becoming a
 * collection of near miss passwords.
 */
class AuthAuditor
{
    public function __construct(protected Request $request) {}

    public function record(
        AuthEvent $event,
        ?User $user = null,
        ?string $identifier = null,
        bool $succeeded = true,
        ?string $reason = null,
        ?string $method = null,
        array $context = [],
    ): AuthAuditLog {
        return AuthAuditLog::query()->create([
            'user_id' => $user?->id,
            'event' => $event,
            'identifier' => $identifier ? $this->mask($identifier) : null,
            'method' => $method,
            'succeeded' => $succeeded,
            'reason' => $reason,
            'ip_address' => $this->request->ip(),
            'user_agent' => str($this->request->userAgent() ?? '')->limit(500)->toString(),
            'context' => $context ?: null,
        ]);
    }

    public function success(AuthEvent $event, ?User $user = null, ?string $method = null, array $context = []): AuthAuditLog
    {
        return $this->record($event, $user, $user?->email, true, null, $method, $context);
    }

    public function failure(AuthEvent $event, ?string $identifier = null, ?string $reason = null, ?User $user = null, ?string $method = null): AuthAuditLog
    {
        return $this->record($event, $user, $identifier, false, $reason, $method);
    }

    /**
     * Partially redact the identifier.
     *
     * Enough to recognise a targeted account when reading the log, not enough
     * to turn the log into a harvestable list of customer contact details.
     */
    protected function mask(string $identifier): string
    {
        if (str_contains($identifier, '@')) {
            [$local, $domain] = explode('@', $identifier, 2);

            return str($local)->limit(2, '')->toString().str_repeat('*', max(strlen($local) - 2, 1))."@{$domain}";
        }

        return strlen($identifier) > 4
            ? str_repeat('*', strlen($identifier) - 4).substr($identifier, -4)
            : $identifier;
    }
}
