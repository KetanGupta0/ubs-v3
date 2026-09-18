<?php

namespace App\Services\Reports;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Money;
use App\Support\Table\Column;
use Illuminate\Support\Collection;

/**
 * What we invoiced and what actually arrived.
 *
 * Two series on one chart on purpose: invoiced and received answer different
 * questions and the gap between them is the whole point. A month that billed
 * well and collected nothing is the thing worth seeing, and it is invisible on
 * either line alone.
 */
class RevenueReport extends Report
{
    public function key(): string
    {
        return 'revenue';
    }

    public function title(): string
    {
        return 'Revenue';
    }

    public function description(): string
    {
        return 'Invoiced against received, month by month, with the tax collected on top.';
    }

    public function permission(): ?string
    {
        return 'billing.view';
    }

    public function summary(ReportWindow $window): array
    {
        $invoiced = (int) $this->invoices($window)->sum('total');
        $received = (int) $this->payments($window)->sum('amount');
        $tax = (int) $this->invoices($window)->sum('tax');

        return [
            [
                'label' => 'Invoiced',
                'value' => Money::display($invoiced),
                'hint' => $this->invoices($window)->count().' invoices',
            ],
            [
                'label' => 'Received',
                'value' => Money::display($received),
                'hint' => $invoiced > 0 ? round($received / $invoiced * 100).'% of what was billed' : null,
                'tone' => 'success',
            ],
            [
                'label' => 'Still owed from this period',
                'value' => Money::display(max(0, $invoiced - $received)),
                'tone' => $invoiced > $received ? 'warning' : 'neutral',
            ],
            [
                'label' => 'Tax collected',
                'value' => Money::display($tax),
                'hint' => 'Already inside the invoiced figure',
            ],
        ];
    }

    public function charts(ReportWindow $window): array
    {
        $months = $window->months();

        $invoiced = $this->byMonth($this->invoices($window)->get(), 'issued_at', 'total', $months);
        $received = $this->byMonth($this->payments($window)->get(), 'created_at', 'amount', $months);

        return [[
            'id' => 'revenue',
            'kind' => 'line',
            'title' => 'Invoiced and received',
            'subtitle' => 'By month, in rupees',
            'money' => true,
            'categories' => collect($months)->map(fn ($month) => $month->format('M Y'))->all(),
            'series' => [
                [
                    'label' => 'Invoiced',
                    'slot' => 1,
                    'values' => array_map(fn ($paise) => round($paise / 100, 2), $invoiced),
                    'display' => array_map(fn ($paise) => Money::display($paise), $invoiced),
                ],
                [
                    'label' => 'Received',
                    'slot' => 2,
                    'values' => array_map(fn ($paise) => round($paise / 100, 2), $received),
                    'display' => array_map(fn ($paise) => Money::display($paise), $received),
                ],
            ],
        ]];
    }

    public function columns(): Collection
    {
        return collect([
            Column::make('month', 'Month'),
            Column::make('invoices', 'Invoices')->numeric(),
            Column::make('invoiced', 'Invoiced')->numeric()
                ->exportUsing(fn (array $row) => $row['invoicedValue']),
            Column::make('received', 'Received')->numeric()
                ->exportUsing(fn (array $row) => $row['receivedValue']),
            Column::make('tax', 'Tax')->numeric()
                ->exportUsing(fn (array $row) => $row['taxValue']),
            Column::make('collected', 'Collected')->numeric(),
        ]);
    }

    public function rows(ReportWindow $window): Collection
    {
        $invoices = $this->invoices($window)->get();
        $payments = $this->payments($window)->get();

        return collect($window->months())->map(function ($month) use ($invoices, $payments) {
            $key = $month->format('Y-m');

            $monthInvoices = $invoices->filter(fn (Invoice $invoice) => $invoice->issued_at->format('Y-m') === $key);
            $monthPayments = $payments->filter(fn (Transaction $payment) => $payment->created_at->format('Y-m') === $key);

            $invoiced = (int) $monthInvoices->sum('total');
            $received = (int) $monthPayments->sum('amount');

            return [
                'month' => $month->format('F Y'),
                'invoices' => $monthInvoices->count(),
                'invoiced' => Money::display($invoiced),
                'invoicedValue' => $invoiced / 100,
                'received' => Money::display($received),
                'receivedValue' => $received / 100,
                'tax' => Money::display((int) $monthInvoices->sum('tax')),
                'taxValue' => (int) $monthInvoices->sum('tax') / 100,
                'collected' => $invoiced > 0 ? round($received / $invoiced * 100).'%' : '—',
            ];
        });
    }

    protected function invoices(ReportWindow $window)
    {
        return Invoice::query()
            ->whereNot('status', 'cancelled')
            ->whereBetween('issued_at', [$window->from, $window->to]);
    }

    protected function payments(ReportWindow $window)
    {
        return Transaction::query()
            ->successful()
            ->whereBetween('created_at', [$window->from, $window->to]);
    }

    /** @return array<int, int> */
    protected function byMonth(Collection $records, string $dateColumn, string $valueColumn, array $months): array
    {
        $totals = $records->groupBy(fn ($record) => $record->{$dateColumn}->format('Y-m'))
            ->map(fn (Collection $group) => (int) $group->sum($valueColumn));

        return collect($months)->map(fn ($month) => $totals[$month->format('Y-m')] ?? 0)->all();
    }
}
