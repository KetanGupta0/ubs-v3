<?php

namespace App\Services\Payments;

use App\Models\Transaction;
use Illuminate\Support\Str;

/**
 * The stand in used when no provider is configured.
 *
 * It exists so the whole payment path — raise a request, issue an invoice, open
 * checkout, record a transaction, mark the invoice paid, produce a receipt — can
 * be run and tested without a Razorpay account and without moving money.
 *
 * It refuses to run in production. A gateway that marks anything paid on
 * request is a gift to whoever finds it, and "we forgot to set the keys" is
 * exactly how it would end up there.
 */
class ManualGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function isLive(): bool
    {
        return false;
    }

    public function createOrder(Transaction $transaction): GatewayOrder
    {
        return new GatewayOrder(
            id: 'order_dev_'.Str::lower(Str::random(14)),
            amount: $transaction->amount,
            currency: $transaction->currency,
            publicKey: null,
            extra: ['simulated' => true],
        );
    }

    public function verify(Transaction $transaction, array $payload): GatewayResult
    {
        if (app()->isProduction()) {
            return GatewayResult::failure('No payment provider is configured.');
        }

        if (($payload['outcome'] ?? 'success') === 'failure') {
            return GatewayResult::failure('Simulated failure.');
        }

        return GatewayResult::success(
            'pay_dev_'.Str::lower(Str::random(14)),
            $payload['method'] ?? 'upi',
            ['simulated' => true],
        );
    }

    public function verifyWebhook(string $body, ?string $signature): bool
    {
        return false;
    }
}
