<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Client\OrderController as ClientOrders;
use App\Http\Controllers\Controller;
use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Subscription;
use App\Services\Shop\Orders;
use App\Support\Money;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Orders from the shop, from our side (§4): who bought what, what it came to,
 * whether it is paid, and what it gave them. Money is taken and refunded in
 * Billing like any other payment; an order only says what it was for.
 */
class OrderController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(Order::query()->with('items', 'client.profile', 'buyer:id,name'))
            ->searchable(['number'])
            ->defaultSort('-created_at')
            ->exportName('shop-orders')
            ->columns([
                Column::make('number', 'Order')->sortable(),
                Column::make('created_at', 'Placed')->sortable(),
                Column::make('client', 'Organisation'),
                Column::make('summary', 'What'),
                Column::make('taxable', 'Before tax')->sortable()->numeric()->exportUsing(fn (array $row) => $row['taxableValue']),
                Column::make('total', 'Total')->sortable()->numeric()->exportUsing(fn (array $row) => $row['totalValue']),
                Column::make('status', 'Status')->sortable(),
            ])
            ->filters([
                Filter::select('status', collect(Order::STATUSES)->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values()->all(), 'Status')
                    ->placeholder('Any status'),
            ])
            ->transform(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'created_at' => $order->created_at->format('j M Y'),
                'client' => $order->client?->organisationName() ?? '—',
                'buyer' => $order->buyer?->name,
                'summary' => $order->summary(),
                'taxable' => Money::display($order->taxable),
                'taxableValue' => $order->taxable / 100,
                'total' => Money::display($order->total),
                'totalValue' => $order->total / 100,
                'status' => $order->statusLabel(),
                'statusKey' => $order->status,
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/shop/Orders', [
            'table' => $table->toArray($request),
            'counts' => [
                'pending' => Order::query()->where('status', 'pending')->count(),
                'paidThisMonth' => Money::display((int) Order::query()->whereNotNull('paid_at')->where('paid_at', '>=', now()->startOfMonth())->whereIn('status', ['paid', 'partially_refunded'])->sum('taxable')),
            ],
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        $order->load('items', 'client.profile', 'buyer:id,name,email', 'redemptions.coupon:id,code', 'paymentRequest.invoice', 'paymentRequest.transactions.refunds');
        $licences = Licence::query()->where('order_id', $order->id)->with('activations')->withCount('downloads')->get();
        $subscriptions = Subscription::query()->whereIn('order_item_id', $order->items->pluck('id'))->get();
        $mayManage = $request->user()->hasPermission('orders.manage');

        return Inertia::render('admin/shop/Order', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'statusLabel' => $order->statusLabel(),
                'placedAt' => $order->created_at->format('j M Y, g:i a'),
                'paidAt' => $order->paid_at?->format('j M Y, g:i a'),
                'cancelledAt' => $order->cancelled_at?->format('j M Y'),
                'cancelReason' => $order->cancel_reason,
                'client' => ['id' => $order->client_id, 'name' => $order->client?->organisationName()],
                'buyer' => $order->buyer ? ['name' => $order->buyer->name, 'email' => $order->buyer->email] : null,
                'billing' => $order->billing,
                'lines' => $order->items->whereNull('parent_id')->map(fn (OrderItem $item) => [
                    'description' => $item->description,
                    'sac' => $item->sac_code,
                    'amount' => Money::display($item->amount),
                    'discount' => $item->discount > 0 ? Money::display($item->discount) : null,
                    'addons' => $order->items->where('parent_id', $item->id)->map(fn (OrderItem $addon) => [
                        'description' => ClientOrders::addonLabel($addon),
                        'kind' => $addon->terms['kind'] ?? 'service',
                        'amount' => Money::display($addon->amount),
                        'discount' => $addon->discount > 0 ? Money::display($addon->discount) : null,
                    ])->values(),
                ])->values(),
                'coupons' => $order->redemptions->map(fn ($redemption) => [
                    'code' => $redemption->coupon?->code,
                    'amount' => Money::display($redemption->amount),
                    'status' => $redemption->status,
                ])->values(),
                'subtotal' => Money::display($order->subtotal),
                'discount' => $order->discount > 0 ? Money::display($order->discount) : null,
                'taxable' => Money::display($order->taxable),
                'breakup' => collect($order->tax_breakup)->map(fn (array $part) => [
                    'label' => $part['label'].' @ '.rtrim(rtrim(number_format((float) $part['rate'], 2), '0'), '.').'%',
                    'amount' => Money::display($part['amount']),
                ])->values(),
                'total' => Money::display($order->total),
                'payment' => $order->paymentRequest ? [
                    'id' => $order->paymentRequest->id,
                    'reference' => $order->paymentRequest->reference,
                    'status' => $order->paymentRequest->status,
                    'invoice' => $order->paymentRequest->invoice ? ['id' => $order->paymentRequest->invoice->id, 'number' => $order->paymentRequest->invoice->number] : null,
                    'refunded' => Money::display((int) $order->paymentRequest->transactions->sum(fn ($transaction) => $transaction->refundedAmount())),
                ] : null,
                'canCancel' => $mayManage && $order->isPending(),
            ],
            'licences' => $licences->map(fn (Licence $licence) => [
                'id' => $licence->id,
                'product' => $licence->product,
                'tier' => $licence->tier,
                'active' => $licence->isActive(),
                'revokedReason' => $licence->revoked_reason,
                'maskedKey' => $licence->maskedKey(),
                'sites' => $licence->sitesLabel(),
                'sitesInUse' => $licence->sitesInUse(),
                'updatesUntil' => $licence->updates_until->format('j M Y'),
                'supportUntil' => $licence->support_until?->format('j M Y'),
                'lastChecked' => $licence->last_checked_at?->diffForHumans(),
                'downloads' => $licence->downloads_count,
                'activations' => $licence->activations->whereNull('released_at')->map(fn (LicenceActivation $site) => [
                    'id' => $site->id,
                    'domain' => $site->domain,
                    'lastSeen' => $site->last_seen_at->diffForHumans(),
                    'version' => $site->last_version,
                ])->values(),
            ])->values(),
            'subscriptions' => $subscriptions->map(fn (Subscription $subscription) => [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'status' => $subscription->status,
                'renewsOn' => $subscription->renews_on?->format('j M Y'),
            ])->values(),
            'mayManage' => $mayManage,
        ]);
    }

    public function cancel(Request $request, Order $order, Orders $orders): RedirectResponse
    {
        $validated = $this->validatedInput($request, ['reason' => ['required', 'string', 'max:300']], [
            'reason.required' => 'Say why, for the record.',
        ]);

        try {
            $orders->cancel($order, trim($validated['reason']), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Order {$order->number} is cancelled, with its payment request.");
    }
}
