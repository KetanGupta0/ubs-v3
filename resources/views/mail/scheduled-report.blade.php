<x-mail::message>
# {{ $title }}

{{ $description }}

**{{ $window }}**

@if (count($summary))
<x-mail::table>
| Figure | Value |
| :----- | ----: |
@foreach ($summary as $figure)
| {{ $figure['label'] }} | {{ $figure['value'] }} |
@endforeach
</x-mail::table>
@endif

The full report is attached, {{ $rowCount }} {{ \Illuminate\Support\Str::plural('row', $rowCount) }} in all.

<x-mail::button :url="config('app.url') . '/admin/reports'">
Open the report library
</x-mail::button>

You are receiving this because somebody set it to go out {{ \Illuminate\Support\Str::lower($cadence) }}.
Reply to this message and we will stop it.

Thanks,<br>
{{ config('company.name') }}
</x-mail::message>
