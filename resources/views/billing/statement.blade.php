{{-- Tax invoice. Printed, filed, and occasionally read by somebody's auditor,
     so it carries every field the law asks for and nothing decorative. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement of account</title>
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
{{-- A statement of account (CL14): rendered on demand from the ledger, never kept. --}}
<table class="head">
    <tr>
        <td>
            <div class="logo">{{ \App\Support\PdfBrand::img(11) }}</div>
            <div class="legal">{{ $from['name'] }}</div>
            @if(!empty($from['gstin']))<div class="muted">GSTIN: {{ $from['gstin'] }}</div>@endif
        </td>
        <td>
            <div class="title">Statement of Account</div>
            <div class="right" style="margin-top:8px">
                <div><span class="label">From</span> {{ $statement['fromLabel'] }}</div>
                <div><span class="label">To</span> {{ $statement['toLabel'] }}</div>
                <div><span class="label">Prepared</span> {{ now()->format('j M Y') }}</div>
            </div>
        </td>
    </tr>
</table>

<table class="party">
    <tr>
        <td>
            <div class="label">For</div>
            <strong>{{ $to['name'] }}</strong>
            @if(!empty($to['gstin']))<div class="muted">GSTIN: {{ $to['gstin'] }}</div>@endif
        </td>
        <td>
            <div class="label">Balance</div>
            <div><strong>{{ $statement['closing'] }}</strong> {{ $statement['closingOwed'] ? 'due to us' : 'to your credit' }}</div>
        </td>
    </tr>
</table>

<table class="items">
    <thead><tr><th>Date</th><th>Reference</th><th>Description</th><th class="right">Debit</th><th class="right">Credit</th><th class="right">Balance</th></tr></thead>
    <tbody>
        <tr><td style="white-space: nowrap">{{ $statement['fromLabel'] }}</td><td></td><td>Opening balance</td><td></td><td></td><td class="right" style="white-space: nowrap">{{ $statement['opening'] }}</td></tr>
        @foreach($statement['entries'] as $entry)
            <tr>
                <td style="white-space: nowrap">{{ $entry['date'] }}</td>
                <td style="white-space: nowrap; font-size: 8.5px">{{ $entry['reference'] }}</td>
                <td>{{ $entry['description'] }}</td>
                <td class="right" style="white-space: nowrap">{{ $entry['debit'] }}</td>
                <td class="right" style="white-space: nowrap">{{ $entry['credit'] }}</td>
                <td class="right" style="white-space: nowrap">{{ $entry['balance'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Billed in the period</td><td class="right">{{ $statement['debits'] }}</td></tr>
    <tr><td>Settled in the period</td><td class="right">{{ $statement['credits'] }}</td></tr>
    <tr class="grand"><td>Closing balance</td><td class="right">{{ $statement['closing'] }}</td></tr>
</table>

<p class="muted" style="margin-top:18px; font-size:9px">
    Debits are invoices and refunds paid to you; credits are payments, TDS you deducted and credit notes. A balance shown with a minus sign is held to your credit.
</p>
</body>
</html>
