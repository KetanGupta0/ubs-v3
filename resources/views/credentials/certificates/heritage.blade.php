{{-- Heritage: a panel down the left in the design's accent (when dark enough
     for white words on it, else burgundy) carrying the logo, a gold seal and
     the certificate number, edged in gold; the wording set left in a serif on
     warm white beside it.
     Written for dompdf: every box has an explicit top, left, width and height. --}}
@php
    $art = \App\Support\CertificateArt::class;
    $panel = $template->ink();
    $gold = '#c9a44c';
    $goldInk = '#7a5a17';
    $ink = '#1f2430';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $facts['number'] }}</title>
    <style>
        {{ $art::fontFaces('cinzel', 'cormorant', 'playfair') }}
        @page { margin: 0; }
        body { margin: 0; font-family: 'cormorant', serif; color: #4b4b55; }
        .sheet { position: relative; width: 297mm; height: 208mm; overflow: hidden; }
        /* The ground and the panel fill the whole A4 page, outside the sheet, which stops 2 mm short so
           it never spills onto a second page. */
        .ground { position: fixed; top: 0; left: 0; width: 297mm; height: 210mm; background: #fffdf7; }
        .panel { position: fixed; top: 0; left: 0; width: 84mm; height: 210mm; background: {{ $panel }}; }
        .stripe { position: fixed; top: 0; left: 84mm; width: 1.6mm; height: 210mm; background: {{ $gold }}; }
        .stripe-thin { position: fixed; top: 0; left: 87.4mm; width: 0.35mm; height: 210mm; background: {{ $gold }}; }
        .frame { position: absolute; top: 10mm; left: 96mm; width: 190.4mm; height: 187.4mm; border: 0.3mm solid #e2d3a8; }

        .panel-brand { position: absolute; top: 24mm; left: 0; width: 84mm; height: 10mm; text-align: center; }
        .panel-rule { position: absolute; top: 40mm; left: 30mm; width: 24mm; height: 0.3mm; background: {{ $gold }}; }
        .seal { position: absolute; top: 82mm; left: 19mm; width: 46mm; height: 43.7mm; }
        .panel-foot { position: absolute; top: 160mm; left: 6mm; width: 72mm; height: 24mm; text-align: center; }
        .panel-label { font-family: 'cinzel', serif; font-size: 9px; letter-spacing: 2px; color: #ecdcae; }
        .panel-value { margin-top: 1mm; font-family: DejaVu Sans Mono, monospace; font-size: 10px; letter-spacing: 1px; color: #ffffff; }

        .top { position: absolute; top: 26mm; left: 106mm; width: 172mm; height: 30mm; }
        .heading { font-family: 'playfair', serif; line-height: 1.15; color: {{ $ink }}; }
        .heading-bar { width: 26mm; height: 0.8mm; margin-top: 4mm; background: {{ $gold }}; }
        .middle { position: absolute; top: 58mm; left: 106mm; width: 172mm; height: 84mm; border-collapse: collapse; }
        .middle td { height: 84mm; padding: 0; vertical-align: middle; text-align: left; }
        .name { font-family: 'playfair', serif; font-style: italic; line-height: 1.2; color: {{ $panel }}; }
        .body-line { margin-top: 4mm; font-style: italic; font-size: 17px; }
        .course { margin-top: 1.5mm; font-family: 'cinzel', serif; line-height: 1.35; letter-spacing: 1px; color: {{ $ink }}; }
        .detail { margin-top: 3mm; font-size: 13.5px; color: #5b5b66; }
        .grade { display: inline-block; margin-top: 5mm; padding: 1.2mm 4.5mm; border: 0.3mm solid {{ $gold }};
                 font-family: 'cinzel', serif; font-size: 11px; letter-spacing: 2px; color: {{ $goldInk }}; }

        .foot { position: absolute; top: 152mm; height: 38mm; }
        .foot table { border-collapse: collapse; }
        .foot td.picture { height: 18mm; vertical-align: bottom; padding: 0; }
        .signature { height: 14mm; }
        .qr { width: 18mm; height: 18mm; }
        .foot-rule { width: 62mm; height: 0.3mm; margin-top: 1.5mm; background: {{ $gold }}; }
        .foot-name { margin-top: 1.6mm; font-family: 'cinzel', serif; font-size: 11.5px; letter-spacing: 1px; color: {{ $ink }}; }
        .foot-line { margin-top: 0.4mm; font-style: italic; font-size: 12.5px; color: #5b5b66; }
        .code { margin-top: 1.6mm; font-family: DejaVu Sans Mono, monospace; font-size: 10.5px; letter-spacing: 1.5px; color: {{ $ink }}; }
    </style>
</head>
<body>
<div class="ground"></div>
<div class="panel"></div>
<div class="stripe"></div>
<div class="stripe-thin"></div>
<div class="sheet">
    <div class="frame"></div>

    <div class="panel-brand">{{ \App\Support\PdfBrand::img(9.5, light: true) }}</div>
    <div class="panel-rule"></div>
    <img class="seal" src="{{ $art::uri('seal') }}" alt="">
    <div class="panel-foot">
        <div class="panel-label">Certificate no.</div>
        <div class="panel-value">{{ $facts['number'] }}</div>
    </div>

    <div class="top">
        <div class="heading" style="font-size: {{ $art::fit($facts['heading'], 26, 34, 22) }}px;">{{ $facts['heading'] }}</div>
        <div class="heading-bar"></div>
    </div>

    <table class="middle"><tr><td>
        <div class="name" style="font-size: {{ $art::fit($facts['name'], 22, 46, 26) }}px;">{{ $facts['name'] }}</div>
        <div class="body-line">{{ $facts['bodyLine'] }}</div>
        <div class="course" style="font-size: {{ $art::fit($facts['course'], 48, 18, 14) }}px;">{{ $facts['course'] }}</div>
        <div class="detail">@if($facts['period']){{ $facts['period'] }} &middot; @endif Issued {{ $facts['issued'] }}</div>

        @if($facts['grade'])
            <div class="grade">Grade {{ $facts['grade'] }}@if($facts['percent']) &middot; {{ $facts['percent'] }}@endif</div>
        @endif
    </td></tr></table>

    <div class="foot" style="left: 106mm; width: 80mm;">
        <table><tr><td class="picture">
            @if($signature)<img class="signature" src="{{ $signature }}" alt="">@endif
        </td></tr></table>
        <div class="foot-rule"></div>
        <div class="foot-name">{{ $facts['signatory'] }}</div>
        <div class="foot-line">{{ $facts['signatoryTitle'] }}</div>
    </div>

    <div class="foot" style="left: 206mm; width: 72mm; text-align: right;">
        <table style="width: 100%;"><tr><td class="picture" style="text-align: right;">
            @if($qr)<img class="qr" src="{{ $qr }}" alt="">@endif
        </td></tr></table>
        <div class="foot-rule" style="margin-left: 10mm;"></div>
        <div class="code">{{ $facts['code'] }}</div>
        <div class="foot-line">Verify at {{ $facts['verifyAt'] }}</div>
    </div>
</div>
</body>
</html>
