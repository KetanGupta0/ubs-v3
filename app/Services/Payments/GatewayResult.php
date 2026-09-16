<?php

namespace App\Services\Payments;

final class GatewayResult
{
    private function __construct(
        public readonly bool $successful,
        public readonly ?string $paymentId = null,
        public readonly ?string $method = null,
        public readonly ?string $failureReason = null,
        public readonly array $raw = [],
    ) {}

    public static function success(string $paymentId, ?string $method = null, array $raw = []): self
    {
        return new self(true, $paymentId, $method, null, $raw);
    }

    public static function failure(string $reason, array $raw = []): self
    {
        return new self(false, null, null, $reason, $raw);
    }
}
