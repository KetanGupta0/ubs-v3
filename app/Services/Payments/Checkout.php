<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\Invoicer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Taking a payment, from opening checkout to marking the invoice paid.
 *
 * The amount comes from the invoice, never from the request body. A client that
 * can name its own amount can pay a lakh rupee invoice with one rupee.
 */
class Checkout
{
    public function __construct(
        protected PaymentGateway $gateway,
        protected Invoicer $invoicer,
    ) {}

    /**
     * Start an attempt, or reuse the one already open.
     *
     * Reusing matters: a client who closes the provider's window and clicks pay
     * again should not leave a trail of abandoned orders behind them.
     *
     * @return array{transaction: Transaction, order: GatewayOrder}
     */
    public function begin(PaymentRequest $request, User $payer): array
    {
        abort_unless($request->user_id === $payer->id, 403);
        abort_unless($request->isPayable(), 422, 'This request is not open for payment.');

        $invoice = $this->invoicer->issueFor($request);

        $transaction = $request->transactions()
            ->where('status', 'created')
            ->where('gateway', $this->gateway->name())
            ->latest('id')
            ->first();

        if (! $transaction) {
            $transaction = Transaction::query()->create([
                'user_id' => $payer->id,
                'invoice_id' => $invoice->id,
                'payment_request_id' => $request->id,
                'gateway' => $this->gateway->name(),
                'amount' => $invoice->balance() ?: $invoice->total,
                'currency' => $invoice->currency,
                'status' => 'created',
            ]);
        }

        $order = $this->gateway->createOrder($transaction);

        $transaction->forceFill(['gateway_order_id' => $order->id])->save();

        return ['transaction' => $transaction, 'order' => $order];
    }

    /**
     * Settle what the browser reported back.
     *
     * A failure is written down rather than thrown away, because "I tried three
     * times" is a question somebody asks when they are already annoyed.
     */
    public function settle(Transaction $transaction, array $payload): GatewayResult
    {
        if ($transaction->isSuccessful()) {
            return GatewayResult::success((string) $transaction->gateway_payment_id, $transaction->method);
        }

        $result = $this->gateway->verify($transaction, $payload);

        DB::transaction(function () use ($transaction, $result, $payload) {
            $transaction->forceFill($result->successful ? [
                'status' => 'successful',
                'gateway_payment_id' => $result->paymentId,
                'gateway_signature' => $payload['razorpay_signature'] ?? null,
                'method' => $result->method,
                'paid_at' => now(),
                'gateway_response' => $result->raw,
            ] : [
                'status' => 'failed',
                'failure_reason' => $result->failureReason,
                'gateway_response' => $result->raw,
            ])->save();

            if ($result->successful) {
                $this->invoicer->recordPayment($transaction);
            }
        });

        if (! $result->successful) {
            Log::info('Payment attempt failed', [
                'transaction' => $transaction->reference,
                'reason' => $result->failureReason,
            ]);
        }

        return $result;
    }

    /** Mark an invoice settled outside the gateway: a bank transfer or a cheque. */
    public function recordOffline(Invoice $invoice, int $amount, string $method, ?string $note = null): Transaction
    {
        $transaction = Transaction::query()->create([
            'user_id' => $invoice->user_id,
            'invoice_id' => $invoice->id,
            'payment_request_id' => $invoice->payment_request_id,
            'gateway' => 'offline',
            'amount' => $amount,
            'currency' => $invoice->currency,
            'status' => 'successful',
            'method' => $method,
            'paid_at' => now(),
            'gateway_response' => ['recorded_by_hand' => true, 'note' => $note],
        ]);

        $this->invoicer->recordPayment($transaction);

        return $transaction;
    }

    public function gateway(): PaymentGateway
    {
        return $this->gateway;
    }
}
