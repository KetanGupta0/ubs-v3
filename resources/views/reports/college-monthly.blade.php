{{-- A month of one college's students, in a form a department can file. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $college->name }} — {{ $month->format('F Y') }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1f2430; }
        .head { border-bottom: 2px solid #4f46e5; padding-bottom: 6px; margin-bottom: 12px; }
        .brand { font-size: 14px; font-weight: bold; color: #4f46e5; }
        .muted { color: #6b7280; font-size: 8.5px; }
        h1 { font-size: 12px; margin: 10px 0 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { background: #f3f4f6; text-align: left; padding: 5px 6px; font-size: 8px;
             text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #e5e7eb; }
        td { padding: 5px 6px; border-bottom: 1px solid #f0f1f3; }
        .right { text-align: right; }
        .risk { color: #b91c1c; font-weight: bold; }
        .ok { color: #15803d; }
        .foot { margin-top: 14px; font-size: 8px; color: #6b7280; }
    </style>
</head>
<body>
<div class="head">
    <div class="brand">{{ $company['trading'] }}</div>
    <div class="muted">{{ $company['name'] }}@if($company['email']) · {{ $company['email'] }}@endif</div>
</div>

<h1>{{ $college->name }} — progress report for {{ $month->format('F Y') }}</h1>
<div class="muted">
    {{ collect([$college->city, $college->university])->filter()->join(' · ') }}
    @if($college->coordinator_name) · Coordinator: {{ $college->coordinator_name }}@endif
</div>

<table>
    <thead>
    <tr>
        <th style="width: 18%">Student</th>
        <th style="width: 11%">Enrolment no.</th>
        <th style="width: 18%">Programme</th>
        <th class="right" style="width: 11%">Classes this month</th>
        <th class="right" style="width: 9%">Submissions</th>
        <th class="right" style="width: 9%">Course progress</th>
        <th class="right" style="width: 9%">Attendance</th>
        <th class="right" style="width: 8%">Result</th>
        <th style="width: 7%">Standing</th>
    </tr>
    </thead>
    <tbody>
    @forelse($rows as $row)
        <tr>
            <td>{{ $row['name'] }}</td>
            <td>{{ $row['enrollmentNumber'] ?: '—' }}</td>
            <td>
                {{ $row['course'] }}
                @if($row['batch'])<br><span class="muted">{{ $row['batch'] }}</span>@endif
            </td>
            <td class="right">{{ $row['monthAttended'] }} of {{ $row['monthHeld'] }}</td>
            <td class="right">{{ $row['monthSubmissions'] }}</td>
            <td class="right">{{ $row['progress'] }}%</td>
            <td class="right">{{ $row['attendance'] !== null ? $row['attendance'].'%' : '—' }}</td>
            <td class="right">{{ $row['overall'] !== null ? $row['overall'].'%' : '—' }}</td>
            <td class="{{ $row['atRisk'] ? 'risk' : 'ok' }}">
                {{ $row['atRisk'] ? 'Needs attention' : ($row['grade'] ?? 'On track') }}
            </td>
        </tr>
    @empty
        <tr><td colspan="9" class="muted">No students from this college are enrolled yet.</td></tr>
    @endforelse
    </tbody>
</table>

<div class="foot">
    Generated {{ now()->format('j F Y') }}. “Needs attention” means attendance below the course minimum, a result
    under the pass mark, or nothing submitted yet — raised now so it can still be fixed.
    Questions about any row can be answered by replying to this report.
</div>
</body>
</html>
