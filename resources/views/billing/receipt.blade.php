{{-- Payment receipt. Shorter than an invoice on purpose: it answers one
     question, which is whether the money arrived. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $transaction->reference }}</title>
    <style>
        @page { margin: 30mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2430; line-height: 1.6; }
        .name { font-size: 17px; font-weight: bold; color: #4f46e5; }
        .muted { color: #6b7280; }
        .label { font-size: 8.5px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; }
        .stamp { margin: 22px 0; padding: 14px 16px; border: 1.5px solid #16a34a; background: #f0fdf4; }
        .amount { font-size: 22px; font-weight: bold; color: #15803d; }
        table.detail { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.detail td { padding: 6px 0; border-bottom: 1px solid #f0f1f3; }
        table.detail td:last-child { text-align: right; }
        .sign { margin-top: 34px; text-align: right; font-size: 9px; }
    </style>
</head>
<body>
@php($from = $invoice?->billed_from ?? [])

<div class="name">{{ $from['trading_name'] ?? config('company.name') }}</div>
<div class="muted">{{ $from['name'] ?? config('company.legal_name') }}</div>
@if(!empty($from['gstin']))<div class="muted">GSTIN: {{ $from['gstin'] }}</div>@endif

<h2 style="margin-top:22px">Payment receipt</h2>

<div class="stamp">
    <div class="label">Received with thanks</div>
    <div class="amount">{{ $money($transaction->amount) }}</div>
    <div class="muted">{{ $words }}</div>
</div>

<table class="detail">
    <tr><td class="label">Receipt number</td><td><strong>{{ $transaction->reference }}</strong></td></tr>
    <tr><td class="label">Received from</td><td>{{ $transaction->user->name }}</td></tr>
    @if($invoice)
        <tr><td class="label">Against invoice</td><td>{{ $invoice->number }}</td></tr>
    @endif
    <tr><td class="label">Paid on</td><td>{{ $transaction->paid_at?->format('j M Y, g:i a') }}</td></tr>
    <tr><td class="label">Method</td><td>{{ $transaction->method ? ucfirst($transaction->method) : '—' }}</td></tr>
    <tr><td class="label">Reference</td><td>{{ $transaction->gateway_payment_id ?: $transaction->reference }}</td></tr>
    @if($invoice && $invoice->balance() > 0)
        <tr><td class="label">Balance still due</td><td><strong>{{ $money($invoice->balance()) }}</strong></td></tr>
    @endif
</table>

<div class="sign">
    For {{ $from['name'] ?? config('company.legal_name') }}<br><br><br>
    Authorised signatory
</div>

<div class="muted" style="margin-top:18px; font-size:9px">
    This is a computer generated receipt and is valid without a signature.
</div>
</body>
</html>
