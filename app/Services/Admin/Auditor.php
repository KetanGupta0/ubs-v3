<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Records what an administrator changed.
 *
 * Stores only the attributes that actually moved, as before and after. Whole
 * record snapshots would bury the one field somebody is looking for, and would
 * grow the table for no benefit.
 *
 * Anything that looks like a credential is redacted, because an audit log that
 * captures a password hash is a second place that hash now lives.
 */
class Auditor
{
    /** Attribute names whose values are never written to the log. */
    protected const REDACTED = [
        'password', 'remember_token', 'secret', 'recovery_codes',
        'code_hash', 'token', 'api_key', 'key_hash', 'push_token',
    ];

    public function __construct(protected Request $request) {}

    public function created(Model $subject, ?string $label = null): AuditLog
    {
        return $this->write('created', $subject, $label, $this->redact($subject->getAttributes()));
    }

    /**
     * Log an update, using the model's own dirty tracking.
     *
     * Call this before saving, or pass the changes explicitly. Returns null
     * when nothing meaningful changed, so a no-op save does not litter the log.
     */
    public function updated(Model $subject, ?array $changes = null, ?string $label = null): ?AuditLog
    {
        $changes ??= $this->diff($subject);

        if ($changes === []) {
            return null;
        }

        return $this->write('updated', $subject, $label, $changes);
    }

    public function deleted(Model $subject, ?string $label = null): AuditLog
    {
        return $this->write('deleted', $subject, $label);
    }

    /** For actions that are not a plain create, update or delete. */
    public function action(string $action, ?Model $subject = null, array $context = [], ?string $label = null): AuditLog
    {
        return $this->write($action, $subject, $label, $context ?: null);
    }

    protected function write(string $action, ?Model $subject, ?string $label, ?array $changes = null): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $this->request->user()?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label ?? $this->describe($subject),
            'changes' => $changes,
            'ip_address' => $this->request->ip(),
        ]);
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    protected function diff(Model $subject): array
    {
        $changes = [];

        foreach ($subject->getDirty() as $key => $new) {
            if (in_array($key, self::REDACTED, true)) {
                $changes[$key] = ['from' => '••••', 'to' => '••••'];

                continue;
            }

            if (in_array($key, ['updated_at', 'created_at'], true)) {
                continue;
            }

            $changes[$key] = [
                'from' => $this->stringify($subject->getOriginal($key)),
                'to' => $this->stringify($new),
            ];
        }

        return $changes;
    }

    protected function redact(array $attributes): array
    {
        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = '••••';
            }
        }

        unset($attributes['created_at'], $attributes['updated_at']);

        return array_map($this->stringify(...), $attributes);
    }

    /** Keep the stored value small and readable. */
    protected function stringify(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            is_array($value) => str(json_encode($value))->limit(300)->toString(),
            is_string($value) => str($value)->limit(300)->toString(),
            default => $value,
        };
    }

    protected function describe(?Model $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        foreach (['title', 'name', 'reference', 'question', 'key'] as $attribute) {
            if (filled($subject->getAttribute($attribute))) {
                return str((string) $subject->getAttribute($attribute))->limit(120)->toString();
            }
        }

        return class_basename($subject).' #'.$subject->getKey();
    }
}
