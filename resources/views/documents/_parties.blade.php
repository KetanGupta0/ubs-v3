{{-- Letterhead and both parties, from what was frozen on the document. --}}
<table class="head">
    <tr>
        <td>
            <div class="logo">{{ \App\Support\PdfBrand::img(11) }}</div>
            <div class="legal">{{ $from['name'] ?? '' }}</div>
            @if(!empty($from['address']))<div class="muted">{{ $from['address'] }}</div>@endif
            <div class="muted">{{ collect([$from['city'] ?? null, $from['state'] ?? null, $from['postal_code'] ?? null])->filter()->join(', ') }}</div>
            @if(!empty($from['gstin']))<div class="muted">GSTIN: {{ $from['gstin'] }}</div>@endif
            @if(!empty($from['email']))<div class="muted">{{ $from['email'] }}</div>@endif
        </td>
        <td>
            <div class="doctitle">{{ $title }}</div>
            <div class="right" style="margin-top:6px">
                @foreach($meta as $label => $value)
                    @if(filled($value))<div><span class="label">{{ $label }}</span> {{ $value }}</div>@endif
                @endforeach
            </div>
        </td>
    </tr>
</table>

<table class="party">
    <tr>
        <td>
            <div class="label">From</div>
            <strong>{{ $from['name'] ?? '' }}</strong>
        </td>
        <td>
            <div class="label">For</div>
            <strong>{{ $to['name'] ?? '' }}</strong>
            @if(!empty($to['contact']) && ($to['contact'] ?? '') !== ($to['name'] ?? ''))<div>{{ $to['contact'] }}</div>@endif
            @if(!empty($to['address']))<div class="muted">{{ $to['address'] }}</div>@endif
            <div class="muted">{{ collect([$to['city'] ?? null, $to['state'] ?? null, $to['postal_code'] ?? null])->filter()->join(', ') }}</div>
            @if(!empty($to['gstin']))<div class="muted">GSTIN: {{ $to['gstin'] }}</div>@endif
        </td>
    </tr>
</table>
