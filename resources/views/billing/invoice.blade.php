{{-- Tax invoice. Printed, filed, and occasionally read by somebody's auditor,
     so it carries every field the law asks for and nothing decorative. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        @page { margin: 26mm 16mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2430; line-height: 1.5; }
        .head { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .head td { vertical-align: top; }
        .name { font-size: 17px; font-weight: bold; color: #4f46e5; }
        .label { font-size: 8.5px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; }
        .title { font-size: 15px; font-weight: bold; text-align: right; }
        .muted { color: #6b7280; }
        .party { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .party td { width: 50%; vertical-align: top; padding: 10px 12px; border: 1px solid #e5e7eb; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th { background: #f3f4f6; text-align: left; padding: 7px 8px; font-size: 9px;
                         text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid #e5e7eb; }
        table.items td { padding: 7px 8px; border-bottom: 1px solid #f0f1f3; }
        .right { text-align: right; }
        .totals { width: 46%; margin-left: auto; margin-top: 12px; border-collapse: collapse; }
        .totals td { padding: 5px 8px; }
        .totals tr.grand td { border-top: 1.5px solid #1f2430; font-weight: bold; font-size: 12px; padding-top: 8px; }
        .words { margin-top: 10px; padding: 8px 10px; background: #f9fafb; border-left: 3px solid #4f46e5; }
        .terms { margin-top: 20px; font-size: 9px; color: #6b7280; white-space: pre-line; }
        .sign { margin-top: 34px; text-align: right; font-size: 9px; }
        .paid { display: inline-block; padding: 3px 9px; border: 1.5px solid #16a34a; color: #16a34a;
                font-weight: bold; text-transform: uppercase; font-size: 9px; letter-spacing: .08em; }
    </style>
</head>
<body>
@php($from = $invoice->billed_from)
@php($to = $invoice->billed_to)

<table class="head">
    <tr>
        <td>
            <div class="name">{{ $from['trading_name'] ?? $from['name'] }}</div>
            <div class="muted">{{ $from['name'] }}</div>
            @if(!empty($from['address']))<div class="muted">{{ $from['address'] }}</div>@endif
            <div class="muted">
                {{ collect([$from['city'] ?? null, $from['state'] ?? null, $from['postal_code'] ?? null])->filter()->join(', ') }}
            </div>
            @if(!empty($from['gstin']))<div class="muted">GSTIN: {{ $from['gstin'] }}</div>@endif
            @if(!empty($from['cin']))<div class="muted">CIN: {{ $from['cin'] }}</div>@endif
            @if(!empty($from['email']))<div class="muted">{{ $from['email'] }}</div>@endif
        </td>
        <td>
            <div class="title">Tax Invoice</div>
            <div class="right" style="margin-top:8px">
                <div><span class="label">Number</span> <strong>{{ $invoice->number }}</strong></div>
                <div><span class="label">Date</span> {{ $invoice->issued_at->format('j M Y') }}</div>
                @if($invoice->due_on)
                    <div><span class="label">Due</span> {{ $invoice->due_on->format('j M Y') }}</div>
                @endif
                @if($invoice->isPaid())
                    <div style="margin-top:6px"><span class="paid">Paid</span></div>
                @endif
            </div>
        </td>
    </tr>
</table>

<table class="party">
    <tr>
        <td>
            <div class="label">Billed to</div>
            <strong>{{ $to['name'] }}</strong>
            @if(!empty($to['contact']) && $to['contact'] !== $to['name'])<div>{{ $to['contact'] }}</div>@endif
            @if(!empty($to['address']))<div class="muted">{{ $to['address'] }}</div>@endif
            <div class="muted">
                {{ collect([$to['city'] ?? null, $to['state'] ?? null, $to['postal_code'] ?? null])->filter()->join(', ') }}
            </div>
            @if(!empty($to['gstin']))<div class="muted">GSTIN: {{ $to['gstin'] }}</div>@endif
            @if(!empty($to['email']))<div class="muted">{{ $to['email'] }}</div>@endif
        </td>
        <td>
            <div class="label">Place of supply</div>
            <div>{{ $invoice->place_of_supply ?: '—' }}</div>
            <div class="label" style="margin-top:8px">Currency</div>
            <div>{{ $invoice->currency }}</div>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
    <tr>
        <th style="width:52%">Description</th>
        <th style="width:12%">HSN / SAC</th>
        <th class="right" style="width:8%">Qty</th>
        <th class="right" style="width:14%">Rate</th>
        <th class="right" style="width:14%">Amount</th>
    </tr>
    </thead>
    <tbody>
    @foreach($invoice->items as $item)
        <tr>
            <td>{{ $item->description }}</td>
            <td>{{ $item->hsn_sac ?: '998314' }}</td>
            <td class="right">{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}</td>
            <td class="right">{{ $money($item->unit_price) }}</td>
            <td class="right">{{ $money($item->amount) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td>Subtotal</td>
        <td class="right">{{ $money($invoice->subtotal) }}</td>
    </tr>
    @if($invoice->discount > 0)
        <tr>
            <td>Discount</td>
            <td class="right">− {{ $money($invoice->discount) }}</td>
        </tr>
    @endif
    @foreach($invoice->tax_breakup ?? [] as $component)
        <tr>
            <td>{{ $component['label'] }} @ {{ rtrim(rtrim(number_format($component['rate'], 2), '0'), '.') }}%</td>
            <td class="right">{{ $money($component['amount']) }}</td>
        </tr>
    @endforeach
    <tr class="grand">
        <td>Total</td>
        <td class="right">{{ $money($invoice->total) }}</td>
    </tr>
    @if($invoice->amount_paid > 0 && ! $invoice->isPaid())
        <tr>
            <td>Paid so far</td>
            <td class="right">{{ $money($invoice->amount_paid) }}</td>
        </tr>
        <tr>
            <td><strong>Balance due</strong></td>
            <td class="right"><strong>{{ $money($invoice->balance()) }}</strong></td>
        </tr>
    @endif
</table>

<div class="words"><span class="label">Amount in words</span><br>{{ $words }}</div>

@if($invoice->terms)
    <div class="terms"><strong>Terms</strong>{{ "\n" }}{{ $invoice->terms }}</div>
@endif

<div class="sign">
    For {{ $from['name'] }}<br><br><br>
    Authorised signatory
</div>

<div class="terms" style="margin-top:18px">
    This is a computer generated invoice and is valid without a signature.
</div>
</body>
</html>
