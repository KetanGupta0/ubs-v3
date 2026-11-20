{{-- Laurel: white and quiet, fine gold rules, the wording in Cormorant, and a
     gold laurel at the foot round the grade (or, without one, the year).
     Written for dompdf: every box has an explicit top, left, width and height. --}}
@php
    $art = \App\Support\CertificateArt::class;
    $ink = $template->ink();
    $gold = '#b8913a';
    $goldInk = '#7a5a17';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $facts['number'] }}</title>
    <style>
        {{ $art::fontFaces('cinzel', 'cormorant') }}
        @page { margin: 0; }
        body { margin: 0; font-family: 'cormorant', serif; color: #4f5562; }
        .sheet { position: relative; width: 297mm; height: 208mm; overflow: hidden; }
        .frame { position: absolute; top: 10mm; left: 10mm; width: 276.3mm; height: 187.3mm; border: 0.35mm solid {{ $gold }}; }
        .frame-inner { position: absolute; top: 12.5mm; left: 12.5mm; width: 271.7mm; height: 182.7mm; border: 0.15mm solid #dcc68f; }

        .top { position: absolute; top: 22mm; left: 30mm; width: 237mm; height: 34mm; text-align: center; }
        .brand { height: 11mm; }
        .heading { margin-top: 9mm; font-family: 'cinzel', serif; letter-spacing: 6px; color: {{ $goldInk }}; }
        .heading-rule { width: 18mm; height: 0.3mm; margin: 3.5mm auto 0; background: {{ $gold }}; }
        .middle { position: absolute; top: 58mm; left: 30mm; width: 237mm; height: 86mm; border-collapse: collapse; }
        .middle td { height: 86mm; padding: 0; vertical-align: middle; text-align: center; }
        .name { font-style: italic; line-height: 1.15; color: #1c1f26; }
        .name-rule { width: 104mm; height: 0.25mm; margin: 1.5mm auto 0; background: #d9c38a; }
        .body-line { margin-top: 5mm; font-style: italic; font-size: 17px; }
        .course { margin-top: 1.5mm; line-height: 1.3; letter-spacing: 0.5px; color: {{ $ink }}; }
        .detail { margin-top: 3mm; font-size: 13.5px; color: #5b6070; }

        .laurel { position: absolute; top: 151mm; left: 125.5mm; width: 46mm; height: 29.44mm; }
        .laurel-text { position: absolute; top: 157mm; left: 125.5mm; width: 46mm; height: 20mm; text-align: center; }
        .grade { font-size: 30px; line-height: 1; color: {{ $ink }}; }
        .percent { margin-top: 0.6mm; font-family: 'cinzel', serif; font-size: 9.5px; letter-spacing: 1px; color: {{ $goldInk }}; }
        .year { margin-top: 3mm; font-family: 'cinzel', serif; font-size: 15px; letter-spacing: 2px; color: {{ $goldInk }}; }

        .foot { position: absolute; top: 153mm; width: 80mm; height: 36mm; text-align: center; }
        .foot table { width: 100%; border-collapse: collapse; }
        .foot td.picture { height: 17mm; vertical-align: bottom; text-align: center; padding: 0; }
        .signature { height: 14mm; }
        .qr { width: 17mm; height: 17mm; }
        .foot-rule { width: 62mm; height: 0.25mm; margin: 1.5mm auto 0; background: {{ $gold }}; }
        .foot-name { margin-top: 1.6mm; font-family: 'cinzel', serif; font-size: 11.5px; letter-spacing: 1px; color: #1c1f26; }
        .foot-line { margin-top: 0.4mm; font-style: italic; font-size: 12.5px; color: #5b6070; }
        .code { margin-top: 1.6mm; font-family: DejaVu Sans Mono, monospace; font-size: 10.5px; letter-spacing: 1.5px; color: #1c1f26; }
        .number { margin-top: 0.6mm; font-family: 'cinzel', serif; font-size: 9.5px; letter-spacing: 1px; color: {{ $goldInk }}; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="frame"></div>
    <div class="frame-inner"></div>

    <div class="top">
        <div class="brand">{{ \App\Support\PdfBrand::img(11) }}</div>
        <div class="heading" style="font-size: {{ $art::fit($facts['heading'], 28, 17, 12) }}px;">{{ $facts['heading'] }}</div>
        <div class="heading-rule"></div>
    </div>

    <table class="middle"><tr><td>
        <div class="name" style="font-size: {{ $art::fit($facts['name'], 22, 54, 30) }}px;">{{ $facts['name'] }}</div>
        <div class="name-rule"></div>
        <div class="body-line">{{ $facts['bodyLine'] }}</div>
        <div class="course" style="font-size: {{ $art::fit($facts['course'], 48, 24, 17) }}px;">{{ $facts['course'] }}</div>
        <div class="detail">@if($facts['period']){{ $facts['period'] }} &middot; @endif Issued {{ $facts['issued'] }}</div>
    </td></tr></table>

    <img class="laurel" src="{{ $art::uri('laurel') }}" alt="">
    <div class="laurel-text">
        @if($facts['grade'])
            <div class="grade">{{ $facts['grade'] }}</div>
            @if($facts['percent'])<div class="percent">{{ $facts['percent'] }}</div>@endif
        @else
            <div class="year">{{ $facts['year'] }}</div>
        @endif
    </div>

    <div class="foot" style="left: 32mm;">
        <table><tr><td class="picture">
            @if($signature)<img class="signature" src="{{ $signature }}" alt="">@endif
        </td></tr></table>
        <div class="foot-rule"></div>
        <div class="foot-name">{{ $facts['signatory'] }}</div>
        <div class="foot-line">{{ $facts['signatoryTitle'] }}</div>
    </div>

    <div class="foot" style="left: 185mm;">
        <table><tr><td class="picture">
            @if($qr)<img class="qr" src="{{ $qr }}" alt="">@endif
        </td></tr></table>
        <div class="foot-rule"></div>
        <div class="code">{{ $facts['code'] }}</div>
        <div class="foot-line">Verify at {{ $facts['verifyAt'] }}</div>
        <div class="number">No. {{ $facts['number'] }}</div>
    </div>
</div>
</body>
</html>
