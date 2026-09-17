<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $document->number }}</title></head>
<body>
@include('credentials._letterhead')

<h1>Internship project report</h1>

<table class="marks">
    <tr><th style="width: 30%">Intern</th><td>{{ $payload['student'] }}</td></tr>
    @if($payload['college'])
        <tr>
            <th>College</th>
            <td>
                {{ $payload['college'] }}
                @if($payload['enrollmentNumber']) · Enrolment {{ $payload['enrollmentNumber'] }}@endif
                @if($payload['courseOfStudy']) · {{ $payload['courseOfStudy'] }}@endif
            </td>
        </tr>
    @endif
    <tr><th>Internship</th><td>{{ $payload['title'] }}</td></tr>
    @if($payload['startsOn'])
        <tr>
            <th>Period</th>
            <td>
                {{ \Illuminate\Support\Carbon::parse($payload['startsOn'])->format('j M Y') }}
                @if($payload['endsOn']) to {{ \Illuminate\Support\Carbon::parse($payload['endsOn'])->format('j M Y') }}@endif
            </td>
        </tr>
    @endif
    @if($payload['projectFocus'])
        <tr><th>Focus</th><td>{{ $payload['projectFocus'] }}</td></tr>
    @endif
</table>

<h2>Work submitted</h2>

@forelse($payload['work'] ?? [] as $index => $piece)
    <div class="entry">
        <p style="margin: 0 0 4px"><strong>{{ $index + 1 }}. {{ $piece['title'] }}</strong>
            <span class="muted">— submitted {{ $piece['submittedOn'] }}</span>
        </p>

        <p style="margin: 0 0 6px" class="muted">{{ $piece['brief'] }}</p>

        @if($piece['notes'])
            <p style="margin: 0 0 4px"><strong>What the intern wrote:</strong> {{ $piece['notes'] }}</p>
        @endif

        @if($piece['repository'] || $piece['demo'])
            <p style="margin: 0 0 4px" class="muted">
                @if($piece['repository'])Repository: {{ $piece['repository'] }}@endif
                @if($piece['demo']) · Demonstration: {{ $piece['demo'] }}@endif
            </p>
        @endif

        @if($piece['marks'] !== null)
            <p style="margin: 0">
                <strong>Assessed {{ $piece['marks'] }} out of {{ $piece['maxMarks'] }}.</strong>
                @if($piece['feedback']) {{ $piece['feedback'] }}@endif
            </p>
        @endif
    </div>
@empty
    <p class="muted">
        No work has been submitted yet, so this report has nothing to describe. It is generated from the
        intern's own submissions rather than written separately, which is why it is empty rather than
        approximate.
    </p>
@endforelse

<div class="sign">
    Compiled from the intern's submissions on {{ $document->issued_at->format('j F Y') }}.<br><br>
    For {{ $company['name'] }}<br>
    <strong>Authorised signatory</strong>
</div>

<div class="verify">
    Verify at {{ url('/verify') }} with the code
    <span class="code">{{ $document->verification_code }}</span>.
</div>
</body>
</html>
