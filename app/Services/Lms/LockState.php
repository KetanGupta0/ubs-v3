<?php

namespace App\Services\Lms;

/**
 * Whether a lesson is open, and if not, what would open it.
 *
 * The reason matters as much as the answer. "Locked" on its own is the most
 * annoying word in any learning system; "opens on 12 March" tells somebody what
 * to do with their week.
 */
final class LockState
{
    private function __construct(
        public readonly bool $open,
        public readonly ?string $reason = null,
        public readonly ?string $kind = null,
        public readonly array $context = [],
    ) {}

    public static function open(): self
    {
        return new self(true);
    }

    public static function locked(string $kind, string $reason, array $context = []): self
    {
        return new self(false, $reason, $kind, $context);
    }

    public function toArray(): array
    {
        return [
            'open' => $this->open,
            'reason' => $this->reason,
            'kind' => $this->kind,
            ...$this->context,
        ];
    }
}
