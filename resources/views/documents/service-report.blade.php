{{-- A month of a maintenance contract (CL17). Ticket figures come from the
     tickets; uptime, hours and the work done are as our side recorded them. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Service report {{ $report->monthLabel() }}</title>
    @include('documents._style')
</head>
<body>
@include('documents._parties', [
    'from' => $from,
    'to' => $to,
    'title' => 'Service report',
    'meta' => [
        'Month' => $report->monthLabel(),
        'Contract' => $contract->reference.' ('.$contract->plan.')',
        'Prepared' => now()->format('j M Y'),
    ],
])

<h1>{{ $report->monthLabel() }}</h1>

<table class="totals" style="width: 100%; margin-left: 0">
    <tr><td>Tickets raised</td><td class="right">{{ $figures['opened'] }}</td><td>Tickets resolved</td><td class="right">{{ $figures['resolved'] }}</td></tr>
    <tr>
        <td>First response within {{ $figures['responseHours'] }} hours</td><td class="right">{{ $figures['responseMet'] === null ? '—' : $figures['responseMet'].'%' }}</td>
        <td>Resolved within {{ $figures['resolutionHours'] }} hours</td><td class="right">{{ $figures['resolutionMet'] === null ? '—' : $figures['resolutionMet'].'%' }}</td>
    </tr>
    <tr>
        <td>Tickets in the contract year so far</td><td class="right">{{ $figures['ticketsThisYear'] }}{{ $figures['includedTickets'] ? ' of '.$figures['includedTickets'] : '' }}</td>
        <td>Uptime</td><td class="right">{{ $report->uptime_percent === null ? 'Not measured' : rtrim(rtrim(number_format($report->uptime_percent, 3), '0'), '.').'%' }}</td>
    </tr>
    <tr>
        <td>Hours used</td><td class="right">{{ $report->hours_used === null ? '—' : rtrim(rtrim(number_format($report->hours_used, 2), '0'), '.') }}{{ $figures['includedHours'] ? ' of '.rtrim(rtrim(number_format($figures['includedHours'], 2), '0'), '.') : '' }}</td>
        <td></td><td></td>
    </tr>
</table>

@if($workDone)
    <h2>Work done</h2>
    <div>{!! $workDone !!}</div>
@endif

@if(!empty($figures['tickets']))
    <h2>Tickets</h2>
    <table class="items">
        <thead><tr><th>Ticket</th><th>Raised</th><th>Resolved</th><th>Response</th><th>Resolution</th></tr></thead>
        <tbody>
        @foreach($figures['tickets'] as $ticket)
            <tr>
                <td>{{ $ticket['reference'] }}: {{ $ticket['subject'] }} <span class="muted">({{ $ticket['priority'] }})</span></td>
                <td>{{ $ticket['opened'] }}</td>
                <td>{{ $ticket['resolved'] ?? 'Open' }}</td>
                <td>{{ $ticket['responseMet'] === null ? '—' : ($ticket['responseMet'] ? 'In time' : 'Late') }}</td>
                <td>{{ $ticket['resolutionMet'] === null ? '—' : ($ticket['resolutionMet'] ? 'In time' : 'Late') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@else
    <p class="muted">No tickets were raised this month.</p>
@endif

<p class="note">
    Response and resolution times are measured against the times written onto each ticket when it was raised, from the contract in force then.
    A ticket still open and not yet due at the end of the month is shown without a judgement.
</p>
</body>
</html>
