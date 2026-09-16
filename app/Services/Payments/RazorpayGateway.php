<?php

namespace App\Services\Payments;

use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Razorpay, over its REST API.
 *
 * No SDK: two endpoints and an HMAC are not worth a dependency that has to be
 * kept current.
 */
class RazorpayGateway implements PaymentGateway
{
    public function __construct(
        protected ?string $keyId,
        protected ?string $keySecret,
        protected ?string $webhookSecret,
    ) {}

    public function name(): string
    {
        return 'razorpay';
    }

    public function isLive(): bool
    {
        return filled($this->keyId) && filled($this->keySecret);
    }

    public function createOrder(Transaction $transaction): GatewayOrder
    {
        $response = Http::withBasicAuth($this->keyId, $this->keySecret)
            ->asJson()
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'receipt' => $transaction->reference,
                'notes' => [
                    'invoice' => $transaction->invoice?->number,
                    'user_id' => (string) $transaction->user_id,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Razorpay refused the order: '.$response->body());
        }

        return new GatewayOrder(
            id: $response->json('id'),
            amount: $transaction->amount,
            currency: $transaction->currency,
            publicKey: $this->keyId,
        );
    }

    public function verify(Transaction $transaction, array $payload): GatewayResult
    {
        $orderId = $transaction->gateway_order_id;
        $paymentId = $payload['razorpay_payment_id'] ?? null;
        $signature = $payload['razorpay_signature'] ?? null;

        if (blank($paymentId) || blank($signature)) {
            return GatewayResult::failure('The payment response was incomplete.');
        }

        $expected = hash_hmac('sha256', "{$orderId}|{$paymentId}", (string) $this->keySecret);

        // hash_equals, not ===, so the comparison cannot be timed.
        if (! hash_equals($expected, $signature)) {
            return GatewayResult::failure('The payment signature did not verify.');
        }

        $payment = Http::withBasicAuth($this->keyId, $this->keySecret)
            ->get("https://api.razorpay.com/v1/payments/{$paymentId}");

        if ($payment->failed()) {
            return GatewayResult::failure('The payment could not be confirmed with Razorpay.');
        }

        // The signature proves the response was not tampered with. It does not
        // prove the payment succeeded or that it was for the right amount, so
        // both are checked against what the provider itself reports.
        if (! in_array($payment->json('status'), ['captured', 'authorized'], true)) {
            return GatewayResult::failure('Razorpay reports this payment as '.$payment->json('status').'.', $payment->json());
        }

        if ((int) $payment->json('amount') !== $transaction->amount) {
            return GatewayResult::failure('The amount paid does not match the amount due.', $payment->json());
        }

        return GatewayResult::success($paymentId, $payment->json('method'), $payment->json());
    }

    public function verifyWebhook(string $body, ?string $signature): bool
    {
        if (blank($signature) || blank($this->webhookSecret)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, $this->webhookSecret), $signature);
    }
}
