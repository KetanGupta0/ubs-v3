{{-- A change request quoted as an addendum (CL12), exactly as sent. Its
     SHA-256 is recorded, and approving it is signing this file. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Change request {{ $change->number }}</title>
    @include('documents._style')
</head>
<body>
@include('documents._parties', [
    'from' => $parties['from'],
    'to' => $parties['to'],
    'title' => 'Quotation addendum',
    'meta' => [
        'Number' => $change->number,
        'Project' => $change->project->name.($change->project->code ? ' ('.$change->project->code.')' : ''),
        'Issued' => ($change->quoted_at ?? now())->format('j M Y'),
    ],
])

<h1>{{ $change->title }}</h1>
<p class="muted">A change to the agreed scope of {{ $change->project->name }}, asked for on {{ $change->created_at->format('j M Y') }}.</p>

<h2>What was asked</h2>
@foreach(preg_split('/\n{2,}/', trim($change->description)) as $paragraph)
    <p>{!! nl2br(e($paragraph)) !!}</p>
@endforeach

@if($change->estimate_note)
    <h2>What we will do</h2>
    @foreach(preg_split('/\n{2,}/', trim($change->estimate_note)) as $paragraph)
        <p>{!! nl2br(e($paragraph)) !!}</p>
    @endforeach
@endif

<h2>Estimate</h2>
<table class="totals" style="width: 60%; margin-left: 0">
    <tr><td>Amount before tax</td><td class="right">{{ $money($change->amount ?? 0) }}</td></tr>
    <tr><td>GST at {{ rtrim(rtrim(number_format($taxRate, 2), '0'), '.') }}%</td><td class="right">{{ $money($tax) }}</td></tr>
    <tr class="grand"><td>Total</td><td class="right">{{ $money(($change->amount ?? 0) + $tax) }}</td></tr>
    <tr><td>Effect on the timeline</td><td class="right">{{ $change->daysLabel() ?? 'none stated' }}</td></tr>
</table>

<p class="note">
    {{ $change->number }}. On approval this addendum forms part of the agreement for {{ $change->project->name }}: the amount is added to its value
    and invoiced when the work is accepted as a milestone, and the timeline moves by the days stated. Tax is shown at the rate today; the invoice
    applies the rate in force when it is raised. This text is fixed from the moment it was issued; a different estimate is a new change request.
</p>
@if(!empty($signatures))
    @include('documents._certificate', [
        'documentLabel' => 'Change request',
        'number' => $change->number,
        'version' => 1,
        'title' => $change->title,
        'sha256' => $change->pdf_sha256,
        'signatures' => $signatures,
    ])
@endif
</body>
</html>
