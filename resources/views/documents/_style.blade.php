{{-- One look for every client document, written for dompdf: plain flow, tables
     for columns, no positioning a printer would argue with. --}}
<style>
    @page { margin: 22mm 16mm 20mm; }
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2430; line-height: 1.55; }
    .head { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    .head td { vertical-align: top; }
    .name { font-size: 16px; font-weight: bold; color: #4f46e5; }
    .label { font-size: 8.5px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; }
    .doctitle { font-size: 15px; font-weight: bold; text-align: right; }
    .right { text-align: right; }
    .muted { color: #6b7280; }
    .party { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    .party td { width: 50%; vertical-align: top; padding: 9px 11px; border: 1px solid #e5e7eb; }
    h1 { font-size: 17px; margin: 6px 0 4px; }
    h2 { font-size: 12.5px; margin: 18px 0 6px; color: #111827; }
    h3 { font-size: 11px; margin: 14px 0 4px; }
    p { margin: 0 0 8px; }
    ul, ol { margin: 0 0 8px 18px; padding: 0; }
    li { margin-bottom: 3px; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.items th { background: #f3f4f6; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid #e5e7eb; }
    table.items td { padding: 6px 8px; border-bottom: 1px solid #f0f1f3; vertical-align: top; }
    .totals { width: 46%; margin-left: auto; margin-top: 10px; border-collapse: collapse; }
    .totals td { padding: 4px 8px; }
    .totals tr.grand td { border-top: 1.5px solid #1f2430; font-weight: bold; font-size: 12px; padding-top: 7px; }
    .words { margin-top: 8px; padding: 7px 10px; background: #f9fafb; border-left: 3px solid #4f46e5; }
    .note { margin-top: 18px; font-size: 8.5px; color: #6b7280; }
    .bar { width: 100%; height: 6px; background: #eef0f6; }
    .bar div { height: 6px; background: #4f46e5; }
</style>
