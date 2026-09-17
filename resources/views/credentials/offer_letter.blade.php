<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $document->number }}</title></head>
<body>
@include('credentials._letterhead')

<h1>Internship offer</h1>

<p>Dear {{ $payload['student'] }},</p>

<p>
    We are pleased to offer you a place on the <strong>{{ $payload['title'] }}</strong> internship at
    {{ $company['trading'] }}.
</p>

<table class="marks">
    <tr>
        <th style="width: 35%">Programme</th>
        <td>{{ $payload['title'] }}</td>
    </tr>
    @if($payload['startsOn'])
        <tr>
            <th>Duration</th>
            <td>
                {{ \Illuminate\Support\Carbon::parse($payload['startsOn'])->format('j M Y') }}
                @if($payload['endsOn']) to {{ \Illuminate\Support\Carbon::parse($payload['endsOn'])->format('j M Y') }}@endif
                @if($payload['durationLabel']) ({{ $payload['durationLabel'] }})@endif
            </td>
        </tr>
    @endif
    <tr>
        <th>Mode</th>
        <td>{{ ucfirst($payload['mode'] ?? 'remote') }}</td>
    </tr>
    @if($payload['projectFocus'])
        <tr>
            <th>What you will build</th>
            <td>{{ $payload['projectFocus'] }}</td>
        </tr>
    @endif
    @if($payload['college'])
        <tr>
            <th>College</th>
            <td>
                {{ $payload['college'] }}
                @if($payload['enrollmentNumber']) · Enrolment {{ $payload['enrollmentNumber'] }}@endif
            </td>
        </tr>
    @endif
</table>

<h2>What this involves</h2>
<p>
    You will work on a real piece of software with a mentor from our engineering team, attend the scheduled
    sessions, and submit the assignments set during the internship. Your mentor reviews your work weekly, and
    those reviews form the evaluation your college receives at the end.
</p>

<h2>What you receive on completion</h2>
<p>
    A completion certificate, a project report assembled from the work you actually submitted, and a signed
    mentor evaluation against the criteria your university asks for. All three carry a reference that your
    department can verify with us directly.
</p>

<p>
    Please confirm your acceptance by replying to the email this letter came with. We look forward to working
    with you.
</p>

<div class="sign">
    Yours sincerely,<br><br><br>
    For {{ $company['name'] }}<br>
    <strong>Authorised signatory</strong>
</div>

<div class="verify">
    This letter can be verified at {{ url('/verify') }} with the code
    <span class="code">{{ $document->verification_code }}</span>.
    Computer generated and valid without a signature.
</div>
</body>
</html>
