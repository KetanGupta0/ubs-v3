<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 22mm 14mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #151B33; margin: 0; }

        .masthead { border-bottom: 2px solid #4F46E5; padding-bottom: 8px; margin-bottom: 14px; }
        .brand { font-size: 15px; font-weight: bold; color: #0B1020; }
        .brand span { color: #4F46E5; }
        .legal { font-size: 8px; color: #66719B; margin-top: 2px; }
        .title { font-size: 12px; font-weight: bold; margin-top: 10px; }
        .meta { font-size: 8.5px; color: #66719B; margin-top: 2px; }

        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th {
            background: #4F46E5; color: #fff; text-align: left;
            padding: 6px 7px; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.04em;
        }
        td { padding: 5px 7px; border-bottom: 1px solid #E6E9F2; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #F6F7FB; }

        .empty { text-align: center; padding: 28px; color: #66719B; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 8px; color: #8792B8; }
        .footer .page:after { content: counter(page); }
    </style>
</head>
<body>
    <div class="masthead">
        <div class="brand">unbound<span>byte</span></div>
        <div class="legal">Unboundbyte Solutions Private Limited</div>
        <div class="title">{{ $title }}</div>
        <div class="meta">
            Generated {{ $generatedAt->format('d M Y, H:i') }} &middot; {{ count($rows) }} {{ Str::plural('record', count($rows)) }}
        </div>
    </div>

    @if (count($rows) === 0)
        <p class="empty">No records matched the selected filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($headings as $heading)
                        <th>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        <span class="page">Page </span>
    </div>
</body>
</html>
