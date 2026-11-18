<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;
use App\Support\Table\Column;
use Illuminate\Support\Collection;

/**
 * What the shop sold (§4): orders paid in the window, before tax, what sold
 * best, what codes gave away, and how many orders were placed and never paid.
 *
 * Sales are counted when the money came in, before tax, because the tax was
 * never ours. A share is only printed when there is something to take a share
 * of: no orders placed is a dash, not a percentage of nothing.
 */
class SalesReport extends Report
{
    public function key(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Shop sales';
    }

    public function description(): string
    {
        return 'Orders paid through the shop, before tax: what sold, what codes gave away, and orders left unpaid.';
    }

    public function permission(): ?string
    {
        return 'orders.view';
    }

    public function summary(ReportWindow $window): array
    {
        $paid = $this->paid($window)->get();
        $placed = $this->placed($window)->count();
        $unpaid = $this->placed($window)->where('status', 'cancelled')->whereNull('paid_at')->count();
        $sold = (int) $paid->sum('taxable');

        return [
            [
                'label' => 'Sold, before tax',
                'value' => Money::display($sold),
                'hint' => $paid->count().' '.str('order')->plural($paid->count()).' paid',
                'tone' => 'success',
            ],
            [
                'label' => 'Average order',
                'value' => $paid->isNotEmpty() ? Money::display(intdiv($sold, $paid->count())) : '—',
            ],
            [
                'label' => 'Given away in codes',
                'value' => Money::display((int) $paid->sum('discount')),
                'hint' => $paid->where('discount', '>', 0)->count().' paid orders used a code',
            ],
            [
                'label' => 'Placed and never paid',
                'value' => (string) $unpaid,
                'hint' => $placed > 0 ? round($unpaid / $placed * 100).'% of '.$placed.' placed' : null,
                'tone' => $unpaid > 0 ? 'warning' : 'neutral',
            ],
        ];
    }

    public function charts(ReportWindow $window): array
    {
        $months = $window->months();
        $paid = $this->paid($window)->get();

        $byMonth = $paid->groupBy(fn (Order $order) => $order->paid_at->format('Y-m'))->map(fn (Collection $orders) => (int) $orders->sum('taxable'));
        $monthly = collect($months)->map(fn ($month) => $byMonth[$month->format('Y-m')] ?? 0)->all();

        $byProduct = $this->byProduct($paid)->take(8);

        return array_values(array_filter([
            [
                'id' => 'sales',
                'kind' => 'bar',
                'title' => 'Sold by month',
                'subtitle' => 'Before tax, when paid, in rupees',
                'money' => true,
                'categories' => collect($months)->map(fn ($month) => $month->format('M Y'))->all(),
                'series' => [[
                    'label' => 'Sold',
                    'slot' => 1,
                    'values' => array_map(fn (int $paise) => round($paise / 100, 2), $monthly),
                    'display' => array_map(fn (int $paise) => Money::display($paise), $monthly),
                ]],
            ],
            $byProduct->isNotEmpty() ? [
                'id' => 'products',
                'kind' => 'bar',
                'title' => 'What sold',
                'subtitle' => 'Before tax, with its add-ons, in rupees',
                'money' => true,
                'categories' => $byProduct->keys()->all(),
                'series' => [[
                    'label' => 'Sold',
                    'slot' => 2,
                    'values' => $byProduct->map(fn (int $paise) => round($paise / 100, 2))->values()->all(),
                    'display' => $byProduct->map(fn (int $paise) => Money::display($paise))->values()->all(),
                ]],
            ] : null,
        ]));
    }

    public function columns(): Collection
    {
        return collect([
            Column::make('number', 'Order'),
            Column::make('paid', 'Paid'),
            Column::make('client', 'Organisation'),
            Column::make('what', 'What'),
            Column::make('codes', 'Codes'),
            Column::make('discount', 'Discount')->numeric()->exportUsing(fn (array $row) => $row['discountValue']),
            Column::make('taxable', 'Before tax')->numeric()->exportUsing(fn (array $row) => $row['taxableValue']),
            Column::make('tax', 'GST')->numeric()->exportUsing(fn (array $row) => $row['taxValue']),
            Column::make('total', 'Total')->numeric()->exportUsing(fn (array $row) => $row['totalValue']),
            Column::make('status', 'Status'),
        ]);
    }

    public function rows(ReportWindow $window): Collection
    {
        return $this->paid($window)
            ->with('items', 'client.profile', 'redemptions.coupon:id,code')
            ->orderBy('paid_at')
            ->get()
            ->map(fn (Order $order) => [
                'number' => $order->number,
                'paid' => $order->paid_at->format('j M Y'),
                'client' => $order->client?->organisationName() ?? '—',
                'what' => $order->items->whereNull('parent_id')->pluck('description')->implode('; '),
                'codes' => $order->redemptions->map(fn ($redemption) => $redemption->coupon?->code)->filter()->implode(', ') ?: '—',
                'discount' => Money::display($order->discount),
                'discountValue' => $order->discount / 100,
                'taxable' => Money::display($order->taxable),
                'taxableValue' => $order->taxable / 100,
                'tax' => Money::display($order->tax),
                'taxValue' => $order->tax / 100,
                'total' => Money::display($order->total),
                'totalValue' => $order->total / 100,
                'status' => $order->statusLabel(),
            ]);
    }

    /** Orders whose money came in during the window, refunded ones included: they were sold. */
    protected function paid(ReportWindow $window)
    {
        return Order::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$window->from, $window->to]);
    }

    protected function placed(ReportWindow $window)
    {
        return Order::query()->whereBetween('created_at', [$window->from, $window->to]);
    }

    /**
     * Before tax by product, each line with its add-ons, after its share of
     * any code.
     *
     * @return Collection<string, int>
     */
    protected function byProduct(Collection $orders): Collection
    {
        return OrderItem::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->get()
            ->groupBy(fn (OrderItem $item) => $item->terms['product'] ?? 'Other')
            ->map(fn (Collection $items) => (int) $items->sum(fn (OrderItem $item) => $item->taxable()))
            ->sortDesc();
    }
}
