{{-- Shared head and letterhead for the internship documents. --}}
<style>
    @page { margin: 22mm 18mm 26mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.65; color: #1f2430; }
    .head { border-bottom: 2px solid #4f46e5; padding-bottom: 8px; margin-bottom: 18px; }
    .brand { font-size: 17px; font-weight: bold; color: #4f46e5; }
    .muted { color: #6b7280; font-size: 9.5px; }
    .meta { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10px; }
    .meta td { padding: 2px 0; }
    .meta td:last-child { text-align: right; }
    h1 { font-size: 15px; margin: 0 0 14px; text-transform: uppercase; letter-spacing: .08em; }
    h2 { font-size: 12px; margin: 18px 0 6px; color: #4f46e5; }
    table.marks { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 10.5px; }
    table.marks th { background: #f3f4f6; text-align: left; padding: 6px 8px; font-size: 9px;
                     text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid #e5e7eb; }
    table.marks td { padding: 6px 8px; border-bottom: 1px solid #f0f1f3; }
    .right { text-align: right; }
    .sign { margin-top: 26px; }
    .verify { margin-top: 20px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-size: 8.5px; color: #6b7280; }
    .code { font-family: DejaVu Sans Mono, monospace; letter-spacing: .1em; color: #1f2430; }
    .entry { margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid #f0f1f3; }
    .entry:last-child { border-bottom: 0; }
</style>

<div class="head">
    <div class="brand">{{ $company['trading'] }}</div>
    <div class="muted">
        {{ $company['name'] }}@if($company['cin']) · CIN {{ $company['cin'] }}@endif
    </div>
    <div class="muted">
        {{ collect([$company['address'] ?? null, $company['city'] ?? null, $company['state'] ?? null])->filter()->join(', ') }}
        @if($company['email']) · {{ $company['email'] }}@endif
    </div>
</div>

<table class="meta">
    <tr>
        <td>Reference <strong>{{ $document->number }}</strong></td>
        <td>{{ $document->issued_at->format('j F Y') }}</td>
    </tr>
</table>
