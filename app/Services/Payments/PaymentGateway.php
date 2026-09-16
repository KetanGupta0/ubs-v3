<?php

namespace App\Services\Payments;

use App\Models\Transaction;

/**
 * What the application needs from a payment provider, and nothing more.
 *
 * Keeping this narrow is what lets the checkout screen, the tests and the
 * development environment run without a provider account, and what will let a
 * second provider be added later without touching a controller.
 */
interface PaymentGateway
{
    public function name(): string;

    /** Create the order the client's browser will hand to the provider. */
    public function createOrder(Transaction $transaction): GatewayOrder;

    /**
     * Confirm a payment the browser reported.
     *
     * Implementations must verify the provider's signature. A callback is
     * client controlled, so an unverified one is a request to mark any invoice
     * paid for free.
     */
    public function verify(Transaction $transaction, array $payload): GatewayResult;

    /** Verify a webhook body against the shared secret. */
    public function verifyWebhook(string $body, ?string $signature): bool;

    /** Whether real money can move, or this is the development stand in. */
    public function isLive(): bool;
}
