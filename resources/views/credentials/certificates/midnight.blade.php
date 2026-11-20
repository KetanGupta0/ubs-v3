{{-- Midnight: a dark ground (the design's accent, when dark enough for white
     words on it, else deep navy) with gold rules, corners and lettering, the
     name in white, and an ivory band at the foot so a signature in ink and the
     QR code stay legible. The seal sits on the band's edge.
     Written for dompdf: every box has an explicit top, left, width and height. --}}
@php
    $art = \App\Support\CertificateArt::class;
    $ground = $template->ink();
    $gold = '#c9a44c';
    $goldText = '#e2c26c';
    $ink = '#141a33';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $facts['number'] }}</title>
    <style>
        {{ $art::fontFaces('cinzel', 'cormorant', 'playfair') }}
        @page { margin: 0; }
        body { margin: 0; font-family: 'cormorant', serif; color: #ffffff; }
        .sheet { position: relative; width: 297mm; height: 208mm; overflow: hidden; }
        /* The ground fills the whole A4 page, outside the sheet, which stops 2 mm short so it never spills onto a second page. */
        .ground { position: fixed; top: 0; left: 0; width: 297mm; height: 210mm; background: {{ $ground }}; }
        .frame { position: absolute; top: 9mm; left: 9mm; width: 277.6mm; height: 188.6mm; border: 0.7mm solid {{ $gold }}; }
        .frame-inner { position: absolute; top: 12.5mm; left: 12.5mm; width: 271.5mm; height: 182.5mm; border: 0.25mm solid #8f7637; }
        .corner { position: absolute; width: 28mm; height: 28mm; }
        .band { position: absolute; top: 151mm; left: 12.75mm; width: 271.5mm; height: 44.25mm; background: #f6efdc; }

        .top { position: absolute; top: 21mm; left: 30mm; width: 237mm; height: 36mm; text-align: center; }
        .middle { position: absolute; top: 59mm; left: 30mm; width: 237mm; height: 75mm; border-collapse: collapse; }
        .middle td { height: 75mm; padding: 0; vertical-align: middle; text-align: center; }
        .brand { height: 11mm; }
        .lead { margin-top: 7mm; font-family: 'cinzel', serif; line-height: 1.1; letter-spacing: 7px; color: {{ $goldText }}; }
        .rest { margin-top: 1.2mm; font-family: 'cinzel', serif; font-size: 13px; letter-spacing: 5px; color: #efe4c2; }
        .hair { display: inline-block; width: 24mm; height: 0.3mm; background: {{ $gold }}; vertical-align: middle; }
        .rest-text { padding: 0 4mm; vertical-align: middle; }
        .name { font-family: 'playfair', serif; font-style: italic; line-height: 1.2; color: #ffffff; }
        .name-rule { width: 110mm; height: 0.3mm; margin: 2mm auto 0; background: {{ $gold }}; }
        .body-line { margin-top: 5mm; font-style: italic; font-size: 17px; color: #d5d9e6; }
        .course { margin-top: 2mm; font-family: 'cinzel', serif; line-height: 1.35; letter-spacing: 1px; color: {{ $goldText }}; }
        .detail { margin-top: 3mm; font-size: 13.5px; color: #b9bfd2; }
        .grade { display: inline-block; margin-top: 5mm; padding: 1.2mm 4.5mm; border: 0.3mm solid {{ $gold }};
                 font-family: 'cinzel', serif; font-size: 11px; letter-spacing: 2px; color: {{ $goldText }}; }

        .seal { position: absolute; top: 136.75mm; left: 133.5mm; width: 30mm; height: 28.5mm; }
        .foot { position: absolute; top: 154mm; width: 80mm; height: 38mm; text-align: center; }
        .foot table { width: 100%; border-collapse: collapse; }
        .foot td.picture { height: 17mm; vertical-align: bottom; text-align: center; padding: 0; }
        .signature { height: 14mm; }
        .qr { width: 17mm; height: 17mm; }
        .foot-rule { width: 64mm; height: 0.3mm; margin: 1.5mm auto 0; background: #b8913a; }
        .foot-name { margin-top: 1.6mm; font-family: 'cinzel', serif; font-size: 11.5px; letter-spacing: 1px; color: {{ $ink }}; }
        .foot-line { margin-top: 0.4mm; font-style: italic; font-size: 12.5px; color: #4a4a5a; }
        .code { margin-top: 1.6mm; font-family: DejaVu Sans Mono, monospace; font-size: 10.5px; letter-spacing: 1.5px; color: {{ $ink }}; }
        .number { margin-top: 0.6mm; font-family: 'cinzel', serif; font-size: 9.5px; letter-spacing: 1px; color: #6b5313; }
    </style>
</head>
<body>
<div class="ground"></div>
<div class="sheet">
    <div class="band"></div>
    <div class="frame"></div>
    <div class="frame-inner"></div>
    <img class="corner" style="top: 7.39mm; left: 7.39mm;" src="{{ $art::uri('corner-tl') }}" alt="">
    <img class="corner" style="top: 7.39mm; left: 261.61mm;" src="{{ $art::uri('corner-tr') }}" alt="">
    <img class="corner" style="top: 172.61mm; left: 7.39mm;" src="{{ $art::uri('corner-bl') }}" alt="">
    <img class="corner" style="top: 172.61mm; left: 261.61mm;" src="{{ $art::uri('corner-br') }}" alt="">

    <div class="top">
        <div class="brand">{{ \App\Support\PdfBrand::img(11, light: true) }}</div>

        <div class="lead" style="font-size: {{ $art::fit($facts['headingLead'], 14, 36, 20) }}px;">{{ $facts['headingLead'] }}</div>
        @if($facts['headingRest'])
            <div class="rest"><span class="hair"></span><span class="rest-text">{{ $facts['headingRest'] }}</span><span class="hair"></span></div>
        @endif
    </div>

    <table class="middle"><tr><td>
        <div class="name" style="font-size: {{ $art::fit($facts['name'], 24, 46, 26) }}px;">{{ $facts['name'] }}</div>
        <div class="name-rule"></div>

        <div class="body-line">{{ $facts['bodyLine'] }}</div>
        <div class="course" style="font-size: {{ $art::fit($facts['course'], 60, 18, 15) }}px;">{{ $facts['course'] }}</div>
        <div class="detail">@if($facts['period']){{ $facts['period'] }} &middot; @endif Issued {{ $facts['issued'] }}</div>

        @if($facts['grade'])
            <div class="grade">Grade {{ $facts['grade'] }}@if($facts['percent']) &middot; {{ $facts['percent'] }}@endif</div>
        @endif
    </td></tr></table>

    <img class="seal" src="{{ $art::uri('seal') }}" alt="">

    <div class="foot" style="left: 30mm;">
        <table><tr><td class="picture">
            @if($signature)<img class="signature" src="{{ $signature }}" alt="">@endif
        </td></tr></table>
        <div class="foot-rule"></div>
        <div class="foot-name">{{ $facts['signatory'] }}</div>
        <div class="foot-line">{{ $facts['signatoryTitle'] }}</div>
    </div>

    <div class="foot" style="left: 187mm;">
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
