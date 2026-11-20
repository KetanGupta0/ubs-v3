{{-- Regal: ivory, a gold double frame with flourished corners, the name in
     script and a gold seal on ribbons. Like every layout it is fixed: a design
     changes the wording, the ink of the heading, name and course (its accent,
     when dark enough to read) and the signatory, never where anything sits.
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
        {{ $art::fontFaces('cinzel', 'cormorant', 'greatvibes') }}
        @page { margin: 0; }
        body { margin: 0; font-family: 'cormorant', serif; color: #4a4334; }
        .sheet { position: relative; width: 297mm; height: 208mm; overflow: hidden; }
        /* The ground fills the whole A4 page, outside the sheet, which stops 2 mm short so it never spills onto a second page. */
        .ground { position: fixed; top: 0; left: 0; width: 297mm; height: 210mm; background: #fbf7ec; }
        .frame { position: absolute; top: 9mm; left: 9mm; width: 277.6mm; height: 188.6mm; border: 0.7mm solid {{ $gold }}; }
        .frame-inner { position: absolute; top: 12.5mm; left: 12.5mm; width: 271.5mm; height: 182.5mm; border: 0.25mm solid #cdb06a; }
        .corner { position: absolute; width: 28mm; height: 28mm; }

        /* The heading sits at the top and the signatures at the foot; whatever lies between is
           centred in the room left, so a long name or course moves nothing else. */
        .top { position: absolute; top: 21mm; left: 30mm; width: 237mm; height: 36mm; text-align: center; }
        .middle { position: absolute; top: 60mm; left: 30mm; width: 237mm; height: 82mm; border-collapse: collapse; }
        .middle td { height: 82mm; padding: 0; vertical-align: middle; text-align: center; }
        .brand { height: 11mm; }
        .lead { margin-top: 7mm; font-family: 'cinzel', serif; line-height: 1.1; letter-spacing: 5px; color: {{ $ink }}; }
        .rest { margin-top: 1.2mm; font-family: 'cinzel', serif; font-size: 13px; letter-spacing: 4px; color: {{ $goldInk }}; }
        .hair { display: inline-block; width: 24mm; height: 0.3mm; background: {{ $gold }}; vertical-align: middle; }
        .rest-text { padding: 0 4mm; vertical-align: middle; }
        .name { font-family: 'greatvibes', cursive; line-height: 1.2; color: {{ $ink }}; }
        .name-rule { width: 120mm; height: 0.3mm; margin: 0.5mm auto 0; background: {{ $gold }}; }
        .body-line { margin-top: 5mm; font-style: italic; font-size: 17px; }
        .course { margin-top: 2mm; font-family: 'cinzel', serif; line-height: 1.35; letter-spacing: 1px; color: {{ $ink }}; }
        .detail { margin-top: 3mm; font-size: 13.5px; color: #5b5446; }
        .grade { display: inline-block; margin-top: 5mm; padding: 1.2mm 4.5mm; border: 0.3mm solid {{ $gold }};
                 font-family: 'cinzel', serif; font-size: 11px; letter-spacing: 2px; color: {{ $goldInk }}; }

        .seal { position: absolute; top: 145mm; left: 131.5mm; width: 34mm; height: 42.5mm; }
        .foot { position: absolute; top: 151mm; width: 78mm; height: 36mm; text-align: center; }
        .foot table { width: 100%; border-collapse: collapse; }
        .foot td.picture { height: 19mm; vertical-align: bottom; text-align: center; padding: 0; }
        .signature { height: 14mm; }
        .qr { width: 18mm; height: 18mm; }
        .foot-rule { width: 64mm; height: 0.3mm; margin: 1.5mm auto 0; background: {{ $gold }}; }
        .foot-name { margin-top: 1.6mm; font-family: 'cinzel', serif; font-size: 11.5px; letter-spacing: 1px; color: {{ $ink }}; }
        .foot-line { margin-top: 0.4mm; font-style: italic; font-size: 12.5px; color: #5b5446; }
        .code { margin-top: 1.6mm; font-family: DejaVu Sans Mono, monospace; font-size: 10.5px; letter-spacing: 1.5px; color: #2b2720; }
        .number { margin-top: 0.6mm; font-family: 'cinzel', serif; font-size: 9.5px; letter-spacing: 1px; color: {{ $goldInk }}; }
    </style>
</head>
<body>
<div class="ground"></div>
<div class="sheet">
    <div class="frame"></div>
    <div class="frame-inner"></div>
    <img class="corner" style="top: 7.39mm; left: 7.39mm;" src="{{ $art::uri('corner-tl') }}" alt="">
    <img class="corner" style="top: 7.39mm; left: 261.61mm;" src="{{ $art::uri('corner-tr') }}" alt="">
    <img class="corner" style="top: 172.61mm; left: 7.39mm;" src="{{ $art::uri('corner-bl') }}" alt="">
    <img class="corner" style="top: 172.61mm; left: 261.61mm;" src="{{ $art::uri('corner-br') }}" alt="">

    <div class="top">
        <div class="brand">{{ \App\Support\PdfBrand::img(11) }}</div>

        <div class="lead" style="font-size: {{ $art::fit($facts['headingLead'], 14, 34, 20) }}px;">{{ $facts['headingLead'] }}</div>
        @if($facts['headingRest'])
            <div class="rest"><span class="hair"></span><span class="rest-text">{{ $facts['headingRest'] }}</span><span class="hair"></span></div>
        @endif
    </div>

    <table class="middle"><tr><td>
        <div class="name" style="font-size: {{ $art::fit($facts['name'], 26, 58, 28) }}px;">{{ $facts['name'] }}</div>
        <div class="name-rule"></div>

        <div class="body-line">{{ $facts['bodyLine'] }}</div>
        <div class="course" style="font-size: {{ $art::fit($facts['course'], 60, 18, 15) }}px;">{{ $facts['course'] }}</div>
        <div class="detail">@if($facts['period']){{ $facts['period'] }} &middot; @endif Issued {{ $facts['issued'] }}</div>

        @if($facts['grade'])
            <div class="grade">Grade {{ $facts['grade'] }}@if($facts['percent']) &middot; {{ $facts['percent'] }}@endif</div>
        @endif
    </td></tr></table>

    <img class="seal" src="{{ $art::uri('seal-ribbons') }}" alt="">

    <div class="foot" style="left: 32mm;">
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
