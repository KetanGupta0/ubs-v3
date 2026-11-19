{{-- Tax invoice. Printed, filed, and occasionally read by somebody's auditor,
     so it carries every field the law asks for and nothing decorative. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Credit note {{ $note->number }}</title>
    <style>
        @page { margin: 12mm 12mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2430; line-height: 1.5; }
        .head { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .head td { vertical-align: top; }
        .logo { margin-bottom: 2.5mm; }
        .legal { font-size: 11px; font-weight: bold; color: #1f2430; }
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
{{-- A credit note (CL14): a tax document of its own against an invoice, with
     the parties and tax rates copied from it. Rendered once and kept. --}}
@php($from = $note->billed_from)
@php($to = $note->billed_to)
@php($money = fn (int $paise) => \App\Support\Money::display($paise))

<table class="head">
    <tr>
        <td>
            <div class="logo">{{ \App\Support\PdfBrand::img(11) }}</div>
            <div class="legal">{{ $from['name'] }}</div>
            @if(!empty($from['address']))<div class="muted">{{ $from['address'] }}</div>@endif
            <div class="muted">{{ collect([$from['city'] ?? null, $from['state'] ?? null, $from['postal_code'] ?? null])->filter()->join(', ') }}</div>
            @if(!empty($from['gstin']))<div class="muted">GSTIN: {{ $from['gstin'] }}</div>@endif
        </td>
        <td>
            <div class="title">Credit Note</div>
            <div class="right" style="margin-top:8px">
                <div><span class="label">Number</span> <strong>{{ $note->number }}</strong></div>
                <div><span class="label">Date</span> {{ $note->issued_at->format('j M Y') }}</div>
                <div><span class="label">Against invoice</span> {{ $note->invoice->number }}</div>
                <div><span class="label">Invoice date</span> {{ $note->invoice->issued_at->format('j M Y') }}</div>
            </div>
        </td>
    </tr>
</table>

<table class="party">
    <tr>
        <td>
            <div class="label">Issued to</div>
            <strong>{{ $to['name'] }}</strong>
            @if(!empty($to['address']))<div class="muted">{{ $to['address'] }}</div>@endif
            <div class="muted">{{ collect([$to['city'] ?? null, $to['state'] ?? null, $to['postal_code'] ?? null])->filter()->join(', ') }}</div>
            @if(!empty($to['gstin']))<div class="muted">GSTIN: {{ $to['gstin'] }}</div>@endif
        </td>
        <td>
            <div class="label">Place of supply</div>
            <div>{{ $note->place_of_supply ?: '—' }}</div>
        </td>
    </tr>
</table>

<table class="items">
    <thead><tr><th>Description</th><th class="right">Amount</th></tr></thead>
    <tbody>
        <tr><td>Credit against invoice {{ $note->invoice->number }}: {{ $note->reason }}</td><td class="right">{{ $money($note->subtotal) }}</td></tr>
    </tbody>
</table>

<table class="totals">
    <tr><td>Taxable value</td><td class="right">{{ $money($note->subtotal) }}</td></tr>
    @foreach($note->tax_breakup ?? [] as $component)
        <tr><td>{{ $component['label'] }} @ {{ rtrim(rtrim(number_format($component['rate'], 2), '0'), '.') }}%</td><td class="right">{{ $money($component['amount']) }}</td></tr>
    @endforeach
    <tr class="grand"><td>Total credited</td><td class="right">{{ $money($note->total) }}</td></tr>
</table>

<p class="muted" style="margin-top:18px; font-size:9px">
    This credit note reduces the amount due on invoice {{ $note->invoice->number }} by {{ $money($note->total) }}, tax included at the rates the invoice charged.
    Where the invoice was already paid, the amount is held to your credit or refunded.
</p>
</body>
</html>
