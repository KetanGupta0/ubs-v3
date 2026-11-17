{{-- A proposal, with its quotation, as it was sent. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Proposal {{ $proposal->number }}</title>
    @include('documents._style')
</head>
<body>
@include('documents._parties', [
    'from' => $parties['from'],
    'to' => $parties['to'],
    'title' => 'Proposal',
    'meta' => [
        'Number' => $proposal->number,
        'Version' => $proposal->version > 1 ? (string) $proposal->version : null,
        'Date' => ($proposal->sent_at ?? now())->format('j M Y'),
        'Valid until' => $proposal->valid_until?->format('j M Y'),
    ],
])

<h1>{{ $proposal->title }}</h1>
@if($proposal->summary)<p class="muted">{{ $proposal->summary }}</p>@endif

@if($proposal->body)
    <h2>The proposal</h2>
    @foreach(preg_split('/\n{2,}/', trim($proposal->body)) as $paragraph)
        <p>{!! nl2br(e($paragraph)) !!}</p>
    @endforeach
@endif

@if(!empty($proposal->deliverables))
    <h2>What we deliver</h2>
    <ul>@foreach($proposal->deliverables as $item)<li>{{ is_array($item) ? ($item['title'] ?? json_encode($item)) : $item }}</li>@endforeach</ul>
@endif

@if($proposal->timeline)
    <h2>Timeline</h2>
    <p>{{ $proposal->timeline }}</p>
@endif

@if(!empty($proposal->assumptions))
    <h2>Assumptions</h2>
    <ul>@foreach($proposal->assumptions as $item)<li>{{ is_array($item) ? ($item['title'] ?? json_encode($item)) : $item }}</li>@endforeach</ul>
@endif

@if($quotation)
    <h2>Quotation {{ $quotation->number }}</h2>
    <table class="items">
        <thead>
            <tr><th>Description</th><th>SAC</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">GST</th><th class="right">Amount</th></tr>
        </thead>
        <tbody>
        @foreach($quotation->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td>{{ $item->hsn_sac }}</td>
                <td class="right">{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }} {{ $item->unit }}</td>
                <td class="right">{{ $money($item->unit_price) }}</td>
                <td class="right">{{ rtrim(rtrim((string) $item->tax_rate, '0'), '.') }}%</td>
                <td class="right">{{ $money($item->amount) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td>Subtotal</td><td class="right">{{ $money($quotation->subtotal) }}</td></tr>
        @if($quotation->discount > 0)<tr><td>Discount</td><td class="right">− {{ $money($quotation->discount) }}</td></tr>@endif
        <tr><td>GST</td><td class="right">{{ $money($quotation->tax) }}</td></tr>
        <tr class="grand"><td>Total</td><td class="right">{{ $money($quotation->total) }}</td></tr>
    </table>
    <div class="words">{{ $words }}</div>
    @if($quotation->notes)<p class="note" style="white-space: pre-line">{{ $quotation->notes }}</p>@endif
@endif

<p class="note">
    This proposal was issued on {{ ($proposal->sent_at ?? now())->format('j M Y') }}{{ $proposal->valid_until ? ' and is valid until '.$proposal->valid_until->format('j M Y') : '' }}.
    Prices are in Indian rupees. Accepting it is recorded in your account with the time and the person who accepted.
</p>
@if(!empty($signatures))
    @include('documents._certificate', [
        'documentLabel' => 'Proposal',
        'number' => $proposal->number,
        'version' => $proposal->version,
        'title' => $proposal->title,
        'sha256' => $proposal->pdf_sha256,
        'signatures' => $signatures,
    ])
@endif
</body>
</html>
