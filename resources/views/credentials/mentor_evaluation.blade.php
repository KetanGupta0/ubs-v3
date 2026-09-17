<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $document->number }}</title></head>
<body>
@include('credentials._letterhead')

<h1>Mentor evaluation</h1>

<table class="marks">
    <tr><th style="width: 30%">Intern</th><td>{{ $payload['student'] }}</td></tr>
    @if($payload['college'])
        <tr>
            <th>College</th>
            <td>{{ $payload['college'] }}@if($payload['enrollmentNumber']) · Enrolment {{ $payload['enrollmentNumber'] }}@endif</td>
        </tr>
    @endif
    <tr><th>Internship</th><td>{{ $payload['title'] }}</td></tr>
    @if($payload['mentor'])
        <tr><th>Mentor</th><td>{{ $payload['mentor'] }}</td></tr>
    @endif
    <tr><th>Weekly reviews held</th><td>{{ $payload['reviewCount'] ?? 0 }}</td></tr>
</table>

<h2>Assessment</h2>

<table class="marks">
    <thead>
        <tr><th>Criterion</th><th class="right" style="width: 20%">Mark</th></tr>
    </thead>
    <tbody>
        @foreach($payload['criteria'] ?? [] as $criterion)
            <tr>
                <td>{{ $criterion['label'] }}</td>
                <td class="right">
                    @if($criterion['mark'] !== null)
                        {{ $criterion['mark'] }} / {{ $criterion['outOf'] }}
                    @else
                        <span class="muted">Not assessed</span>
                    @endif
                </td>
            </tr>
        @endforeach
        @if(($payload['overall'] ?? null) !== null)
            <tr>
                <td><strong>Overall</strong></td>
                <td class="right"><strong>{{ $payload['overall'] }} / 10</strong></td>
            </tr>
        @endif
    </tbody>
</table>

@if(($payload['result']['overall'] ?? null) !== null)
    <h2>Course record</h2>
    <table class="marks">
        <tr>
            <th style="width: 30%">Assessed result</th>
            <td>{{ $payload['result']['overall'] }}%
                @if($payload['result']['grade']) · {{ $payload['result']['grade'] }}@endif
            </td>
        </tr>
        @if(($payload['result']['attendance']['percent'] ?? null) !== null)
            <tr>
                <th>Attendance</th>
                <td>
                    {{ $payload['result']['attendance']['percent'] }}%
                    ({{ $payload['result']['attendance']['attended'] }} of
                    {{ $payload['result']['attendance']['held'] }} sessions)
                </td>
            </tr>
        @endif
        @if(($payload['result']['assignments']['total'] ?? 0) > 0)
            <tr>
                <th>Assignments</th>
                <td>
                    {{ $payload['result']['assignments']['submitted'] }} of
                    {{ $payload['result']['assignments']['total'] }} submitted
                </td>
            </tr>
        @endif
    </table>
@endif

@if(filled($payload['weeks'] ?? []))
    <h2>Weekly reviews</h2>
    @foreach($payload['weeks'] as $week)
        <div class="entry">
            <p style="margin: 0 0 3px"><strong>Week {{ $week['week'] }}</strong></p>
            <p style="margin: 0 0 3px">{{ $week['summary'] }}</p>
            @if($week['wentWell'])
                <p style="margin: 0 0 2px" class="muted"><strong>Went well:</strong> {{ $week['wentWell'] }}</p>
            @endif
            @if($week['toImprove'])
                <p style="margin: 0" class="muted"><strong>To improve:</strong> {{ $week['toImprove'] }}</p>
            @endif
        </div>
    @endforeach
@endif

<div class="sign">
    Assessed from {{ $payload['reviewCount'] ?? 0 }} weekly
    {{ \Illuminate\Support\Str::plural('review', $payload['reviewCount'] ?? 0) }} held during the internship.<br><br><br>
    @if($payload['mentor']){{ $payload['mentor'] }}<br>@endif
    <strong>Mentor, {{ $company['trading'] }}</strong>
</div>

<div class="verify">
    Verify at {{ url('/verify') }} with the code
    <span class="code">{{ $document->verification_code }}</span>.
</div>
</body>
</html>
