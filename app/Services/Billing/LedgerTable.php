<?php

namespace App\Services\Billing;

use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;

/**
 * One definition of "a person's transactions", used by both portals.
 *
 * A client and a student read the same ledger, and two copies of this would
 * disagree within a quarter. One copy means a column added for one side shows
 * up on the other, which is the correct outcome: it is the same table.
 */
class LedgerTable
{
    public static function for(User $user): Table
    {
        return Table::for(Transaction::query()
            ->forUser($user)
            ->with(['invoice:id,number', 'paymentRequest:id,title']))
            ->searchable(['reference', 'gateway_payment_id', 'method'])
            ->sortable(['created_at', 'amount'])
            ->defaultSort('-created_at')
            ->exportName('transactions')
            ->columns([
                Column::make('created_at', 'Date')->sortable(),
                Column::make('reference', 'Reference'),
                Column::make('description', 'For'),
                Column::make('method', 'Method'),
                Column::make('amount', 'Amount')->numeric()
                    ->exportUsing(fn (array $row) => $row['amountValue']),
                Column::make('status', 'Status'),
                Column::make('invoice', 'Invoice'),
            ])
            ->filters([
                Filter::multi('status', collect(Transaction::STATUSES)
                    ->map(fn (string $status) => ['value' => $status, 'label' => ucfirst($status)])
                    ->all(), 'Status'),
                Filter::dateRange('created_at', 'Between'),
            ])
            ->transform(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'created_at' => $transaction->created_at->format('j M Y'),
                'reference' => $transaction->reference,
                'description' => $transaction->paymentRequest?->title
                    ?? $transaction->invoice?->number
                    ?? 'Payment',
                'method' => $transaction->method ? ucfirst($transaction->method) : '—',
                'amount' => $transaction->amountLabel(),
                'amountValue' => $transaction->amount / 100,
                'status' => $transaction->status,
                'invoice' => $transaction->invoice?->number ?? '—',
                'invoiceId' => $transaction->invoice_id,
                'receiptable' => $transaction->isSuccessful(),
            ]);
    }

    /** @return array<string, string> */
    public static function summaryFor(User $user): array
    {
        return [
            'billed' => Money::display((int) $user->invoices()->whereNot('status', 'cancelled')->sum('total')),
            'paid' => Money::display((int) $user->transactions()->successful()->sum('amount')),
            'due' => Money::display((int) $user->paymentRequests()->pending()->sum('total')),
        ];
    }
}
