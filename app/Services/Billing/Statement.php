<?php

namespace App\Services\Billing;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Refund;
use App\Models\TdsDeduction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * A statement of account (CL14): everything billed to an organisation and
 * everything that settled it, in date order, with a running balance.
 *
 * Invoices and refunds paid back are debits; payments, TDS the client
 * deducted and credit notes are credits. A positive balance is owed to us,
 * a negative one is held to the client's credit. Cancelled invoices were
 * never owed and are left out.
 */
class Statement
{
    /**
     * The order entries made at the same moment appear in: what was billed,
     * then TDS and credit notes, which are decided before the net is paid.
     */
    protected const ORDER = ['invoice' => 0, 'refund' => 1, 'tds' => 2, 'credit_note' => 3, 'payment' => 4];

    /**
     * @return array{from: Carbon, to: Carbon, opening: int, closing: int, debits: int, credits: int, entries: array<int, array{date: Carbon, kind: string, reference: string, description: string, debit: int, credit: int, balance: int}>}
     */
    public function for(User $account, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $all = $this->entries($account)->sortBy(fn (array $entry) => [$entry['date']->getTimestamp(), self::ORDER[$entry['kind']]])->values();

        $opening = $all->filter(fn (array $entry) => $entry['date']->lt($from))->sum(fn (array $entry) => $entry['debit'] - $entry['credit']);
        $balance = $opening;

        $entries = $all->filter(fn (array $entry) => $entry['date']->betweenIncluded($from, $to))->map(function (array $entry) use (&$balance) {
            $balance += $entry['debit'] - $entry['credit'];

            return $entry + ['balance' => $balance];
        })->values();

        return [
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'closing' => $balance,
            'debits' => $entries->sum('debit'),
            'credits' => $entries->sum('credit'),
            'entries' => $entries->all(),
        ];
    }

    /** @return Collection<int, array{date: Carbon, kind: string, reference: string, description: string, debit: int, credit: int}> */
    protected function entries(User $account): Collection
    {
        $invoices = Invoice::query()->forUser($account)->where('status', '!=', 'cancelled')->with('items:id,invoice_id,description')->get();

        return collect()
            ->concat($invoices->map(fn (Invoice $invoice) => [
                'date' => $invoice->issued_at,
                'kind' => 'invoice',
                'reference' => $invoice->number,
                'description' => $invoice->items->first()?->description ?? 'Invoice',
                'debit' => $invoice->total,
                'credit' => 0,
            ]))
            ->concat(Transaction::query()->where('user_id', $account->id)->whereIn('status', ['successful', 'refunded'])->with('invoice:id,number')->get()
                ->map(fn (Transaction $payment) => [
                    'date' => $payment->paid_at ?? $payment->created_at,
                    'kind' => 'payment',
                    'reference' => $payment->reference,
                    'description' => 'Payment'.($payment->invoice ? " against {$payment->invoice->number}" : '').($payment->method ? ", {$payment->method}" : ''),
                    'debit' => 0,
                    'credit' => $payment->amount,
                ]))
            ->concat(TdsDeduction::query()->forClient($account)->with('invoice:id,number')->get()
                ->map(fn (TdsDeduction $deduction) => [
                    'date' => $deduction->created_at,
                    'kind' => 'tds',
                    'reference' => $deduction->invoice->number,
                    'description' => "TDS deducted, {$deduction->sectionLabel()}, {$deduction->quarter}",
                    'debit' => 0,
                    'credit' => $deduction->amount,
                ]))
            ->concat(CreditNote::query()->forUser($account)->with('invoice:id,number')->get()
                ->map(fn (CreditNote $note) => [
                    'date' => $note->issued_at,
                    'kind' => 'credit_note',
                    'reference' => $note->number,
                    'description' => "Credit note against {$note->invoice->number}: {$note->reason}",
                    'debit' => 0,
                    'credit' => $note->total,
                ]))
            ->concat(Refund::query()->where('status', 'processed')->whereHas('transaction', fn ($query) => $query->where('user_id', $account->id))->get()
                ->map(fn (Refund $refund) => [
                    'date' => $refund->processed_at ?? $refund->created_at,
                    'kind' => 'refund',
                    'reference' => $refund->reference,
                    'description' => 'Refund paid to you',
                    'debit' => $refund->amount,
                    'credit' => 0,
                ]));
    }

    /** @return array<string, mixed> The statement as screens show it. */
    public static function toScreen(array $statement): array
    {
        $money = fn (int $paise) => ($paise < 0 ? '−' : '').Money::display(abs($paise));

        return [
            'from' => $statement['from']->toDateString(),
            'to' => $statement['to']->toDateString(),
            'fromLabel' => $statement['from']->format('j M Y'),
            'toLabel' => $statement['to']->format('j M Y'),
            'opening' => $money($statement['opening']),
            'closing' => $money($statement['closing']),
            'closingOwed' => $statement['closing'] > 0,
            'debits' => $money($statement['debits']),
            'credits' => $money($statement['credits']),
            'entries' => collect($statement['entries'])->map(fn (array $entry) => [
                'date' => $entry['date']->format('j M Y'),
                'kind' => $entry['kind'],
                'reference' => $entry['reference'],
                'description' => $entry['description'],
                'debit' => $entry['debit'] ? $money($entry['debit']) : '',
                'credit' => $entry['credit'] ? $money($entry['credit']) : '',
                'balance' => $money($entry['balance']),
            ])->all(),
        ];
    }

    /** A download of the statement as PDF or CSV, named for the organisation and the window. */
    public function download(User $account, array $statement, string $format): Response
    {
        $name = str($account->organisationName())->slug()->append('-statement-', $statement['from']->toDateString(), '-to-', $statement['to']->toDateString())->toString();

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($statement) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Date', 'Type', 'Reference', 'Description', 'Debit', 'Credit', 'Balance']);
                fputcsv($out, [$statement['from']->toDateString(), 'opening', '', 'Opening balance', '', '', $statement['opening'] / 100]);

                foreach ($statement['entries'] as $entry) {
                    fputcsv($out, [$entry['date']->toDateString(), $entry['kind'], $entry['reference'], $entry['description'], $entry['debit'] / 100 ?: '', $entry['credit'] / 100 ?: '', $entry['balance'] / 100]);
                }

                fclose($out);
            }, "{$name}.csv", ['Content-Type' => 'text/csv']);
        }

        $pdf = Pdf::loadView('billing.statement', [
            'statement' => self::toScreen($statement),
            'account' => $account,
            'from' => app(Invoicer::class)->billedFrom(),
            'to' => app(Invoicer::class)->billedTo($account),
        ])->setPaper('a4')->output();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$name}.pdf\"",
        ]);
    }

    /** The window asked for, defaulting to the financial year so far. */
    public static function window(?string $from, ?string $to): array
    {
        $start = now()->month >= 4 ? now()->copy()->setDate(now()->year, 4, 1) : now()->copy()->setDate(now()->year - 1, 4, 1);

        try {
            $fromDate = $from ? Carbon::parse($from) : $start;
            $toDate = $to ? Carbon::parse($to) : now();
        } catch (\Throwable) {
            [$fromDate, $toDate] = [$start, now()];
        }

        return $fromDate->gt($toDate) ? [$toDate, $fromDate] : [$fromDate, $toDate];
    }
}
