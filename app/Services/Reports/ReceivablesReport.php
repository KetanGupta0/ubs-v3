<?php

namespace App\Services\Reports;

use App\Models\Invoice;
use App\Support\Money;
use App\Support\Table\Column;
use Illuminate\Support\Collection;

/**
 * Who owes us, and for how long.
 *
 * Aged in buckets rather than listed by date, because "three invoices are over
 * ninety days" is a decision and "here are forty invoices" is a spreadsheet.
 * The buckets are ordered, so the chart uses the one hue ramp: the reader
 * should see the order in the colour without reading the labels.
 */
class ReceivablesReport extends Report
{
    /** Days overdue, and what to call each band. */
    public const BUCKETS = [
        ['label' => 'Not yet due', 'from' => null, 'to' => 0],
        ['label' => '1 to 30 days', 'from' => 1, 'to' => 30],
        ['label' => '31 to 60 days', 'from' => 31, 'to' => 60],
        ['label' => '61 to 90 days', 'from' => 61, 'to' => 90],
        ['label' => 'Over 90 days', 'from' => 91, 'to' => null],
    ];

    public function key(): string
    {
        return 'receivables';
    }

    public function title(): string
    {
        return 'Receivables';
    }

    public function description(): string
    {
        return 'Everything still owed, aged from its due date, oldest debt first.';
    }

    public function permission(): ?string
    {
        return 'billing.view';
    }

    public function summary(ReportWindow $window): array
    {
        $outstanding = $this->outstanding($window);
        $total = $outstanding->sum(fn (Invoice $invoice) => $invoice->total - $invoice->amount_paid);
        $overdue = $outstanding->filter(fn (Invoice $invoice) => $this->daysOverdue($invoice) > 0);

        return [
            [
                'label' => 'Outstanding',
                'value' => Money::display((int) $total),
                'hint' => $outstanding->count().' invoices',
            ],
            [
                'label' => 'Overdue',
                'value' => Money::display((int) $overdue->sum(fn (Invoice $invoice) => $invoice->total - $invoice->amount_paid)),
                'hint' => $overdue->count().' past their due date',
                'tone' => $overdue->isEmpty() ? 'neutral' : 'warning',
            ],
            [
                'label' => 'Oldest debt',
                'value' => $overdue->isEmpty()
                    ? 'None'
                    : max($overdue->map(fn (Invoice $invoice) => $this->daysOverdue($invoice))->all()).' days',
                'tone' => 'neutral',
            ],
        ];
    }

    public function charts(ReportWindow $window): array
    {
        $outstanding = $this->outstanding($window);

        $values = collect(self::BUCKETS)->map(function (array $bucket) use ($outstanding) {
            return (int) $outstanding
                ->filter(fn (Invoice $invoice) => $this->inBucket($invoice, $bucket))
                ->sum(fn (Invoice $invoice) => $invoice->total - $invoice->amount_paid);
        });

        return [[
            'id' => 'ageing',
            'kind' => 'bar',
            // Ordered bands, so one hue getting darker rather than five
            // identities: the order is the message.
            'ramp' => true,
            'title' => 'What is owed, by age',
            'subtitle' => 'Counted from each invoice’s due date',
            'money' => true,
            'categories' => collect(self::BUCKETS)->pluck('label')->all(),
            'series' => [[
                'label' => 'Outstanding',
                'slot' => 1,
                'values' => $values->map(fn (int $paise) => round($paise / 100, 2))->all(),
                'display' => $values->map(fn (int $paise) => Money::display($paise))->all(),
            ]],
        ]];
    }

    public function columns(): Collection
    {
        return collect([
            Column::make('number', 'Invoice'),
            Column::make('client', 'Billed to'),
            Column::make('issued', 'Issued'),
            Column::make('due', 'Due'),
            Column::make('age', 'Age'),
            Column::make('total', 'Invoiced')->numeric()->exportUsing(fn (array $row) => $row['totalValue']),
            Column::make('owed', 'Still owed')->numeric()->exportUsing(fn (array $row) => $row['owedValue']),
        ]);
    }

    public function rows(ReportWindow $window): Collection
    {
        return $this->outstanding($window)
            ->sortByDesc(fn (Invoice $invoice) => $this->daysOverdue($invoice))
            ->map(function (Invoice $invoice) {
                $owed = $invoice->total - $invoice->amount_paid;
                $days = $this->daysOverdue($invoice);

                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'client' => $invoice->billed_to['name'] ?? $invoice->user?->name ?? '—',
                    'issued' => $invoice->issued_at->format('j M Y'),
                    'due' => $invoice->due_on?->format('j M Y') ?? '—',
                    'age' => $days > 0 ? $days.' days overdue' : 'Not yet due',
                    'overdue' => $days > 0,
                    'total' => Money::display($invoice->total),
                    'totalValue' => $invoice->total / 100,
                    'owed' => Money::display($owed),
                    'owedValue' => $owed / 100,
                ];
            })
            ->values();
    }

    /**
     * Everything unpaid as things stand.
     *
     * Deliberately not filtered by the window: money owed from two years ago is
     * still owed today, and a receivables report that hides it because it falls
     * outside a date range is worse than no report.
     */
    protected function outstanding(ReportWindow $window): Collection
    {
        return Invoice::query()
            ->unpaid()
            ->with('user:id,name')
            ->where('issued_at', '<=', $window->to)
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->total > $invoice->amount_paid);
    }

    protected function daysOverdue(Invoice $invoice): int
    {
        if (! $invoice->due_on || $invoice->due_on->isFuture()) {
            return 0;
        }

        return (int) $invoice->due_on->diffInDays(now());
    }

    protected function inBucket(Invoice $invoice, array $bucket): bool
    {
        $days = $this->daysOverdue($invoice);

        return ($bucket['from'] === null || $days >= $bucket['from'])
            && ($bucket['to'] === null || $days <= $bucket['to']);
    }
}
