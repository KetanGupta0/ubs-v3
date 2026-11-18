<?php

namespace App\Http\Controllers\Client;

use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductTier;
use App\Models\ProductVersion;
use App\Services\Shop\Licences;
use App\Services\Shop\Orders;
use App\Support\Money;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * What the organisation bought from the shop (CL5): every order, what was in
 * it, its invoice, and for source code the licence, its key, the sites using
 * it and the versions to download (CL4).
 *
 * Everybody in the organisation can see them. The key, the sites and the
 * downloads are for technical people and owners, and for whoever bought it.
 */
class OrderController extends ClientController
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(Order::query()->forClient($this->client($request))->with('items', 'buyer:id,name'))
            ->searchable(['number'])
            ->defaultSort('-created_at')
            ->exportName('orders')
            ->columns([
                Column::make('number', 'Order')->sortable(),
                Column::make('created_at', 'Placed')->sortable(),
                Column::make('summary', 'What'),
                Column::make('buyer', 'By'),
                Column::make('total', 'Total')->sortable()->numeric(),
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
                'summary' => $order->summary(),
                'buyer' => $order->buyer?->name ?? '—',
                'total' => Money::display($order->total),
                'status' => $order->statusLabel(),
                'statusKey' => $order->status,
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('client/orders/Index', ['table' => $table->toArray($request)]);
    }

    public function show(Request $request, int $order, Licences $licences): Response
    {
        $record = $this->order($request, $order);
        $record->load('items', 'buyer:id,name', 'redemptions.coupon:id,code', 'paymentRequest.invoice');
        $mayHandle = $this->mayHandleLicences($request, $record);

        $licenceRows = Licence::query()->where('order_id', $record->id)->with('activations')->get();

        return Inertia::render('client/orders/Show', [
            'order' => [
                'id' => $record->id,
                'number' => $record->number,
                'placedAt' => $record->created_at->format('j M Y, g:i a'),
                'placedBy' => $record->buyer?->name,
                'status' => $record->status,
                'statusLabel' => $record->statusLabel(),
                'paidAt' => $record->paid_at?->format('j M Y'),
                'lines' => $record->items->whereNull('parent_id')->map(fn (OrderItem $item) => [
                    'description' => $item->description,
                    'amount' => Money::display($item->amount),
                    'terms' => $this->termsOf($item),
                    'addons' => $record->items->where('parent_id', $item->id)->map(fn (OrderItem $addon) => [
                        'description' => self::addonLabel($addon),
                        'amount' => Money::display($addon->amount),
                    ])->values(),
                ])->values(),
                'coupons' => $record->redemptions->map(fn ($redemption) => ['code' => $redemption->coupon?->code, 'amount' => Money::display($redemption->amount)])->values(),
                'subtotal' => Money::display($record->subtotal),
                'discount' => $record->discount > 0 ? Money::display($record->discount) : null,
                'taxable' => Money::display($record->taxable),
                'breakup' => collect($record->tax_breakup)->map(fn (array $part) => [
                    'label' => $part['label'].' @ '.rtrim(rtrim(number_format((float) $part['rate'], 2), '0'), '.').'%',
                    'amount' => Money::display($part['amount']),
                ])->values(),
                'total' => Money::display($record->total),
                'billing' => $record->billing,
                'paymentId' => $record->payment_request_id,
                'invoice' => ($invoice = $record->paymentRequest?->invoice) ? ['id' => $invoice->id, 'number' => $invoice->number] : null,
                'canCancel' => $record->isPending() && Orders::mayPay($record, $request->user()),
                'canPay' => $record->isPending() && Orders::mayPay($record, $request->user()),
            ],
            'licences' => $licenceRows->map(fn (Licence $licence) => $this->licenceForScreen($licence, $licences, $mayHandle))->values(),
            'mayHandleLicences' => $mayHandle,
        ]);
    }

    public function cancel(Request $request, int $order, Orders $orders): RedirectResponse
    {
        $record = $this->order($request, $order);
        abort_unless(Orders::mayPay($record, $request->user()), 403, 'Only whoever placed the order, finance or an owner can cancel it.');

        try {
            $orders->cancel($record, 'Cancelled by the buyer.', $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Order {$record->number} is cancelled. Nothing was charged.");
    }

    /* ---------------------------------------------------------- licences */

    public function issueKey(Request $request, int $licence, Licences $licences): RedirectResponse
    {
        $record = $this->licence($request, $licence);

        try {
            $key = $licences->issueKey($record, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('issuedLicenceKey', ['key' => $key, 'licence' => $record->id, 'product' => $record->product]);
    }

    public function rotateKey(Request $request, int $licence, Licences $licences): RedirectResponse
    {
        $record = $this->licence($request, $licence);

        try {
            $key = $licences->rotateKey($record, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('issuedLicenceKey', ['key' => $key, 'licence' => $record->id, 'product' => $record->product, 'rotated' => true]);
    }

    public function releaseSite(Request $request, int $licence, int $activation, Licences $licences): RedirectResponse
    {
        $record = $this->licence($request, $licence);
        $site = LicenceActivation::query()->where('licence_id', $record->id)->whereKey($activation)->firstOrFail();

        $licences->release($site, $request->user());

        return back()->with('success', "{$site->domain} no longer holds a place. The licence can be used on another site.");
    }

    /**
     * A link to the file that works for a few minutes, from anywhere: a
     * download manager or another machine, not just this browser.
     */
    public function download(Request $request, int $licence, int $version, Licences $licences): HttpResponse
    {
        $record = $this->licence($request, $licence);
        $file = ProductVersion::query()->where('solution_id', $record->solution_id)->whereKey($version)->firstOrFail();

        if ($refusal = $licences->downloadRefusal($record, $file)) {
            return back()->with('error', $refusal);
        }

        return Inertia::location(URL::temporarySignedRoute('downloads.licence', now()->addMinutes((int) config('company.shop.download_link_minutes')), [
            'licence' => $record->id,
            'version' => $file->id,
            'by' => $request->user()->id,
        ]));
    }

    /* ----------------------------------------------------------- helpers */

    protected function order(Request $request, int $id): Order
    {
        return $this->own($request, Order::query()->forClient($this->client($request)), $id);
    }

    /** A licence of this organisation's, for somebody who may handle it. */
    protected function licence(Request $request, int $id): Licence
    {
        $licence = $this->own($request, Licence::query()->forClient($this->client($request))->with('order'), $id);
        abort_unless($this->mayHandleLicences($request, $licence->order), 403, 'Only technical people, an owner, or whoever bought it can use the licence.');

        return $licence;
    }

    protected function mayHandleLicences(Request $request, Order $order): bool
    {
        return $order->user_id === $request->user()->id || $request->user()->canAsClient('licences');
    }

    /** @return array<int, string> */
    protected function termsOf(OrderItem $item): array
    {
        $terms = $item->terms ?? [];

        return match ($item->kind) {
            'licence' => array_values(array_filter([
                match (true) {
                    ($terms['sites'] ?? null) === null => 'Unlimited sites',
                    $terms['sites'] === 1 => 'One site',
                    default => "Up to {$terms['sites']} sites",
                },
                ($terms['update_months'] ?? 0) > 0 ? ProductTier::months($terms['update_months']).' of updates' : 'The version bought',
                ($terms['support_months'] ?? 0) > 0 ? ProductTier::months($terms['support_months']).' of support' : null,
            ])),
            'plan' => ['Hosted by us, billed '.(($terms['interval'] ?? 'yearly') === 'monthly' ? 'every month' : 'every year')],
            default => [],
        };
    }

    /** "Customisation, 10 hours", as the cart said it. */
    public static function addonLabel(OrderItem $addon): string
    {
        $name = str($addon->description)->after(': ')->toString();
        $unit = $addon->terms['unit'] ?? null;

        return match (true) {
            $unit !== null => "{$name}, {$addon->quantity} ".str($unit)->plural($addon->quantity),
            $addon->quantity > 1 => "{$name} × {$addon->quantity}",
            default => $name,
        };
    }

    /** @return array<string, mixed> */
    protected function licenceForScreen(Licence $licence, Licences $licences, bool $mayHandle): array
    {
        $versions = $mayHandle ? $licences->versionsFor($licence) : collect();

        return [
            'id' => $licence->id,
            'product' => $licence->product,
            'tier' => $licence->tier,
            'active' => $licence->isActive(),
            'revokedReason' => $licence->revoked_reason,
            'hasKey' => $licence->hasKey(),
            'maskedKey' => $licence->maskedKey(),
            'keyIssuedAt' => $licence->key_issued_at?->format('j M Y'),
            'sites' => $licence->sitesLabel(),
            'sitesInUse' => $licence->sitesInUse(),
            'sitesLimit' => $licence->sites,
            'updatesUntil' => $licence->updates_until->format('j M Y'),
            'inUpdateWindow' => $licence->inUpdateWindow(),
            'supportUntil' => $licence->support_until?->format('j M Y'),
            'activations' => $mayHandle ? $licence->activations->whereNull('released_at')->map(fn (LicenceActivation $site) => [
                'id' => $site->id,
                'domain' => $site->domain,
                'since' => $site->first_seen_at->format('j M Y'),
                'lastSeen' => $site->last_seen_at->diffForHumans(),
                'version' => $site->last_version,
            ])->values() : [],
            'versions' => $versions->map(fn (ProductVersion $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'releasedOn' => $version->released_on->format('j M Y'),
                'size' => $version->sizeLabel(),
                'sha256' => $version->sha256,
                'changelog' => $version->changelog,
                'downloadsLeft' => $licences->downloadsLeft($licence, $version),
            ])->values(),
        ];
    }
}
