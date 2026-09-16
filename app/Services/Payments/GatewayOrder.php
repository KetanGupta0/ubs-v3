<?php

namespace App\Services\Payments;

/**
 * What the checkout screen needs to open the provider's widget.
 *
 * Only the publishable key is ever in here. The secret stays on the server.
 */
final class GatewayOrder
{
    public function __construct(
        public readonly string $id,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $publicKey = null,
        public readonly array $extra = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'publicKey' => $this->publicKey,
            ...$this->extra,
        ];
    }
}
