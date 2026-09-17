{{-- The internship completion certificate. Landscape, like the course one, but
     it names the college and the project, because that is what a department
     wants to see on it. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $document->number }}</title>
    <style>
        @page { margin: 0; }
        body { font-family: DejaVu Sans, sans-serif; margin: 0; color: #1f2430; }
        .sheet { position: relative; width: 297mm; height: 210mm; padding: 18mm 20mm; box-sizing: border-box; }
        .frame { position: absolute; inset: 10mm; border: 2px solid #4f46e5; }
        .frame-inner { position: absolute; inset: 12mm; border: 1px solid #c7d2fe; }
        .content { position: relative; text-align: center; padding-top: 10mm; }
        .brand { font-size: 20px; font-weight: bold; color: #4f46e5; letter-spacing: .04em; }
        .legal { font-size: 9.5px; color: #6b7280; margin-top: 2px; }
        .kicker { margin-top: 12mm; font-size: 10px; letter-spacing: .28em; text-transform: uppercase; color: #6b7280; }
        .name { margin-top: 4mm; font-size: 32px; font-weight: bold; }
        .rule { width: 110mm; height: 1px; background: #c7d2fe; margin: 4mm auto 0; }
        .for { margin-top: 5mm; font-size: 12px; color: #4b5563; }
        .course { margin-top: 2mm; font-size: 19px; font-weight: bold; color: #4f46e5; }
        .detail { margin-top: 3mm; font-size: 11px; color: #4b5563; }
        .project { margin-top: 4mm; font-size: 11px; color: #4b5563; max-width: 190mm; margin-left: auto; margin-right: auto; }
        .feet { position: absolute; left: 20mm; right: 20mm; bottom: 22mm; }
        .feet table { width: 100%; border-collapse: collapse; }
        .feet td { vertical-align: bottom; font-size: 9px; color: #6b7280; }
        .sign { border-top: 1px solid #9ca3af; padding-top: 3px; width: 60mm; }
        .verify { text-align: center; font-size: 8.5px; color: #6b7280; }
        .code { font-family: DejaVu Sans Mono, monospace; font-size: 11px; color: #1f2430; letter-spacing: .12em; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="frame"></div>
    <div class="frame-inner"></div>

    <div class="content">
        <div class="brand">{{ $company['trading'] }}</div>
        <div class="legal">{{ $company['name'] }}</div>

        <div class="kicker">Certificate of internship</div>

        <div class="name">{{ $payload['student'] }}</div>
        <div class="rule"></div>

        <div class="for">
            @if($payload['college'])
                of {{ $payload['college'] }}, has completed an internship in
            @else
                has completed an internship in
            @endif
        </div>
        <div class="course">{{ $payload['title'] }}</div>

        <div class="detail">
            @if($payload['startsOn'])
                {{ \Illuminate\Support\Carbon::parse($payload['startsOn'])->format('j M Y') }}
                @if($payload['endsOn']) to {{ \Illuminate\Support\Carbon::parse($payload['endsOn'])->format('j M Y') }}@endif
                ·
            @endif
            {{ ucfirst($payload['mode'] ?? 'remote') }}
            @if($payload['durationLabel']) · {{ $payload['durationLabel'] }}@endif
        </div>

        @if($payload['projectFocus'])
            <div class="project">Project work: {{ $payload['projectFocus'] }}</div>
        @endif
    </div>

    <div class="feet">
        <table>
            <tr>
                <td style="width: 34%">
                    <div class="sign">Authorised signatory<br>{{ $company['trading'] }}</div>
                </td>
                <td style="width: 32%" class="verify">
                    Verify at {{ url('/verify') }}<br>
                    <span class="code">{{ $document->verification_code }}</span>
                </td>
                <td style="width: 34%; text-align: right">
                    Certificate number<br><strong>{{ $document->number }}</strong>
                </td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>
