{{-- A certificate somebody will print and frame, and an employer will check.
     A template swaps in its background, accent, wording and signatory; the
     layout, the name, the number and the verification block stay fixed. --}}
@php
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($template?->accent ?? '')) ? $template->accent : '#4f46e5';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->number }}</title>
    <style>
        @page { margin: 0; }
        body { font-family: DejaVu Sans, sans-serif; margin: 0; color: #1f2430; }
        /* dompdf ignores `inset` and is unreliable with `bottom`, and a sheet exactly as tall as the page
           spills onto a second one; so every box gets an explicit top, left, width and height. */
        .sheet { position: relative; width: 297mm; height: 208mm; overflow: hidden; }
        .frame { position: absolute; top: 10mm; left: 10mm; width: 276mm; height: 187mm; border: 2px solid {{ $accent }}; }
        .frame-inner { position: absolute; top: 12.5mm; left: 12.5mm; width: 271mm; height: 182mm; border: 1px solid #c7d2fe; }
        .content { position: absolute; top: 26mm; left: 20mm; width: 257mm; text-align: center; }
        .brand { font-size: 20px; font-weight: bold; color: {{ $accent }}; letter-spacing: .04em; }
        .legal { font-size: 9.5px; color: #6b7280; margin-top: 2px; }
        .kicker { margin-top: 12mm; font-size: 10px; letter-spacing: .28em; text-transform: uppercase; color: #6b7280; }
        .name { margin-top: 5mm; font-size: 34px; font-weight: bold; }
        .rule { width: 110mm; height: 1px; background: #c7d2fe; margin: 4mm auto 0; }
        .for { margin-top: 6mm; font-size: 12px; color: #4b5563; }
        .course { margin-top: 3mm; font-size: 20px; font-weight: bold; color: {{ $accent }}; }
        .detail { margin-top: 4mm; font-size: 11px; color: #4b5563; }
        .grade { display: inline-block; margin-top: 5mm; padding: 4px 14px; border: 1.5px solid #16a34a;
                 color: #15803d; font-weight: bold; font-size: 11px; letter-spacing: .05em; }
        .feet { position: absolute; top: 150mm; left: 24mm; width: 249mm; }
        .feet table { width: 100%; border-collapse: collapse; }
        .feet td { vertical-align: bottom; font-size: 9px; color: #6b7280; }
        .sign { border-top: 1px solid #9ca3af; padding-top: 3px; width: 60mm; }
        .verify { text-align: center; font-size: 8.5px; color: #6b7280; }
        .code { font-family: DejaVu Sans Mono, monospace; font-size: 11px; color: #1f2430; letter-spacing: .12em; }
        .background { position: absolute; top: 0; left: 0; width: 297mm; height: 208mm; }
        .qr { width: 22mm; height: 22mm; }
        .signature { height: 14mm; margin-bottom: 1mm; }
    </style>
</head>
<body>
<div class="sheet">
    @if($background)
        <img class="background" src="{{ $background }}" alt="">
    @else
        <div class="frame"></div>
        <div class="frame-inner"></div>
    @endif

    <div class="content">
        <div class="brand">{{ $company['trading'] }}</div>
        <div class="legal">{{ $company['name'] }}</div>

        <div class="kicker">{{ $template?->heading ?? 'Certificate of completion' }}</div>

        <div class="name">{{ $certificate->user->name }}</div>
        <div class="rule"></div>

        <div class="for">{{ $template?->body_line ?? 'has completed' }}</div>
        <div class="course">{{ $certificate->title }}</div>

        <div class="detail">
            @if($certificate->batch?->starts_on && $certificate->batch?->ends_on)
                {{ $certificate->batch->starts_on->format('j M Y') }} to
                {{ $certificate->batch->ends_on->format('j M Y') }} ·
            @endif
            Issued {{ $certificate->issued_at->format('j M Y') }}
        </div>

        @if($certificate->grade && ($template?->show_grade ?? true))
            <div class="grade">{{ $certificate->grade }}@if($certificate->final_percent) · {{ rtrim(rtrim(number_format((float) $certificate->final_percent, 1), '0'), '.') }}%@endif</div>
        @endif
    </div>

    <div class="feet">
        <table>
            <tr>
                <td style="width: 34%">
                    @if($signature)
                        <img class="signature" src="{{ $signature }}" alt=""><br>
                    @endif
                    <div class="sign">
                        {{ $template?->signatory_name ?? 'Authorised signatory' }}<br>
                        {{ $template?->signatory_title ?? $company['trading'] }}
                    </div>
                </td>
                <td style="width: 32%" class="verify">
                    @if($qr)
                        <img class="qr" src="{{ $qr }}" alt=""><br>
                    @endif
                    Verify at {{ url('/verify') }}<br>
                    <span class="code">{{ $certificate->verification_code }}</span>
                </td>
                <td style="width: 34%; text-align: right">
                    Certificate number<br><strong>{{ $certificate->number }}</strong>
                </td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>
